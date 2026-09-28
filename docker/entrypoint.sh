#!/bin/bash
# Start services which would normally run under systemd on a real VPS.
set -e

mkdir -p /run/php /run/sshd /var/run/mysqld
chown mysql:mysql /var/run/mysqld

service ssh start || true

# Use MySQL from the host machine instead of the local server when requested
if [ -n "$MYSQL_FORWARD_HOST" ]; then
    socat TCP-LISTEN:3306,bind=127.0.0.1,fork,reuseaddr "TCP:${MYSQL_FORWARD_HOST}:3306" &
else
    service mysql start || true
fi
service "php${PHP_VERSION}-fpm" start || true
service nginx start || true
service cron start || true
service supervisor start || true

# Install composer dependencies of the mounted package on first start.
if [ -f composer.json ] && [ ! -d vendor ]; then
    composer install --no-interaction
fi

exec "$@"
