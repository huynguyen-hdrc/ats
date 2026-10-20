#!/usr/bin/env bash
# Deploy Helidirect HR lên Hostinger (hr.helidirect.io).
#
#   scripts/deploy.sh            build + đẩy code + migrate
#   scripts/deploy.sh --check    chỉ in những gì sẽ đổi (rsync --dry-run)
#
# Cần: file .deploy.env (không commit) ở gốc repo:
#   HR_DEPLOY_HOST=user@ip-server
#   HR_DEPLOY_PORT=cong-ssh
#   HR_DEPLOY_KEY=~/.ssh/khoa-deploy      (tuỳ chọn)
# .env trên server (APP_KEY, DB_*, MAIL_*) KHÔNG nằm trong repo và không bị ghi đè.
set -euo pipefail
cd "$(dirname "$0")/.."

[[ -f .deploy.env ]] && source .deploy.env
HOST=${HR_DEPLOY_HOST:?Thiếu HR_DEPLOY_HOST — tạo file .deploy.env (xem đầu script)}
PORT=${HR_DEPLOY_PORT:?Thiếu HR_DEPLOY_PORT}
KEY=${HR_DEPLOY_KEY:-$HOME/.ssh/hr_helidirect_deploy}
KEY=${KEY/#\~/$HOME}
SITE=domains/hr.helidirect.io
APP=$SITE/app
PHP=/usr/bin/php            # PHP 8.3 của tài khoản (CloudLinux selector)
COMPOSER=/usr/local/bin/composer

SSH=(ssh -i "$KEY" -p "$PORT" -o BatchMode=yes "$HOST")

DRY=()
[[ "${1:-}" == "--check" ]] && DRY=(--dry-run --itemize-changes)

if [[ ${#DRY[@]} -eq 0 ]]; then
  echo "▸ Kiểm thử"
  php artisan test
  echo "▸ Build giao diện"
  npm run build >/dev/null
fi

echo "▸ Đồng bộ code lên $APP"
rsync -az --delete "${DRY[@]}" -e "ssh -i $KEY -p $PORT -o BatchMode=yes" \
  --exclude .git --exclude node_modules --exclude vendor --exclude .env --exclude '.env.*' \
  --exclude 'storage/app/*' --exclude 'storage/framework/*' --exclude 'storage/logs/*' \
  --exclude 'database/*.sqlite' --exclude tests --exclude .phpunit.cache --exclude 'public/hot' \
  ./ "$HOST:$APP/"

[[ ${#DRY[@]} -gt 0 ]] && exit 0

echo "▸ Cài thư viện, migrate, cache"
"${SSH[@]}" bash -s <<EOF
set -euo pipefail
cd ~/$APP
mkdir -p storage/app/private storage/framework/{cache/data,sessions,views} storage/logs bootstrap/cache
chmod -R u+rwX storage bootstrap/cache
test -f .env || { echo "Thiếu ~/$APP/.env — tạo từ .env.example trước"; exit 1; }
$PHP artisan down --retry=15 >/dev/null 2>&1 || true
$PHP $COMPOSER install --no-dev --optimize-autoloader --no-interaction --no-progress -q
$PHP artisan migrate --force
$PHP artisan db:seed --class=RoleSeeder --force
$PHP artisan optimize
$PHP artisan up
# public_html -> app/public (lần đầu: giữ bản cũ để phòng hờ)
cd ~/$SITE
if [ ! -L public_html ]; then
  mv public_html public_html.before-laravel.\$(date +%Y%m%d%H%M%S)
  ln -s app/public public_html
fi
echo "OK: \$(readlink public_html)"
EOF

echo "▸ Kiểm tra"
curl -fsS -o /dev/null -w "  / → %{http_code}\n" https://hr.helidirect.io/login
curl -fsS -o /dev/null -w "  /up → %{http_code}\n" https://hr.helidirect.io/up
