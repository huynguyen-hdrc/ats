# Chạy Helidirect HR trên máy local

## Cần cài sẵn

PHP 8.3 trở lên, Composer, Node 20 trở lên, Docker Desktop (đang chạy).

## Mở hệ thống

```bash
scripts/local.sh
```

Lần đầu mất vài phút. Script lo hết các bước:

- **MariaDB 11.8** (cùng bản với Hostinger) trong Docker, cổng `33306`, dữ liệu giữ trong volume `hr-mysql-data`.
- **Mailpit**: hộp thư giả. Mọi thư mời, thư đặt lại mật khẩu gửi ra đều hiện ở http://127.0.0.1:8025, không có thư nào đi ra ngoài.
- `.env` cho local, `composer install`, `npm install`, migrate, tài khoản demo, build giao diện, rồi chạy server.

| | |
|---|---|
| Ứng dụng | http://127.0.0.1:8010 |
| Hộp thư (Mailpit) | http://127.0.0.1:8025 |
| App cũ (Supabase) | http://127.0.0.1:8010/legacy/ |

Các biến thể:

```bash
scripts/local.sh --dev     # giao diện chạy Vite dev: sửa JS/CSS thấy ngay, không cần build
scripts/local.sh --fresh   # xoá sạch database local rồi dựng lại
scripts/local.sh --stop    # tắt MariaDB + Mailpit
PORT=8020 scripts/local.sh # đổi cổng
```

## Tài khoản demo (chỉ có ở local)

Mật khẩu chung: `Demo-Pass-2026`

| Email | Vai trò | Ghi chú |
|---|---|---|
| admin.demo@helidirect.com | Quản trị | Có 2FA. Lấy mã 6 số: `php artisan hr:otp` |
| hr.demo@helidirect.com | Nhân sự | Gần như đủ quyền, không cần 2FA. Dùng để test nhanh |
| manager.demo@helidirect.com | Quản lý | Xem + ghi chú ứng viên, duyệt nghỉ phép |
| staff.demo@helidirect.com | Nhân viên | Chỉ xem vị trí, danh bạ, nghỉ phép của mình |

`php artisan hr:demo-users` tạo lại các tài khoản này (đặt lại mật khẩu). Lệnh từ chối chạy ngoài môi trường local.

## Test API bằng Postman

1. Postman → **Import** → chọn cả 3 file trong `docs/postman/`.
2. Góc phải trên chọn environment **HR Local**. Mặc định đăng nhập bằng `hr.demo@helidirect.com`; muốn đổi tài khoản thì sửa `email` / `password` trong environment.
3. Chạy theo thứ tự: **0. Bắt đầu → Lấy cookie CSRF → Đăng nhập**. Sau đó gọi API bất kỳ.
4. Đăng nhập bằng admin thì `/login` trả `two_factor: true`. Chạy `php artisan hr:otp`, dán mã vào biến `otp` của environment, rồi gọi **Nhập mã 2FA**. Mã đổi sau 30 giây.
5. Các request tạo dữ liệu tự lưu `jobId`, `candidateId`, `userId`, `roleKey` cho các request sau. Thư mục **9. Dọn dẹp** xoá những gì vừa tạo.

Chạy cả collection không cần mở Postman:

```bash
npx newman@6.2.1 run docs/postman/Helidirect-HR.postman_collection.json -e docs/postman/Local.postman_environment.json
```

Bỏ qua các request trong "1. Mật khẩu & 2FA" nếu không muốn đổi mật khẩu hoặc bật 2FA cho tài khoản demo.

Danh sách đầy đủ các API: `docs/API.md`.

## Kiểm thử tự động

```bash
php artisan test                         # SQLite trong RAM, nhanh
DB_CONNECTION=mariadb DB_HOST=127.0.0.1 DB_PORT=33306 \
  DB_DATABASE=hr DB_USERNAME=hr DB_PASSWORD=hrpass php artisan test   # trên MariaDB như server
```

Lưu ý: chạy test trên MariaDB sẽ **xoá dữ liệu local**. Chạy xong thì `scripts/local.sh` để dựng lại tài khoản demo.

## Gặp lỗi

| Hiện tượng | Cách xử lý |
|---|---|
| `Docker chưa chạy` | Mở Docker Desktop rồi chạy lại script |
| Cổng 8010 / 33306 / 8025 bị chiếm | `PORT=8020 scripts/local.sh`, hoặc tắt chương trình đang dùng cổng (`lsof -i :8010`) |
| Postman báo 419 | Chạy lại "Lấy cookie CSRF" |
| Postman báo 403 `two_factor_required` | Tài khoản admin chưa nhập mã 2FA. Xem bước 4 ở trên |
| Sửa JS/CSS mà không thấy đổi | Dùng `scripts/local.sh --dev`, hoặc `npm run build` |
