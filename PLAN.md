# 🗺️ PLAN — Pallet Control

> **Qué es esto:** el backlog vivo. Qué falta, en qué orden, y en qué estado está cada cosa.
> **Se actualiza:** en cada sesión de trabajo. Es lo primero que lee la IA para saber dónde estamos.
> **Referencia a teoría:** con `§`, nunca copiando texto de las biblias. El detalle de cada hallazgo vive en `AUDITORIA.md` (`H-XXX`) — acá solo se prioriza y se ordena el trabajo.

**Última actualización:** 2026-08-24
**Rama integradora:** `modernizacion/base`
**Entrada obligatoria:** `ROUTER.md`
**Rebanada actual:** cierre de Fase 1 — migración aislada del WhatsApp bot a Baileys 7 por H-042/D-001.

---

## 📍 ESTADO GENERAL

```
Fase 0 — Reconocimiento        ✅ hecho   (AUDITORIA.md — 42 hallazgos, H-001 a H-041 + H-006b)
Fase 1 — Red de seguridad      🟡 en progreso (CI/tooling y caracterización hechos; audit bloqueado)
Fase 2 — Rebanadas verticales  🔲  (0 de 7 cerradas)
Fase 3 — Dominio específico    🔲
Fase 4 — Performance           🔲 (benchmarks/ armado, baseline sin correr — H-040)
Fase 5 — Infraestructura       🔲
Fase 6 — Presentación          🔲
```

---

## 🔥 PRIORIDAD INMEDIATA

_(Estos 3 primero, antes que cualquier rebanada. Los dos primeros no son código — son 5 minutos en el dashboard de Render.)_

1. **Verificar en Render que `RESET_ON_STARTUP` esté ausente/`false`** — si quedó en `true`, el próximo deploy borra toda la DB operativa. `H-028`.
2. **Verificar en Render que `FILESYSTEM_DISK=r2`** (no `public`/`local`) — si no, las fotos de pallets/tickets se pierden en el próximo deploy. `H-029`.
3. **Migrar WhatsApp bot a Baileys 7** — Composer, frontend y npm backend ya están en audit 0; quedan 3 vulnerabilidades en la cadena legacy de Baileys 6. `H-042`, `D-001`, DEVOPS-BIBLIA §3.6.
4. **Cerrar Fase 1** — repetir instalación limpia, Pint, PHPStan, tests, lint, builds y audits.
5. **Arrancar R-01** (`adjust-item`) — es el endpoint de escritura de stock con el gap más simple y más peligroso: escribe cantidad sin ningún techo contra lo pedido. `H-006b`.

---

## 🧭 CÓMO SE PRIORIZÓ

Orden pedido: **riesgo de seguridad > corrupción/pérdida de datos > performance > calidad.**
Dentro de cada categoría, por severidad (`AUDITORIA.md` §3). El detalle de "qué está pasando" y "por qué importa" de cada `H-XXX` está en `AUDITORIA.md` — acá solo esfuerzo, impacto y si se puede automatizar.

**Esfuerzo:** 🟢 bajo (<1h, mecánico) · 🟡 medio (horas, requiere diseño/tests) · 🔴 alto (multi-día, toca varios archivos o cambia comportamiento).
**Automatizable:** ✅ sí (tooling/migración/CI, sin juicio de negocio) · 🟡 parcial (tooling detecta o hace el 80%, un humano decide/cierra el 20%) · ❌ no (requiere diseño y código a mano).

### 🔴 Seguridad

| ID | Hallazgo | Esfuerzo | Impacto | Automatizable |
|---|---|---|---|---|
| H-001 | IDOR/enumeración en `GET /public/orders/{code}` | 🟡 medio | Alto | ❌ no (decisión: throttle vs. code opaco) |
| H-019 | `$e->getMessage()` filtrado al cliente en 4 controladores | 🟢 bajo | Medio | 🟡 parcial |
| H-030 | Contenedor corre como root | 🟢 bajo | Alto | ✅ sí |
| H-032 | `pallet-frontend/.env` trackeado en git | 🟢 bajo | Medio | ✅ sí |
| H-002 | Webhook Telegram falla abierto sin secret | 🟢 bajo | Medio | ✅ sí |
| H-003 | Desactivar usuario no revoca tokens | 🟡 medio | Medio | ❌ no |
| H-025 | `APP_DEBUG` sin safeguard en el deploy | 🟢 bajo | Medio | ✅ sí |
| H-004 | Rutas de verificación de email rotas | 🟢 bajo | Bajo | ✅ sí |

