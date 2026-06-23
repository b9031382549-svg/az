FROM php:8.3-cli

# System libraries needed for the PHP extensions Laravel + Postgres use
RUN apt-get update && apt-get install -y --no-install-recommends \
        git \
        unzip \
        libpq-dev \
        libzip-dev \
        libicu-dev \
        libonig-dev \
        libpng-dev \
        libjpeg-dev \
        libfreetype6-dev \
        curl \
    && rm -rf /var/lib/apt/lists/*

# PHP extensions: Postgres (pdo_pgsql/pgsql), the usual Laravel set, plus gd
# (required by phpoffice/phpspreadsheet for .xlsx/.xls import).
RUN docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" \
        pdo_pgsql \
        pgsql \
        zip \
        intl \
        bcmath \
        mbstring \
        opcache \
        pcntl \
        gd

# Composer (copied from the official image)
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# This container has no working IPv6 route (WSL2). The resolver returns AAAA
# records first, so getaddrinfo hangs ~10s before falling back to IPv4.
# Make IPv4 the preferred family so curl/composer/apt connect immediately.
RUN printf 'precedence ::ffff:0:0/96  100\n' > /etc/gai.conf

# Run as the host user so files created in the bind mount stay owned by us
ARG UID=1000
ARG GID=1000
RUN groupadd -g "${GID}" app 2>/dev/null || true \
    && useradd -u "${UID}" -g "${GID}" -m -s /bin/bash app 2>/dev/null || true

# Raise PHP limits so the UI can accept real invoice/catalog file uploads.
COPY uploads.ini /usr/local/etc/php/conf.d/uploads.ini

ENV COMPOSER_HOME=/tmp/composer \
    COMPOSER_MEMORY_LIMIT=-1

WORKDIR /var/www/html

EXPOSE 8000

CMD ["php", "-a"]
