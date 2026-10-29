# Benchmarks — baseline de performance

> Solo medición. Nada acá optimiza código. Correr esto y guardar el resultado
> ANTES de tocar cualquier query es lo que después permite escribir
> "reduje de Xms a Yms (p95)" con evidencia reproducible (`BE-PERF`, `QA-PERF`).

## Los 5 endpoints elegidos y por qué

Identificados por lectura de código (no medidos todavía), ordenados por
profundidad de relaciones cargadas / fragilidad del contrato de eager loading:

| # | Endpoint | Por qué es candidato a N+1 |
|---|---|---|
| 1 | `GET /api/v1/orders/{order}` ([OrderController::show](../pallet-backend/app/Http/Controllers/Api/OrderController.php)) | El endpoint más profundo del backend: `pallets.bases.photos`, `items.bases.pallet`, `tickets.photos` (7 rutas de eager load) + 2 queries extra de `PendingItem`. Además depende de que `OrderService::enrichItemsWithLocations()` reciba exactamente `items.bases.pallet` precargado — el docblock del método lo advierte, pero es un contrato manual, no forzado por tipos. Cualquier caller nuevo que no siga esa receta dispara N+1 fila por fila. |
| 2 | `GET /api/v1/public/orders/{token}` ([PublicOrderController::show](../pallet-backend/app/Http/Controllers/Api/PublicOrderController.php)) | Misma profundidad que (1) más `pallets.photos` y `pallets.bases.photos`. Es público mediante capacidad opaca y tiene rate limit; el test R-06 fija 6 queries con 20 ítems. En k6 sólo los `200` alimentan latencia y los `429` verifican el límite. |
| 3 | `GET /api/v1/pallets/{pallet}` ([PalletController::show](../pallet-backend/app/Http/Controllers/Api/PalletController.php)) | Misma familia de riesgo que (1) pero del lado del pallet: `orders.items`, `bases.photos`, `bases.orderItems`, con dos `foreach` en PHP sobre esas colecciones para enriquecer con info de producto. |
| 4 | `GET /api/v1/orders` ([OrderController::index](../pallet-backend/app/Http/Controllers/Api/OrderController.php)) | Es la versión *listado* de (1): la misma complejidad de relaciones, multiplicada por hasta 20 filas por página. Ya usa eager loading y subqueries correlacionadas, pero es justamente donde un solo `with()` olvidado se convierte en 20-100 queries extra en vez de 1. |
| 5 | `POST /api/v1/telegram/webhook` (`TelegramBotController::handlePhotoWithoutCaption` / `sendMainMenu` / `sendRecentItems`) | Es el endpoint que más se ejecuta en la práctica — cada foto o `/menu` que manda un operario del depósito dispara una de estas queries con `Pallet::with([...])->limit(3-5)->get()` y varios `flatMap()->sum()` en PHP sobre las relaciones. Hoy están eager-loaded, pero es el lugar con más probabilidad de que alguien agregue un campo nuevo (`$order->customer->email`, por ejemplo) sin actualizar el `with()`. |

**Por qué el #5 no está en el script de k6:** no es un GET idempotente — requiere
un `Update` de Telegram con forma específica, el header secreto
`X-Telegram-Bot-Api-Secret-Token`, y varias de sus ramas **escriben en la DB**
(crean una base nueva, mueven una cache key) cada vez que se llaman. Reproducirlo
con carga real ensuciaría la base con "Base N" fantasma en cada corrida y no
representa el patrón real de uso (1 mensaje por operario, no 20 VUs en loop).
Para medir esa ruta, mejor: activar Debugbar en local y mandar un par de fotos
reales por Telegram mirando el conteo de queries, o un test de Feature que
cuente queries con `DB::listen()`. Si más adelante hace falta carga real ahí,
armamos un script aparte que solo pegue a las ramas de solo-lectura
(`sendMainMenu`, `sendRecentItems`) sin el flujo de upload.

## Requisitos

