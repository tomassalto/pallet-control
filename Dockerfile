FROM php:8.3-cli-alpine

# Dependencias del sistema
RUN apk add --no-cache \
    git curl zip unzip \
    libpng-dev oniguruma-dev libxml2-dev \
    freetype-dev libjpeg-turbo-dev \
    nodejs npm

# Extensiones PHP
RUN docker-php-ext-configure gd --with-freetype --with-jpeg && \
    docker-php-ext-install pdo pdo_mysql pdo_sqlite mbstring exif bcmath gd intl pcntl

# Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

# Copiar todo el proyecto
COPY . .

# Build del frontend (sale a pallet-backend/public/app/)
RUN cd pallet-frontend && npm ci && npm run build

# Dependencias PHP (sin dev)
RUN cd pallet-backend && \
    composer install --no-dev --optimize-autoloader && \
    php artisan config:cache && \
    php artisan route:cache

# Permisos de storage
RUN chown -R www-data:www-data pallet-backend/storage pallet-backend/bootstrap/cache

EXPOSE 8000

CMD sh -c "cd pallet-backend && php artisan migrate --force && php artisan storage:link && php artisan serve --host=0.0.0.0 --port=${PORT:-8000}"
