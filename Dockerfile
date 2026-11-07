FROM php:8.3-apache

RUN apt-get update \
    && apt-get install -y --no-install-recommends libsqlite3-dev \
    && docker-php-ext-install pdo_sqlite \
    && a2enmod rewrite \
    && rm -rf /var/lib/apt/lists/*

# Keep SQLite data outside Apache's public document root.
ENV PROJECT_DASHBOARD_DB=/var/lib/project-control/dashboard.sqlite \
    PROJECT_DASHBOARD_COOKIE_SECURE=1

RUN install -d -o www-data -g www-data -m 0700 /var/lib/project-control

COPY . /var/www/html/
RUN chown -R root:root /var/www/html \
    && find /var/www/html -type d -exec chmod 0755 {} \; \
    && find /var/www/html -type f -exec chmod 0644 {} \;

EXPOSE 80
