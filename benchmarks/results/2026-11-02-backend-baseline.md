# Baseline backend — endpoints candidatos a N+1 / costos crecientes

**Fecha:** 2026-11-02
**Rama:** `performance/backend-baseline`
**Ruta normativa:** `BE-PERF`, `QA-PERF`, `DO-OBS`
**Alcance:** sólo medición. No se modificó código funcional; la única
instrumentación es un test de benchmark nuevo
(`tests/Feature/Performance/HotEndpointsBenchmarkTest.php`).

## Entorno

| Variable | Valor |
|---|---|
| Máquina | Windows local de desarrollo (Windows 11 10.0.26300) |
| CPU | Intel Core i3-12100F (4C/8T) |
| RAM | 32 GB |
| PHP | 8.4.27 NTS (VS17 x64), CLI |
| Framework | Laravel 12.70.2 |
| DB | SQLite `:memory:` (entorno de test) |
| Queue / Cache | `sync` / `array` (`phpunit.xml`) |
| HTTP externo | `Http::fake()` — la respuesta a Telegram no viaja a la red |
| Iteraciones | 1 warmup + 30 medidas por endpoint |
| Concurrencia | 0 — requests secuenciales dentro del proceso de test |

> Las latencias medidas **no representan producción** (`QA-PERF`: "localhost
> como producción" es antipatrón). SQLite en memoria sin contención, sin
> red y con el framework ya caliente es el mejor caso posible. Sirven para
> comparar antes/después en este mismo entorno, no como SLO.

## Metodología

- Test: `pallet-backend/tests/Feature/Performance/HotEndpointsBenchmarkTest.php`
- Comando: `RUN_PERFORMANCE_BENCHMARKS=1 php artisan test --filter HotEndpointsBenchmarkTest`
  (script: `benchmarks/run-backend-baseline.ps1`)
- Por endpoint: 1 request de calentamiento + 30 iteraciones medidas con
  `hrtime`, ordenadas para p50/p95/p99; una pasada extra instrumentada con
  `DB::listen()` para conteo de queries, `strlen(content)` para payload y
  `memory_get_usage()` delta + `memory_get_peak_usage()` para memoria.
- `Model::preventLazyLoading()` activo en tests: cualquier N+1 real habría
  lanzado excepción durante la corrida — ninguna se produjo.
- `GET /public/orders/{token}` limita 20 req/min por IP+recurso; el limiter
  se limpia entre iteraciones (`Cache::flush()` en store `array`) para
  medir 30 respuestas 200 reales. El control sigue activo en cada request.
- El webhook de Telegram se midió sólo por su rama de sólo lectura
  (`/vermas`, la de mayor profundidad que no escribe filas de negocio);
  las ramas de upload crean datos y ya fueron excluidas por diseño
  (`benchmarks/README.md`).

## Dataset reproducible (fijo en tamaño)

20 pedidos; por pedido: 12 ítems (qty 50), 3 pallets con 2 fotos c/u,
3 bases por pallet con 3 fotos c/u, 9 asignaciones `pallet_base_order_items`
(qty 5), 2 tickets con 3 fotos c/u. Pedido "caliente": 4 pending items.

Totales: 20 orders · 240 items · 60 pallets · 120 fotos de pallet ·
180 bases · 540 fotos de base · 180 asignaciones · 40 tickets ·
120 fotos de ticket · 4 pendientes.

## Resultados

| Endpoint | Queries | Payload | p50 | p95 | p99 | Mem. delta | Mem. pico |
|---|---:|---:|---:|---:|---:|---:|---:|
| `GET /orders?limit=20` | 7 | 55.380 B | 69,0 ms | 80,5 ms | 84,0 ms | 840 KB | 50 MB |
| `GET /orders/{id}` | 11 | 27.304 B | 45,0 ms | 65,8 ms | 66,3 ms | 238 KB | 56 MB |
| `GET /public/orders/{token}` | 11 | 5.101 B | 18,7 ms | 26,8 ms | 27,1 ms | 91 KB | 56 MB |
| `GET /pallets/{id}` | 8 | 17.439 B | 25,4 ms | 35,1 ms | 35,7 ms | 127 KB | 56 MB |
| `POST /telegram/webhook` (`/vermas`) | 9 | 11 B | 20,2 ms | 49,6 ms | 50,9 ms | 38 KB | 58 MB |

Errores: 0 en las 150 iteraciones. Violaciones de lazy loading: 0.

`memory_peak_mb` es el pico acumulado del proceso de test, no por request;
se reporta porque es reproducible, pero no comparar por endpoint.

## Observaciones

- **No hay N+1 medible hoy** en ninguno de los cinco caminos: los conteos
  de queries son constantes para este dataset y `preventLazyLoading`
  no disparó excepciones. El riesgo documentado en `benchmarks/README.md`
  sigue siendo contractual (un `with()` olvidado en un caller futuro), no
  un defecto actual.
- `GET /orders?limit=20` es el camino más caro medido (mayor p50/p95 y
  payload): combina 20 filas × eager loading + 5 subqueries
  correlacionadas por fila. Es el hot path recomendado para la siguiente
  rama de perfilado.
- `GET /public/orders/{token}` da 11 queries aquí vs. las 6 registradas
  en `2026-10-29-r06-public-order.md`. La diferencia es de dataset: la
  medición R-06 no cargaba pallets/bases/fotos/productos. No se clasifica
  como regresión sin re-medir sobre el mismo dataset; queda como
  observación a verificar si aparece otra señal.
- `POST /telegram/webhook` excluye el costo de red a Telegram
  (`Http::fake()`); el payload de 11 B es `{"ok":true}` del webhook, no el
  mensaje enviado. Su latencia real productiva incluye la API de Telegram.

## Budgets locales (regresión, no SLO)

Piso para detectar regresiones en este mismo entorno/dataset. Los límites
de latencia equivalen al umbral ya usado por el script k6 (`p(95)<2000`),
los de queries al conteo observado +1 de margen:

| Endpoint | Queries máx | p95 máx (local) |
|---|---:|---:|
| `GET /orders?limit=20` | 8 | 2000 ms |
| `GET /orders/{id}` | 12 | 2000 ms |
| `GET /public/orders/{token}` | 12 | 2000 ms |
| `GET /pallets/{id}` | 9 | 2000 ms |
| `POST /telegram/webhook` (`/vermas`) | 10 | 2000 ms |

## Limitaciones

- SQLite `:memory:`, proceso único, sin concurrencia: no ejercita locks,
  conexiones ni latencia de red — separado deliberadamente de PostgreSQL.
- Para PostgreSQL repetir el mismo test con `DB_CONNECTION=pgsql` apuntando
  a una instancia de staging y guardar el resultado como archivo aparte;
  nunca mezclar ambos en la misma tabla de comparación.
- k6 no está instalado en esta máquina; el script
  `benchmarks/k6-hot-endpoints.js` sigue siendo la vía para medir concurrencia
  real contra un servidor levantado (README del directorio).
- Factores Faker (descripciones, EANs) varían por corrida; los tamaños de
  dataset son fijos y es lo que hace comparables las corridas.

## Recomendación de siguiente hot path

`GET /api/v1/orders` (listado): mayor latencia y payload del baseline,
5 subqueries correlacionadas por fila y shape que escala con la cantidad
de pedidos. Siguiente bloque: perfilar el plan de queries (EXPLAIN sobre
PostgreSQL) y evaluar índices/pre-agregación — con este baseline guardado
como el "antes".
