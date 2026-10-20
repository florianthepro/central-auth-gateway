#!/usr/bin/env bash
set -e
TARGET="${1:-/var/www/html/central-auth-gateway}"
REPO="${REPOSITORY:-https://github.com/florianthepro/central-auth-gateway}"
read -rp "Zielordner [$TARGET]: " INPUT
TARGET="${INPUT:-$TARGET}"
read -rp "Repository [$REPO]: " INPUT
REPO="${INPUT:-$REPO}"
if [ -e "$TARGET" ]; then
  read -rp "$TARGET existiert. Inhalt ersetzen? [nein]: " ANSWER
  ANSWER="${ANSWER:-nein}"
  [ "$ANSWER" = "ja" ] || exit 1
  rm -rf -- "$TARGET"
fi
mkdir -p -- "$TARGET"
curl -fsSL "$REPO/archive/refs/heads/main.tar.gz" | tar -xz --strip-components=1 -C "$TARGET"
mkdir -p -- "$TARGET/storage"
chown -R www-data:www-data -- "$TARGET"
chmod -R u+rwX,g+rwX,o-rwx -- "$TARGET"
echo "[01] [✓] $TARGET installiert"
