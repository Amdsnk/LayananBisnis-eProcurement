FROM node:24.19.0-bookworm-slim AS frontend
WORKDIR /build/frontend
COPY frontend/package*.json ./
RUN npm ci --ignore-scripts
COPY frontend/ ./
RUN npm run build

FROM php:8.3.35-fpm-bookworm AS phpbase
RUN apt-get update && apt-get install -y --no-install-recommends libpq-dev libzip-dev libxml2-dev libonig-dev libicu-dev nginx supervisor gettext-base postgresql-client unzip \
 && docker-php-ext-install pdo_pgsql bcmath mbstring zip soap intl pcntl opcache \
 && rm -rf /var/lib/apt/lists/*
COPY --from=composer:2.10.3 /usr/bin/composer /usr/bin/composer
WORKDIR /app

FROM phpbase AS dependencies
COPY backend/composer.json backend/composer.lock ./
RUN composer install --no-dev --no-scripts --no-interaction --prefer-dist --optimize-autoloader
COPY backend/ ./
RUN mkdir -p bootstrap/cache storage/framework/cache storage/framework/sessions storage/framework/views storage/logs \
 && composer dump-autoload --no-dev --optimize --no-interaction

FROM phpbase AS runtime
COPY --from=dependencies /app /app
COPY --from=frontend /build/backend/public/app /app/public/app
COPY railway/ /app/railway/
RUN rm -f /etc/nginx/sites-enabled/default /usr/local/etc/php-fpm.d/www.conf /usr/local/etc/php-fpm.d/zz-docker.conf \
 && cp /app/railway/php-fpm.conf /usr/local/etc/php-fpm.d/www.conf \
 && cp /app/railway/php.ini /usr/local/etc/php/conf.d/production.ini \
 && useradd --uid 10001 --create-home app \
 && mkdir -p /app/storage/framework/cache /app/storage/framework/sessions /app/storage/framework/views /app/storage/logs /app/bootstrap/cache /tmp/nginx \
 && chown -R app:app /app/storage /app/bootstrap/cache /tmp/nginx \
 && chmod +x /app/railway/*.sh
ENV APP_ENV=production APP_DEBUG=false LOG_CHANNEL=stderr PORT=8080 SERVICE_ROLE=web
USER app
EXPOSE 8080
ENTRYPOINT ["/app/railway/entrypoint.sh"]
