# Laravel API for Render (Docker Web Service). nginx + PHP-FPM in one
# container, managed by supervisord — a Render Web Service is one container,
# so both processes must live together here rather than as separate services.
#
# Deliberately excludes backend/package.json and backend/resources/ (the
# unused Laravel-skeleton Blade/Vite asset pipeline — the real frontend is
# the separate Vue SPA under frontend/, built and deployed independently as
# a Render Static Site). No `npm install`/`npm run build` runs in this image.

FROM php:8.3-fpm-alpine

RUN apk add --no-cache \
        nginx \
        supervisor \
        gettext \
        curl \
        libzip \
    && apk add --no-cache --virtual .build-deps \
        $PHPIZE_DEPS \
        oniguruma-dev \
        libzip-dev \
        curl-dev \
    && docker-php-ext-install -j"$(nproc)" pdo_mysql mbstring bcmath opcache zip curl \
    && apk del .build-deps

WORKDIR /var/www/html

# Composer itself, copied from its own official image rather than installed
# via a curl|sh script.
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Dependencies first (separate layer, cached across builds that only change
# application code).
COPY composer.json composer.lock ./
RUN composer install \
        --no-dev \
        --no-scripts \
        --no-interaction \
        --no-progress \
        --optimize-autoloader \
        --prefer-dist

COPY . .

# post-autoload-dump normally runs `artisan package:discover`, skipped above
# via --no-scripts because it needs a full .env/app key that doesn't exist
# yet at build time — safe to run now that the rest of the app code is
# present, and does not depend on runtime secrets (DB, AI keys).
RUN composer dump-autoload --optimize \
    && php artisan package:discover --ansi

RUN cp docker/php.ini /usr/local/etc/php/conf.d/zz-app.ini \
    && cp docker/php-fpm-pool.conf /usr/local/etc/php-fpm.d/www.conf \
    && cp docker/nginx.conf.template /etc/nginx/nginx.conf.template \
    && cp docker/supervisord.conf /etc/supervisord.conf \
    && cp docker/entrypoint.sh /entrypoint.sh \
    && chmod +x /entrypoint.sh \
    && mkdir -p /run/nginx \
    && chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache

# config:cache/route:cache are deliberately NOT run here — several config
# values (DB host, AI key/model, APP_URL) are only known at container start
# via Render-injected env vars, not at build time. Baking them in now would
# cache build-time-absent values and require a rebuild (not just a redeploy)
# to pick up an env var change. Skipped entirely rather than moved to the
# entrypoint: at this app's beta scale (5-20 users), the read cost of
# uncached config is not a measurable problem, and this avoids a whole class
# of "stale cached config" bugs for a benefit nothing here needs yet.

# Matches config/app.php's 'timezone' => 'UTC' (the app-level clock; actual
# user-facing "today" goes through User::localNow()/localToday() using each
# user's stored timezone, not this container setting).
ENV TZ=UTC
ENV PORT=10000
EXPOSE 10000

HEALTHCHECK --interval=30s --timeout=5s --start-period=30s --retries=3 \
    CMD wget --quiet --spider "http://127.0.0.1:${PORT}/up" || exit 1

ENTRYPOINT ["/entrypoint.sh"]
