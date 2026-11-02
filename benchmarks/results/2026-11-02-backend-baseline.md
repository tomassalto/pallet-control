# Baseline backend — endpoints candidatos a N+1 / costos crecientes

**Fecha:** 2026-11-02 (corregido el mismo día tras revisión)
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
| Iteraciones | 1 warmup + 30 medidas por endpoint y por pasada |
| Concurrencia | 0 — requests secuenciales dentro del proceso de test |
| Corridas documentadas | 3 (idénticas en queries y payload; latencias como rango) |

> Las latencias medidas **no representan producción** (`QA-PERF`: "localhost
> como producción" es antipatrón). SQLite en memoria sin contención, sin
> red y con el framework ya caliente es el mejor caso posible. Sirven para
> comparar antes/después en este mismo entorno, no como SLO.

## Metodología

- Test: `pallet-backend/tests/Feature/Performance/HotEndpointsBenchmarkTest.php`
- Comando: `RUN_PERFORMANCE_BENCHMARKS=1 php artisan test --filter HotEndpointsBenchmarkTest`
  (script: `benchmarks/run-backend-baseline.ps1`)
- Instrumentación uniforme para los cinco endpoints: `DB::flushQueryLog()` +
  `DB::enableQueryLog()` habilitado sólo durante la request instrumentada;
  sin `DB::listen()` (nada que se acumule entre endpoints).
- Por endpoint: 1 request de calentamiento + 30 iteraciones medidas con
  `hrtime`, ordenadas para p50/p95/p99; una pasada instrumentada para
  conteo de queries, `strlen(content)` para payload y
  `memory_get_usage()` delta + `memory_get_peak_usage()` para memoria.
- `fake()->seed(20261102)` al inicio de cada pasada: los payloads son
  idénticos byte a byte entre corridas (verificado: mismo `payload_bytes`
  en las tres).
- `GET /public/orders/{token}` limita 20 req/min por IP+recurso; el limiter
  se limpia entre iteraciones (`Cache::flush()` en store `array`) para
  medir 30 respuestas 200 reales. El control sigue activo en cada request.
- El webhook de Telegram se midió sólo por su rama de sólo lectura
  (`/vermas`, la de mayor profundidad que no escribe filas de negocio).

## Verificación anti-N+1: dos cardinalidades

Cada endpoint se midió dos veces dentro del mismo test: una pasada con
dataset chico y una con dataset grande. El test **afirma** que el conteo
de queries no crece entre ambas (`assertSame` por endpoint) — esa es la
única conclusión sobre N+1 que este benchmark puede sostener, y queda
limitada a estas cardinalidades y este shape de datos. Nada se afirma de
datasets, filtros ni cardinalidades no medidas.

| Dataset | Pedidos | Ítems/pedido | Pallets/pedido | Bases/pallet | Fotos/base | Fotos/pallet | Tickets/pedido | Fotos/ticket |
|---|---:|---:|---:|---:|---:|---:|---:|---:|
| Chico | 4 | 4 | 1 | 1 | 1 | 1 | 1 | 1 |
| Grande | 20 | 12 | 3 | 3 | 3 | 2 | 2 | 3 |

Totales dataset grande: 20 orders · 240 items · 60 pallets · 120 fotos de
pallet · 180 bases · 540 fotos de base · 180 asignaciones · 40 tickets ·
120 fotos de ticket · 4 pendientes (en el pedido caliente).

## Resultados — conteos de queries (idénticos en ambas cardinalidades)

| Endpoint | Chico | Grande | Budget (assert) |
|---|---:|---:|---:|
| `GET /orders?limit=N` | 7 | 7 | ≤ 8 |
| `GET /orders/{id}` | 11 | 11 | ≤ 12 |
| `GET /public/orders/{token}` | 11 | 11 | ≤ 12 |
| `GET /pallets/{id}` | 8 | 8 | ≤ 9 |
| `POST /telegram/webhook` (`/vermas`) | 9 | 9 | ≤ 10 |

Los budgets son assertions reales del test (`assertLessThanOrEqual`);
budget = conteo observado + 1 de margen para cambios triviales.

## Resultados — dataset grande (rango de 3 corridas)

| Endpoint | Queries | Payload | p50 | p95 | p99 | Mem. delta |
|---|---:|---:|---|---|---|---:|
| `GET /orders?limit=20` | 7 | 55.503 B | 29,3–36,9 ms | 31,9–53,1 ms | 32,8–56,0 ms | 854 KB |
| `GET /orders/{id}` | 11 | 27.423 B | 21,0–23,9 ms | 25,5–29,2 ms | 27,0–33,4 ms | 245 KB |
| `GET /public/orders/{token}` | 11 | 5.111 B | 8,1–9,8 ms | 11,3–16,3 ms | 11,5–19,4 ms | 99 KB |
| `GET /pallets/{id}` | 8 | 17.457 B | 12,0–18,4 ms | 14,1–23,8 ms | 15,4–26,5 ms | 132 KB |
| `POST /telegram/webhook` (`/vermas`) | 9 | 11 B | 12,0 ms | 12,7–15,6 ms | 16,0 ms | 43 KB |

Errores: 0 en las iteraciones medidas. Violaciones de lazy loading: 0
(`Model::preventLazyLoading()` activo durante el test — una violación
habría lanzado excepción en el dataset medido).

Variación observada: las latencias difieren hasta ~1,7× entre corridas
(p. ej. `orders_list` p95 31,9 → 53,1 ms) por ruido del SO/proceso de
test; payloads y queries son idénticos. Por eso la latencia queda como
métrica **observacional**, sin assertion: un umbral estilo `p(95)<2000`
sería un gate inútil contra un baseline de ~30–50 ms.

`memory_peak_mb` es el pico acumulado del proceso de test, no por request.

## Observaciones

- Con estas dos cardinalidades, el conteo de queries es **constante** en
  los cinco caminos — el eager loading actual cubre los datos medidos.
  No se afirma ausencia general de N+1: otro shape (p. ej. un pedido con
  cientos de ítems por base, o filtros no medidos) podría comportarse
  distinto. El riesgo contractual documentado en `benchmarks/README.md`
  sigue vigente.
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

## Limitaciones

- SQLite `:memory:`, proceso único, sin concurrencia: no ejercita locks,
  conexiones ni latencia de red — separado deliberadamente de PostgreSQL.
- Para PostgreSQL repetir el mismo test con `DB_CONNECTION=pgsql` apuntando
  a una instancia de staging y guardar el resultado como archivo aparte;
  nunca mezclar ambos en la misma tabla de comparación.
- k6 no está instalado en esta máquina; el script
  `benchmarks/k6-hot-endpoints.js` sigue siendo la vía para medir concurrencia
  real contra un servidor levantado (README del directorio).
- El dataset chico/grande comparte la misma forma; escalados con shape
  distinto (índices de selectividad diferente, muchos ítems por base) no
  están cubiertos por la afirmación de conteo constante.

## Recomendación de siguiente hot path

`GET /api/v1/orders` (listado): mayor latencia y payload del baseline,
5 subqueries correlacionadas por fila y shape que escala con la cantidad
de pedidos. Siguiente bloque: perfilar el plan de queries (EXPLAIN sobre
PostgreSQL) y evaluar índices/pre-agregación — con este baseline guardado
como el "antes".
