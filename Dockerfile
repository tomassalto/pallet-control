FROM php:8.4-cli-alpine

# Dependencias del sistema + extensiones PHP en un solo layer
RUN apk add --no-cache \
    git curl zip unzip \
    libpng-dev oniguruma-dev libxml2-dev \
    freetype-dev libjpeg-turbo-dev libwebp-dev \
    postgresql-dev \
    tesseract-ocr tesseract-ocr-data-eng \
    supervisor \
    nodejs npm && \
    docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp && \
    docker-php-ext-install pdo pdo_pgsql mbstring exif bcmath gd intl pcntl

# Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

# Copiar todo el proyecto
COPY . .

# Build del frontend (sale a pallet-backend/public/app/)
RUN cd pallet-frontend && npm ci && npm run build

# Dependencias PHP — sin scripts porque artisan necesita .env que no existe en build
ENV COMPOSER_MEMORY_LIMIT=-1
RUN cd pallet-backend && composer install --no-dev --no-scripts --no-autoloader && \
    composer dump-autoload --no-scripts --optimize

# Permisos de storage
RUN chown -R www-data:www-data pallet-backend/storage pallet-backend/bootstrap/cache

COPY supervisord.conf /etc/supervisord.conf

EXPOSE 8000

# Al iniciar: preparar Laravel y arrancar supervisord (web + queue worker)
CMD sh -c "cd pallet-backend && \
    php artisan package:discover --ansi && \
    php artisan config:cache && \
    php artisan route:cache && \
    php artisan migrate --force && \
    php artisan storage:link && \
    PORT=${PORT:-8000} supervisord -c /etc/supervisord.conf"
