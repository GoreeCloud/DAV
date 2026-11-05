FROM composer:2 AS deps
WORKDIR /app
COPY composer.json ./
RUN composer install --no-dev --no-interaction --prefer-dist --no-scripts --optimize-autoloader

FROM php:8.3-apache
# pdo_sqlite requires the SQLite development headers and pkg-config metadata.
RUN apt-get update \
    && apt-get install -y --no-install-recommends libsqlite3-dev \
    && rm -rf /var/lib/apt/lists/* \
    && docker-php-ext-install pdo_sqlite && a2enmod rewrite headers reqtimeout \
    && mkdir -p /var/lib/goreecloud-dav \
    && chown www-data:www-data /var/lib/goreecloud-dav \
    && chmod 0700 /var/lib/goreecloud-dav
COPY --from=deps /app/vendor /var/www/html/vendor
COPY composer.json /var/www/html/composer.json
COPY src/ /var/www/html/src/
COPY resources/ /var/www/html/resources/
COPY bin/ /var/www/html/bin/
COPY public/ /var/www/html/public/
RUN sed -ri 's!DocumentRoot /var/www/html!DocumentRoot /var/www/html/public!g' /etc/apache2/sites-available/000-default.conf \
    && printf 'ServerTokens Prod\nServerSignature Off\nRequestReadTimeout header=20-40,MinRate=500 body=20,MinRate=500\n<Directory /var/www/html/public>\nAllowOverride All\nLimitRequestBody 67108864\nRequire all granted\n</Directory>\n' > /etc/apache2/conf-available/goreecloud-dav.conf \
    && a2enconf goreecloud-dav \
    && printf 'SetEnvIf Authorization "(.*)" HTTP_AUTHORIZATION=$1\n' > /etc/apache2/conf-available/goreecloud-dav-authorization.conf \
    && a2enconf goreecloud-dav-authorization
ENV DAV_DATA_DIR=/var/lib/goreecloud-dav DAV_BASE_URI=/ DAV_REQUIRE_HTTPS=1
EXPOSE 80
