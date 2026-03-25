FROM php:8.3-apache

# Install system dependencies
RUN apt-get update && apt-get install -y \
    git unzip libfreetype6-dev libjpeg62-turbo-dev libpng-dev libzip-dev \
    libicu-dev libxslt-dev libonig-dev libsodium-dev default-mysql-client curl \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j$(nproc) \
    gd intl pdo_mysql xsl zip soap bcmath sockets sodium opcache mbstring \
    && a2enmod rewrite headers \
    && rm -rf /var/lib/apt/lists/*

# PHP config for Magento
RUN echo "memory_limit=2G" > /usr/local/etc/php/conf.d/magento.ini \
    && echo "max_execution_time=600" >> /usr/local/etc/php/conf.d/magento.ini \
    && echo "realpath_cache_size=10M" >> /usr/local/etc/php/conf.d/magento.ini \
    && echo "realpath_cache_ttl=7200" >> /usr/local/etc/php/conf.d/magento.ini

# Apache config
RUN sed -i 's|/var/www/html|/var/www/html/pub|g' /etc/apache2/sites-available/000-default.conf \
    && echo '<Directory /var/www/html/pub>\n    AllowOverride All\n    Require all granted\n</Directory>' \
    >> /etc/apache2/sites-available/000-default.conf

# Install Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

# Set ownership
RUN chown -R www-data:www-data /var/www/html
