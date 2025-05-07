# Use official PHP image with Apache
FROM php:8.1-apache

# Install system dependencies and PHP extensions (mysqli, pdo_mysql, curl, json)
RUN apt-get update && apt-get install -y \
    libzip-dev \
    zip \
    unzip \
    curl \
    && docker-php-ext-install mysqli pdo pdo_mysql

# Enable Apache mod_rewrite if needed
RUN a2enmod rewrite

# Set the working directory (optional)
WORKDIR /var/www/html

# Copy all files from your project into the container
COPY . /var/www/html/

# Fix file permissions (optional but useful)
RUN chown -R www-data:www-data /var/www/html \
    && chmod -R 755 /var/www/html

# Expose port 80 for HTTP
EXPOSE 80
