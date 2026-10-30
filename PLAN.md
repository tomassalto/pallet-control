# 🗺️ PLAN — Pallet Control

> **Qué es esto:** el backlog vivo. Qué falta, en qué orden, y en qué estado está cada cosa.
> **Se actualiza:** en cada sesión de trabajo. Es lo primero que lee la IA para saber dónde estamos.
> **Referencia a teoría:** con `§`, nunca copiando texto de las biblias. El detalle de cada hallazgo vive en `AUDITORIA.md` (`H-XXX`) — acá solo se prioriza y se ordena el trabajo.

**Última actualización:** 2026-10-29
**Rama integradora:** `modernizacion/base`
**Entrada obligatoria:** `ROUTER.md`
**Trabajo actual:** reset automático eliminado y comando destructivo endurecido; `H-038` requiere acreditar el modo real de Render. Siguiente bloque autónomo: baseline/performance (`H-040`).

**Fase Fundacional:** ✅ cerrada — cinco biblias normativas, gobierno, router estable, dominio y producto. Auditoría: `AUDITORIA-BIBLIAS.md`.

---

## 📍 ESTADO GENERAL

```
Fase 0 — Reconocimiento        ✅ hecho   (AUDITORIA.md — 42 hallazgos, H-001 a H-041 + H-006b)
Fase 1 — Red de seguridad      ✅ hecho (gates activos y caracterización completos)
Fase Fundacional — Normativa  ✅ cinco autoridades y router por IDs estables
Fase 2 — Rebanadas verticales  ✅ 7 de 7 cerradas
Fase 3 — Dominio específico    🔲
Fase 4 — Performance           🔲 (benchmarks/ armado, baseline sin correr — H-040)
Fase 5 — Infraestructura       🔲
Fase 6 — Presentación          🔲
```

---

## 🔥 PRIORIDAD INMEDIATA

_(Estos 3 primero, antes que cualquier rebanada. Los dos primeros no son código — son 5 minutos en el dashboard de Render.)_

1. **Reset productivo seguro** — ✅ `RESET_ON_STARTUP` ya no tiene efecto; sólo existe comando manual confirmado y probado. `H-028`, `H-037`, `D-011`.
2. **Verificar en Render que `FILESYSTEM_DISK=r2`** (no `public`/`local`) — si no, las fotos de pallets/tickets se pierden en el próximo deploy. `H-029`.
3. **Normalizar errores y evitar fugas internas** — ✅ cerrado en `ce48ca3`; contrato `D-008`, handler global y 5xx correlacionables. `H-018`, `H-019`, `H-024`.

---

## 🧭 CÓMO SE PRIORIZÓ

Orden pedido: **riesgo de seguridad > corrupción/pérdida de datos > performance > calidad.**
Dentro de cada categoría, por severidad (`AUDITORIA.md` §3). El detalle de "qué está pasando" y "por qué importa" de cada `H-XXX` está en `AUDITORIA.md` — acá solo esfuerzo, impacto y si se puede automatizar.

**Esfuerzo:** 🟢 bajo (<1h, mecánico) · 🟡 medio (horas, requiere diseño/tests) · 🔴 alto (multi-día, toca varios archivos o cambia comportamiento).
**Automatizable:** ✅ sí (tooling/migración/CI, sin juicio de negocio) · 🟡 parcial (tooling detecta o hace el 80%, un humano decide/cierra el 20%) · ❌ no (requiere diseño y código a mano).

### 🔴 Seguridad

