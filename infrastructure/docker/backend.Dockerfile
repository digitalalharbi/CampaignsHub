# Backend image — Laravel 12 API (PHP 8.3-FPM).
# NOTE: authored per spec; not built locally (Docker is not installed on the dev machine).
FROM php:8.3-fpm-alpine

RUN apk add --no-cache postgresql-dev icu-dev oniguruma-dev $PHPIZE_DEPS \
    && docker-php-ext-install pdo pdo_pgsql bcmath intl opcache \
    && pecl install redis && docker-php-ext-enable redis \
    && apk del $PHPIZE_DEPS

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-interaction --prefer-dist --optimize-autoloader

COPY . .
RUN composer dump-autoload --optimize \
    && chown -R www-data:www-data storage bootstrap/cache

# Run as non-root.
USER www-data

EXPOSE 8000
CMD ["php", "artisan", "serve", "--host=0.0.0.0", "--port=8000"]
