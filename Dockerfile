# FROM php:8.0.3-apache
FROM php:7.4.16-apache-buster

ENV TZ=Europe/Prague
RUN ln -snf /usr/share/zoneinfo/$TZ /etc/localtime && echo $TZ > /etc/timezone

# Install and run apache
RUN apt-cache search mysql
RUN apt-get install -y apache2 && apt-get clean php7.4-zip

RUN apt-get update && apt-get install -y \
    zlib1g-dev \
    libzip-dev \
    python3 \
    python3-pip \
    libkrb5-dev \ 
    iputils-ping

RUN apt-get upgrade -y

RUN python3 -m pip install --upgrade pip

RUN pip3 install gssapi
RUN pip3 install awscli --ignore-installed six
RUN pip3 install setuptools
RUN pip3 install paramiko


RUN docker-php-ext-install mysqli pdo pdo_mysql zip sockets

RUN a2enmod rewrite

EXPOSE 80