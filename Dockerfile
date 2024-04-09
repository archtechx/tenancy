FROM shivammathur/node:latest
SHELL ["/bin/bash", "-c"]

ARG PHP_VERSION=8.3

WORKDIR /var/www/html

# our default timezone and langauge
ENV TZ=Europe/London
ENV LANG=en_GB.UTF-8

# install MSSQL ODBC driver (1/2)
RUN apt-get update \
    && apt-get install -y gnupg2 \
    && curl https://packages.microsoft.com/keys/microsoft.asc | apt-key add - \
    && curl https://packages.microsoft.com/config/ubuntu/20.04/prod.list > /etc/apt/sources.list.d/mssql-release.list \
    && apt-get update

# install MSSQL ODBC driver (2/2)
RUN if [[ $(uname -m) == "arm64" || $(uname -m) == "aarch64" ]]; \
    then ACCEPT_EULA=Y apt-get install -y unixodbc-dev msodbcsql18; \
    else ACCEPT_EULA=Y apt-get install -y unixodbc-dev=2.3.7 unixodbc=2.3.7 odbcinst1debian2=2.3.7 odbcinst=2.3.7 msodbcsql17; \
    fi

# set PHP version
RUN update-alternatives --set php /usr/bin/php$PHP_VERSION \
    && update-alternatives --set phar /usr/bin/phar$PHP_VERSION \
    && update-alternatives --set phar.phar /usr/bin/phar.phar$PHP_VERSION \
    && update-alternatives --set phpize /usr/bin/phpize$PHP_VERSION \
    && update-alternatives --set php-config /usr/bin/php-config$PHP_VERSION

# install composer
COPY --from=composer:latest /usr/bin/composer /usr/local/bin/composer

# set the system timezone
RUN ln -snf /usr/share/zoneinfo/$TZ /etc/localtime && echo $TZ > /etc/timezone

# install PHP extensions
RUN pecl install redis && printf "; priority=20\nextension=redis.so\n" > /etc/php/$PHP_VERSION/mods-available/redis.ini && phpenmod -v $PHP_VERSION redis
RUN pecl install pdo_sqlsrv && printf "; priority=30\nextension=pdo_sqlsrv.so\n" > /etc/php/$PHP_VERSION/mods-available/pdo_sqlsrv.ini && phpenmod -v $PHP_VERSION pdo_sqlsrv
RUN pecl install pcov && printf "; priority=40\nextension=pcov.so\n" > /etc/php/$PHP_VERSION/mods-available/pcov.ini && phpenmod -v $PHP_VERSION pcov

RUN apt-get install -y --no-install-recommends libmemcached-dev zlib1g-dev
RUN pecl install memcached && printf "; priority=50\nextension=memcached.so\n" > /etc/php/$PHP_VERSION/mods-available/memcached.ini && phpenmod -v $PHP_VERSION memcached
RUN pecl install apcu && printf "; priority=60\nextension=apcu.so\napc.enable_cli=1\n" > /etc/php/$PHP_VERSION/mods-available/apcu.ini && phpenmod -v $PHP_VERSION apcu
