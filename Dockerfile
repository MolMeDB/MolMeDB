# FROM php:8.0.3-apache
FROM php:7.4.16-apache-buster

# Install and run apache
RUN apt-cache search mysql
RUN apt-get install -y apache2 && apt-get clean php7.4-zip

RUN apt-get update && apt-get install -y \
    zlib1g-dev \
    libzip-dev

RUN docker-php-ext-install mysqli pdo pdo_mysql zip

RUN a2enmod rewrite

EXPOSE 80