### 🔴 Integridad / pérdida de datos

| ID | Hallazgo | Esfuerzo | Impacto | Automatizable |
|---|---|---|---|---|
| H-028 | `RESET_ON_STARTUP` puede borrar toda la DB | 🟢 bajo (verificar) / 🟡 medio (rediseñar el safeguard) | **Altísimo** | ✅ verificación / ❌ rediseño |
| H-029 | Fotos sin persistencia garantizada en redeploy | 🟢 bajo (verificar) / 🟡 medio (warning en boot) | **Altísimo** | 🟡 parcial |
| H-005 | Race condition en `createBase`/`updateBase` | 🔴 alto | Alto | ❌ no |
| H-006 | Sin CHECK constraint "asignado ≤ pedido" | 🟡 medio | Alto | 🟡 parcial |
| H-006b | `adjustItem` sin techo de validación | 🟡 medio | Alto | ❌ no |
| H-007 | `migrate` lee stock antes de abrir la transacción | 🟡 medio | Alto | ❌ no |
| H-008 | Race duplicando EAN en `order_items` | 🟡 medio | Alto | 🟡 parcial |
| H-009 | Cero soft deletes en todo el esquema | 🔴 alto | Alto | 🟡 parcial |
| H-010 | Reducir `qty` sin validar lo ya asignado | 🟡 medio | Medio | ❌ no |
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
| H-018 | Sin handler global de excepciones | 🟡 medio | Medio | ❌ no |
| H-031 | Sin CI de ningún tipo | 🟢 bajo | Alto | ✅ sí |
| H-020 | 16 controladores validan inline | 🔴 alto (son 16 archivos) | Bajo | 🟡 parcial |
| H-021 / H-022 / H-023 / H-027 | Códigos HTTP inconsistentes (401/403/409 vs 422/400) | 🟢 bajo c/u | Bajo | 🟡 parcial (CI puede flaggear, no decidir) |
| H-024 | Formato de error no estandarizado | 🟡 medio (depende de H-018) | Bajo | ❌ no |
| H-041 | Larastan/PHPStan no instalado | 🟢 bajo | Medio | ✅ sí |
| H-033 / H-034 / H-035 / H-036 | Docker: composer en imagen, dockerignore incompleto, versiones sin pinear a patch, sin healthcheck | 🟢 bajo c/u | Bajo | ✅ sí |
| H-004 / H-014 / H-016 / H-026 / H-037 / H-038 | Varios ⚪ (rutas rotas, columna muerta, índice duplicado, etc.) | 🟢 bajo c/u | Bajo | ✅ sí (salvo H-038, es decisión) |

---

## 📦 REBANADAS VERTICALES

> Protocolo de 8 puntos por endpoint (ver CLAUDE.md). Un endpoint no se cierra hasta tener los 8 en verde.
> **Orden: empezando por los endpoints de escritura de stock** (la tabla real de stock es `pallet_base_order_items`, no un endpoint "movimientos" — este proyecto no tiene ese endpoint genérico, tiene estos 6).

### R-01 · `PATCH /pallets/{pallet}/bases/{base}/adjust-item`
```
□ 1. Auth (ya tiene has.role — verificar que sea suficiente)
□ 2. Validación            → tiene AdjustPalletBaseItemRequest, revisar si le falta 'qty máx'
□ 3. Negocio (tx + lock)   → HOY NO VALIDA CONTRA EL TOTAL PEDIDO. Agregar fetchAllocations()
                              + lockForUpdate() + DB::transaction() → ref BACKEND-BIBLIA §5.6
□ 4. Datos (constraint)    → evaluar CHECK/trigger que respalde la invariante en DB
□ 5. Respuesta (HTTP)      → "sin stock" hoy es 422, debería ser 409 → ref §3.1
□ 6. Audit log             → ya existe, mover adentro de la transacción
□ 7. Tests (feliz+límite+concurrencia) → caso clave: 2 requests concurrentes al límite exacto
□ 8. Medición antes/después → benchmarks/
```
**Por qué primero:** es el endpoint con el gap más simple de cerrar (una sola escritura, sin la complejidad de `sync()` de los otros) y establece el patrón (lock + transacción + constraint) que R-02 y R-03 van a reusar.
**Hallazgos que resuelve:** H-006b, H-005 (patrón), H-006 (si se agrega constraint acá), H-023, H-017.

