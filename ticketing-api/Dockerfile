FROM php:8.4-apache
RUN apt-get update \
    && apt-get install -y --no-install-recommends libpq-dev \
    && docker-php-ext-install pdo_pgsql \
    && rm -rf /var/lib/apt/lists/*
COPY public/ /var/www/html/
COPY src/ /var/www/src/
COPY routing.conf /etc/apache2/conf-available/ticketing.conf
RUN a2enconf ticketing
