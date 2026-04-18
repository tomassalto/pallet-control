FROM php:8.3-cli-alpine

# Dependencias del sistema + extensiones PHP en un solo layer
RUN apk add --no-cache \
    git curl zip unzip \
    libpng-dev oniguruma-dev libxml2-dev \
    freetype-dev libjpeg-turbo-dev \
    postgresql-dev \
    nodejs npm && \
    docker-php-ext-configure gd --with-freetype --with-jpeg && \
    docker-php-ext-install pdo pdo_pgsql mbstring exif bcmath gd intl pcntl

# Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

# Copiar todo el proyecto
COPY . .

# Build del frontend (sale a pallet-backend/public/app/)
RUN cd pallet-frontend && npm ci && npm run build

# Dependencias PHP (sin dev)
RUN cd pallet-backend && composer install --no-dev --optimize-autoloader

# Permisos de storage
RUN chown -R www-data:www-data pallet-backend/storage pallet-backend/bootstrap/cache

EXPOSE 8000

# config:cache y route:cache se corren al iniciar (necesitan las env vars de Render)
CMD sh -c "cd pallet-backend && php artisan config:cache && php artisan route:cache && php artisan migrate --force && php artisan storage:link && php artisan serve --host=0.0.0.0 --port=${PORT:-8000}"
