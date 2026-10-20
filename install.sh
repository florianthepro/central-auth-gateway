#!/usr/bin/env bash
set -e
TARGET="${1:-/var/www/html/central-auth-gateway}"
apt-get update
apt-get install -y apache2 php libapache2-mod-php php-sqlite3 php-ldap php-mbstring php-sodium composer
mkdir -p -- "$TARGET"
cp -R . "$TARGET/"
composer install --no-dev --optimize-autoloader --working-dir="$TARGET"
mkdir -p -- "$TARGET/storage"
chown -R www-data:www-data -- "$TARGET"
chmod -R u+rwX,g+rwX,o-rwx -- "$TARGET"
a2enmod rewrite headers
systemctl restart apache2
echo "[01] [✓] $TARGET installiert"
