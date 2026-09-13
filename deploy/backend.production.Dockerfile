FROM php:8.4-fpm-alpine

RUN apk add --no-cache freetype-dev icu-dev libjpeg-turbo-dev libpng-dev libzip-dev oniguruma-dev postgresql-dev zlib-dev $PHPIZE_DEPS \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install pdo pdo_pgsql bcmath gd intl opcache pcntl zip \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && apk del $PHPIZE_DEPS

# ---------------------------------------------------------------------------
# REPORT-EXPORT-FUNCTIONAL-001 — the report renderer's own runtime.
#
# A client PDF is not rendered by PHP. `ChromiumPdfRenderer` spawns Node on
# `backend/scripts/report-print.mjs`, which drives a real browser over the print
# route, and then a FAIL-CLOSED python pass rewrites and validates the Arabic
# text layer. `ReportDownloadController` additionally refuses any PDF whose
# `validation_status` is not `passed`, so that python step is not optional
# polish — without it there is no downloadable PDF at all.
#
# None of it was here. The image carried PHP and nothing else, so Production
# could not render a PDF while CI stayed green on runners that install all three
# — which is exactly how a button that cannot work survived to the owner.
#
# Alpine, deliberately not Playwright's own download: Playwright ships glibc
# builds and this base is musl, so the supported path is the DISTRO browser plus
# `executablePath` — which `report-print.mjs` already honours via
# `REPORTS_CHROMIUM_PATH`. Packages verified against the Alpine v3.21 community
# index rather than assumed: chromium (provides /usr/bin/chromium),
# py3-pikepdf 9.2.1, font-noto-arabic 24.7.1.
#
# `font-noto-arabic` is not decoration: without an Arabic face the renderer
# draws tofu, and this product's reports are Arabic first.
# ---------------------------------------------------------------------------
RUN apk add --no-cache \
        chromium nss freetype harfbuzz ca-certificates ttf-freefont font-noto-arabic \
        nodejs npm python3 py3-pikepdf

# `playwright-core` resolved from a location that EXISTS on a server.
#
# The default `require_base` points at `../frontend/package.json` — a developer's
# checkout. A production backend image has no frontend tree, so the require would
# fail even with Node present. Pinned to the version the gate proves the script
# against, so the renderer here is the renderer CI exercised.
RUN mkdir -p /opt/print \
    && cd /opt/print \
    && npm init -y >/dev/null \
    && npm install --omit=dev --no-audit --no-fund playwright-core@1.61.1

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

COPY backend/composer.json backend/composer.lock ./
RUN composer install --no-dev --no-scripts --no-interaction --prefer-dist --optimize-autoloader

COPY backend/ ./
RUN composer dump-autoload --optimize \
    && mkdir -p storage/framework/cache storage/framework/sessions storage/framework/views bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache

USER www-data

EXPOSE 8000
CMD ["php", "artisan", "serve", "--host=0.0.0.0", "--port=8000"]
