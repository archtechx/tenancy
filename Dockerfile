FROM ubuntu:18.04

LABEL maintainer="Samuel Štancl"

ENV DEBIAN_FRONTEND=noninteractive

RUN apt-get update
RUN apt-get install -y software-properties-common
RUN add-apt-repository -y ppa:ondrej/php

RUN apt-get install -y curl zip unzip git sqlite3 \
    php7.4-fpm php7.4-cli \
    php7.4-pgsql php7.4-sqlite3 php7.4-gd \
    php7.4-curl php7.4-memcached \
    php7.4-imap php7.4-mysql php7.4-mbstring \
    php7.4-xml php7.4-zip php7.4-bcmath php7.4-soap \
    php7.4-intl php7.4-readline php7.4-xdebug \
    php-msgpack php-igbinary \
    && php -r "readfile('http://getcomposer.org/installer');" | php -- --install-dir=/usr/bin/ --filename=composer \
    && mkdir /run/php

RUN apt-get install -y python3

RUN apt-get install -y php7.4-dev php-pear

RUN pecl install redis-4.3.0
RUN echo "extension=redis.so" > /etc/php/7.4/mods-available/redis.ini
RUN ln -sf /etc/php/7.4/mods-available/redis.ini /etc/php/7.4/fpm/conf.d/20-redis.ini
RUN ln -sf /etc/php/7.4/mods-available/redis.ini /etc/php/7.4/cli/conf.d/20-redis.ini

RUN pecl install xdebug
RUN echo 'zend_extension=/usr/lib/php/20190902/xdebug.so' > /etc/php/7.4/cli/conf.d/20-xdebug.ini

RUN apt-get -y autoremove \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/* /tmp/* /var/tmp/*

WORKDIR /var/www/html
