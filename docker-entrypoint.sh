#!/bin/sh
set -e

. /deploy-env.sh

cd pallet-backend

php artisan package:discover --ansi
php artisan config:clear

# Render puede conservar comillas literales si la variable se cargó como texto.
# Esto evita que Laravel ignore una DATABASE_URL válida por venir entre comillas.
DATABASE_URL=${DATABASE_URL%\"}
DATABASE_URL=${DATABASE_URL#\"}
DB_URL=${DB_URL%\"}
DB_URL=${DB_URL#\"}
export DATABASE_URL DB_URL

php -r '
$url = getenv("DB_URL") ?: getenv("DATABASE_URL") ?: "";
if ($url === "") {
    echo ">>> DATABASE_URL/DB_URL: missing\n";
    exit;
}
$parts = parse_url($url);
$host = $parts["host"] ?? "unparseable";
$db = isset($parts["path"]) ? ltrim($parts["path"], "/") : "";
$scheme = $parts["scheme"] ?? "unknown";
echo ">>> DB target: {$scheme}://{$host}/{$db}\n";
'

php artisan config:cache
php artisan route:cache
php artisan migrate --force
php artisan storage:link

if [ "$IMPORT_BULTO" = "true" ]; then
    echo ">>> IMPORT_BULTO=true detectado, corriendo app:import-bulto..."
    php artisan app:import-bulto
    echo ">>> Import completado. Acordate de borrar la variable IMPORT_BULTO en Render."
fi

PORT=${PORT:-8000} exec supervisord -c /etc/supervisord.conf