| ID | Hallazgo | Esfuerzo | Impacto | Automatizable |
|---|---|---|---|---|
| H-001 | IDOR/enumeración en `GET /public/orders/{code}` | 🟡 medio | Alto | ❌ no (resuelto mediante decisión D-006) |
| H-019 | `$e->getMessage()` filtrado al cliente en 4 controladores — ✅ resuelto | 🟢 bajo | Medio | 🟡 parcial |
| H-030 | Contenedor corre como root — ✅ resuelto | 🟢 bajo | Alto | ✅ sí |
| H-032 | `pallet-frontend/.env` trackeado en git — ✅ resuelto | 🟢 bajo | Medio | ✅ sí |
| H-002 | Webhook Telegram falla abierto sin secret | 🟢 bajo | Medio | ✅ sí |
| H-003 | Desactivar usuario no revoca tokens | 🟡 medio | Medio | ❌ no |
| H-025 | `APP_DEBUG` sin safeguard en el deploy — ✅ resuelto | 🟢 bajo | Medio | ✅ sí |
| H-004 | Rutas de verificación de email rotas | 🟢 bajo | Bajo | ✅ sí |

### 🔴 Integridad / pérdida de datos

| ID | Hallazgo | Esfuerzo | Impacto | Automatizable |
|---|---|---|---|---|
| H-028 | `RESET_ON_STARTUP` puede borrar toda la DB — ✅ resuelto estructuralmente | 🟢 bajo (verificar) / 🟡 medio (rediseñar el safeguard) | **Altísimo** | ✅ verificación / ❌ rediseño |
| H-029 | Fotos sin persistencia garantizada en redeploy | 🟢 bajo (verificar) / 🟡 medio (warning en boot) | **Altísimo** | 🟡 parcial |
| H-005 | Race condition en `createBase`/`updateBase` | 🔴 alto | Alto | ❌ no |
| H-006 | Sin CHECK constraint "asignado ≤ pedido" | 🟡 medio | Alto | 🟡 parcial |
| H-006b | `adjustItem` sin techo de validación | 🟡 medio | Alto | ❌ no |
| H-007 | `migrate` lee stock antes de abrir la transacción | 🟡 medio | Alto | ❌ no |
| H-008 | Race duplicando EAN en `order_items` | 🟡 medio | Alto | ✅ sí |
| H-009 | Cero soft deletes en todo el esquema | 🔴 alto | Alto | 🟡 parcial |
| H-010 | Reducir `qty` sin validar lo ya asignado | 🟡 medio | Medio | ✅ sí |
| H-011 | Escrituras multi-tabla sin transacción | 🟡 medio | Medio | 🟡 parcial |
| H-012 | FKs sin índice (Postgres) | 🟢 bajo | Medio | ✅ sí |
| H-013 | `activity_logs.pallet_id` no es FK real | 🟢 bajo | Bajo | ✅ sí |
| H-015 | Sin CHECK constraints generales | 🟡 medio | Bajo | 🟡 parcial |
| H-017 | `ActivityLogger::log()` no atómico | 🟢 bajo | Bajo | ✅ sí (efecto colateral de H-011) |

### 🟡 Performance

| ID | Hallazgo | Esfuerzo | Impacto | Automatizable |
|---|---|---|---|---|
| H-040 | `PendingItemController::index` sin paginación | 🟢 bajo | Medio | ✅ sí |
| — | Baseline k6 de los 5 endpoints candidatos a N+1 | 🟢 bajo (correr el script ya armado) | — (es medición, no fix) | ✅ sí |

### ⚪ Calidad

| ID | Hallazgo | Esfuerzo | Impacto | Automatizable |
|---|---|---|---|---|
| H-039 | Prácticamente sin tests | 🔴 alto | Alto | 🟡 parcial (scaffolding sí, tests de negocio no) |
| H-018 | Sin handler global de excepciones — ✅ resuelto | 🟡 medio | Medio | ❌ no |
| H-031 | Sin CI de ningún tipo | 🟢 bajo | Alto | ✅ sí |
| H-020 | 16 controladores validan inline | 🔴 alto (son 16 archivos) | Bajo | 🟡 parcial |
| H-021 / H-022 / H-023 / H-027 | Códigos HTTP inconsistentes (401/403/409 vs 422/400) | 🟢 bajo c/u | Bajo | 🟡 parcial (CI puede flaggear, no decidir) |
| H-024 | Formato de error no estandarizado — ✅ resuelto | 🟡 medio (depende de H-018) | Bajo | ❌ no |
| H-041 | Larastan/PHPStan no instalado | 🟢 bajo | Medio | ✅ sí |
| H-033 / H-034 / H-035 / H-036 | Docker: Composer, contexto, digests y healthcheck — ✅ resueltos | 🟢 bajo c/u | Bajo | ✅ sí |
| H-004 / H-014 / H-016 / H-026 / H-037 / H-038 | Varios ⚪ (rutas rotas, columna muerta, índice duplicado, etc.) | 🟢 bajo c/u | Bajo | ✅ sí (salvo H-038, es decisión) |

