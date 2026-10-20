#!/usr/bin/env python3
"""Sinh Postman collection + environment cho Helidirect HR API.

    python3 scripts/postman/build.py

Ghi ra docs/postman/. Khi thêm module mới: thêm request vào FOLDERS rồi chạy lại.
API dùng phiên cookie + CSRF (không có token Bearer): collection tự lưu cookie XSRF-TOKEN sau mỗi
response và gắn header X-XSRF-TOKEN vào request kế tiếp.
"""
import json
import pathlib
import uuid

OUT = pathlib.Path(__file__).resolve().parents[2] / "docs" / "postman"

PRE_REQUEST = """// Header chung cho mọi request
pm.request.headers.upsert({ key: 'Accept', value: 'application/json' });
pm.request.headers.upsert({ key: 'X-Requested-With', value: 'XMLHttpRequest' });
const xsrf = pm.collectionVariables.get('xsrf');
if (xsrf) pm.request.headers.upsert({ key: 'X-XSRF-TOKEN', value: xsrf });"""

AFTER_RESPONSE = """// Lưu token CSRF mới nhất (Laravel đặt lại cookie XSRF-TOKEN trên mọi response)
const c = pm.cookies.get('XSRF-TOKEN');
if (c) pm.collectionVariables.set('xsrf', decodeURIComponent(c));
if (pm.response.code === 419) console.warn('419: token CSRF hết hạn — chạy lại "Lấy cookie CSRF".');
if (pm.response.code === 401) console.warn('401: chưa đăng nhập — chạy "Đăng nhập".');"""


def save(var, path="data.id"):
    js_path = "".join(f"[{p}]" if p.isdigit() else f".{p}" for p in path.split("."))
    return f"""if (pm.response.code < 300) {{
  const v = pm.response.json(){js_path};
  if (v) pm.collectionVariables.set('{var}', v);
}}"""


def req(name, method, path, body=None, desc="", tests=None, query=None, form=None, pre=None):
    url_raw = "{{baseUrl}}" + path
    url = {"raw": url_raw, "host": ["{{baseUrl}}"], "path": [p for p in path.strip("/").split("/") if p]}
    if query:
        url["query"] = [{"key": k, "value": v, "disabled": v == ""} for k, v in query.items()]
        url["raw"] += "?" + "&".join(f"{k}={v}" for k, v in query.items() if v != "")
    r = {"method": method, "header": [], "url": url, "description": desc}
    if body is not None:
        r["header"].append({"key": "Content-Type", "value": "application/json"})
        r["body"] = {"mode": "raw", "raw": json.dumps(body, ensure_ascii=False, indent=2),
                     "options": {"raw": {"language": "json"}}}
    if form is not None:
        r["body"] = {"mode": "formdata", "formdata": form}
    item = {"name": name, "request": r, "event": []}
    if pre:
        item["event"].append({"listen": "prerequest", "script": {"type": "text/javascript", "exec": pre.split("\n")}})
    if tests:
        item["event"].append({"listen": "test", "script": {"type": "text/javascript", "exec": tests.split("\n")}})
    return item


def folder(name, items, desc=""):
    return {"name": name, "description": desc, "item": items}


EXCEL_SAMPLE = {
    "headers": ["Tên vị trí *", "Phòng ban", "Số lượng", "Hạn tuyển xong (yyyy-mm-dd)", "Đang tuyển (Có/Không)"],
    "rows": [
        [{"v": "Content Executive", "s": "Content Executive"}, {"v": "Marketing", "s": "Marketing"},
         {"v": 2, "s": "2"}, {"v": "2026-12-31", "s": "2026-12-31"}, {"v": "Có", "s": "Có"}],
        [{"v": "Kế toán", "s": "Kế toán"}, {"v": "Finance", "s": "Finance"},
         {"v": "hai", "s": "hai"}, {"v": "31/02/2026", "s": "31/02/2026"}, {"v": "Không", "s": "Không"}],
    ],
}

