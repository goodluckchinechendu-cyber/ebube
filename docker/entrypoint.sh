#!/bin/sh
set -eu

PORT="${PORT:-8080}"

# Railway/php-apache images often enable multiple MPMs; keep only prefork.
a2dismod mpm_event 2>/dev/null || true
a2dismod mpm_worker 2>/dev/null || true
a2enmod mpm_prefork 2>/dev/null || true

# Apache must listen on Railway's assigned PORT.
sed -i "s/Listen 80/Listen ${PORT}/" /etc/apache2/ports.conf
sed -i "s/:80>/:${PORT}>/" /etc/apache2/sites-available/000-default.conf

# Prefer Railway MySQL plugin vars when DB_* are not set.
export DB_HOST="${DB_HOST:-${MYSQLHOST:-${MYSQL_HOST:-127.0.0.1}}}"
export DB_PORT="${DB_PORT:-${MYSQLPORT:-${MYSQL_PORT:-3306}}}"
export DB_USER="${DB_USER:-${MYSQLUSER:-${MYSQL_USER:-}}}"
export DB_PASS="${DB_PASS:-${MYSQLPASSWORD:-${MYSQL_PASSWORD:-}}}"
export DB_NAME="${DB_NAME:-${MYSQLDATABASE:-${MYSQL_DATABASE:-}}}"

exec apache2-foreground