---

## 📦 REBANADAS VERTICALES

> Protocolo de 8 puntos por endpoint, gobernado por `ROUTER.md` y `QA-GATE`. Un endpoint no se cierra hasta tener los 8 en verde.
> **Orden: empezando por los endpoints de escritura de stock** (la tabla real de stock es `pallet_base_order_items`, no un endpoint "movimientos" — este proyecto no tiene ese endpoint genérico, tiene estos 6).

### R-01 · `PATCH /pallets/{pallet}/bases/{base}/adjust-item`
```
✅ 1. Auth                  → auth:sanctum + has.role; 401 acreditado
✅ 2. Validación            → pertenencia y techo global dentro de la transacción
✅ 3. Negocio (tx + lock)   → lockForUpdate sobre order_items + locks pivot ordenados
🟡 4. Datos                 → invariante agregada protegida por lock; defensa DB transversal sigue en H-006
✅ 5. Respuesta (HTTP)      → conflicto de estado devuelve 409 con available/requested
✅ 6. Audit log             → misma transacción; rollback forzado acreditado
✅ 7. Tests                 → SQLite verde y concurrencia PostgreSQL verde en Actions run `40826632779`
✅ 8. Medición              → 9 queries constantes con 20 bases; `benchmarks/results/2026-10-29-r01-adjust-item.md`
```
**Estado:** ✅ cerrada. CI general y PostgreSQL concurrente verdes en Actions run `40827127268` sobre `78786b1`.
**Por qué primero:** es el endpoint con el gap más simple de cerrar (una sola escritura, sin la complejidad de `sync()` de los otros) y establece el patrón (lock + transacción + constraint) que R-02 y R-03 van a reusar.
**Hallazgos que resuelve:** H-006b, H-005 (patrón), H-006 (si se agrega constraint acá), H-023, H-017.

---

### R-02 · `POST /pallets/{pallet}/bases` + `PATCH /pallets/{pallet}/bases/{base}` (con items)
```
✅ 1. Auth                  → has.role preservado; ownership base/pallet devuelve 404
✅ 2. Validación            → Form Request rechaza items duplicados; pertenencia y techo se validan bajo lock
✅ 3. Negocio (tx + lock)   → create/update atómicos; order_items y pivots se bloquean en orden D-003
✅ 4. Datos                 → techo global entre pallets protegido; defensa DB transversal sigue en H-006
✅ 5. Respuesta (HTTP)      → conflictos de pertenencia, estado y disponibilidad devuelven 409
✅ 6. Audit log             → confirma dentro de la transacción; rollback forzado acreditado en create/update
✅ 7. Tests                 → 57 tests/135 assertions locales; PostgreSQL concurrente verde
✅ 8. Medición              → 14 queries constantes con 20 bases; `benchmarks/results/2026-10-29-r02-create-base.md`
```
**Nota:** se agrupan los dos endpoints porque comparten `PalletBaseService` — separarlos no reduce el riesgo, la corrección es una sola.
**Estado:** ✅ cerrada en `87d6a4b`; jobs `ci` y `postgres-concurrency` verdes en Actions run `40919324208`.
**Hallazgos que resuelve:** H-005, H-006, H-011 (parcial).

---

