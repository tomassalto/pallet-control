#!/bin/sh
set -e

cd pallet-backend

php artisan package:discover --ansi
php artisan config:cache
php artisan route:cache
php artisan migrate --force
php artisan storage:link

if [ "$IMPORT_BULTO" = "true" ]; then
    echo ">>> IMPORT_BULTO=true detectado, corriendo app:import-bulto..."
    php artisan app:import-bulto
    echo ">>> Import completado. Acordate de borrar la variable IMPORT_BULTO en Render."
fi

if [ "$RESET_ON_STARTUP" = "true" ]; then
    echo ">>> RESET_ON_STARTUP=true detectado, corriendo app:reset..."
    php artisan app:reset --confirm
    echo ">>> Reset completado. Acordate de borrar la variable RESET_ON_STARTUP en Render."
fi

PORT=${PORT:-8000} exec supervisord -c /etc/supervisord.conf
