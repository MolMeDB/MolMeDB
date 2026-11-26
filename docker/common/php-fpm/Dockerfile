# syntax=docker/dockerfile:1

# The patch version is pinned: an image built today and one built next month
# must not differ in the PHP they ship. Raise it deliberately.
ARG PHP_VERSION=8.4.27

# Use the official PHP image as a base image
FROM php:${PHP_VERSION}-fpm AS builder

# Copy dependency manifests separately so dependency installation stays cached
# when only application source files change.
COPY composer.lock composer.json /var/www/

# Set working directory
WORKDIR /var/www

# Install dependencies
RUN apt-get clean && apt-get update && apt-get install -y \
    build-essential \
    libmemcached-dev \
    libpng-dev \
    libjpeg62-turbo-dev \
    libfreetype6-dev \
    locales \
    zip \
    jpegoptim optipng pngquant gifsicle \
    vim \
    unzip \
    git \
    curl \
    iputils-ping \
    libzip-dev \
    libpq-dev \
    libjpeg-dev \
    libicu-dev \
    libxpm-dev \
    libwebp-dev \
    libonig-dev \
    libfcgi-bin 

# Clear cache
RUN apt-get clean && rm -rf /var/lib/apt/lists/*

# Install extensions
# bcmath: minishlink/web-push encrypts push payloads with it (without GMP or
# BCMath it falls back to slow pure-PHP maths and logs a notice per push).
RUN docker-php-ext-install pdo_mysql mbstring zip exif pcntl opcache bcmath
RUN docker-php-ext-configure gd --with-jpeg --with-freetype --with-webp --with-xpm
RUN docker-php-ext-install gd
RUN docker-php-ext-configure pgsql -with-pgsql=/usr/local/pgsql
RUN docker-php-ext-install pdo_pgsql pgsql
RUN docker-php-ext-configure intl
RUN docker-php-ext-install intl

# Install Composer from its official image.
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

# Install production PHP dependencies before copying the application source.
# Scripts need the full Laravel application, so they are run after COPY below.
RUN composer install \
    --no-interaction \
    --no-dev \
    --prefer-dist \
    --optimize-autoloader \
    --no-scripts

# Copy existing application directory contents
COPY . /var/www

# Never carry host-generated Laravel caches into the production image.
RUN mkdir -p \
        bootstrap/cache \
        storage/app/public \
        storage/framework/cache \
        storage/framework/sessions \
        storage/framework/views \
        storage/logs \
    && find bootstrap/cache -type f -name '*.php' -delete \
    && php artisan package:discover --ansi


FROM builder AS production

RUN apt-get update && apt-get install -y \
    libpq-dev \
    libicu-dev \
    libzip-dev \
    libfcgi-bin \
    postgresql-client \
    nano \
    procps
    
RUN apt-get autoremove -y && apt-get clean && rm -rf /var/lib/apt/lists/* /tmp/* /var/tmp/*

RUN curl -o /usr/local/bin/php-fpm-healthcheck \
    https://raw.githubusercontent.com/renatomefi/php-fpm-healthcheck/master/php-fpm-healthcheck \
    && chmod +x /usr/local/bin/php-fpm-healthcheck

COPY ./docker/common/php-fpm/conf.d/zz-pool.conf /usr/local/etc/php-fpm.d/zz-pool.conf

# Copy the initialization script
COPY ./docker/production/php-fpm/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

# COPY ./storage /var/www/storage-init

# Copy PHP extensions and libraries from the builder stage
COPY --from=builder /usr/local/lib/php/extensions/ /usr/local/lib/php/extensions/
COPY --from=builder /usr/local/etc/php/conf.d/ /usr/local/etc/php/conf.d/
COPY --from=builder /usr/local/bin/docker-php-ext-* /usr/local/bin/


# Use the recommended production PHP configuration
# -----------------------------------------------------------
# PHP provides development and production configurations.
# Here, we replace the default php.ini with the production
# version to apply settings optimized for performance and
# security in a live environment.
# -----------------------------------------------------------
RUN mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"
# RUN echo "extension=pdo_pgsql.so" >> "$PHP_INI_DIR/php.ini"
COPY ./docker/common/php-fpm/conf.d/uploads.ini "$PHP_INI_DIR/conf.d/uploads.ini"

# Enable PHP-FPM status and ping endpoints in the www pool.
RUN sed -i '/\[www\]/a pm.status_path = /status' /usr/local/etc/php-fpm.d/www.conf \
 && sed -i '/\[www\]/a ping.path = /ping' /usr/local/etc/php-fpm.d/www.conf

# Copy existing application directory permissions
COPY --from=builder --chown=www-data:www-data /var/www /var/www
WORKDIR /var/www

RUN mkdir -p storage/logs \
    && chown -R www-data:www-data storage \
    && chmod -R 775 storage \
    && chmod -R 775 bootstrap 

USER www-data

ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]

# Expose port 9000 and start php-fpm server
EXPOSE 9000
CMD ["php-fpm"]


# Stage 3: Development image
FROM production AS development

# Use ARG to define environment variables passed from the Docker build command or Docker Compose.
ARG XDEBUG_ENABLED=true
ARG XDEBUG_MODE=develop,coverage,debug,profile
ARG XDEBUG_HOST=host.docker.internal
ARG XDEBUG_IDE_KEY=DOCKER
ARG XDEBUG_LOG=/dev/stdout
ARG XDEBUG_LOG_LEVEL=0

USER root

# Configure Xdebug if enabled
RUN if [ "${XDEBUG_ENABLED}" = "true" ]; then \
    pecl install xdebug && \
    docker-php-ext-enable xdebug && \
    echo "xdebug.mode=${XDEBUG_MODE}" >> /usr/local/etc/php/conf.d/docker-php-ext-xdebug.ini && \
    echo "xdebug.idekey=${XDEBUG_IDE_KEY}" >> /usr/local/etc/php/conf.d/docker-php-ext-xdebug.ini && \
    echo "xdebug.log=${XDEBUG_LOG}" >> /usr/local/etc/php/conf.d/docker-php-ext-xdebug.ini && \
    echo "xdebug.log_level=${XDEBUG_LOG_LEVEL}" >> /usr/local/etc/php/conf.d/docker-php-ext-xdebug.ini && \
    echo "xdebug.client_host=${XDEBUG_HOST}" >> /usr/local/etc/php/conf.d/docker-php-ext-xdebug.ini ; \
    echo "xdebug.start_with_request=yes" >> /usr/local/etc/php/conf.d/docker-php-ext-xdebug.ini ; \
fi

# Add ARGs for syncing permissions
ARG UID=1000
ARG GID=1000

# Create a new user with the specified UID and GID, reusing an existing group if GID exists
RUN if getent group ${GID}; then \
      group_name=$(getent group ${GID} | cut -d: -f1); \
      useradd -m -u ${UID} -g ${GID} -s /bin/bash www; \
    else \
      groupadd -g ${GID} www && \
      useradd -m -u ${UID} -g www -s /bin/bash www; \
      group_name=www; \
    fi

# Dynamically update php-fpm to use the new user and group
RUN sed -i "s/user = www-data/user = www/g" /usr/local/etc/php-fpm.d/www.conf && \
    sed -i "s/group = www-data/group = $group_name/g" /usr/local/etc/php-fpm.d/www.conf

# Set the working directory
WORKDIR /var/www

# Copy the entrypoint script
COPY ./docker/dev/php-fpm/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

# Switch back to the non-privileged user to run the application
USER www-data

# Change the default command to run the entrypoint script
ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]

# Expose port 9000 and start php-fpm server
EXPOSE 9000
CMD ["php-fpm"]
