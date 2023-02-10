# FROM php:8.0.3-apache
FROM php:7.4.16-apache-buster

# Install and run apache
RUN apt-cache search mysql
RUN apt-get update -y
RUN apt-get install -y apache2 && apt-get clean php7.4-zip php7.4-gd
RUN apt-get install -y build-essential libssl-dev zlib1g-dev libpng-dev libjpeg-dev libfreetype6-dev

RUN apt-get update && apt-get install -y \
    zlib1g-dev \
    libzip-dev \
    libpng-dev

RUN docker-php-ext-configure gd --with-freetype --with-jpeg=/usr/include/ --enable-gd

RUN docker-php-ext-install mysqli pdo pdo_mysql zip gd

RUN a2enmod rewrite

EXPOSE 80