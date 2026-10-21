# SQL Web Manager: PHP 8.3 + Apache + Microsoft ODBC Driver 18 + pdo_sqlsrv
FROM php:8.3-apache

ENV ACCEPT_EULA=Y \
    DEBIAN_FRONTEND=noninteractive

# ODBC Driver 18 (Microsoft's official repository) and the sqlsrv/pdo_sqlsrv extensions (PECL).
RUN apt-get update \
 && apt-get install -y --no-install-recommends $PHPIZE_DEPS curl gnupg ca-certificates unixodbc-dev \
 && curl -fsSL https://packages.microsoft.com/keys/microsoft.asc | gpg --dearmor -o /usr/share/keyrings/microsoft-prod.gpg \
 && curl -fsSL https://packages.microsoft.com/config/debian/12/prod.list -o /etc/apt/sources.list.d/mssql-release.list \
 && apt-get update \
 && apt-get install -y --no-install-recommends msodbcsql18 \
 && pecl install sqlsrv pdo_sqlsrv \
 && docker-php-ext-enable sqlsrv pdo_sqlsrv \
 && a2enmod rewrite \
 && rm -rf /var/lib/apt/lists/* /tmp/pear

COPY docker/apache.conf /etc/apache2/sites-available/000-default.conf
COPY docker/php.ini $PHP_INI_DIR/conf.d/zz-sqlwm.ini

WORKDIR /var/www/html
COPY app ./app
COPY config ./config
COPY public ./public

EXPOSE 80
