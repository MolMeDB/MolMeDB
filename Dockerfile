# FROM php:8.0.3-apache
FROM php:8.0.3-apache-buster

# Install and run apache
RUN apt-cache search mysql
RUN apt-get install -y apache2 && apt-get clean 

RUN docker-php-ext-install mysqli pdo pdo_mysql

RUN a2enmod rewrite

EXPOSE 80