### R-03 · `POST /pallets/{pallet}/bases/{base}/migrate`
```
✅ 1. Auth                  → auth:sanctum + has.role; 401 y pertenencia origen acreditados
✅ 2. Validación            → items distintos y coherencia pallet/base destino
✅ 3. Negocio (tx + lock)   → pallets, bases, order_items y pivots bloqueados en orden determinista
✅ 4. Datos                 → débito, crédito y asociación del pedido confirman como una operación
✅ 5. Respuesta (HTTP)      → conflictos de estado, destino y stock devuelven 409
✅ 6. Audit log             → misma transacción; rollback forzado acreditado
✅ 7. Tests                 → 65 tests/165 assertions locales; PostgreSQL concurrente verde
✅ 8. Medición              → 15 queries constantes con 20 bases; `benchmarks/results/2026-10-29-r03-migrate-base.md`
```
**Estado:** ✅ cerrada en `28e6cf6`; jobs `ci` y `postgres-concurrency` verdes en Actions run `40922987827`.
**Hallazgos que resuelve:** H-007.

---

### R-04 · `POST /orders/{order}/items`
```
✅ 1. Auth                  → auth:sanctum + has.role; 401 acreditado
✅ 2. Validación            → `StoreOrderItemRequest`; EAN y últimos cuatro numéricos
✅ 3. Negocio (tx + lock)   → pedido bloqueado; alta y auditoría en una transacción
✅ 4. Datos                 → `UNIQUE(order_id, ean)` reversible y con preflight no destructivo
✅ 5. Respuesta (HTTP)      → duplicado devuelve 409 con el item existente
✅ 6. Audit log             → misma transacción; rollback forzado acreditado
✅ 7. Tests                 → 71 tests/177 assertions; concurrencia PostgreSQL `201/409` acreditada
✅ 8. Medición              → 7 queries constantes con 20 items; `benchmarks/results/2026-10-29-r04-add-order-item.md`
```
**Estado:** ✅ cerrada en `82af27c`; Actions run `40929456582` con `ci` y `postgres-concurrency` verdes. El commit `2ffc4de` agregó reintento acotado solo para fallos operativos de Packagist.
**Hallazgos que resuelve:** H-008, H-020 (parcial, solo este endpoint).

---

### R-05 · `PATCH /order-items/{item}`
```
✅ 1. Auth                  → auth:sanctum + has.role; 401 acreditado
✅ 2. Validación            → límite agregado se evalúa dentro de la transacción
✅ 3. Negocio (tx + lock)   → item y pivots bloqueados; reducción compite con asignaciones
✅ 4. Datos                 → suma global entre todas las bases, sin constraint simple inaplicable
✅ 5. Respuesta (HTTP)      → 409 estable con assigned/requested
✅ 6. Audit log             → misma transacción; rollback forzado acreditado
✅ 7. Tests                 → 75 tests/185 assertions; reducción vs. asignación concurrente acreditada
✅ 8. Medición              → 6 queries constantes con 20 asignaciones; `benchmarks/results/2026-10-29-r05-update-order-item.md`
```
**Estado:** ✅ cerrada en `85daa84`; Actions run `40931851828` con `ci` y `postgres-concurrency` verdes.
**Hallazgos que resuelve:** H-010.

---

### R-06 · `GET /public/orders/{token}`
```
✅ 1. Acceso                → token opaco de 48 caracteres; límites por IP + recurso (`D-006`)
✅ 2. Validación            → sólo 48 caracteres alfanuméricos; código o token inexistente devuelven 404
✅ 3. Negocio               → GET y solicitud de faltantes comparten la misma capacidad
✅ 4. Datos                 → token único, backfill reversible y oculto por defecto
✅ 5. Respuesta (HTTP)      → contrato público no expone el token; abuso devuelve 429 + Retry-After
✅ 6. Audit log             → no se registra cada lectura; se limitan y observan patrones (`D-006`)
✅ 7. Tests                 → 82 tests/226 assertions; token, contrato y rate limit acreditados en CI
✅ 8. Medición              → 6 queries con 20 ítems; `benchmarks/results/2026-10-29-r06-public-order.md`
```
**Estado:** ✅ cerrada en `f344770`; Actions run `40933450650` con `ci` y `postgres-concurrency` verdes.
**Hallazgos que resuelve:** H-001.

