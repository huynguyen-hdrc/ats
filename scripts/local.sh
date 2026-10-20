#!/usr/bin/env bash
# Mở Helidirect HR trên máy local.
#
#   scripts/local.sh          chuẩn bị (lần đầu) + chạy http://127.0.0.1:8010
#   scripts/local.sh --dev    như trên nhưng giao diện chạy Vite dev (sửa JS/CSS thấy ngay)
#   scripts/local.sh --fresh  xoá sạch database local rồi dựng lại
#   scripts/local.sh --stop   tắt MariaDB + Mailpit
#
# Cần: PHP 8.3+, Composer, Node 20+, Docker.
set -euo pipefail
cd "$(dirname "$0")/.."

PORT=${PORT:-8010}
DB_CT=hr-mysql
MAIL_CT=hr-mailpit
DB_PORT=33306

say() { printf '\033[1m▸ %s\033[0m\n' "$*"; }

if [[ "${1:-}" == "--stop" ]]; then
  docker stop "$DB_CT" "$MAIL_CT" >/dev/null 2>&1 || true
  say "Đã tắt MariaDB và Mailpit"; exit 0
fi

for bin in php composer npm docker; do
  command -v "$bin" >/dev/null || { echo "Thiếu $bin"; exit 1; }
done
docker info >/dev/null 2>&1 || { echo "Docker chưa chạy — mở Docker Desktop rồi thử lại"; exit 1; }

# ---- MariaDB 11.8 (cùng bản với Hostinger) ----
if ! docker ps -a --format '{{.Names}}' | grep -qx "$DB_CT"; then
  say "Tạo MariaDB 11.8 ở cổng $DB_PORT"
  docker run -d --name "$DB_CT" -p "$DB_PORT:3306" -v hr-mysql-data:/var/lib/mysql \
    -e MARIADB_ROOT_PASSWORD=root -e MARIADB_DATABASE=hr -e MARIADB_USER=hr -e MARIADB_PASSWORD=hrpass \
    mariadb:11.8 --character-set-server=utf8mb4 --collation-server=utf8mb4_unicode_ci >/dev/null
fi
docker start "$DB_CT" >/dev/null
say "Chờ MariaDB sẵn sàng"
for _ in $(seq 1 60); do
  docker exec "$DB_CT" mariadb -uhr -phrpass hr -e 'select 1' >/dev/null 2>&1 && break
  sleep 1
done

# ---- Mailpit: hộp thư giả, xem thư ở http://127.0.0.1:8025 ----
if ! docker ps -a --format '{{.Names}}' | grep -qx "$MAIL_CT"; then
  say "Tạo Mailpit (SMTP 1025, giao diện 8025)"
  docker run -d --name "$MAIL_CT" -p 1025:1025 -p 8025:8025 axllent/mailpit:v1.27 >/dev/null
fi
docker start "$MAIL_CT" >/dev/null

# ---- .env local ----
if [[ ! -f .env ]]; then
  say "Tạo .env từ .env.example"
  cp .env.example .env
fi
set_env() { # set_env KEY VALUE — thay hoặc thêm dòng KEY=VALUE
  if grep -qE "^#? ?$1=" .env; then sed -i.bak -E "s|^#? ?$1=.*|$1=$2|" .env; else echo "$1=$2" >> .env; fi
}
set_env APP_ENV local
set_env APP_URL "http://127.0.0.1:$PORT"
set_env DB_CONNECTION mariadb
set_env DB_HOST 127.0.0.1
set_env DB_PORT "$DB_PORT"
set_env DB_DATABASE hr
set_env DB_USERNAME hr
set_env DB_PASSWORD hrpass
set_env MAIL_MAILER smtp
set_env MAIL_HOST 127.0.0.1
set_env MAIL_PORT 1025
set_env MAIL_FROM_ADDRESS '"hr@helidirect.com"'
rm -f .env.bak
grep -qE '^APP_KEY=.+' .env || php artisan key:generate -q

# ---- thư viện ----
[[ -d vendor ]] || { say "composer install"; composer install -q; }
[[ -d node_modules ]] || { say "npm install"; npm install --no-audit --no-fund >/dev/null; }

# ---- database ----
if [[ "${1:-}" == "--fresh" ]]; then
  say "Xoá và dựng lại database"
  php artisan migrate:fresh --force
else
  php artisan migrate --force
fi
php artisan hr:demo-users

php artisan optimize:clear -q
echo
echo "  Ứng dụng:   http://127.0.0.1:$PORT"
echo "  Hộp thư:    http://127.0.0.1:8025   (thư mời, đặt lại mật khẩu)"
echo "  Bản cũ:     http://127.0.0.1:$PORT/legacy/"
echo "  Mã 2FA admin demo: php artisan hr:otp"
echo

if [[ "${1:-}" == "--dev" ]]; then
  say "Chạy server + Vite dev (Ctrl+C để dừng)"
  npx concurrently -k -n web,vite -c blue,magenta "php artisan serve --host=127.0.0.1 --port=$PORT" "npm run dev"
else
  say "Build giao diện"
  rm -f public/hot
  npm run build >/dev/null
  say "Chạy server (Ctrl+C để dừng)"
  php artisan serve --host=127.0.0.1 --port="$PORT"
fi
