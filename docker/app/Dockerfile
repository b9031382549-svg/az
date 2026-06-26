# syntax=docker/dockerfile:1

# ============================================================================
# base — shared system libs + PHP extensions. SINGLE source of truth for the
# extension set so dev and prod never drift. Built on the -fpm image because
# prod serves via php-fpm; the php CLI binary is also present, so the dev
# stage's `php artisan serve` keeps working exactly as before.
# ============================================================================
FROM php:8.3-fpm-bookworm AS base

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

WORKDIR /var/www/html

# ============================================================================
# vendor — production PHP dependencies + optimized autoloader (no dev deps).
# Composer scripts are skipped at build (package:discover needs a bootable
# app/.env); discovery + caching run at deploy via `php artisan optimize`.
# ============================================================================
FROM base AS vendor

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
ENV COMPOSER_HOME=/tmp/composer \
    COMPOSER_MEMORY_LIMIT=-1

# Dependency layer first (cache-friendly): only re-runs when composer.* change.
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader \
        --prefer-dist --no-interaction --no-progress

# Full source, then build the optimized classmap autoloader.
COPY . .
RUN composer dump-autoload --optimize --no-dev --no-scripts

# ============================================================================
# assets — compiled front-end (Vite + Tailwind 4) -> public/build. No Node at
# runtime. Requires a committed package-lock.json for `npm ci`.
# ============================================================================
FROM node:22-alpine AS assets

WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci
COPY vite.config.js ./
COPY resources ./resources
COPY public ./public
RUN npm run build

# ============================================================================
# prod — php-fpm + nginx in one container (supervisord). Code, vendor and built
# assets are baked in; the web tier runs as www-data. No bind mounts.
# ============================================================================
FROM base AS prod

RUN apt-get update && apt-get install -y --no-install-recommends \
        nginx \
        supervisor \
    && rm -rf /var/lib/apt/lists/* \
    && rm -f /etc/nginx/sites-enabled/default

# Production PHP base config, then our overrides (opcache, limits, fpm pool),
# the nginx vhost and the supervisor program set.
RUN cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"
COPY docker/app/php-prod.ini      "$PHP_INI_DIR/conf.d/zz-prod.ini"
COPY docker/app/php-fpm-pool.conf /usr/local/etc/php-fpm.d/zz-pool.conf
COPY docker/app/nginx.conf        /etc/nginx/conf.d/default.conf
COPY docker/app/supervisord.conf  /etc/supervisor/conf.d/app.conf

WORKDIR /var/www/html

# App source + vendor (optimized autoloader), then the compiled assets.
COPY --from=vendor --chown=www-data:www-data /var/www/html ./
COPY --from=assets --chown=www-data:www-data /app/public/build ./public/build

# Only the writable trees must be owned by the runtime user.
RUN chown -R www-data:www-data storage bootstrap/cache \
    && chmod -R ug+rwX storage bootstrap/cache

EXPOSE 80
CMD ["supervisord", "-c", "/etc/supervisor/conf.d/app.conf"]

# ============================================================================
# dev — the ORIGINAL development image, unchanged in behavior. Kept as the LAST
# stage so the existing docker-compose.yml (which sets no build target) builds
# this stage by default. Local development stays exactly as it was.
# ============================================================================
FROM base AS dev

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# WSL2 has no working IPv6 route; the resolver returns AAAA first and hangs
# ~10s before IPv4 fallback. Prefer IPv4 so curl/composer/apt connect at once.
# (Dev-only: the production host has working IPv6/DNS.)
RUN printf 'precedence ::ffff:0:0/96  100\n' > /etc/gai.conf

# Run as the host user so files created in the bind mount stay owned by us.
ARG UID=1000
ARG GID=1000
RUN groupadd -g "${GID}" app 2>/dev/null || true \
    && useradd -u "${UID}" -g "${GID}" -m -s /bin/bash app 2>/dev/null || true

# Raise PHP limits so the UI can accept real invoice/catalog file uploads.
COPY uploads.ini /usr/local/etc/php/conf.d/uploads.ini

ENV COMPOSER_HOME=/tmp/composer \
    COMPOSER_MEMORY_LIMIT=-1

EXPOSE 8000

CMD ["php", "-a"]