---

### R-02 · `POST /pallets/{pallet}/bases` + `PATCH /pallets/{pallet}/bases/{base}` (con items)
```
□ 1. Auth                  → has.role, ok
□ 2. Validación            → StorePalletBaseRequest, revisar
□ 3. Negocio (tx + lock)   → PalletBaseService::createBase/updateBase SIN transacción ni lock hoy.
                              Envolver en DB::transaction() + lockForUpdate() sobre order_items
                              involucrados antes de calcular $available → ref §5.6
□ 4. Datos                 → fetchAllocations() ya evita N+1, revisar bajo lock
□ 5. Respuesta (HTTP)      → mismos casos 422→409 que R-01
□ 6. Audit log             → ya existe (ActivityLogger dentro del service), moverlo a la transacción
□ 7. Tests                 → caso clave: 2 bases distintas del mismo pallet asignando el mismo
                              order_item concurrentemente hasta agotar el disponible
□ 8. Medición              → benchmarks/
```
**Nota:** se agrupan los dos endpoints porque comparten `PalletBaseService` — separarlos no reduce el riesgo, la corrección es una sola.
**Hallazgos que resuelve:** H-005, H-006, H-011 (parcial).

---

### R-03 · `POST /pallets/{pallet}/bases/{base}/migrate`
```
□ 1. Auth                  → ok
□ 2. Validación            → MigratePalletBaseRequest, ok
□ 3. Negocio (tx + lock)   → mover la lectura de $sourceRecords DE ADENTRO del DB::transaction()
                              existente, agregar lockForUpdate() → ref §5.6
□ 4. Datos                 → revisar índices usados en el join de pallet_base_order_items
□ 5. Respuesta (HTTP)      → ok
□ 6. Audit log             → ya existe
□ 7. Tests                 → caso clave: migrate concurrente con adjustItem sobre la misma fila
□ 8. Medición              → benchmarks/
```
**Hallazgos que resuelve:** H-007.

---

### R-04 · `POST /orders/{order}/items`
```
□ 1. Auth                  → ok
□ 2. Validación            → inline hoy (H-020) — aprovechar para migrar a Form Request
□ 3. Negocio (tx + lock)   → agregar unique(order_id, ean) en DB + manejar el conflicto
                              (23505/QueryException) en vez de check-then-insert → ref §5.6
□ 4. Datos                 → agregar el unique index de arriba
□ 5. Respuesta (HTTP)      → duplicado ya devuelve 409, correcto — mantener tras el fix
□ 6. Audit log             → ya existe
□ 7. Tests                 → caso clave: 2 requests concurrentes agregando el mismo EAN
□ 8. Medición              → benchmarks/
```
**Hallazgos que resuelve:** H-008, H-020 (parcial, solo este endpoint).

---

### R-05 · `PATCH /order-items/{item}`
```
□ 1. Auth                  → ok
□ 2. Validación            → UpdateOrderItemRequest, agregar regla custom: qty >= assigned
□ 3. Negocio               → validar contra SUM(pallet_base_order_items.qty) antes de aceptar
                              una reducción de qty → ref §2.2 (capa de negocio)
□ 4. Datos                 → —
□ 5. Respuesta (HTTP)      → agregar 409 para el caso "no podés bajar de X unidades"
□ 6. Audit log             → ya existe
□ 7. Tests                 → caso feliz + intento de reducir por debajo de lo asignado
□ 8. Medición              → benchmarks/
```
**Hallazgos que resuelve:** H-010.

---

### R-06 · `GET /public/orders/{code}`
```
□ 1. Auth                  → decidir: throttle agresivo, o migrar `code` público a un token
                              opaco separado del código de pedido interno → ver DECISIONES.md D-XXX
□ 2. Validación             → —
□ 3. Negocio                → —
□ 4. Datos                  → ya usa eager loading correcto, revisar con benchmarks/
□ 5. Respuesta (HTTP)       → ok
□ 6. Audit log              → evaluar loguear accesos (rate de consultas por code)
□ 7. Tests                  → caso clave: código inexistente, code de otro pedido, rate limit
□ 8. Medición                → benchmarks/ (ya priorizado, endpoint #2 de la lista)
```
**Hallazgos que resuelve:** H-001.

