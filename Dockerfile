FROM ubuntu:18.04

LABEL maintainer="Samuel Štancl"

ENV DEBIAN_FRONTEND=noninteractive

RUN apt-get update && apt-get install -y software-properties-common

RUN add-apt-repository -y ppa:ondrej/php

RUN apt-get update \
    && apt-get install -y curl zip unzip git sqlite3 \
    php7.3-fpm php7.3-cli \
    php7.3-pgsql php7.3-sqlite3 php7.3-gd \
    php7.3-curl php7.3-memcached \
    php7.3-imap php7.3-mysql php7.3-mbstring \
    php7.3-xml php7.3-zip php7.3-bcmath php7.3-soap \
    php7.3-intl php7.3-readline php7.3-xdebug \
    php-msgpack php-igbinary \
    && php -r "readfile('http://getcomposer.org/installer');" | php -- --install-dir=/usr/bin/ --filename=composer \
    && mkdir /run/php

RUN apt-get install -y php7.3-redis

RUN apt-get install -y python3

RUN apt-get install -y php7.3-dev php-pear php-xdebug
# RUN echo '' > /etc/php/7.3/cli/conf.d/30-xdebug.ini
# RUN echo 'zend_extension=/usr/lib/php/30170718/xdebug.so' >> /etc/php/7.3/cli/php.ini


RUN apt-get -y autoremove \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/* /tmp/* /var/tmp/*

WORKDIR /var/www/html