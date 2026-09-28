#!/bin/bash
# Start services which would normally run under systemd on a real VPS.
set -e

mkdir -p /run/php /run/sshd /var/run/mysqld
chown mysql:mysql /var/run/mysqld

service ssh start || true
service mysql start || true
service "php${PHP_VERSION}-fpm" start || true
service nginx start || true
service cron start || true

# Install composer dependencies of the mounted package on first start.
if [ -f composer.json ] && [ ! -d vendor ]; then
    composer install --no-interaction
fi

exec "$@"
