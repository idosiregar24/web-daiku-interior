# --- Stage 1: build frontend assets (React 18 + Inertia + Tailwind v4) ---
FROM node:20-alpine AS frontend

WORKDIR /app

COPY package.json package-lock.json ./
RUN npm ci

COPY . .

# Vite inlines VITE_* at build time, and .env is not in the build context
# (.dockerignore). docker-compose.prod.yml passes these as build args
# (WebSocket host = APP_DOMAIN over wss/443). Dev builds keep the defaults:
# docker-compose.yml bind-mounts the host checkout, public/build included.
ARG VITE_APP_NAME="Daiku Interior"
ARG VITE_BROADCAST_CONNECTION=log
ARG VITE_REVERB_APP_KEY=
ARG VITE_REVERB_HOST=
ARG VITE_REVERB_PORT=443
ARG VITE_REVERB_SCHEME=https
RUN npm run build

# --- Stage 2: nginx serving the built public/ (docker-compose.prod.yml) ---
# Production mounts no source into nginx, so it needs its own copy of the
# static files; the vhost template is mounted from docker/nginx/.
FROM nginx:stable-alpine AS web

COPY --from=frontend /app/public /var/www/html/public

# --- Stage 3: PHP-FPM runtime (default target — keep it the LAST stage) ---
FROM php:8.4-fpm AS app

RUN apt-get update && apt-get install -y \
    git curl unzip libpng-dev libonig-dev libxml2-dev libzip-dev libicu-dev \
    && docker-php-ext-install pdo_mysql mbstring exif pcntl bcmath gd zip intl \
    && pecl install redis && docker-php-ext-enable redis \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

# `php artisan db:backup` shells out to mysqldump. Debian only ships
# MariaDB's client (php:8.4-fpm = trixie → MariaDB 11.8); the command
# detects it and adds the flags a MySQL 8 server needs (see
# app/Console/Commands/DatabaseBackup.php). The symlink guards against
# Debian releases that only install `mariadb-dump`.
RUN apt-get update && apt-get install -y --no-install-recommends mariadb-client \
    && { command -v mysqldump >/dev/null || ln -s "$(command -v mariadb-dump)" /usr/local/bin/mysqldump; } \
    && mysqldump --version \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

# PHP's defaults (2M upload / 8M post) would reject the 5 MB login image
# before Laravel's validation runs. Keep in sync with client_max_body_size
# in docker/nginx/*.conf.
RUN { echo 'upload_max_filesize = 20M'; echo 'post_max_size = 20M'; } \
    > "$PHP_INI_DIR/conf.d/zz-daiku-uploads.ini"

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

COPY . .
COPY --from=frontend /app/public/build ./public/build

RUN composer install --no-dev --optimize-autoloader --no-interaction

RUN chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache

EXPOSE 9000

CMD ["php-fpm"]
