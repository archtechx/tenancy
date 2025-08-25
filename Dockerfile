ARG PHP_VERSION=8.4

FROM php:${PHP_VERSION}-cli-bookworm
SHELL ["/bin/bash", "-c"]

RUN apt-get update && apt-get install -y --no-install-recommends \
    git unzip libzip-dev libicu-dev libmemcached-dev zlib1g-dev libssl-dev sqlite3 libsqlite3-dev libpq-dev mariadb-client

RUN apt-get install -y gnupg2 \
    && curl -fsSL https://packages.microsoft.com/keys/microsoft.asc | gpg --dearmor -o /usr/share/keyrings/microsoft-prod.gpg \
    && curl https://packages.microsoft.com/config/debian/12/prod.list > /etc/apt/sources.list.d/mssql-release.list \
    && apt-get update \
    && ACCEPT_EULA=Y apt-get install -y unixodbc-dev msodbcsql18

RUN apt autoremove && apt clean

RUN pecl install apcu && docker-php-ext-enable apcu
RUN pecl install pcov && docker-php-ext-enable pcov
RUN pecl install redis && docker-php-ext-enable redis
RUN pecl install memcached && docker-php-ext-enable memcached
RUN pecl install pdo_sqlsrv && docker-php-ext-enable pdo_sqlsrv
RUN docker-php-ext-install zip && docker-php-ext-enable zip
RUN docker-php-ext-install intl && docker-php-ext-enable intl
RUN docker-php-ext-install pdo_mysql && docker-php-ext-enable pdo_mysql
RUN docker-php-ext-install pdo_pgsql && docker-php-ext-enable pdo_pgsql

RUN mv "$PHP_INI_DIR/php.ini-development" "$PHP_INI_DIR/php.ini"
RUN echo "apc.enable_cli=1" >> "$PHP_INI_DIR/php.ini"

# Only used on GHA
COPY --from=composer:latest /usr/bin/composer /usr/local/bin/composer

# Conditionally install and configure Xdebug (last step for faster rebuilds)
ARG XDEBUG_ENABLED=false
RUN if [ "$XDEBUG_ENABLED" = "true" ]; then \
    pecl install xdebug && docker-php-ext-enable xdebug && \
    echo "xdebug.mode=debug" >> "$PHP_INI_DIR/conf.d/docker-php-ext-xdebug.ini" && \
    echo "xdebug.start_with_request=yes" >> "$PHP_INI_DIR/conf.d/docker-php-ext-xdebug.ini" && \
    echo "xdebug.client_host=host.docker.internal" >> "$PHP_INI_DIR/conf.d/docker-php-ext-xdebug.ini" && \
    echo "xdebug.client_port=9003" >> "$PHP_INI_DIR/conf.d/docker-php-ext-xdebug.ini" && \
    echo "xdebug.discover_client_host=true" >> "$PHP_INI_DIR/conf.d/docker-php-ext-xdebug.ini" && \
    echo "xdebug.log=/var/log/xdebug.log" >> "$PHP_INI_DIR/conf.d/docker-php-ext-xdebug.ini"; \
fi

WORKDIR /var/www/html
