# ── Stage 1: Build del frontend ────────────────────────────────────────
FROM node:22-alpine AS frontend-build
WORKDIR /app

# Instalar dependencias primero (mejor cache de capas)
COPY pallet-frontend/package.json pallet-frontend/package-lock.json ./pallet-frontend/
RUN cd pallet-frontend && npm ci

# Copiar fuentes y compilar (output → pallet-backend/public/app/)
COPY pallet-frontend/ ./pallet-frontend/
RUN mkdir -p pallet-backend/public/app && \
    cd pallet-frontend && npm run build

# ── Stage 2: Runtime PHP ───────────────────────────────────────────────
FROM php:8.4-cli-alpine

# Dependencias del sistema + extensiones PHP (sin nodejs ni npm)
RUN apk add --no-cache \
    git curl zip unzip \
    libpng-dev oniguruma-dev libxml2-dev \
    freetype-dev libjpeg-turbo-dev libwebp-dev \
    postgresql-dev \
    tesseract-ocr tesseract-ocr-data-eng \
    supervisor && \
    docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp && \
    docker-php-ext-install pdo pdo_pgsql mbstring exif bcmath gd intl pcntl

# Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

# Dependencias PHP — copiar solo lo necesario para composer install primero
COPY pallet-backend/composer.json pallet-backend/composer.lock ./pallet-backend/
ENV COMPOSER_MEMORY_LIMIT=-1
RUN cd pallet-backend && composer install --no-dev --no-scripts --no-autoloader

# Copiar el resto del backend y optimizar autoloader
COPY pallet-backend/ ./pallet-backend/
RUN cd pallet-backend && composer dump-autoload --no-scripts --optimize

# Copiar el frontend ya compilado desde Stage 1
COPY --from=frontend-build /app/pallet-backend/public/app/ ./pallet-backend/public/app/

# Permisos de storage
RUN chown -R www-data:www-data pallet-backend/storage pallet-backend/bootstrap/cache

# Entrypoint y supervisor
COPY supervisord.conf /etc/supervisord.conf
COPY docker-entrypoint.sh /docker-entrypoint.sh
RUN chmod +x /docker-entrypoint.sh

EXPOSE 8000

# Para resetear producción: setear RESET_ON_STARTUP=true en Render → deploy → borrar la variable → deploy.
CMD ["/docker-entrypoint.sh"]
