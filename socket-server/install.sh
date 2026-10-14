#!/usr/bin/env bash
# نصب خودکار سرور سوکت اعلان‌های بیست‌برگ
# Bistbarg socket server installer — run as root:
#   curl -fsSL https://github.com/akoSalman/bistbarg-App/releases/latest/download/install-socket.sh | sudo bash
#
# Optional: BISTBARG_SECRET=... PORT=8090 (environment variables)
set -euo pipefail

RELEASE_URL="https://github.com/akoSalman/bistbarg-App/releases/latest/download"
APP_DIR="/opt/bistbarg-socket"
ENV_FILE="/etc/bistbarg-socket.env"
SERVICE="bistbarg-socket"
RUN_USER="bistbarg"
PORT="${PORT:-8090}"
NODE_VERSION="22.12.0"
NODE_LEGACY="18.20.4" # for very old systems (glibc < 2.28, e.g. CentOS 7)

ok()   { printf '\033[1;32m✔ %s\033[0m\n' "$*"; }
info() { printf '\033[1;36m➜ %s\033[0m\n' "$*"; }
warn() { printf '\033[1;33m! %s\033[0m\n' "$*"; }
fail() { printf '\033[1;31m✘ %s\033[0m\n' "$*" >&2; exit 1; }

[ "$(id -u)" -eq 0 ] || fail "این اسکریپت باید با root اجرا شود (اول sudo -i بزنید)."
command -v systemctl >/dev/null || fail "systemd روی این سرور پیدا نشد."

# ---------------------------------------------------------------- 1. tools
install_pkgs() {
  if command -v apt-get >/dev/null; then
    DEBIAN_FRONTEND=noninteractive apt-get update -qq >/dev/null
    DEBIAN_FRONTEND=noninteractive apt-get install -y -qq "$@" >/dev/null
  elif command -v dnf >/dev/null; then
    dnf install -y -q "$@" >/dev/null
  elif command -v yum >/dev/null; then
    yum install -y -q "$@" >/dev/null
  else
    fail "نصب ابزار ($*) ممکن نشد؛ لطفاً دستی نصب کنید."
  fi
}

missing=()
command -v curl  >/dev/null || missing+=(curl)
command -v unzip >/dev/null || missing+=(unzip)
command -v tar   >/dev/null || missing+=(tar)
command -v xz    >/dev/null || { command -v apt-get >/dev/null && missing+=(xz-utils) || missing+=(xz); }
if [ ${#missing[@]} -gt 0 ]; then
  info "نصب ابزارهای لازم: ${missing[*]}"
  install_pkgs "${missing[@]}"
fi
ok "ابزارهای لازم آماده است"

# ---------------------------------------------------------------- 2. Node.js
node_ok() {
  command -v node >/dev/null && node -e 'process.exit(+process.versions.node.split(".")[0] >= 18 ? 0 : 1)' 2>/dev/null
}

download_node() { # $1 = version, $2 = file suffix, $3... = base URLs
  local version="$1" suffix="$2"; shift 2
  local file="node-v${version}-linux-${suffix}.tar.xz"
  for base in "$@"; do
    info "دانلود Node.js $version از $base"
    if curl -fsSL --connect-timeout 20 --retry 2 -o /tmp/node.tar.xz "$base/v${version}/${file}"; then
      tar -xJf /tmp/node.tar.xz -C /usr/local --strip-components=1 \
        --exclude='*/CHANGELOG.md' --exclude='*/README.md' --exclude='*/LICENSE'
      rm -f /tmp/node.tar.xz
      hash -r
      return 0
    fi
  done
  return 1
}

if node_ok; then
  ok "Node.js $(node -v) از قبل نصب است"
else
  case "$(uname -m)" in
    x86_64) ARCH=x64 ;;
    aarch64|arm64) ARCH=arm64 ;;
    *) fail "معماری $(uname -m) پشتیبانی نمی‌شود." ;;
  esac
  download_node "$NODE_VERSION" "$ARCH" \
    https://nodejs.org/dist \
    https://registry.npmmirror.com/-/binary/node \
    https://mirrors.cloud.tencent.com/nodejs-release \
    || fail "دانلود Node.js ممکن نشد. اینترنت سرور یا دسترسی به nodejs.org را بررسی کنید."
  if ! node_ok; then
    warn "نسخهٔ جدید Node.js روی این سیستم‌عامل اجرا نشد؛ نسخهٔ سازگار با سیستم‌های قدیمی نصب می‌شود."
    [ "$ARCH" = x64 ] || fail "Node.js روی این سرور اجرا نمی‌شود."
    download_node "$NODE_LEGACY" "x64-glibc-217" https://unofficial-builds.nodejs.org/download/release \
      || fail "دانلود Node.js سازگار ممکن نشد."
    node_ok || fail "Node.js روی این سرور اجرا نمی‌شود."
  fi
  ok "Node.js $(node -v) نصب شد"
fi
NODE_BIN="$(command -v node)"

# ---------------------------------------------------------------- 3. app files
TMP_ZIP=/tmp/bistbarg-socket.zip
if [ -f ./bistbarg-socket.zip ]; then
  info "استفاده از فایل bistbarg-socket.zip موجود در همین پوشه"
  cp ./bistbarg-socket.zip "$TMP_ZIP"
else
  info "دانلود سرور سوکت از گیت‌هاب"
  curl -fsSL --connect-timeout 20 --retry 2 -o "$TMP_ZIP" "$RELEASE_URL/bistbarg-socket.zip" \
    || fail "دانلود از گیت‌هاب ممکن نشد. فایل bistbarg-socket.zip را دستی در همین پوشه ($(pwd)) بگذارید و دوباره اجرا کنید."
