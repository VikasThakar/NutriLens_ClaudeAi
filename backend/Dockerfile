# syntax=docker/dockerfile:1
#
# NutriLens API — production image.
#
# This is a Dockerfile rather than a builder-detected app because two PHP
# extensions are load-bearing and easy to lose to a default image: MealImagePreparer
# downscales every uploaded photo with GD and reads its orientation with EXIF.
# Pinning them here means the build is the same on Railway, on CI and locally.

FROM php:8.2-fpm-alpine

# nginx serves public/ and hands .php to php-fpm; supervisor keeps both alive in
# the single container Railway runs; gettext provides envsubst, which is how
# Railway's injected $PORT reaches the nginx config.
RUN apk add --no-cache nginx supervisor gettext

# Resolves the C libraries each extension needs (libpng, libjpeg and freetype
# for GD) instead of us tracking that list by hand.
COPY --from=mlocati/php-extension-installer:latest \
     /usr/bin/install-php-extensions /usr/local/bin/
RUN install-php-extensions gd exif pdo_mysql zip bcmath opcache pcntl

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

# Manifests before source. Installing dependencies is the slow layer and should
# be rebuilt when composer.lock changes, not on every code edit.
COPY composer.json composer.lock ./
RUN composer install \
      --no-dev \
      --no-scripts \
      --no-autoloader \
      --no-interaction \
      --prefer-dist

COPY . .

# package:discover needs the full application tree, which is why the install
# above ran with --no-scripts.
RUN composer dump-autoload --no-dev --optimize --classmap-authoritative \
 && php artisan package:discover --ansi

COPY docker/php.ini          /usr/local/etc/php/conf.d/zz-nutrilens.ini
COPY docker/nginx.conf       /etc/nginx/nginx.conf.template
COPY docker/supervisord.conf /etc/supervisord.conf
COPY docker/entrypoint.sh    /usr/local/bin/entrypoint
RUN chmod +x /usr/local/bin/entrypoint

RUN chown -R www-data:www-data /app

# php:8.2-fpm-alpine carries an inherited `EXPOSE 9000` for php-fpm's FastCGI
# socket. A platform that picks its target port from image metadata will route
# HTTP straight at it, and FastCGI does not speak HTTP — the result is a 502
# in front of a perfectly healthy container. Declaring PORT makes nginx's port
# the explicit default; the entrypoint still honours an injected $PORT.
ENV PORT=8080
EXPOSE 8080

ENTRYPOINT ["/usr/local/bin/entrypoint"]
