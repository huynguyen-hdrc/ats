# Helidirect HR — làm lại nền (Laravel + MySQL)

Ngày: 2026-10-20 · Trạng thái: đang triển khai theo từng module.
Kiểm kê nghiệp vụ của app cũ: `docs/specs/2026-10-20-module-inventory.md`.

## Mục tiêu

Giữ nguyên 9 màn hình và luồng nghiệp vụ của app cũ (`public/legacy/index.html`, chạy trên Supabase),
chuyển sang backend riêng trên Hostinger với hệ thống user đầy đủ. Đợt này không thêm tính năng nghiệp vụ.

## Quyết định đã chốt

| Hạng mục | Chọn |
|---|---|
| Backend | Laravel 13, PHP 8.3 (phiên bản PHP chung của cả tài khoản Hostinger; đổi sẽ ảnh hưởng các site dashboard khác) |
| CSDL | MariaDB 11.8 trên Hostinger (driver `mariadb`), kết nối `127.0.0.1` |
| Frontend | Vite + JavaScript thuần chia module ES; giữ giao diện chuẩn HD (`docs/HD-document-standard.md`) |
| Đăng nhập | Email + mật khẩu (Laravel Fortify), phiên cookie httpOnly + CSRF, cùng domain |
| Email | Google Workspace SMTP (App Password của một hộp thư @helidirect.com) |
| AI đọc CV | Claude API gọi từ server; key trong `.env` |
| Domain | `hr.helidirect.io`; app cũ giữ tại `/legacy/` tới ngày chuyển hẳn |

## Bố cục triển khai

```
~/domains/hr.helidirect.io/
  app/            mã Laravel + .env + storage (ngoài web root)
  public_html  -> app/public   (symlink)
cron: * * * * * /usr/bin/php ~/domains/hr.helidirect.io/app/artisan schedule:run
```

File CV và hồ sơ nằm trong `storage/app/private/{cvs,hr-docs}/{record_id}/…`, chỉ tải qua API có kiểm quyền.

## Hệ thống user

- Bảng `users` (khoá uuid): email duy nhất, họ tên, `role_key`, `status` (`invited` | `active` | `locked`),
  `employee_id` (liên kết hồ sơ nhân sự, có thể trống), lần đăng nhập cuối, cột 2FA của Fortify.
  Không xoá cứng user.
- **Mời**: admin/HR tạo user → thư có link đặt mật khẩu, hạn 72 giờ, dùng 1 lần (broker `invites`).
- **Đăng nhập**: email + mật khẩu, "ghi nhớ" 30 ngày; phiên hết hạn sau 8 giờ không thao tác.
  Tài khoản `invited`/`locked` không đăng nhập được (thông báo chung, không lộ trạng thái).
- **Quên mật khẩu**: link hạn 60 phút; phản hồi luôn giống nhau dù email có tồn tại hay không.
- **Đổi mật khẩu**: cần mật khẩu cũ; xong thì huỷ mọi phiên khác.
- **Khoá**: huỷ ngay mọi phiên và token "ghi nhớ" của user.
- **Mật khẩu**: tối thiểu 10 ký tự, kiểm tra danh sách mật khẩu đã lộ; bcrypt.
- **Chống dò**: sai 5 lần trong 15 phút (theo email + IP) thì tạm chặn.
- **2FA** (TOTP): bắt buộc với vai trò gốc admin; tuỳ chọn với vai trò khác. Admin chưa bật 2FA
  chỉ dùng được màn hình bật 2FA.
- **Nhật ký** `audit_logs`: đăng nhập thành công/thất bại/bị chặn, đăng xuất, đặt lại/đổi mật khẩu,
  tạo/mời/khoá/mở khoá user, đổi vai trò, sửa ma trận quyền, mọi thao tác xoá và duyệt dữ liệu nhân sự.

## Phân quyền

Giữ mô hình cũ: 4 vai trò gốc (`admin`, `hr`, `manager`, `staff`), vai trò tuỳ chỉnh có `base`,
ma trận `role_perms` 12 module × 6 hành động (`view`, `create`, `edit`, `delete`, `approve`, `comment`).
Admin luôn đủ quyền. Backend kiểm quyền ở mọi route (middleware `perm:module,action`) và lọc dữ liệu
theo phạm vi (Policy) thay cho RLS của Supabase. Giao diện chỉ ẩn nút để dễ dùng, không phải hàng rào.

## Cách chuyển module

Mỗi module của app cũ thành một khối độc lập:

- Backend: migration, Model, FormRequest (validation), Controller REST `/api/<module>`, Policy (phạm vi
  dữ liệu), Feature test.
- Frontend: `resources/js/views/<module>.js` dùng chung `core/api.js`, `core/ui.js`, `core/forms.js`.
- Thứ tự: nền + user → Vị trí & JD → Tuyển dụng (ứng viên, CV, offer) → Hồ sơ nhân sự & hợp đồng →
  Nhân sự mới (onboarding, thử việc) → Nghỉ phép → Thư & Email → Tổng quan & thông báo → Sao lưu.
- Mỗi module xong thì deploy và kiểm trên `hr.helidirect.io`.

Tên bảng giữ như Supabase, trừ bảng hàng đợi của Laravel đổi thành `queue_jobs` để nhường tên `jobs`
cho vị trí tuyển dụng. Khoá chính các bảng nghiệp vụ giữ uuid để chuyển dữ liệu giữ nguyên id và đường dẫn file.

## Chuyển dữ liệu

Lệnh `php artisan hr:import-supabase` đọc Postgres (connection string) và Storage (service_role) của
Supabase, ghi vào MySQL và `storage/app/private`. Chạy lại được nhiều lần (upsert theo id).
User cũ được tạo ở trạng thái `invited`; thư mời gửi vào ngày chuyển hẳn.

## Kiểm thử

Feature test (PHPUnit) cho từng route: đúng quyền, sai quyền, sai phạm vi, validation.
Bộ selftest của app cũ (`public/legacy/selftest.html`) được chuyển thành test tương ứng.
Test chạy trên SQLite in-memory và trên MariaDB 11.8 (Docker, cùng bản với server) trước mỗi lần deploy.
Deploy: `scripts/deploy.sh` (SSH key `~/.ssh/hr_helidirect_deploy`).