FOLDERS = [
    folder("0. Bắt đầu — đăng nhập", [
        req("Lấy cookie CSRF", "GET", "/login",
            desc="Chạy đầu tiên: server trả cookie phiên + XSRF-TOKEN. Collection tự lưu token."),
        req("Đăng nhập", "POST", "/login", {"email": "{{email}}", "password": "{{password}}", "remember": False},
            desc="200 `{\"two_factor\": false}` = đã đăng nhập. `{\"two_factor\": true}` = tài khoản có 2FA → chạy "
                 "\"Nhập mã 2FA\". Sai 5 lần trong 15 phút (theo email + IP) thì bị chặn."),
        req("Nhập mã 2FA (sau khi đăng nhập)", "POST", "/two-factor-challenge", {"code": "{{otp}}"},
            desc="Mã 6 số từ app Authenticator. Hoặc gửi `{\"recovery_code\": \"...\"}`."),
        req("Thông tin của tôi + ma trận quyền", "GET", "/api/me",
            desc="`two_factor_required: true` = admin chưa bật 2FA; mọi API khác trả 403 `two_factor_required`."),
        req("Đăng xuất", "POST", "/logout"),
    ], "Thứ tự: Lấy cookie CSRF → Đăng nhập → (Nhập mã 2FA) → gọi API bất kỳ."),

    folder("1. Mật khẩu & 2FA", [
        req("Quên mật khẩu", "POST", "/forgot-password", {"email": "{{email}}"},
            desc="Luôn trả cùng một thông báo dù email có tồn tại hay không. Thư chứa link /reset-password?token=…&email=…"),
        req("Đặt lại mật khẩu (từ link email)", "POST", "/reset-password",
            {"token": "TOKEN_TRONG_EMAIL", "email": "{{email}}", "password": "Mat-khau-moi-123",
             "password_confirmation": "Mat-khau-moi-123"}),
        req("Nhận lời mời (đặt mật khẩu lần đầu)", "POST", "/accept-invite",
            {"token": "TOKEN_TRONG_EMAIL", "email": "nguoi.moi@helidirect.com", "password": "Mat-khau-moi-123",
             "password_confirmation": "Mat-khau-moi-123"}, desc="Link mời hạn 72 giờ, dùng một lần. Không tự đăng nhập."),
        req("Đổi mật khẩu", "PUT", "/user/password",
            {"current_password": "{{password}}", "password": "Mat-khau-moi-123", "password_confirmation": "Mat-khau-moi-123"},
            desc="Đổi xong thì các thiết bị khác bị đăng xuất."),
        req("Xác nhận mật khẩu (trước thao tác bảo mật)", "POST", "/user/confirm-password", {"password": "{{password}}"},
            desc="Cần trước các route 2FA bên dưới. Đăng nhập bằng mật khẩu cũng được tính là vừa xác nhận."),
        req("Trạng thái xác nhận mật khẩu", "GET", "/user/confirmed-password-status"),
        req("2FA ① Bật (tạo secret)", "POST", "/user/two-factor-authentication"),
        req("2FA ② Lấy mã QR (SVG)", "GET", "/user/two-factor-qr-code"),
        req("2FA ② Lấy khoá thủ công", "GET", "/user/two-factor-secret-key",
            tests="if (pm.response.code === 200) pm.collectionVariables.set('totpSecret', pm.response.json().secretKey);"),
        req("2FA ③ Xác nhận bằng mã 6 số", "POST", "/user/confirmed-two-factor-authentication", {"code": "{{otp}}"}),
        req("2FA ④ Xem mã khôi phục", "GET", "/user/two-factor-recovery-codes"),
        req("2FA Tạo lại mã khôi phục", "POST", "/user/two-factor-recovery-codes"),
        req("2FA Tắt", "DELETE", "/user/two-factor-authentication", desc="Admin tắt xong sẽ bị buộc bật lại."),
    ]),

    folder("2. Người dùng (quyền settings)", [
        req("Danh sách", "GET", "/api/users", query={"q": "", "status": ""},
            desc="Lọc: q (tên/email), status = invited | active | locked."),
        req("Mời người dùng", "POST", "/api/users",
            {"name": "Nguyễn Văn Test", "email": "postman.{{$timestamp}}@helidirect.com", "role_key": "staff"},
            desc="Tạo trạng thái invited + gửi thư mời. `invite_sent: false` = SMTP chưa cấu hình (local ghi vào log).",
            tests=save("userId")),
        req("Sửa (tên, vai trò)", "PATCH", "/api/users/{{userId}}", {"name": "Nguyễn Văn Test 2", "role_key": "manager"}),
        req("Khoá", "POST", "/api/users/{{userId}}/lock", desc="Huỷ ngay mọi phiên của người đó."),
        req("Mở khoá", "POST", "/api/users/{{userId}}/unlock"),
        req("Gửi lại lời mời", "POST", "/api/users/{{userId}}/resend-invite"),
        req("Đặt lại 2FA (chỉ admin)", "POST", "/api/users/{{userId}}/reset-two-factor"),
    ]),

    folder("3. Vai trò & ma trận quyền (quyền settings)", [
        req("Danh sách vai trò + ma trận", "GET", "/api/roles"),
        req("Tạo vai trò", "POST", "/api/roles", {"key": "{{roleKey}}", "label": "Trưởng nhóm tuyển dụng", "base": "staff"},
            desc="base = admin | hr | manager | staff (quyết định tab và phạm vi dữ liệu). Mã vai trò tự sinh mỗi lần chạy.",
            pre="pm.collectionVariables.set('roleKey', 'td_' + Date.now());"),
        req("Sửa vai trò", "PATCH", "/api/roles/{{roleKey}}", {"label": "Trưởng nhóm TD"}),
        req("Ghi ma trận quyền", "PUT", "/api/roles/{{roleKey}}/perms",
            {"perms": {"candidates": {"view": True, "create": True, "edit": True, "comment": True}, "jobs": {"view": True}}},
            desc="Gửi cả ma trận: ô không có = false. Module: candidates, jobs, offers, employees, contracts, leave, "
                 "onboarding, probation, mail, reports, backups, settings. Hành động: view, create, edit, delete, approve, comment."),
    ]),

    folder("4. Nhật ký thao tác (quyền settings.view)", [
        req("Danh sách (phân trang 50)", "GET", "/api/audit-logs",
            query={"action": "auth.", "user_id": "", "from": "", "to": "", "page": "1"},
            desc="action kết thúc bằng dấu chấm = cả nhóm (auth., user., role., jobs., candidates.); không có dấu chấm = khớp đúng."),
    ]),

    folder("5. Vị trí & JD (quyền jobs)", [
        req("Danh sách", "GET", "/api/jobs", desc="Có candidates_count / hired_count nếu bạn có quyền candidates.view."),
        req("Tạo vị trí", "POST", "/api/jobs",
            {"title": "Data Analyst", "department": "Marketing", "headcount": 2, "location": "HCM",
             "employment_type": "Full-time", "hiring_manager": "Nguyễn Văn A", "salary_range": "15-20tr",
             "target_date": "2026-12-31", "is_open": True, "jd_summary": "Phân tích dữ liệu bán hàng",
             "jd_responsibilities": "Làm báo cáo tuần\nXây dashboard", "jd_requirements": "2 năm SQL\nPower BI",
             "jd_benefits": "Lương tháng 13"},
            desc="employment_type: Full-time | Part-time | Thực tập | Thời vụ. target_date dạng yyyy-mm-dd.",
            tests=save("jobId")),
        req("Chi tiết", "GET", "/api/jobs/{{jobId}}"),
        req("Sửa", "PATCH", "/api/jobs/{{jobId}}", {"is_open": False, "headcount": 3}),
    ]),

    folder("6. Tuyển dụng — ứng viên (quyền candidates)", [
        req("Danh sách vòng tuyển", "GET", "/api/stages"),
        req("Danh sách ứng viên", "GET", "/api/candidates", query={"job_id": ""}),
        req("Tạo ứng viên", "POST", "/api/candidates",
            {"full_name": "Trần Thị B", "email": "b.tran@gmail.com", "phone": "0912345678",
             "current_title": "Data Analyst", "current_company": "ACB", "years_experience": 2.8,
             "source": "TopCV", "job_id": "{{jobId}}", "stage": "new", "skills": ["SQL", "Excel", "Power BI"]},
            tests=save("candidateId")),
        req("Chi tiết (kèm lịch sử)", "GET", "/api/candidates/{{candidateId}}"),
        req("Sửa", "PATCH", "/api/candidates/{{candidateId}}", {"current_title": "Senior Data Analyst"}),
        req("Chuyển vòng", "PATCH", "/api/candidates/{{candidateId}}/stage", {"stage": "phone"},
            desc="Server tự ghi sự kiện stage_change vào lịch sử."),
        req("Đổi trạng thái (loại / mở lại)", "PATCH", "/api/candidates/{{candidateId}}/status", {"status": "rejected"},
            desc="status = active | rejected | withdrawn."),
        req("Thêm ghi chú (quyền comment)", "POST", "/api/candidates/{{candidateId}}/notes", {"body": "Phỏng vấn tốt, mong muốn 18tr"}),
        req("Tải CV lên", "POST", "/api/candidates/{{candidateId}}/cv",
            form=[{"key": "file", "type": "file", "src": []}],
            desc="multipart, field `file`: .pdf / .docx / .txt, tối đa 15 MB. Chọn file ở tab Body."),
        req("Tải CV về", "GET", "/api/candidates/{{candidateId}}/cv", desc="Trả file dạng attachment. Bấm Save Response → Save to a file."),
        req("Xoá CV", "DELETE", "/api/candidates/{{candidateId}}/cv"),
        req("Đọc CV (AI / quy tắc)", "POST", "/api/candidates/parse-cv",
            {"filename": "TopCV-TranMinhHoang.pdf",
             "text": "CURRICULUM VITAE\nTran Minh Hoang\nSenior Data Analyst at Tiki\nhoang.tran@gmail.com\n0912345678\n\nSUMMARY\n5 years of experience in analytics. SQL, Python, Power BI."},
            desc="engine = ai khi có ANTHROPIC_API_KEY, ngược lại rules. Văn bản < 20 ký tự → 400. Giới hạn 30 lần/phút."),
    ]),

    folder("7. Excel (entity = jobs | candidates)", [
        req("Mẫu nhập (tiêu đề + dòng mẫu + hướng dẫn)", "GET", "/api/excel/jobs/template"),
        req("Xuất dữ liệu", "GET", "/api/excel/jobs/export", desc="Trả JSON; giao diện web chuyển thành file .xlsx."),
        req("Xem trước khi nhập", "POST", "/api/excel/jobs/preview", EXCEL_SAMPLE,
            desc="Body = các ô đọc từ file: v = giá trị gốc, s = chuỗi hiển thị. Mẫu có 1 dòng lỗi để xem báo lỗi."),
        req("Nhập (ghi dữ liệu)", "POST", "/api/excel/jobs/import", {**EXCEL_SAMPLE, "rows": EXCEL_SAMPLE["rows"][:1]},
            desc="Chỉ ghi khi không còn dòng lỗi (có lỗi → 422, không ghi dòng nào). Trùng tên + phòng ban thì cập nhật. Cần quyền create + edit."),
    ]),

    folder("8. Hệ thống", [
        req("Health check", "GET", "/up", desc="200 khi app chạy (không cần đăng nhập)."),
    ]),
    folder("9. Dọn dẹp (xoá dữ liệu vừa tạo)", [
        req("Xoá ứng viên", "DELETE", "/api/candidates/{{candidateId}}", desc="Cần candidates.delete. Xoá luôn thư mục CV."),
        req("Xoá vị trí", "DELETE", "/api/jobs/{{jobId}}", desc="Cần jobs.delete. Ứng viên của vị trí giữ nguyên, job_id → null."),
        req("Xoá vai trò", "DELETE", "/api/roles/{{roleKey}}",
            desc="Cần settings.delete (mặc định chỉ admin). Không xoá được vai trò hệ thống hoặc vai trò đang có người dùng."),
    ], "Chạy cuối cùng. Người dùng không xoá được (chỉ khoá) để giữ lịch sử thao tác."),

]


