# Helidirect HR — danh sách API

Base URL: local `http://127.0.0.1:8010` · production `https://hr.helidirect.io`

Postman: import `docs/postman/Helidirect-HR.postman_collection.json` và một environment
(`Local.postman_environment.json` hoặc `Production.postman_environment.json`). Collection sinh từ
`scripts/postman/build.py`: thêm API mới thì sửa file đó rồi chạy `python3 scripts/postman/build.py`.

## Cách xác thực

API dùng **phiên cookie + CSRF**, không có token Bearer (giao diện web và API cùng domain).

1. `GET /login`: server trả cookie phiên và cookie `XSRF-TOKEN`.
2. Mọi request ghi (POST/PUT/PATCH/DELETE) gửi header `X-XSRF-TOKEN` = giá trị cookie `XSRF-TOKEN` (đã URL-decode).
   Collection Postman tự làm việc này.
3. Luôn gửi `Accept: application/json` để lỗi trả về dạng JSON.
4. `POST /login`. Nếu trả `{"two_factor": true}` thì gọi tiếp `POST /two-factor-challenge` với mã 6 số.

| Mã | Ý nghĩa |
|---|---|
| 401 | Chưa đăng nhập, phiên hết hạn, hoặc tài khoản vừa bị khoá (`code: account_inactive`) |
| 403 | Vai trò không có quyền (`code: forbidden`), hoặc admin chưa bật 2FA (`code: two_factor_required`) |
| 419 | Token CSRF cũ. Gọi lại `GET /login` |
| 422 | Dữ liệu không hợp lệ: `{"message": "...", "errors": {"field": ["..."]}}` |
| 429 | Gọi quá nhanh (đăng nhập, quên mật khẩu, đọc CV) |

Quyền ghi theo dạng `module.hành_động`. Admin luôn đủ quyền. Ma trận quyền xem và sửa ở `GET/PUT /api/roles…`.

## Đăng nhập & tài khoản (không có tiền tố /api)

| Method | Đường dẫn | Body | Ghi chú |
|---|---|---|---|
| GET | `/login` | | Lấy cookie phiên + CSRF |
| POST | `/login` | `email, password, remember?` | Sai 5 lần / 15 phút (email + IP) thì bị chặn |
| POST | `/two-factor-challenge` | `code` hoặc `recovery_code` | Sau khi `/login` trả `two_factor: true` |
| POST | `/logout` | | |
| POST | `/forgot-password` | `email` | Luôn trả cùng một thông báo |
| POST | `/reset-password` | `token, email, password, password_confirmation` | Token trong thư, hạn 60 phút |
| POST | `/accept-invite` | `token, email, password, password_confirmation` | Token trong thư mời, hạn 72 giờ |
| PUT | `/user/password` | `current_password, password, password_confirmation` | Đăng xuất các thiết bị khác |
| POST | `/user/confirm-password` | `password` | Cần trước các route 2FA |
| GET | `/user/confirmed-password-status` | | |
| POST | `/user/two-factor-authentication` | | Bật 2FA (tạo secret) |
| GET | `/user/two-factor-qr-code` | | `{svg, url}` |
| GET | `/user/two-factor-secret-key` | | `{secretKey}` |
| POST | `/user/confirmed-two-factor-authentication` | `code` | Xác nhận để 2FA có hiệu lực |
| GET / POST | `/user/two-factor-recovery-codes` | | Xem / tạo lại mã khôi phục |
| DELETE | `/user/two-factor-authentication` | | Tắt 2FA |

Mật khẩu: tối thiểu 10 ký tự, không nằm trong danh sách mật khẩu đã bị lộ.

## Hệ thống — quyền `settings`

| Method | Đường dẫn | Quyền | Body / query |
|---|---|---|---|
| GET | `/api/me` | đăng nhập | User, vai trò, ma trận quyền đầy đủ, `two_factor_required` |
| GET | `/api/users` | settings.view | `?q=&status=invited\|active\|locked` |
| POST | `/api/users` | settings.create | `name, email, role_key`: mời người dùng (gửi thư) |
| PATCH | `/api/users/{id}` | settings.edit | `name?, role_key?` |
| POST | `/api/users/{id}/lock` | settings.edit | Huỷ mọi phiên của người đó |
| POST | `/api/users/{id}/unlock` | settings.edit | |
| POST | `/api/users/{id}/resend-invite` | settings.create | Chỉ tài khoản chưa kích hoạt |
| POST | `/api/users/{id}/reset-two-factor` | settings.edit + admin | Khi người dùng mất điện thoại |
| GET | `/api/roles` | settings.view | Vai trò + ma trận quyền |
| POST | `/api/roles` | settings.create | `key, label, base (admin\|hr\|manager\|staff)` |
| PATCH | `/api/roles/{key}` | settings.edit | `label?, sort?, base?` |
| DELETE | `/api/roles/{key}` | settings.delete | Không xoá vai trò hệ thống / đang có người dùng |
| PUT | `/api/roles/{key}/perms` | settings.edit | `{perms: {module: {action: bool}}}` |
| GET | `/api/audit-logs` | settings.view | `?action=auth.&user_id=&from=&to=&page=` (50 dòng/trang) |