---

### R-07 · Auth transversal (login / desactivación / webhook Telegram)
```
✅ 1. Auth                  → 401 genérico; inactivos bloqueados; tokens revocados (`D-007`)
✅ 2. Validación            → identidad inexistente, password e inactivo no son distinguibles
✅ 3. Negocio               → Telegram falla cerrado sin secret y compara de forma segura
✅ 4. Datos                 → desactivación/rol, tokens y auditoría comparten transacción
✅ 5. Respuesta (HTTP)      → login inválido 401; secret inválido 401; config ausente 503
✅ 6. Audit log             → desactivación, reactivación y cambio de rol auditados sin secretos
✅ 7. Tests                 → 90 tests/256 assertions; auth, revocación, rollback y webhook acreditados
✅ 8. Medición              → n/a; operaciones acotadas por usuario
```
**Estado:** ✅ cerrada en `9bd77b7`; Actions run `40965649678` con `ci` y `postgres-concurrency` verdes.
**Hallazgos que resuelve:** H-002, H-003, H-021, H-022.

---

## 🧱 TAREAS TRANSVERSALES

> No son de un endpoint — son la base que hace posible cerrar las rebanadas de arriba sin reabrir todo después.

```
🔥 URGENTE, no-código:
✅ Eliminar RESET_ON_STARTUP del arranque y probar comando manual → H-028, H-037
□ Verificar FILESYSTEM_DISK=r2 en Render                        → H-029

Antes de tocar código de negocio (Fase 1 — red de seguridad):
✅ GitHub Actions: Pint → PHPStan → tests → composer audit      → H-031, `DO-CI`
✅ Larastan/PHPStan nivel 5 con baseline legacy                 → H-041
□ Branch protection en main                                     → `DO-CI`
✅ Tests de caracterización de los 6 endpoints de stock ANTES   → `QA-LEVELS` (`f114458` + `c44cafd`)
  de tocarlos — congelan el comportamiento actual para detectar
  regresiones durante R-01 a R-05
✅ Remediar H-042 en todos los componentes activos               → PHP/frontend/backend npm: ✅
  (`whatsapp-bot/` es legado inactivo y queda fuera de gates/deploy)
□ Handler global de excepciones + respuesta de error estándar   → H-018, H-024
□ Model::preventLazyLoading() en local                          → ✅ HECHO (esta sesión)
□ Debugbar en dev                                                → ✅ HECHO (esta sesión)
□ /benchmarks con script k6 + README                             → ✅ HECHO (esta sesión) —
                                                                     falta CORRER y guardar baseline

Constraints e índices (una migración, bajo riesgo, alto retorno):
□ Índices en FKs sin cubrir (Postgres)                           → H-012
□ activity_logs.pallet_id → FK real                              → H-013
□ CHECK constraint / trigger para "asignado ≤ pedido"            → H-006 (decidir enfoque
                                                                     junto con R-01/R-02)

Docker/infra (mecánico, bajo riesgo):
✅ USER no-root en Dockerfile + user= en supervisord.conf         → H-030
✅ Sacar pallet-frontend/.env de git (git rm --cached) + gitignore → H-032
✅ .dockerignore: agregar *.md, tests/, storage/logs/*            → H-034
✅ HEALTHCHECK en Dockerfile                                       → H-036
✅ Pinear versiones de imágenes base a digest                      → H-035
✅ Sacar Composer de la imagen final (stage vendor separado)       → H-033
□ Decidir si nixpacks.toml sigue vigente o se borra                → H-038

Decisiones que necesitan entrada en DECISIONES.md antes de ejecutarse:
□ Soft delete vs. borrado físico en las tablas de movimiento       → H-009, `BE-DATA`
□ Estrategia de locking (lockForUpdate vs. optimista con versión)  → H-005, H-006b, H-007, `BE-TX`
✅ Code público de pedido: token opaco + límites por IP/recurso      → D-006, H-001, `SEC-ABUSE`

Más adelante (Fase 3+):
□ Migración de los 16 controladores a Form Request                  → H-020
□ Códigos HTTP: pasada de consistencia (422→409/403/401 según caso) → H-021,022,023,027
□ PendingItemController::index con paginación                        → H-040
□ Infraestructura futura                                                → `DO-INFRA`, `DO-EDGE`, `DO-RELEASE`
□ Observabilidad y uptime                                               → `DO-OBS`
□ Backups + restauración probada                                       → `DO-DR`
□ README con evidencia de performance                                  → `BE-PERF`, `QA-PERF`
```

