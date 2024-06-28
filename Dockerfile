# add amd64 platform to support Mac M1
FROM --platform=linux/amd64 shivammathur/node:jammy-amd64

ARG PHP_VERSION=8.1

WORKDIR /var/www/html

# our default timezone and langauge
ENV TZ=Europe/London
ENV LANG=en_GB.UTF-8

# install MYSSQL ODBC Driver
RUN apt-get update \
    && apt-get install -y gnupg2 \
    && curl https://packages.microsoft.com/keys/microsoft.asc | apt-key add - \
    && curl https://packages.microsoft.com/config/ubuntu/20.04/prod.list > /etc/apt/sources.list.d/mssql-release.list \
    && apt-get update \
    && ACCEPT_EULA=Y apt-get install -y unixodbc-dev=2.3.7 unixodbc=2.3.7 odbcinst1debian2=2.3.7 odbcinst=2.3.7 msodbcsql17

# set PHP version
RUN update-alternatives --set php /usr/bin/php$PHP_VERSION \
    && update-alternatives --set phar /usr/bin/phar$PHP_VERSION \
    && update-alternatives --set phar.phar /usr/bin/phar.phar$PHP_VERSION \
    && update-alternatives --set phpize /usr/bin/phpize$PHP_VERSION \
    && update-alternatives --set php-config /usr/bin/php-config$PHP_VERSION

RUN apt-get update \
    && apt-get install -y --no-install-recommends libhiredis0.14 libjemalloc2 liblua5.1-0 lua-bitop lua-cjson redis redis-server redis-tools

RUN pecl install redis-5.3.7 sqlsrv pdo_sqlsrv pcov \
    && printf "; priority=20\nextension=redis.so\n" > /etc/php/$PHP_VERSION/mods-available/redis.ini \
    && printf "; priority=20\nextension=sqlsrv.so\n" > /etc/php/$PHP_VERSION/mods-available/sqlsrv.ini \
    && printf "; priority=30\nextension=pdo_sqlsrv.so\n" > /etc/php/$PHP_VERSION/mods-available/pdo_sqlsrv.ini \
    && printf "; priority=40\nextension=pcov.so\n" > /etc/php/$PHP_VERSION/mods-available/pcov.ini \
    && phpenmod -v $PHP_VERSION redis sqlsrv pdo_sqlsrv pcov

# install composer
COPY --from=composer:latest /usr/bin/composer /usr/local/bin/composer

# set the system timezone
RUN ln -snf /usr/share/zoneinfo/$TZ /etc/localtime \
    && echo $TZ > /etc/timezone