Rào chắn: không tự khoá hay tự đổi vai trò của mình; không khoá hay hạ quyền admin cuối cùng; chỉ admin được gán hoặc sửa vai trò admin;
người không phải admin không sửa được ma trận quyền của chính vai trò mình đang giữ.

## Vị trí & JD — quyền `jobs`

| Method | Đường dẫn | Quyền | Ghi chú |
|---|---|---|---|
| GET | `/api/jobs` | jobs.view | Có `candidates_count`, `hired_count` nếu có candidates.view |
| POST | `/api/jobs` | jobs.create | `title*` + department, headcount, location, employment_type (Full-time\|Part-time\|Thực tập\|Thời vụ), hiring_manager, salary_range, target_date (yyyy-mm-dd), is_open, jd_summary, jd_responsibilities, jd_requirements, jd_benefits |
| GET | `/api/jobs/{id}` | jobs.view | |
| PATCH | `/api/jobs/{id}` | jobs.edit | Gửi trường nào sửa trường đó |
| DELETE | `/api/jobs/{id}` | jobs.delete | Ứng viên giữ nguyên, `job_id` → null |

## Tuyển dụng — quyền `candidates`

| Method | Đường dẫn | Quyền | Ghi chú |
|---|---|---|---|
| GET | `/api/stages` | candidates.view | Các vòng tuyển theo thứ tự |
| GET | `/api/candidates` | candidates.view | `?job_id=` |
| POST | `/api/candidates` | candidates.create | `full_name*` + email, phone, current_title, current_company, years_experience, source, job_id, stage, skills[] |
| GET | `/api/candidates/{id}` | candidates.view | Kèm lịch sử (events) |
| PATCH | `/api/candidates/{id}` | candidates.edit | |
| PATCH | `/api/candidates/{id}/stage` | candidates.edit | `stage`: tự ghi lịch sử chuyển vòng |
| PATCH | `/api/candidates/{id}/status` | candidates.edit | `status`: active \| rejected \| withdrawn |
| POST | `/api/candidates/{id}/notes` | candidates.comment | `body` |
| DELETE | `/api/candidates/{id}` | candidates.delete | Xoá luôn file CV |
| POST | `/api/candidates/{id}/cv` | candidates.edit (hoặc create với hồ sơ mình vừa tạo) | multipart `file`: .pdf/.docx/.txt, ≤ 15 MB |
| GET | `/api/candidates/{id}/cv` | candidates.view | Tải file (attachment) |
| DELETE | `/api/candidates/{id}/cv` | candidates.edit | |
| POST | `/api/candidates/parse-cv` | candidates.create | `text, filename?` → `{candidate, engine: ai\|rules, not_a_cv}`; 30 lần/phút |

## Excel — `entity` = `jobs` \| `candidates`

| Method | Đường dẫn | Quyền | Ghi chú |
|---|---|---|---|
| GET | `/api/excel/{entity}/template` | {module}.view | Tiêu đề cột, dòng mẫu, hướng dẫn |
| GET | `/api/excel/{entity}/export` | {module}.view | `{filename, sheet, headers, rows}` |
| POST | `/api/excel/{entity}/preview` | create + edit | `{headers: [], rows: [[{v, s}]]}` → số dòng hợp lệ, lỗi theo số dòng trong file, số dòng trùng |
| POST | `/api/excel/{entity}/import` | create + edit | Cùng body. Có lỗi → 422, không ghi dòng nào. Trùng khoá → cập nhật |

`v` = giá trị gốc của ô, `s` = chuỗi hiển thị (giữ số 0 đầu của số điện thoại, mã nhân viên).

## Khác

| Method | Đường dẫn | Ghi chú |
|---|---|---|
| GET | `/up` | Health check, không cần đăng nhập |

Các module sau sẽ thêm API khi chuyển sang: Hồ sơ nhân sự & hợp đồng, Nhân sự mới (onboarding, thử việc),
Nghỉ phép, Thư & Email (offer, mẫu thư), Tổng quan & nhắc việc, Sao lưu.
