# Wyn Analytics: Apache + PHP 8.3. The database lives in /var/www/wyn-analytics/storage.
FROM php:8.3-apache

RUN apt-get update && apt-get install -y --no-install-recommends libicu-dev && rm -rf /var/lib/apt/lists/* \
 && docker-php-ext-install intl pcntl \
 && a2enmod rewrite headers deflate \
 && sed -ri 's#/var/www/html#/var/www/wyn-analytics/public#g' /etc/apache2/sites-available/000-default.conf /etc/apache2/apache2.conf \
 && sed -ri 's#AllowOverride None#AllowOverride All#g' /etc/apache2/apache2.conf \
 && echo "ServerName localhost" > /etc/apache2/conf-available/servername.conf && a2enconf servername \
 && { echo 'expose_php=Off'; echo 'log_errors=On'; } > /usr/local/etc/php/conf.d/wyn-analytics.ini \
 && echo 'CustomLog /dev/null common' > /etc/apache2/conf-available/no-access-log.conf && a2enconf no-access-log

WORKDIR /var/www/wyn-analytics
COPY . .
RUN mkdir -p storage && chown -R www-data:www-data storage

ENV WYN_DATABASE=/var/www/wyn-analytics/storage/wyn-analytics.sqlite
VOLUME ["/var/www/wyn-analytics/storage"]
EXPOSE 80
