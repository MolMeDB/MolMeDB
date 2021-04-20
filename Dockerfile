FROM php:8.0.3-apache

# Install and run apache
RUN apt-get install -y apache2 && apt-get clean

RUN a2enmod rewrite

EXPOSE 80