---

### R-07 · Auth transversal (login / desactivación / webhook bot)
```
□ 1. Auth                  → login inválido: 422→401 · toggle-active: revocar tokens
□ 2. Validación             → —
□ 3. Negocio                 → invertir el `if ($secret)` de TelegramBotController a fail-closed
□ 4. Datos                   → —
□ 5. Respuesta (HTTP)        → 401 en login inválido
□ 6. Audit log                → —
□ 7. Tests                    → login inválido, token revocado tras desactivación, webhook sin secret
□ 8. Medición                  → n/a
```
**Hallazgos que resuelve:** H-002, H-003, H-021.

---

## 🧱 TAREAS TRANSVERSALES

> No son de un endpoint — son la base que hace posible cerrar las rebanadas de arriba sin reabrir todo después.

```
🔥 URGENTE, no-código:
□ Verificar RESET_ON_STARTUP en Render                         → H-028
□ Verificar FILESYSTEM_DISK=r2 en Render                        → H-029

Antes de tocar código de negocio (Fase 1 — red de seguridad):
✅ GitHub Actions: Pint → PHPStan → tests → composer audit      → H-031, DEVOPS §3.3
✅ Larastan/PHPStan nivel 5 con baseline legacy                 → H-041
□ Branch protection en main                                     → DEVOPS §3.4
✅ Tests de caracterización de los 6 endpoints de stock ANTES   → Backend §6 (`f114458` + `c44cafd`)
  de tocarlos — congelan el comportamiento actual para detectar
  regresiones durante R-01 a R-05
🟡 Remediar H-042 y obtener exit code 0 en todos los audits      → PHP/frontend/backend npm: ✅
                                                                  WhatsApp bot: 🔲 Baileys 7
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
□ USER no-root en Dockerfile + user= en supervisord.conf         → H-030
□ Sacar pallet-frontend/.env de git (git rm --cached) + gitignore → H-032
□ .dockerignore: agregar *.md, tests/, storage/logs/*            → H-034
□ HEALTHCHECK en Dockerfile                                       → H-036
□ Pinear versiones de imágenes base a digest                      → H-035
□ Sacar Composer de la imagen final (stage vendor separado)       → H-033
□ Decidir si nixpacks.toml sigue vigente o se borra                → H-038

Decisiones que necesitan entrada en DECISIONES.md antes de ejecutarse:
□ Soft delete vs. borrado físico en las tablas de movimiento       → H-009
□ Estrategia de locking (lockForUpdate vs. optimista con versión)  → H-005, H-006b, H-007
□ Code público de pedido: throttle vs. token opaco separado         → H-001

Más adelante (Fase 3+):
□ Migración de los 16 controladores a Form Request                  → H-020
□ Códigos HTTP: pasada de consistencia (422→409/403/401 según caso) → H-021,022,023,027
□ PendingItemController::index con paginación                        → H-040
□ Migración Render → VPS                                              → DEVOPS §4,§5,§6
□ Sentry + UptimeRobot                                                 → DEVOPS §7
□ Backups + restauración probada                                       → DEVOPS §8
□ README profesional con sección Performance                            → BACKEND-BIBLIA §8.8
```

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
| **`unique(order_id, ean)`** | El check-then-insert manual de `OrderItemController::store` — Postgres rechaza el duplicado solo | 🔲 falta agregar (H-008) |
| **CI check simple** (`grep -r "getMessage()" en respuestas json`) | No previene el bug ya existente, pero evita que se reintroduzca una vez arreglado H-019 | 🔲 se puede armar como parte del pipeline de H-031 |

**Lo que NO se automatiza — necesita diseño humano:**
`lockForUpdate()` + transacciones en R-01 a R-04 (dónde exactamente poner el lock es una decisión de concurrencia, no mecánica) · el handler global de excepciones (H-018) · la decisión soft-delete vs. físico (H-009) · si el `code` público pasa a ser un token opaco (H-001) · los tests de negocio reales (H-039 — el scaffolding de factories sí se automatiza, el "qué probar" no).

---

## 🚫 DECIDIDO QUE NO

> Cosas que se evaluaron y se descartaron a propósito. Evita rediscutirlas cada sesión.

| Qué | Por qué no | Fecha |
|---|---|---|
| | | |
