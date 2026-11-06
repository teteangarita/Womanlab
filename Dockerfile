FROM composer:2 AS composer

FROM php:8.2-apache

RUN apt-get update \
    && apt-get install -y --no-install-recommends libcurl4-openssl-dev libonig-dev libxml2-dev unzip \
    && docker-php-ext-install pdo_mysql curl mbstring fileinfo xml \
    && a2enmod rewrite headers \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer /usr/bin/composer /usr/local/bin/composer
COPY cpanel/composer.json cpanel/composer.lock /var/www/
COPY cpanel/womanlab-core /var/www/womanlab-core
COPY cpanel/public_html /var/www/html
COPY docker/render.conf /etc/apache2/conf-available/womanlab-render.conf
COPY docker/entrypoint.sh /usr/local/bin/womanlab-entrypoint

WORKDIR /var/www
RUN composer install --no-dev --no-interaction --prefer-dist --classmap-authoritative \
    && sed -i 's/AllowOverride None/AllowOverride All/' /etc/apache2/apache2.conf \
    && a2enconf womanlab-render \
    && mkdir -p /var/www/html/uploads/images \
    && chown -R www-data:www-data /var/www/html/uploads

ENV WOMANLAB_CORE_PATH=/var/www/womanlab-core \
    PUBLIC_UPLOAD_DIR=/var/www/html/uploads/images \
    TUU_CHECKOUT_ENABLED=0

EXPOSE 80
CMD ["sh", "/usr/local/bin/womanlab-entrypoint"]