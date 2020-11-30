ARG PHP_VERSION=7.4
ARG PHP_TARGET=php:${PHP_VERSION}-cli

FROM ${PHP_TARGET}

ARG COMPOSER_TARGET=2.0.3

WORKDIR /var/www/html

LABEL org.opencontainers.image.source=https://github.com/stancl/tenancy \
    org.opencontainers.image.vendor="Samuel Štancl" \
    org.opencontainers.image.licenses="MIT" \
    org.opencontainers.image.title="PHP ${PHP_VERSION} with modules for laravel support" \
    org.opencontainers.image.description="PHP ${PHP_VERSION} with a set of php/os packages suitable for running Laravel apps"

# our default timezone and langauge
ENV TZ=Europe/London
ENV LANG=en_GB.UTF-8

# Note: we only install reliable/core 1st-party php extensions here.
#       If your app needs custom ones install them in the apps own
#       Dockerfile _and pin the versions_! Eg:
#       RUN pecl install memcached-2.2.0 && docker-php-ext-enable memcached

# install some OS packages we need
RUN apt-get update
RUN apt-get install -y --no-install-recommends libfreetype6-dev libjpeg62-turbo-dev libpng-dev libgmp-dev libldap2-dev netcat curl sqlite3 libsqlite3-dev libpq-dev libzip-dev unzip vim-tiny gosu git
    # install php extensions
RUN docker-php-ext-configure pgsql -with-pgsql=/usr/local/pgsql \
    # && if [ "${PHP_VERSION}" = "7.4" ]; then docker-php-ext-configure gd --with-freetype --with-jpeg; else docker-php-ext-configure gd --with-freetype-dir=/usr/include/ --with-jpeg-dir=/usr/include/; fi \
    && docker-php-ext-install -j$(nproc) gd pdo pdo_mysql pdo_pgsql pdo_sqlite pgsql zip gmp bcmath pcntl ldap sysvmsg exif \
    # install the redis php extension
    && pecl install redis-5.3.2 \
    && docker-php-ext-enable redis \
    # install the pcov extention
    && pecl install pcov \
    && docker-php-ext-enable pcov \
    && echo "pcov.enabled = 1" > /usr/local/etc/php/conf.d/pcov.ini
# clear the apt cache
RUN rm -rf /var/lib/apt/lists/* \
    && rm -rf /var/lib/apt/lists/* \
    # install composer
    && curl -o /tmp/composer-setup.php https://getcomposer.org/installer \
    && curl -o /tmp/composer-setup.sig https://composer.github.io/installer.sig \
    && php -r "if (hash('SHA384', file_get_contents('/tmp/composer-setup.php')) !== trim(file_get_contents('/tmp/composer-setup.sig'))) { unlink('/tmp/composer-setup.php'); echo 'Invalid installer' . PHP_EOL; exit(1); }" \
    && php /tmp/composer-setup.php --version=${COMPOSER_TARGET} --no-ansi --install-dir=/usr/local/bin --filename=composer --snapshot \
    && rm -f /tmp/composer-setup.*
# set the system timezone
RUN ln -snf /usr/share/zoneinfo/$TZ /etc/localtime \
    && echo $TZ > /etc/timezone