fi
mkdir -p "$APP_DIR"
unzip -oq "$TMP_ZIP" -d "$APP_DIR"
rm -f "$TMP_ZIP"
if [ ! -f "$APP_DIR/node_modules/ws/package.json" ]; then
  info "نصب کتابخانهٔ ws"
  (cd "$APP_DIR" && npm ci --omit=dev --no-audit --no-fund >/dev/null) || fail "نصب کتابخانهٔ ws ممکن نشد."
fi
ok "فایل‌های سرور در $APP_DIR قرار گرفت"

# ---------------------------------------------------------------- 4. secret
SECRET="${BISTBARG_SECRET:-}"
if [ -z "$SECRET" ] && [ -f "$ENV_FILE" ]; then
  SECRET="$(sed -n 's/^BISTBARG_SECRET=//p' "$ENV_FILE" | head -n1)"
  [ -n "$SECRET" ] && ok "رمز سوکت قبلی حفظ شد"
fi
while [ ${#SECRET} -lt 16 ]; do
  echo
  echo "رمز سوکت را از وردپرس کپی کنید:"
  echo "  پیشخوان ← اعلان‌های اپ ← اعلان‌های خودکار سفارش ← بخش «سرور سوکت» ← رمز سوکت"
  read -r -p "رمز سوکت را اینجا بچسبانید و Enter بزنید: " SECRET < /dev/tty
  SECRET="$(printf '%s' "$SECRET" | tr -d '[:space:]')"
  [ ${#SECRET} -ge 16 ] || warn "رمز کوتاه است؛ دوباره امتحان کنید."
done
umask 077
printf 'BISTBARG_SECRET=%s\nPORT=%s\nHOST=127.0.0.1\n' "$SECRET" "$PORT" > "$ENV_FILE"
chmod 600 "$ENV_FILE"
umask 022
ok "تنظیمات در $ENV_FILE ذخیره شد"

# ---------------------------------------------------------------- 5. service
if ! id "$RUN_USER" >/dev/null 2>&1; then
  useradd --system --no-create-home --shell "$(command -v nologin || echo /bin/false)" "$RUN_USER"
fi
chown -R root:root "$APP_DIR"
chmod -R a+rX "$APP_DIR"

if command -v ss >/dev/null && ss -ltn 2>/dev/null | grep ":${PORT} " >/dev/null && ! systemctl is-active --quiet "$SERVICE"; then
  fail "پورت $PORT قبلاً استفاده شده است. با پورت دیگری اجرا کنید، مثلاً:  PORT=8091 bash install.sh  (و همان پورت را در وردپرس و nginx هم بگذارید)"
fi

cat > "/etc/systemd/system/${SERVICE}.service" <<UNIT
[Unit]
Description=Bistbarg app notification socket server
After=network.target

[Service]
Type=simple
WorkingDirectory=${APP_DIR}
ExecStart=${NODE_BIN} ${APP_DIR}/server.js
EnvironmentFile=${ENV_FILE}
Restart=always
RestartSec=3
User=${RUN_USER}
NoNewPrivileges=true
LimitNOFILE=65535

[Install]
WantedBy=multi-user.target
UNIT

systemctl daemon-reload
systemctl enable "$SERVICE" >/dev/null 2>&1
systemctl restart "$SERVICE"
sleep 2

# ---------------------------------------------------------------- 6. check
if curl -fsS -H "X-Bistbarg-Secret: ${SECRET}" "http://127.0.0.1:${PORT}/health" >/dev/null 2>&1; then
  ok "سرور سوکت روشن است و روی 127.0.0.1:${PORT} کار می‌کند"
else
  journalctl -u "$SERVICE" -n 20 --no-pager || true
  fail "سرور سوکت روشن نشد. گزارش بالا را برای پشتیبانی بفرستید."
fi

echo
echo "================================================================"
ok "نصب تمام شد. سرویس با هر بار روشن شدن سرور خودکار اجرا می‌شود."
echo
echo "قدم بعدی: وب‌سرور را تنظیم کنید تا آدرس wss://<دامنه>/bistbarg-socket/ به این سرور برسد."
if command -v nginx >/dev/null; then
  echo "این سرور nginx دارد. این را داخل بلوک server دامنه (همان که listen 443 دارد) بگذارید:"
  cat <<NGINX

    location /bistbarg-socket/ {
        proxy_pass http://127.0.0.1:${PORT}/;
        proxy_http_version 1.1;
        proxy_set_header Upgrade \$http_upgrade;
        proxy_set_header Connection "upgrade";
        proxy_set_header Host \$host;
        proxy_read_timeout 3600s;
        proxy_send_timeout 3600s;
    }

سپس:  nginx -t && systemctl reload nginx
NGINX
elif command -v apache2 >/dev/null || command -v httpd >/dev/null; then
  echo "این سرور Apache دارد. فایل apache.conf.example در $APP_DIR را ببینید."
fi
[ -d /usr/local/directadmin ] && echo "DirectAdmin: تنظیم nginx را در Custom HTTPD Configurations دامنه وارد کنید."
[ -d /usr/local/cpanel ] && echo "cPanel: تنظیم را در include مخصوص دامنه (userdata) وارد کنید."
[ -d /www/server/panel ] && echo "aaPanel: سایت ← Config ← تنظیم nginx را داخل بلوک server اضافه کنید."
echo
echo "دستورهای مفید:"
echo "  وضعیت:        systemctl status $SERVICE"
echo "  گزارش زنده:   journalctl -u $SERVICE -f"
echo "  راه‌اندازی دوباره: systemctl restart $SERVICE"
echo "================================================================"
