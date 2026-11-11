FROM node:22-bookworm-slim AS frontend

WORKDIR /build

COPY package.json package-lock.json ./
RUN npm ci --no-audit --no-fund

COPY resources ./resources
COPY public ./public
COPY vite.config.js tailwind.config.js postcss.config.js ./
RUN npm run build

FROM php:8.3-fpm-bookworm

ENV APP_ENV=production \
    APP_DEBUG=false \
    DB_CONNECTION=mysql \
    LOCAL_NLP_ENABLED=true \
    LOCAL_NLP_PYTHON=/opt/venv/bin/python \
    NLTK_DATA=/var/www/html/nlp/nltk_data \
    HF_HOME=/tmp/huggingface \
    HF_HUB_OFFLINE=1 \
    TRANSFORMERS_OFFLINE=1 \
    HF_HUB_DISABLE_TELEMETRY=1

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        ca-certificates \
        gettext-base \
        libcurl4-openssl-dev \
        libfreetype6-dev \
        libjpeg62-turbo-dev \
        libonig-dev \
        libpng-dev \
        libxml2-dev \
        libzip-dev \
        nginx \
        python3 \
        python3-venv \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" \
        bcmath \
        curl \
        dom \
        exif \
        gd \
        mbstring \
        opcache \
        pdo_mysql \
        xml \
        zip \
    && rm -f /etc/nginx/sites-enabled/default \
    && sed -i 's/^;clear_env = no/clear_env = no/' /usr/local/etc/php-fpm.d/www.conf \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /var/www/html

COPY nlp/requirements-server.txt /tmp/requirements-server.txt
RUN python3 -m venv /opt/venv \
    && /opt/venv/bin/python -m pip install --no-cache-dir -r /tmp/requirements-server.txt \
    && rm /tmp/requirements-server.txt

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY . .
RUN composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader --no-progress \
    && mkdir -p bootstrap/cache \
    && chown -R www-data:www-data bootstrap/cache

COPY --from=frontend /build/public/build ./public/build
COPY docker/nginx.conf.template /etc/nginx/templates/default.conf.template
COPY docker/entrypoint.sh /usr/local/bin/skillsync-entrypoint
COPY docker/php.ini /usr/local/etc/php/conf.d/zz-skillsync.ini

RUN chmod 0755 /usr/local/bin/skillsync-entrypoint

EXPOSE 10000

ENTRYPOINT ["/usr/local/bin/skillsync-entrypoint"]
