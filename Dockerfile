FROM php:8.2-apache-buster

# Set locale
ENV TZ=Europe/Prague
RUN ln -snf /usr/share/zoneinfo/$TZ /etc/localtime && echo $TZ > /etc/timezone

# Install packages
RUN apt-get update && apt-get install -y \
    build-essential \
    zlib1g-dev \
    libssl-dev \
    libzip-dev \
    libpng-dev \
    libjpeg-dev \ 
    libfreetype6-dev \
    python3 \
    python3-pip \
    libkrb5-dev \ 
    iputils-ping

# Install DB
RUN docker-php-ext-configure gd --with-freetype --with-jpeg=/usr/include/ --enable-gd
RUN docker-php-ext-install mysqli pdo pdo_mysql zip gd
    
# Install python required libraries for /app/scripts files
RUN python3 -m pip install --upgrade pip
RUN pip3 install gssapi
RUN pip3 install awscli --ignore-installed six
RUN pip3 install setuptools 
RUN pip3 install paramiko

# Enable mod-rewrite
RUN a2enmod rewrite

EXPOSE 80