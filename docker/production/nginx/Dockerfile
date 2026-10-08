# syntax=docker/dockerfile:1

FROM nginx:alpine AS production

# Copy custom Nginx configuration
# -----------------------------------------------------------
# nginx.conf holds the shared settings; the server of every MolMeDB domain
# is rendered from templates/ with the domain names from the environment
# (MOLMEDB_*_HOST) by the image's entrypoint into /etc/nginx/conf.d.
# See README.md in this directory.
# -----------------------------------------------------------
COPY ./docker/production/nginx/nginx.conf /etc/nginx/nginx.conf
COPY ./docker/production/nginx/snippets /etc/nginx/snippets
COPY ./docker/production/nginx/templates /etc/nginx/templates
RUN rm -f /etc/nginx/conf.d/default.conf

RUN mkdir -p /var/cache/nginx/fastcgi && chown -R nginx:nginx /var/cache/nginx

# Nginx only needs Laravel's versioned public files. Application source and PHP
# dependencies remain in the backend image.
COPY public /var/www/public

# Set the working directory to the public folder
WORKDIR /var/www/public

# Plain HTTP inside Docker; TLS is terminated by the proxy in front.
EXPOSE 80
CMD ["nginx", "-g", "daemon off;"]
