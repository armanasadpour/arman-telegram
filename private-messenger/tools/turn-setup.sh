#!/bin/bash
# One-command TURN server for the messenger's calls (coturn) on a fresh Ubuntu/Debian VPS.
#   sudo bash turn-setup.sh                 # detects the public IP
#   sudo bash turn-setup.sh 185.1.2.3       # or give the server's public IP yourself
# At the end it prints exactly what to enter in: Admin panel → Settings → Calls.
# Call content stays end-to-end encrypted (DTLS-SRTP); this server only relays encrypted packets.
set -euo pipefail

CONF="${CONF:-/etc/turnserver.conf}"   # overridable for testing
PORT="${PORT:-3478}"
[ "$(id -u)" = 0 ] || { echo "لطفاً با sudo اجرا کنید:  sudo bash $0"; exit 1; }

# ---- public IP
LOCAL_IP=$(ip -4 route get 8.8.8.8 2>/dev/null | awk '{for (i = 1; i < NF; i++) if ($i == "src") print $(i + 1)}' | head -1)
PUBLIC_IP="${1:-}"
if [ -z "$PUBLIC_IP" ]; then
  for u in https://api.ipify.org https://ifconfig.me https://icanhazip.com; do
    PUBLIC_IP=$(curl -4 -fs --max-time 5 "$u" 2>/dev/null | tr -d '[:space:]') && [ -n "$PUBLIC_IP" ] && break
  done
fi
[ -z "$PUBLIC_IP" ] && PUBLIC_IP="$LOCAL_IP"
if ! [[ "$PUBLIC_IP" =~ ^[0-9]+\.[0-9]+\.[0-9]+\.[0-9]+$ ]]; then
  echo "IP عمومی سرور پیدا نشد. آن را از پنل سرور بردارید و این‌طور اجرا کنید:  sudo bash $0 <IP>"; exit 1
fi
echo "IP عمومی: $PUBLIC_IP   (IP روی کارت شبکه: ${LOCAL_IP:-?})"

# ---- install
if ! command -v turnserver >/dev/null; then
  echo "نصب coturn…"
  export DEBIAN_FRONTEND=noninteractive
  # Many VPS images still list the installation CD as a package source («file:/cdrom … no longer has a Release
  # file»), which makes apt-get update fail. Switch those lines off (a backup is kept).
  for f in /etc/apt/sources.list /etc/apt/sources.list.d/*.list; do
    [ -f "$f" ] && grep -qE '^[[:space:]]*deb .*(cdrom:|file:/cdrom)' "$f" && { cp "$f" "$f.bak.cdrom"; sed -i -E 's@^([[:space:]]*deb .*(cdrom:|file:/cdrom).*)@# \1@' "$f"; echo "منبع CD-ROM در $f غیرفعال شد."; }
  done
  apt-get update -q || { echo "به‌روزرسانی فهرست بسته‌ها ناموفق بود (خطا بالا). اگر سرور به مخزن Ubuntu دسترسی ندارد، از پشتیبانی شرکت میزبان «آینه (mirror) داخلی Ubuntu» بخواهید."; exit 1; }
  apt-get install -y -q coturn || { echo "نصب coturn ناموفق بود (خطا بالا)."; exit 1; }
fi

# ---- config (a new secret every run; enter it again in the panel if you re-run)
SECRET=$(openssl rand -hex 32)
EXTERNAL=""
if [ -n "$LOCAL_IP" ] && [ "$LOCAL_IP" != "$PUBLIC_IP" ]; then EXTERNAL="external-ip=$PUBLIC_IP/$LOCAL_IP"; fi
[ -f "$CONF" ] && cp "$CONF" "$CONF.bak.$(date +%s)"
cat > "$CONF" <<EOF
# Messenger TURN server — written by turn-setup.sh
listening-port=$PORT
min-port=49152
max-port=65535
fingerprint
use-auth-secret
static-auth-secret=$SECRET
realm=messenger-turn
$EXTERNAL
total-quota=300
user-quota=12
stale-nonce=600
no-cli
no-tls
no-dtls
no-multicast-peers
no-tcp-relay
# never relay into private / internal networks
denied-peer-ip=0.0.0.0-0.255.255.255
denied-peer-ip=10.0.0.0-10.255.255.255
denied-peer-ip=100.64.0.0-100.127.255.255
denied-peer-ip=127.0.0.0-127.255.255.255
denied-peer-ip=169.254.0.0-169.254.255.255
denied-peer-ip=172.16.0.0-172.31.255.255
denied-peer-ip=192.168.0.0-192.168.255.255
denied-peer-ip=::1
log-file=/var/log/turnserver.log
simple-log
EOF
chmod 640 "$CONF"; chgrp turnserver "$CONF" 2>/dev/null || true

# ---- service + firewall
if [ "${SKIP_SERVICE:-0}" != 1 ]; then
  [ -f /etc/default/coturn ] && sed -i 's/^#\?TURNSERVER_ENABLED=.*/TURNSERVER_ENABLED=1/' /etc/default/coturn
  systemctl enable coturn >/dev/null 2>&1 || true
  systemctl restart coturn
  sleep 2
  systemctl is-active --quiet coturn || { echo "coturn اجرا نشد. خطا:"; journalctl -u coturn -n 20 --no-pager; exit 1; }
  if command -v ufw >/dev/null && ufw status | grep -q "Status: active"; then
    ufw allow "$PORT"/udp >/dev/null; ufw allow "$PORT"/tcp >/dev/null; ufw allow 49152:65535/udp >/dev/null
    echo "فایروال (ufw): پورت‌ها باز شد."
  fi
fi

cat <<EOF

✅ سرور TURN آماده است.
اگر شرکت میزبان فایروال جداگانه در پنل خودش دارد، این‌ها را آنجا باز کنید:
   ورودی UDP و TCP روی $PORT  ،  ورودی UDP روی 49152 تا 65535

در پنل مدیریت پیام‌رسان ← تنظیمات ← «تماس صوتی و تصویری» وارد کنید:
───────────────────────────────────────────────
سرورهای STUN:
stun:$PUBLIC_IP:$PORT

سرور TURN (هر کدام در یک خط):
turn:$PUBLIC_IP:$PORT?transport=udp
turn:$PUBLIC_IP:$PORT?transport=tcp

نوع احراز هویت TURN:  راز مشترک (coturn use-auth-secret)
راز مشترک TURN:
$SECRET
───────────────────────────────────────────────
بعد «ذخیره تنظیمات» و «آزمایش STUN / TURN» را بزنید (یک بار هم روی گوشی با اینترنت همراه).
EOF
