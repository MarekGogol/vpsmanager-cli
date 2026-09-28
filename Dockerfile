# Local test environment for VPS Manager CLI.
# Mimics a fresh Ubuntu VPS with nginx, PHP-FPM, MySQL and certbot.
FROM ubuntu:24.04

ARG PHP_VERSION=8.5
ENV DEBIAN_FRONTEND=noninteractive \
    TZ=Europe/Bratislava \
    PHP_VERSION=${PHP_VERSION}

RUN ln -snf /usr/share/zoneinfo/$TZ /etc/localtime && echo $TZ > /etc/timezone \
    && apt-get update \
    && apt-get install -y --no-install-recommends \
        ca-certificates curl gnupg locales software-properties-common \
        zip unzip rsync ssh ssl-cert nano less git cron sudo \
        gcc make libpng-dev imagemagick \
        jpegoptim optipng pngquant gifsicle webp \
        nginx mysql-server certbot python3-certbot-nginx \
    && add-apt-repository -y ppa:ondrej/php \
    && apt-get update \
    && apt-get install -y --no-install-recommends \
        php${PHP_VERSION}-cli php${PHP_VERSION}-fpm php${PHP_VERSION}-soap php${PHP_VERSION}-mysql \
        php${PHP_VERSION}-zip php${PHP_VERSION}-gd php${PHP_VERSION}-mbstring php${PHP_VERSION}-curl \
        php${PHP_VERSION}-xml php${PHP_VERSION}-bcmath php${PHP_VERSION}-redis php${PHP_VERSION}-imagick \
        php${PHP_VERSION}-intl php${PHP_VERSION}-sqlite3 \
    && curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer \
    && rm -rf /var/lib/apt/lists/*

COPY docker/entrypoint.sh /usr/local/bin/vps-entrypoint
RUN chmod +x /usr/local/bin/vps-entrypoint

WORKDIR /root/vpsmanager

EXPOSE 80 443 22 3306

ENTRYPOINT ["vps-entrypoint"]
CMD ["sleep", "infinity"]
