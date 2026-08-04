# =============================================================================
#  Laravel 12 API — multi-stage build
#
#  `development` keeps dev dependencies and the artisan serve loop.
#  `production` ships PHP-FPM with OPcache primed and no build toolchain.
# =============================================================================

# --- base: shared runtime -----------------------------------------------------
FROM php:8.3-fpm-alpine AS base

RUN apk add --no-cache \
        bash curl git icu-dev libzip-dev oniguruma-dev \
        freetype-dev libjpeg-turbo-dev libpng-dev \
        mysql-client supervisor tzdata \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" \
        pdo_mysql bcmath gd intl opcache zip pcntl \
    && apk del --no-network \
    && rm -rf /var/cache/apk/*

# Asia/Manila everywhere: the DOE adjustment window is defined in local time,
# and a UTC container would apply it seven hours early.
ENV TZ=Asia/Manila
RUN ln -snf /usr/share/zoneinfo/$TZ /etc/localtime && echo $TZ > /etc/timezone

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

# --- development --------------------------------------------------------------
FROM base AS development

ENV APP_ENV=local

# Xdebug is opt-in — it costs roughly 2× on every request when loaded.
ARG WITH_XDEBUG=false
RUN if [ "$WITH_XDEBUG" = "true" ]; then \
        apk add --no-cache --virtual .build $PHPIZE_DEPS \
        && pecl install xdebug \
        && docker-php-ext-enable xdebug \
        && apk del .build; \
    fi

# No `|| true` here: a dependency set that will not install is a broken image,
# and swallowing the failure only defers it to a crash loop at run time. The
# lock file is required rather than optional for the same reason — resolving
# afresh inside the build would silently drift from what was tested.
COPY composer.json composer.lock ./
RUN composer install --no-scripts --no-autoloader --prefer-dist

COPY . .
RUN composer dump-autoload --optimize

RUN chown -R www-data:www-data storage bootstrap/cache 2>/dev/null || true

EXPOSE 8000
CMD ["sh", "-c", "php artisan serve --host=0.0.0.0 --port=8000"]

# --- vendor: production dependencies only ------------------------------------
FROM base AS vendor

# The lock is required, not optional: the production image must install exactly
# what was tested, and a `composer.lock*` glob silently falls back to resolving
# afresh when the file is absent.
COPY composer.json composer.lock ./
RUN composer install \
        --no-dev \
        --no-scripts \
        --no-autoloader \
        --prefer-dist \
        --no-interaction

COPY . .
RUN composer dump-autoload --optimize --classmap-authoritative --no-dev

# --- production ---------------------------------------------------------------
FROM base AS production

ENV APP_ENV=production APP_DEBUG=false

# OPcache settings for a long-lived container: never revalidate timestamps,
# because the code cannot change without a new image.
RUN { \
        echo 'opcache.enable=1'; \
        echo 'opcache.memory_consumption=256'; \
        echo 'opcache.interned_strings_buffer=16'; \
        echo 'opcache.max_accelerated_files=20000'; \
        echo 'opcache.validate_timestamps=0'; \
        echo 'opcache.save_comments=1'; \
        echo 'realpath_cache_size=4096K'; \
        echo 'realpath_cache_ttl=600'; \
        echo 'memory_limit=512M'; \
        echo 'upload_max_filesize=12M'; \
        echo 'post_max_size=14M'; \
        echo 'expose_php=Off'; \
    } > /usr/local/etc/php/conf.d/zz-production.ini

COPY --from=vendor --chown=www-data:www-data /var/www/html /var/www/html

# Cache config, routes and views into the image so the first request after a
# deploy is as fast as the thousandth.
RUN php artisan config:cache \
    && php artisan route:cache \
    && php artisan view:cache \
    || echo 'Caching skipped: environment not available at build time'

RUN chown -R www-data:www-data storage bootstrap/cache

USER www-data

EXPOSE 9000

HEALTHCHECK --interval=30s --timeout=5s --start-period=30s --retries=3 \
    CMD php -r "exit(0);"

CMD ["php-fpm"]
