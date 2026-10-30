# ── Stage 1: Build del frontend ────────────────────────────────────────
FROM node:22-alpine@sha256:fe4f6d74e20370acb04ad889077d14d0f64a9c8ea6a42bc60cddf1e844699914 AS frontend-build
WORKDIR /app

# Instalar dependencias primero (mejor cache de capas)
COPY pallet-frontend/package.json pallet-frontend/package-lock.json ./pallet-frontend/
RUN cd pallet-frontend && npm ci

# Copiar fuentes y compilar (output → pallet-backend/public/app/)
COPY pallet-frontend/ ./pallet-frontend/
RUN mkdir -p pallet-backend/public/app && \
    cd pallet-frontend && npm run build

# ── Stage 2: Base PHP compartida ───────────────────────────────────────
FROM php:8.4-cli-alpine@sha256:d8784155c4d37986be9ddd7a8950df7e78998cc48e35472cd69e2b9371424a65 AS php-base

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

# PHP upload/memory limits
RUN { \
    echo "upload_max_filesize = 50M"; \
    echo "post_max_size = 55M"; \
    echo "memory_limit = 256M"; \
    echo "max_execution_time = 120"; \
    echo "max_input_time = 120"; \
} > /usr/local/etc/php/conf.d/uploads.ini

WORKDIR /app

# ── Stage 3: Dependencias PHP ──────────────────────────────────────────
FROM php-base AS vendor-build
COPY --from=composer:2@sha256:c7f68260503e3c1f0ece10bdbe84519fd98d48ba091f2c02f16ea94a7faae337 /usr/bin/composer /usr/bin/composer
COPY pallet-backend/composer.json pallet-backend/composer.lock ./pallet-backend/
ENV COMPOSER_MEMORY_LIMIT=-1
RUN cd pallet-backend && composer install --no-dev --no-scripts --no-autoloader

COPY pallet-backend/ ./pallet-backend/
RUN cd pallet-backend && composer dump-autoload --no-scripts --optimize

# ── Stage 4: Runtime PHP sin Composer ──────────────────────────────────
FROM php-base AS runtime

# Copiar el backend y las dependencias ya resueltas desde el stage aislado.
COPY pallet-backend/ ./pallet-backend/
COPY --from=vendor-build /app/pallet-backend/vendor/ ./pallet-backend/vendor/

# Copiar el frontend ya compilado desde Stage 1
COPY --from=frontend-build /app/pallet-backend/public/app/ ./pallet-backend/public/app/

# Permisos de runtime: cache, logs, uploads y enlace de storage.
RUN chown -R www-data:www-data \
    pallet-backend/storage \
    pallet-backend/bootstrap/cache \
    pallet-backend/public

# Entrypoint y supervisor
COPY supervisord.conf /etc/supervisord.conf
COPY deploy-env.sh /deploy-env.sh
COPY docker-entrypoint.sh /docker-entrypoint.sh
RUN chmod +x /deploy-env.sh /docker-entrypoint.sh

USER www-data

EXPOSE 8000

HEALTHCHECK --interval=30s --timeout=5s --start-period=30s --retries=3 \
    CMD curl --fail --silent --show-error "http://127.0.0.1:${PORT:-8000}/ready" || exit 1

# Para resetear producción: setear RESET_ON_STARTUP=true en Render → deploy → borrar la variable → deploy.
CMD ["/docker-entrypoint.sh"]