def ids(items, prefix="hr-helidirect"):
    """_postman_id ổn định theo đường dẫn thư mục, để import lại thì cập nhật chứ không nhân đôi."""
    for it in items:
        key = f"{prefix}/{it['name']}"
        it["_postman_id"] = str(uuid.uuid5(uuid.NAMESPACE_URL, key))
        ids(it.get("item", []), key)


collection = {
    "info": {
        "_postman_id": str(uuid.uuid5(uuid.NAMESPACE_URL, "hr-helidirect/collection")),
        "name": "Helidirect HR API",
        "description": "API của hr.helidirect.io (Laravel). Đăng nhập bằng phiên cookie + CSRF.\n\n"
                       "1. Chọn environment (Local hoặc Production).\n"
                       "2. Chạy \"0. Bắt đầu → Lấy cookie CSRF\" rồi \"Đăng nhập\".\n"
                       "3. Gọi API bất kỳ. Request tạo dữ liệu tự lưu id (jobId, candidateId, userId) cho các request sau.\n\n"
                       "Lỗi thường gặp: 401 = chưa đăng nhập · 403 = vai trò không có quyền (hoặc admin chưa bật 2FA) · "
                       "419 = token CSRF cũ, chạy lại \"Lấy cookie CSRF\" · 422 = dữ liệu không hợp lệ (xem `errors`).",
        "schema": "https://schema.getpostman.com/json/collection/v2.1.0/collection.json",
    },
    "event": [
        {"listen": "prerequest", "script": {"type": "text/javascript", "exec": PRE_REQUEST.split("\n")}},
        {"listen": "test", "script": {"type": "text/javascript", "exec": AFTER_RESPONSE.split("\n")}},
    ],
    "variable": [{"key": k, "value": v} for k, v in {
        "xsrf": "", "jobId": "", "candidateId": "", "userId": "", "roleKey": "truong_nhom_td",
        "totpSecret": "",
    }.items()],
    "item": FOLDERS,
}
ids(collection["item"])


def env(name, base, email, password):
    return {
        "id": str(uuid.uuid5(uuid.NAMESPACE_URL, "hr-helidirect/env/" + name)),
        "name": name,
        "values": [
            {"key": "baseUrl", "value": base, "enabled": True},
            {"key": "email", "value": email, "enabled": True},
            {"key": "password", "value": password, "type": "secret", "enabled": True},
            {"key": "otp", "value": "", "enabled": True},
        ],
        "_postman_variable_scope": "environment",
    }


OUT.mkdir(parents=True, exist_ok=True)
(OUT / "Helidirect-HR.postman_collection.json").write_text(json.dumps(collection, ensure_ascii=False, indent=2))
(OUT / "Local.postman_environment.json").write_text(json.dumps(
    env("HR Local", "http://127.0.0.1:8010", "hr.demo@helidirect.com", "Demo-Pass-2026"), ensure_ascii=False, indent=2))
(OUT / "Production.postman_environment.json").write_text(json.dumps(
    env("HR Production", "https://hr.helidirect.io", "", ""), ensure_ascii=False, indent=2))

count = sum(len(f["item"]) for f in FOLDERS)
print(f"Đã ghi {count} request vào {OUT}")