1. **k6** instalado (no está instalado en esta máquina — instalar antes de correr):
   - Windows: `choco install k6` o descargar el binario de https://k6.io/docs/get-started/installation/
   - El proyecto no lo trae como dependencia porque es una herramienta externa de carga, no de la app.
2. Backend corriendo y accesible (local: `php artisan serve`, o la URL de Render).
3. Un usuario con `role` asignado (no `null`) para autenticar contra `/auth/login`.
4. Datos de prueba realistas — **esto es crítico**: en una DB casi vacía, hasta
   un endpoint con N+1 real da p95 bajo porque no hay nada que iterar. Para que
   el número signifique algo, el pedido/pallet usados como `ORDER_ID` /
   `PALLET_ID` tienen que tener varios pallets, varias bases por pallet, y
   varias fotos por base (5-10 de cada uno, no 1).

## Cómo correrlo

```bash
BASE_URL=http://localhost:8000 \
AUTH_EMAIL=admin@ejemplo.com \
AUTH_PASSWORD=tu-password \
ORDER_ID=42 \
ORDER_PUBLIC_TOKEN=token-opaco-de-48-caracteres \
PALLET_ID=17 \
k6 run --vus 20 --duration 30s benchmarks/k6-hot-endpoints.js
```

Variables:

| Variable | Requerida | Descripción |
|---|---|---|
| `BASE_URL` | no (default `http://localhost:8000`) | Origen del backend |
| `AUTH_EMAIL` / `AUTH_PASSWORD` | sí | Credenciales de un usuario con rol — el script hace login en `setup()` y reusa el token |
| `ORDER_ID` | sí | Id numérico de un pedido con datos reales para `/orders/{id}` |
| `ORDER_PUBLIC_TOKEN` | sí | Token opaco del mismo pedido, visible en `GET /orders/{id}`, para `/public/orders/{token}` |
| `PALLET_ID` | sí | Id numérico de un pallet con datos reales |
| `--vus` / `--duration` | no | Usuarios concurrentes / duración — 20 VUs por 30s es un piso razonable, no un tope |

**Cómo encontrar IDs con datos reales** (vía `php artisan tinker` en `pallet-backend/`):

```php
// Pedido con más pallets asociados
Order::withCount('pallets')->orderByDesc('pallets_count')->first(['id', 'code']);

// Pallet con más bases
Pallet::withCount('bases')->orderByDesc('bases_count')->first('id');
```

## Guardar el baseline

```bash
k6 run --vus 20 --duration 30s benchmarks/k6-hot-endpoints.js \
  | tee benchmarks/results/$(date +%Y-%m-%d)-baseline.txt
```

Guardar el `.txt` de salida en `benchmarks/results/` con fecha en el nombre.
Eso es la evidencia — sin el archivo en el repo, "antes: 3.2s / después: 480ms"
es una afirmación no verificable (`BE-PERF`, `QA-PERF`).

## Qué mirar en la salida de k6

- **`p(95)` por cada `endpoint_*`** (no el agregado general) — es el número que
  importa, no el promedio (`QA-PERF`).
- Con Debugbar activo en local (`APP_DEBUG=true`, ya instalado —
  `barryvdh/laravel-debugbar`), abrir cada uno de los 4 endpoints una vez en el
  navegador y anotar la **cantidad de queries** que muestra la barra inferior.
  Si algún endpoint pasa de ~10 queries para una sola respuesta, ahí está el N+1
  (`BE-PERF` — suele ser el primer lugar a inspeccionar).
- `Model::preventLazyLoading()` ya está activo en local
  (`app/Providers/AppServiceProvider.php`) — si hay un N+1 real, **tira una
  excepción al navegar la SPA en desarrollo**, no hace falta ni mirar Debugbar
  para encontrarlo.

## Qué NO hacer todavía

No tocar ninguna query, índice ni relación a partir de este baseline sin que
el número esté guardado en `benchmarks/results/`. Ese es el orden que pide
`BE-PERF`: medir → perfilar → hipótesis → **recién ahí** arreglar.
