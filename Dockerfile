FROM ubuntu:18.04

LABEL maintainer="Samuel Štancl"

ENV DEBIAN_FRONTEND=noninteractive

RUN apt-get update \
    && apt-get install -y curl zip unzip git sqlite3 \
    php7.2-fpm php7.2-cli \
    php7.2-pgsql php7.2-sqlite3 php7.2-gd \
    php7.2-curl php7.2-memcached \
    php7.2-imap php7.2-mysql php7.2-mbstring \
    php7.2-xml php7.2-zip php7.2-bcmath php7.2-soap \
    php7.2-intl php7.2-readline php7.2-xdebug \
    php-msgpack php-igbinary \
    && php -r "readfile('http://getcomposer.org/installer');" | php -- --install-dir=/usr/bin/ --filename=composer \
    && mkdir /run/php

RUN apt-get install -y php7.2-redis

RUN apt-get install -y python3

RUN apt-get install -y php7.2-dev php-pear
RUN pecl install xdebug
# RUN echo '' > /etc/php/7.2/cli/conf.d/20-xdebug.ini
# RUN echo 'zend_extension=/usr/lib/php/20170718/xdebug.so' >> /etc/php/7.2/cli/php.ini
RUN echo 'zend_extension=/usr/lib/php/20170718/xdebug.so' > /etc/php/7.2/cli/conf.d/20-xdebug.ini

RUN apt-get -y autoremove \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/* /tmp/* /var/tmp/*

WORKDIR /var/www/html