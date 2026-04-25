FROM php:8.2-apache

# Install PHP extensions needed for Ticketix
RUN docker-php-ext-install mysqli pdo pdo_mysql

# Install additional extensions
RUN apt-get update && apt-get install -y \
    libpng-dev \
    libssl-dev \
    libcurl4-openssl-dev \
    && docker-php-ext-install gd curl \
    && rm -rf /var/lib/apt/lists/*

# Enable Apache mod_rewrite
RUN a2enmod rewrite

# Copy all project files to Apache web root
COPY . /var/www/html/

# Set correct permissions
RUN chown -R www-data:www-data /var/www/html/

# Apache config: set document root and allow .htaccess
RUN echo '<Directory /var/www/html/>\n\
    Options Indexes FollowSymLinks\n\
    AllowOverride All\n\
    Require all granted\n\
</Directory>' > /etc/apache2/conf-available/ticketix.conf \
    && a2enconf ticketix

# Expose port 80
EXPOSE 80

CMD ["apache2-foreground"]
