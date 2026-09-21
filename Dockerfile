# EbubeConnect — multi-stage: Flutter web → PHP/Apache
# Git push / Railway builds Flutter inside Docker; no local web build required.

# ── Stage 1: Flutter web ────────────────────────────────────────────────────
FROM ghcr.io/cirruslabs/flutter:stable AS flutter_web

WORKDIR /src
# Cache pub deps when lock/yaml unchanged.
COPY app/pubspec.yaml app/pubspec.lock ./
RUN flutter config --no-analytics \
  && flutter pub get

COPY app/ ./
# Platform folders are not needed for web; keep context small via .dockerignore.

ARG API_BASE=https://ebubeconnect.com/backend
RUN flutter build web --release \
      --base-href /agent/ \
      --web-resources-cdn \
      --dart-define=API_BASE=${API_BASE} \
  && rm -rf build/web/canvaskit

# ── Stage 2: PHP + Apache runtime ───────────────────────────────────────────
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
# APK binaries live on a Railway volume mounted here (see scripts/upload_apks.sh).
RUN mkdir -p ./downloads/apks \
  && printf '%s\n' 'Place APKs via ./scripts/upload_apks.sh' > ./downloads/apks/README.txt

COPY private/.htaccess ./private/.htaccess
COPY --from=flutter_web /src/build/web ./agent
COPY docker/agent.htaccess ./agent/.htaccess

COPY docker/apache-vhost.conf /etc/apache2/sites-available/000-default.conf
COPY docker/entrypoint.sh /usr/local/bin/ebube-entrypoint.sh
RUN chmod +x /usr/local/bin/ebube-entrypoint.sh \
  && chown -R www-data:www-data /var/www/html \
  && find /var/www/html -type d -exec chmod 755 {} \;

EXPOSE 8080
ENTRYPOINT ["/usr/local/bin/ebube-entrypoint.sh"]
