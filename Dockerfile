FROM php:8.3-cli

# Install Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Install Xdebug
RUN apt-get update && apt-get install -y \
    libzip-dev \
    zip \
    unzip \
    git \
    && pecl install xdebug-3.3.2 \
    && docker-php-ext-enable xdebug \
    && rm -rf /var/lib/apt/lists/*

# Configure Xdebug for remote debugging
RUN echo "xdebug.mode=debug" >> /usr/local/etc/php/conf.d/docker-php-ext-xdebug.ini \
    && echo "xdebug.start_with_request=yes" >> /usr/local/etc/php/conf.d/docker-php-ext-xdebug.ini \
    && echo "xdebug.client_host=host.docker.internal" >> /usr/local/etc/php/conf.d/docker-php-ext-xdebug.ini \
    && echo "xdebug.client_port=9003" >> /usr/local/etc/php/conf.d/docker-php-ext-xdebug.ini \
    && echo "xdebug.log=/tmp/xdebug.log" >> /usr/local/etc/php/conf.d/docker-php-ext-xdebug.ini \
    && echo "xdebug.idekey=PHPSTORM" >> /usr/local/etc/php/conf.d/docker-php-ext-xdebug.ini

WORKDIR /app
