# Scraper de imágenes — comerciante.carrefour.com.ar

Lee el `listado.csv`, hace login al sitio de Carrefour para comerciantes,
extrae la URL de imagen de cada producto por EAN y la guarda en la base de datos.

## Setup (solo la primera vez)

```bash
cd scraper
npm install
npx playwright install chromium
cp .env.example .env
# Editar .env con tus credenciales
```

## Uso

```bash
node scrape.js
```

El script guarda progreso en `progress.json` — si lo interrumpís, al volver
a correr saltea los EANs ya procesados automáticamente.

## Si el login falla

1. Cambiar `headless: true` → `headless: false` en `scrape.js` para ver el browser
2. El screenshot se guarda en `login-debug.png`
3. Buscar los selectores correctos del formulario en DevTools y actualizar la
   función `login()` en `scrape.js`

## Variables de entorno (.env)

| Variable | Descripción |
|---|---|
| `CARREFOUR_EMAIL` | Tu email de acceso |
| `CARREFOUR_DNI` | DNI del comerciante |
| `CARREFOUR_PHONE` | Teléfono registrado |
| `CARREFOUR_NAME` | Nombre registrado |
| `API_BASE_URL` | URL base del backend (ej: `https://tu-app.onrender.com/api/v1`) |
| `BOT_SECRET` | El mismo valor que `BOT_SECRET` en Render |
| `DELAY_MS` | Pausa entre requests en ms (default: 1500) |
| `BATCH_SIZE` | Productos por batch a la API (default: 20) |