## 🧩 GAPS TEÓRICOS FUTUROS

No habilitan implementación hasta completar `BIBLIAS-GOBERNANZA.md §8`:

- `GAP-OFFLINE` — almacenamiento local y sincronización.
- `GAP-DESKTOP` — distribución y actualización Windows.
- `GAP-MOBILE` — clientes móviles y publicación.
- `GAP-TENANCY` — aislamiento SaaS multiempresa.
- `GAP-DEVICE` — identidad y revocación de dispositivos.

---

## 🤖 QUÉ SE AUTOMATIZA CON TOOLING (en vez de arreglar a mano)

| Tooling | Reemplaza arreglo manual de | Estado |
|---|---|---|
| **GitHub Actions** (lint+static+test+audit en cada push) | Revisión manual de "¿esto rompe algo?" en cada PR — atrapa regresiones automáticamente | ✅ implementado; falta validar la ejecución remota (H-031) |
| **Larastan/PHPStan nivel 5 + baseline** | Errores de tipos y nulls sin bloquear por deuda legacy | ✅ implementado (H-041) |
| **Pint** | Estilo de código | ✅ ejecutado por CI en modo `--test` |
| **`composer audit` en CI** | Revisión manual de dependencias PHP vulnerables | ✅ implementado; `npm audit` queda pendiente de definir por componente |
| **Migración de índices** (una sola migración con varios `->index()`) | Arreglar cada FK sin índice una por una | 🔲 mecánico, H-012 |
| **`Model::preventLazyLoading()`** | Revisión manual de cada endpoint buscando N+1 — tira excepción sola en desarrollo | ✅ activo desde esta sesión |
| **Laravel Debugbar** | Contar queries a mano por endpoint | ✅ instalado desde esta sesión |
| **k6 (`benchmarks/`)** | "Me parece que está lento" sin número — da p50/p95/p99 reproducible | ✅ script armado, falta correrlo |
| **CHECK constraint en Postgres** | Repetir la validación "asignado ≤ pedido" en cada controlador nuevo que toque stock — la DB la garantiza una vez, para siempre | 🔲 falta decidir expresión exacta (H-006) |
| **`unique(order_id, ean)`** | El check-then-insert manual de `OrderItemController::store` — Postgres rechaza el duplicado solo | ✅ implementado en R-04 (H-008) |
| **CI check simple** (`grep -r "getMessage()" en respuestas json`) | No previene el bug ya existente, pero evita que se reintroduzca una vez arreglado H-019 | 🔲 se puede armar como parte del pipeline de H-031 |

**Lo que NO se automatiza — necesita diseño humano:**
`lockForUpdate()` + transacciones en R-01 a R-04 (dónde exactamente poner el lock es una decisión de concurrencia, no mecánica) · el handler global de excepciones (H-018) · la decisión soft-delete vs. físico (H-009) · si el `code` público pasa a ser un token opaco (H-001) · los tests de negocio reales (H-039 — el scaffolding de factories sí se automatiza, el "qué probar" no).

---

## 🚫 DECIDIDO QUE NO

> Cosas que se evaluaron y se descartaron a propósito. Evita rediscutirlas cada sesión.

| Qué | Por qué no | Fecha |
|---|---|---|
| | | |
