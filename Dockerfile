# EbubeConnect — PHP + Apache for Railway
FROM php:8.3-apache-bookworm

RUN apt-get update \
  && apt-get install -y --no-install-recommends \
    libzip-dev \
    unzip \
  && docker-php-ext-install mysqli pdo_mysql \
  && a2dismod mpm_event \
  && a2enmod mpm_prefork \
  && a2enmod rewrite headers deflate expires \
  && rm -rf /var/lib/apt/lists/*

ENV PORT=8080
ENV APACHE_DOCUMENT_ROOT=/var/www/html

WORKDIR /var/www/html

COPY index.html ./index.html
COPY docker/htaccess.railway ./.htaccess
COPY backend ./backend
COPY downloads ./downloads
COPY private/.htaccess ./private/.htaccess
COPY app/build/web ./agent
COPY docker/agent.htaccess ./agent/.htaccess

COPY docker/apache-vhost.conf /etc/apache2/sites-available/000-default.conf
COPY docker/entrypoint.sh /usr/local/bin/ebube-entrypoint.sh
RUN chmod +x /usr/local/bin/ebube-entrypoint.sh \
  && chown -R www-data:www-data /var/www/html \
  && find /var/www/html -type d -exec chmod 755 {} \;

EXPOSE 8080
ENTRYPOINT ["/usr/local/bin/ebube-entrypoint.sh"]
