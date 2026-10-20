#!/usr/bin/env bash
set -Eeuo pipefail
TARGET="${TARGET:-/var/www/html/central-auth-gateway}"
REPOSITORY="${REPOSITORY:-https://github.com/florianthepro/central-auth-gateway}"
LOG="/tmp/central-auth-gateway-install.log"
STEP=1
ok(){ printf '[%02d] [✓] %s\n' "$STEP" "$1"; STEP=$((STEP+1)); }
fail(){ printf '[%02d] [✗] %s\n' "$STEP" "$1"; printf 'Log: %s\n' "$LOG"; exit 1; }
trap 'fail "Installation fehlgeschlagen"' ERR
cd /
: > "$LOG"
apt-get update >>"$LOG" 2>&1
apt-get install -y apache2 php libapache2-mod-php php-sqlite3 php-ldap php-gd phpqrcode curl ca-certificates >>"$LOG" 2>&1
ok "Abhängigkeiten installiert"
if [ -d "$TARGET" ] && [ -n "$(find "$TARGET" -mindepth 1 -maxdepth 1 -print -quit 2>/dev/null)" ]; then
  printf '%s enthält Daten. Vollständig zurücksetzen? [nein]: ' "$TARGET" >/dev/tty
  IFS= read -r ANSWER </dev/tty || ANSWER=nein
  ANSWER="${ANSWER:-nein}"
  [ "$ANSWER" = ja ] || { printf '[%02d] [✗] Abgebrochen\n' "$STEP"; exit 1; }
  rm -rf -- "$TARGET"
  ok "Alter Stand entfernt"
fi
mkdir -p "$TARGET"
curl -fsSL "$REPOSITORY/archive/refs/heads/main.tar.gz" 2>>"$LOG" | tar -xz --strip-components=1 -C "$TARGET" >>"$LOG" 2>&1
ok "Repository installiert"
mkdir -p "$TARGET/storage"
chown -R www-data:www-data "$TARGET"
chmod -R u+rwX,g+rwX,o-rwx "$TARGET"
ok "Rechte gesetzt"
php -l "$TARGET/index.php" >>"$LOG" 2>&1
a2enmod rewrite headers >>"$LOG" 2>&1
apache2ctl configtest >>"$LOG" 2>&1
systemctl enable --now apache2 >>"$LOG" 2>&1
systemctl restart apache2 >>"$LOG" 2>&1
ok "Anwendung gestartet"
