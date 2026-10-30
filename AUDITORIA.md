# 🔍 AUDITORÍA — Pallet Control

> **Qué es esto:** foto del estado REAL del proyecto al momento de auditar.
> **Qué NO es:** teoría. La teoría vive en las biblias — acá solo se referencia (`§` = sección).
> **Cuándo se escribe:** Fase 0. Se congela al terminarla. No se edita después salvo para marcar hallazgos como resueltos (`Estado: ✅ resuelto en <commit>`).

**Fecha de auditoría:** 2026-08-05
**Commit auditado:** `9c35cbd` (HEAD al momento de auditar — ver `git log --oneline -1`)

---

## 1. MAPA DEL PROYECTO

**Stack real:**
| Componente | Tecnología | Versión |
|---|---|---|
| Backend | Laravel | ^12.0 (PHP ^8.2, runtime Docker `php:8.4-cli-alpine`) |
| Auth API | Laravel Sanctum | ^4.2 |
| Frontend | React + Vite | React ^19.2, Vite ^7.2 |
| DB producción | PostgreSQL (Neon) | vía `pdo_pgsql` |
| DB local | SQLite | default de `.env.example` |
| Storage prod | Cloudflare R2 (opt-in) / disco local (default) | `league/flysystem-aws-s3-v3` |
| Testing | PHPUnit ^11.5 | prácticamente sin tests reales (ver H-039) |
| Deploy | Docker multi-stage → Render | `nixpacks.toml` presente pero de vigencia ambigua (H-038) |

**Endpoints:** 70+ rutas bajo `/api/v1`, ver `pallet-backend/routes/api.php`. Los de escritura de stock (foco de la Fase 2) son:
`PATCH /pallets/{pallet}/bases/{base}/adjust-item` · `POST /pallets/{pallet}/bases` · `PATCH /pallets/{pallet}/bases/{base}` · `POST /pallets/{pallet}/bases/{base}/migrate` · `POST /orders/{order}/items` · `PATCH /order-items/{item}`.

**Modelos y relaciones:** dominio Order→Customer, Order↔Pallet (N:M vía `order_pallet`), Order→OrderItem, Pallet→PalletBase, PalletBase↔OrderItem (N:M **con datos** — `pallet_base_order_items.qty` — es la tabla real de stock), + fotos/tickets/anotaciones/pending_items/missing_item_requests/activity_logs. Sin concepto de ownership por usuario: es un sistema interno compartido (varios operarios sobre el mismo recurso).

**Tests existentes:** 2 archivos, ambos el ejemplo default de Laravel (`tests/Feature/ExampleTest.php`, `tests/Unit/ExampleTest.php`). Cero cobertura real de auth, ownership, negocio o regresión.

**Deploy actual:** Docker multi-stage (`Dockerfile`) → Render, `supervisord` corre `php artisan serve` + `queue:work`, `docker-entrypoint.sh` migra y cachea config en cada boot. Sin CI.

---

## 2. HALLAZGOS

### 🔴 SEGURIDAD

#### H-001 — IDOR/enumeración en pedido público
```
Severidad:   🔴 crítico
Categoría:   seguridad
Ubicación:   app/Http/Controllers/Api/PublicOrderController.php:18
Referencia:  `SEC-AUTHZ`, `BE-API`
```
**Qué está pasando:** `GET /public/orders/{code}` no requiere auth ni tiene `throttle`, y `code` es un string libre que en la práctica es numérico y secuencial (`attachOrder` usa regex `^\d+$`).
**Por qué importa acá:** cualquiera puede iterar códigos y extraer cliente, items, precios y fotos de pedidos ajenos, sin límite de tasa.
**Resolución R-06:** un token aleatorio separado reemplaza al código comercial en GET y faltantes. El token se oculta por defecto y las rutas aplican límites combinados por IP y recurso según D-006.
**Evidencia:** tests acreditan acceso por token, rechazo del código enumerable, contrato sin token, entrega sólo autenticada, faltantes protegidos, `429` con `Retry-After`, backfill/rollback y presupuesto constante.
**Estado:** ✅ resuelto en R-06, commit `f344770`; Actions run `40933450650` con `ci` y `postgres-concurrency` verdes.

#### H-002 — Webhook de Telegram falla abierto sin secret configurado
```
Severidad:   🟡 medio
Categoría:   seguridad
Ubicación:   app/Http/Controllers/Api/TelegramBotController.php:33-39
Referencia:  `BE-AUTH`, `SEC-IDENTITY`
```
**Qué está pasando:** el chequeo de `X-Telegram-Bot-Api-Secret-Token` solo se ejecuta `if ($secret)` — si la env var no está seteada, cualquiera puede pegarle al webhook.
**Por qué importa acá:** a diferencia de `BotSecretMiddleware` (que sí falla cerrado), acá una env var faltante deja el bot completamente abierto (puede crear bases, subir fotos, mover cache keys).
**Resolución R-07:** configuración ausente devuelve `503` antes de procesar payload y deja log crítico sin secretos; header incorrecto devuelve `401` mediante `hash_equals`.
**Evidencia:** tests cubren secret ausente, incorrecto y correcto; `.env.example` documenta el valor obligatorio.
**Estado:** ✅ resuelto en R-07, commit `9bd77b7`; Actions run `40965649678` verde.

#### H-003 — Desactivar usuario no revoca sus tokens
```
Severidad:   🟡 medio
Categoría:   seguridad
Ubicación:   app/Http/Controllers/Api/UserController.php:48-61
Referencia:  `BE-AUTH`, `SEC-IDENTITY`
```
**Qué está pasando:** `toggle-active` cambia `is_active`, pero `is_active` solo se valida en `login()` — un token Sanctum ya emitido sigue funcionando hasta su expiración natural (14 días).
**Por qué importa acá:** un empleado desvinculado sigue teniendo acceso completo a la API durante semanas.
**Resolución R-07:** desactivar revoca todos los tokens dentro de la transacción y `user.active` rechaza credenciales residuales. Los cambios de rol también renuevan sesión por revocación según D-007.
**Evidencia:** tests cubren múltiples tokens, reuso rechazado, defensa residual, auditoría y rollback total si el log falla.
**Estado:** ✅ resuelto en R-07, commit `9bd77b7`; Actions run `40965649678` verde.

#### H-004 — Rutas de verificación de email rotas
```
Severidad:   ⚪ bajo
Categoría:   calidad
Ubicación:   routes/api.php:53,57 — app/Http/Controllers/Api/AuthController.php
Referencia:  —
```
**Qué está pasando:** `GET /auth/verify/{id}` y `POST /auth/email/resend` apuntan a métodos que no existen en `AuthController` (dan 500 si se invocan). No es explotable — la verificación real está desactivada (auto-verifica en `register()`) — pero es dead code que confunde.
**Estado:** 🔲 abierto

---

### 🔴 INTEGRIDAD DE DATOS

#### H-005 — Race condition en asignación de stock a bases
```
Severidad:   🔴 crítico
Categoría:   integridad de datos
Ubicación:   app/Services/PalletBaseService.php:15-137 (createBase, updateBase/syncBaseItems)
Referencia:  `BE-TX`, `QA-INVARIANT`
```
**Resolución R-02:** `createBase()` y `updateBase()` bloquean los `order_items` en orden determinista, bloquean sus asignaciones y calculan el total global dentro de la misma transacción antes de sincronizar pivots.
**Evidencia:** exceso, límite exacto, asignaciones en otros pallets, ownership, rollback y auditoría se cubren en `PalletBaseStockMovementTest`; `AdjustItemConcurrencyTest` acredita dos creaciones simultáneas sobre PostgreSQL.
**Estado:** ✅ resuelto en R-02, commit `87d6a4b`; Actions run `40919324208` con `ci` y `postgres-concurrency` verdes.

#### H-006 — Sin constraint de DB para "asignado ≤ pedido"
```
Severidad:   🔴 crítico
Categoría:   integridad de datos
Ubicación:   database/migrations/2025_12_20_000003_create_pallet_base_order_items_table.php
Referencia:  `BE-VALID`, `BE-TX`, `DOMINIO.md`
```
**Qué está pasando:** no existe ningún CHECK/trigger que impida `SUM(pallet_base_order_items.qty) > order_items.qty`. Toda la garantía depende de que el código PHP de cada endpoint la calcule bien.
**Por qué importa acá:** un bug futuro, un `tinker`, o el propio `adjustItem` (ver H-006b) puede violar la invariante si sólo existe una comprobación parcial en PHP. `BE-VALID` exige defensas adecuadas a la naturaleza agregada de la regla.
**Estado:** 🟡 mitigado en R-01/R-02 mediante el lock coordinador D-003; permanece abierto como defensa transversal frente a escritores futuros o directos.

#### H-006b — `adjustItem` no valida contra el total pedido
```
Severidad:   🔴 crítico
Categoría:   integridad de datos
Ubicación:   app/Http/Controllers/Api/PalletBaseController.php:179-223
Referencia:  `BE-TX`, `QA-INVARIANT`
```
**Resolución R-01:** el endpoint bloquea el `order_item`, suma las asignaciones globales excluyendo la base actual y rechaza con `409` cualquier cantidad superior al disponible. La pertenencia del pedido al pallet se valida dentro de la misma transacción.
**Evidencia:** `PalletBaseStockMovementTest` cubre exceso, asignaciones en otros pallets, límite exacto, repetición, pertenencia y presupuesto constante de queries. `AdjustItemConcurrencyTest` acredita dos procesos contra PostgreSQL mediante el job `postgres-concurrency`.
**Estado:** ✅ resuelto en `dea6631`; CI SQLite/PostgreSQL cerrado en Actions run `40827127268` sobre `78786b1`.

#### H-007 — `migrate` tiene transacción pero lee stock antes de abrirla
```
Severidad:   🔴 crítico
Categoría:   integridad de datos
Ubicación:   app/Http/Controllers/Api/PalletBaseController.php:52-175
Referencia:  `BE-TX`, `QA-INVARIANT`
```
**Resolución R-03:** disponibilidad, origen, destino, asociación de pedido y auditoría se leen/escriben dentro de una transacción. Pallets, bases, items y pivots se bloquean en orden determinista; el segundo retiro concurrente debe reevaluar el stock ya confirmado.
**Evidencia:** `PalletBaseStockMovementTest` cubre 401, pertenencia, stock insuficiente, destinos incoherentes/finalizados, duplicados, acumulación, asociación, rollback de auditoría y presupuesto constante. PostgreSQL acredita dos migraciones simultáneas sobre el mismo origen con resultado `200/409`, origen 4, destino 6 y un solo log.
**Estado:** ✅ resuelto en R-03, commit `28e6cf6`; Actions run `40922987827` con `ci` y `postgres-concurrency` verdes.

#### H-008 — Race check-then-insert al agregar producto a un pedido
```
Severidad:   🔴 crítico
Categoría:   integridad de datos
Ubicación:   app/Http/Controllers/Api/OrderItemController.php:42-65
Referencia:  `BE-TX`, `QA-INVARIANT`
```
**Resolución R-04:** el pedido se bloquea durante la comprobación y el alta; item y auditoría confirman juntos. Una migración reversible agrega `UNIQUE(order_id, ean)` y se niega a continuar si detecta duplicados preexistentes.
**Evidencia:** tests cubren duplicado secuencial, constraint directo, mismo EAN en pedidos distintos, EAN/last4, input inválido, rollback de auditoría y presupuesto constante. PostgreSQL acredita dos altas simultáneas sobre el mismo pedido/EAN con resultado `201/409`, un item y un log.
**Estado:** ✅ resuelto en R-04, commit `82af27c`; Actions run `40929456582` con `ci` y `postgres-concurrency` verdes.

#### H-009 — Cero soft deletes en todo el esquema
```
Severidad:   🟠 alto
Categoría:   integridad de datos
Ubicación:   todas las migraciones (`cascadeOnDelete()` en todos lados)
Referencia:  `BE-DATA`, `DOMINIO.md`
```
**Qué está pasando:** `grep -rn "SoftDeletes|deleted_at"` → 0 resultados en todo el proyecto. Todo borrado es físico y en cascada.
**Por qué importa acá:** borrar un pallet/base puede destruir el registro de qué se asignó dónde; la política de conservación debe decidirse desde `DOMINIO.md` y `BE-DATA`, no asumirse.
**Estado:** 🔲 abierto

#### H-010 — Reducir `qty` de un item no valida contra lo ya asignado
```
Severidad:   🟠 alto
Categoría:   integridad de datos
Ubicación:   app/Http/Controllers/Api/OrderItemController.php:138-194 — app/Http/Requests/UpdateOrderItemRequest.php
Referencia:  `BE-VALID`, `DOMINIO.md`
```
**Qué está pasando:** `PATCH /order-items/{item}` solo valida `qty >= 1`. No chequea cuánto de ese item ya está en `pallet_base_order_items`.
**Por qué importa acá:** un solo request bien formado (sin concurrencia) puede dejar `asignado > pedido` — mismo resultado final que H-005/H-008 por una vía totalmente distinta.
**Resolución R-05:** el item y sus asignaciones se bloquean antes de sumar; una reducción menor que lo ya asignado devuelve `409`. La actualización y su auditoría comparten transacción.
**Evidencia:** límite exacto, conflicto, auth, input inválido, rollback de auditoría y presupuesto constante acreditados. PostgreSQL enfrenta una reducción con una asignación simultánea: sólo una confirma y el estado final conserva `asignado ≤ pedido`.
**Estado:** ✅ resuelto en R-05, commit `85daa84`; Actions run `40931851828` con `ci` y `postgres-concurrency` verdes.

#### H-011 — Escrituras multi-tabla sin transacción
```
Severidad:   🟠 alto
Categoría:   integridad de datos
Ubicación:   PalletController::attachOrder:135-182 · MissingItemRequestController::update:79-124 · OrderController::attachPallet:210-238 · controladores de fotos (PalletPhotoController, PalletBasePhotoController, OrderTicketController)
Referencia:  `BE-TX`, `QA-INVARIANT`
```
**Qué está pasando:** cada uno hace 2-4 escrituras en tablas distintas (crear/actualizar registro + `ActivityLogger::log()` + a veces `Storage`) sin `DB::transaction()`.
**Por qué importa acá:** el más grave es `MissingItemRequestController::update` — si `PendingItem::create()` falla después de marcar la solicitud "approved", la aprobación queda registrada sin su pendiente correspondiente, silenciosamente.
**Resolución parcial:** `MissingItemRequestController::update` delega en un servicio transaccional que bloquea la solicitud, reevalúa `pending_review` y confirma estado, pendiente y auditoría como una unidad. Una segunda revisión devuelve `409`. Tests fuerzan fallos al crear el pendiente y el log; PostgreSQL enfrenta dos aprobaciones simultáneas y acredita `200/409`, un pendiente y un log.

`PalletController::attachOrder` y `OrderController::attachPallet` comparten un servicio que bloquea pallet y pedido en ese orden, inserta pivot y audit log en una transacción y devuelve `409` para una asociación existente. Crear un pedido por código también confirma o revierte junto a la asociación. Telegram se ejecuta después del commit. PostgreSQL cruza ambos endpoints simultáneamente y acredita `201/409`, una fila pivot y un log.

Las subidas de fotos web y del servicio activo de Telegram guardan primero el objeto y luego confirman registro y auditoría dentro de una transacción. Si cualquier escritura falla, un servicio común elimina el objeto; `ImageConverter` ahora también falla explícitamente cuando el driver devuelve `false`. En el alta de tickets por bot, ticket, foto y auditoría se crean dentro de la misma transacción, después de almacenar la imagen.

**Estado:** 🟡 resuelto para solicitudes faltantes en `eaadf87`, asociaciones pedido/pallet en `a59022b` y subidas de fotos en `ee41860`. Permanece abierto para los borrados físicos de fotos/tickets, que necesitan recuperación durable ante fallos del object storage.

#### H-012 — Foreign keys sin índice (Postgres no las indexa solo)
```
Severidad:   🟡 medio
Categoría:   integridad de datos / performance
Ubicación:   orders.customer_id · order_tickets.order_id · order_ticket_photos.ticket_id · pending_items.* (las 4 FKs) · missing_item_requests.order_item_id/reviewed_by
Referencia:  `BE-DATA`, `BE-PERF`
```
**Qué está pasando:** a diferencia de MySQL, Postgres no crea un índice automático al agregar un FK constraint. Estas columnas no tienen `->index()` explícito ni están cubiertas por el prefijo izquierdo de un índice compuesto.
**Por qué importa acá:** joins y cascade-deletes sobre estas tablas van a full scan a medida que crecen — y un cascade-delete lento amplía la ventana de las race conditions de arriba.
**Resolución:** una migración agrega nueve índices explícitos sólo sobre las FK que no estaban cubiertas por el primer campo de otro índice. Tests de esquema comprueban la cobertura por columna en SQLite y PostgreSQL.
**Estado:** ✅ resuelto en commit `da4a7f4`; Actions run `41001431227` con `ci`, `postgres-concurrency` y `container-security` verdes.

#### H-013 — `activity_logs.pallet_id` no es FK real
```
Severidad:   🟡 medio
Categoría:   integridad de datos
Ubicación:   database/migrations/2025_12_20_060000_create_activity_logs_table.php:11
Referencia:  `BE-DATA`
```
**Qué está pasando:** es `unsignedBigInteger` suelto, mientras que `user_id` y `order_id` en la misma tabla sí están `constrained()`.
**Resolución:** la migración aborta antes de modificar el esquema si detecta una referencia huérfana y luego agrega la FK a `pallets` con `nullOnDelete`. El test elimina el pallet y acredita que el log se conserva con `pallet_id = null`.
**Estado:** ✅ resuelto en commit `da4a7f4`; Actions run `41001431227` con `ci`, `postgres-concurrency` y `container-security` verdes.

#### H-014 — `pallets.created_by` es columna muerta
```
Severidad:   ⚪ bajo · Categoría: calidad
Ubicación:   PalletController::store:53 (siempre `null`)
```
**Estado:** 🔲 abierto

#### H-015 — Sin CHECK constraints en general
```
Severidad:   ⚪ bajo · Categoría: integridad de datos
Ubicación:   todo el esquema
```
**Qué está pasando:** `qty > 0`, `done_qty <= qty`, etc. solo se validan en FormRequests (`min:1`), no en DB — bypasseable por cualquier código que no pase por ahí.
**Resolución:** siete reglas protegen cantidad pedida positiva, cantidad completada entre cero y lo pedido, asignaciones positivas, cantidades faltantes positivas y allowlists de estado para pendientes y solicitudes. PostgreSQL usa `CHECK NOT VALID` seguido de `VALIDATE`; SQLite usa triggers equivalentes porque no permite agregar esos checks sobre tablas existentes. La migración hace preflight antes de modificar el esquema y tiene recuperación probada.

El endpoint de actualización de ítems calcula el estado final bajo lock y devuelve `409` si `done_qty > qty`, incluso cuando la inconsistencia surge al reducir `qty`. H-006 permanece abierto: la suma entre múltiples filas no es un `CHECK` por fila.

**Estado:** ✅ resuelto en commit `1020075`; Actions run `41002593369` con `ci`, `postgres-concurrency` y `container-security` verdes.

#### H-016 — Índice duplicado en `activity_logs.order_id`
```
Severidad:   ⚪ bajo · Categoría: calidad
Ubicación:   migrations 2025_12_20_072953 y 2026_04_21_000001
```
**Estado:** 🔲 abierto

#### H-017 — `ActivityLogger::log()` no es atómico con el cambio que audita
```
Severidad:   ⚪ bajo · Categoría: integridad de datos
Ubicación:   app/Helpers/ActivityLogger.php
```
**Qué está pasando:** al no correr dentro de la misma transacción que el cambio de negocio (ver H-011), una falla del insert de auditoría después de un commit ya aplicado pierde esa entrada del log silenciosamente.
**Estado:** 🟡 resuelto para `adjust-item` en R-01, crear/actualizar bases en R-02, revisión de solicitudes en `eaadf87`, asociación pedido/pallet en `a59022b` y subidas web/Telegram en `ee41860`; abierto en los borrados de archivos citados por H-011. Tests fuerzan la falla de `activity_logs` y acreditan rollback del estado asociado y compensación del objeto subido.

---

### 🟠 CALIDAD / MANEJO DE ERRORES

#### H-018 — Sin handler global de excepciones
```
Severidad:   🟠 alto · Categoría: calidad
Ubicación:   bootstrap/app.php:21-23 (closure vacío) — no existe app/Exceptions/
Referencia:  `BE-ERR`, `SEC-LOG`
```
**Resolución:** el handler final de Laravel conserva 4xx esperados y normaliza toda excepción API 5xx como `message` genérico + `error_id`, también enviado en `X-Error-Id` y agregado al contexto del log.
**Estado:** ✅ resuelto en contrato global de errores, commit `ce48ca3`; Actions run `40966390873`.

#### H-019 — `$e->getMessage()` devuelto al cliente sin importar `APP_DEBUG`
```
Severidad:   🟠 alto · Categoría: seguridad / calidad
Ubicación:   PalletBasePhotoController.php:64,91-100 · OrderImportController.php:153-158 · BotController.php:39 · OrderTicketController.php:107-118
Referencia:  `BE-ERR`
```
**Qué está pasando:** varios `catch` devuelven el mensaje de excepción crudo (y en un caso, `get_class($e)`) al cliente, de forma incondicional — no depende de `APP_DEBUG=false`.
**Resolución:** los cuatro controladores señalados dejaron de devolver texto o clase de la excepción; los fallos inesperados llegan al handler 5xx. Las respuestas visibles del bot activo de Telegram también usan texto genérico. Un test prueba que el detalle no aparece aun con debug habilitado.
**Estado:** ✅ resuelto en contrato global de errores, commit `ce48ca3`; Actions run `40966390873`.

#### H-020 — 16 controladores validan inline en vez de Form Request
```
Severidad:   🟡 medio · Categoría: calidad
Ubicación:   ver lista completa en conversación — AuthController, OrderController, PalletController, etc.
Referencia:  `BE-VALID`
```
**Estado:** 🔲 abierto

#### H-021 — Login inválido devuelve 422 en vez de 401
```
Severidad:   🟡 medio · Categoría: calidad
Ubicación:   AuthController.php:63-67
```
**Resolución R-07:** usuario inexistente, password incorrecto y cuenta inactiva comparten `401` y el mismo cuerpo genérico.
**Estado:** ✅ resuelto en R-07, commit `9bd77b7`; Actions run `40965649678` verde.

#### H-022 — Reglas de autorización en UserController devuelven 422 en vez de 403
```
Severidad:   🟡 medio · Categoría: calidad
Ubicación:   UserController.php:38-39,50-56
```
**Resolución R-07:** autoedición de rol, autodesactivación y modificación de una cuenta superadmin sin ese nivel devuelven `403`; un admin tampoco puede degradar a un superadmin.
**Estado:** ✅ resuelto en R-07, commit `9bd77b7`; Actions run `40965649678` verde.

#### H-023 — "Sin stock disponible" devuelve 422 en vez de 409
```
Severidad:   🟡 medio · Categoría: calidad
Ubicación:   PalletBaseController.php:63,85,186 — PalletBaseService.php:48
Referencia:  `BE-ERR`
```
**Estado:** 🟡 resuelto para disponibilidad/pertenencia en `adjust-item` (R-01), crear/actualizar bases (R-02) y migración (R-03); resta normalizar los demás endpoints señalados.

#### H-024 — Formato de respuesta de error no estandarizado
```
Severidad:   🟡 medio · Categoría: calidad
Ubicación:   todo app/Http/Controllers/Api/
Referencia:  `BE-ERR`, `QA-CONTRACT`
```
**Resolución:** `D-008` fija `message` como campo común, preserva `errors` y datos de conflicto para fallos esperados, y reserva `error_id` correlacionable para 5xx inesperados. El test de contrato acredita tanto el 5xx como la compatibilidad del 422 consumido por frontend.
**Estado:** ✅ resuelto en contrato global de errores, commit `ce48ca3`; Actions run `40966390873`.

#### H-025 — `APP_DEBUG` sin safeguard estructural en el deploy
```
Severidad:   🟡 medio · Categoría: seguridad
Ubicación:   docker-entrypoint.sh, nixpacks.toml — nada fuerza `APP_DEBUG=false`
```
**Resolución:** `deploy-env.sh` fuerza `APP_DEBUG=false` en producción antes de cachear configuración y es usado tanto por Docker como por Nixpacks. CI ejecuta el guard dentro de la imagen con `APP_DEBUG=true` y acredita el resultado `false`.
**Estado:** ✅ resuelto en `7cf1875` + `f01bc14`; Actions run `40967326459`, job `container-security` verde.

#### H-026 — Único uso de `$request->all()`
```
Severidad:   ⚪ bajo · Categoría: calidad
Ubicación:   TelegramBotController.php:41
```
**Estado:** 🔲 abierto

#### H-027 — Inconsistencia 400 vs 422 entre `Order::finalize` y `Pallet::finalize`
```
Severidad:   ⚪ bajo · Categoría: calidad
Ubicación:   OrderController.php:305 vs PalletController.php:224,254
```
**Estado:** 🔲 abierto

#### H-039 — Prácticamente sin tests
```
Severidad:   🟠 alto · Categoría: calidad
Ubicación:   pallet-backend/tests/ (solo ejemplos default)
Referencia:  `QA-RISK`, `QA-LEVELS`
```
**Por qué importa acá:** es la causa raíz por la que H-005 a H-011 llegaron a producción sin detectarse — no hay red de seguridad que los hubiera atrapado.
**Estado:** ✅ resuelto en `f114458` + `c44cafd` — factories y 41 tests de caracterización propios cubren los seis endpoints de escritura priorizados.

#### H-041 — Larastan/PHPStan no está instalado
```
Severidad:   🟡 medio · Categoría: calidad
Ubicación:   composer.json (require-dev) — faltaba el análisis requerido por `BE-GATE` y `QA-STATIC`
```
**Estado:** ✅ resuelto en `468b215` — Larastan/PHPStan nivel 5 con baseline de deuda legacy.

#### H-042 — Dependencias con vulnerabilidades conocidas
```
Severidad:   🔴 crítico
Categoría:   seguridad / supply chain
Ubicación:   composer.lock y package-lock.json de frontend/bot
Referencia:  `DO-SUPPLY`, `SEC-SUPPLY`
```
**Qué está pasando:** el audit del 2026-08-24 reportó 38 advisories Composer en 14 paquetes,
18 vulnerabilidades npm en frontend (14 high) y 11 en WhatsApp bot (2 critical, 5 high).
El paquete npm auxiliar del backend no tiene lockfile y por eso no puede auditarse de forma reproducible.
**Estado:** ✅ resuelto para el sistema activo en `54eb1f7`, `a667f1a` y `d2df817`.
Composer, frontend y npm auxiliar del backend quedaron en 0. `whatsapp-bot/` fue
confirmado por el usuario como legado descartado: no se despliega ni participa de los gates.
Su árbol conserva 3 avisos y no debe reactivarse sin una migración y auditoría separadas. Ver D-001.

---

### 🔴 INFRAESTRUCTURA / PÉRDIDA DE DATOS

#### H-028 — `RESET_ON_STARTUP=true` borra toda la DB operativa
```
Severidad:   🔴 crítico
Categoría:   integridad de datos (pérdida total)
Ubicación:   docker-entrypoint.sh:41-45 — app/Console/Commands/ResetForProduction.php
Referencia:  `DO-INFRA`, `DO-DR`
```
**Qué está pasando:** si esa env var queda en `true` en Render, el próximo boot ejecuta `php artisan app:reset --confirm` automáticamente — `--confirm` viene hardcodeado en el script, cero paso humano intermedio.
**Por qué importa acá:** el propio comentario del script ("Acordate de borrar la variable...") indica que ya es un riesgo conocido/casi ocurrido. Borra orders, pallets, bases, items, users, activity_logs — todo excepto `products`.
**Resolución:** el entrypoint ya no consulta `RESET_ON_STARTUP` ni puede ejecutar `app:reset`. `D-011` limita el borrado al comando manual con `--confirm`, preflight de esquema, transacción DB y tests de preservación de productos. Una variable residual en Render queda sin efecto.
**Estado:** ✅ resuelto estructuralmente en `f908d39`; Actions run `40982444647` verde.

#### H-029 — Fotos sin garantía de persistencia en redeploy
```
Severidad:   🔴 crítico
Categoría:   integridad de datos (pérdida total)
Ubicación:   config/filesystems.php:16 (default `local`) — .env.example (default `public`)
Referencia:  `DO-INFRA`, `DO-DR`
```
**Qué está pasando:** el contenedor no tiene volumen persistente; si `FILESYSTEM_DISK` en Render no está explícitamente en `r2`, todas las fotos (evidencia central del dominio) se pierden en el próximo deploy.
**Por qué importa acá:** no verificable desde el repo — depende 100% de una env var externa sin ningún check en código que lo garantice.
**Estado:** 🔲 abierto — **verificar HOY en el dashboard de Render**

#### H-030 — Contenedor corre como root
```
Severidad:   🟠 alto · Categoría: seguridad
Ubicación:   Dockerfile (sin USER) — supervisord.conf (sin user=)
Referencia:  `DO-CONTAINER`
```
**Resolución:** la imagen declara `USER www-data`, limita ownership a rutas de escritura y supervisor ejecuta web/worker como `www-data`. CI construye la imagen, inspecciona `Config.User` y comprueba que el UID efectivo no sea 0.
**Estado:** ✅ resuelto en `7cf1875` + `f01bc14`; Actions run `40967326459`, job `container-security` verde.

#### H-031 — Sin CI de ningún tipo
```
Severidad:   🟠 alto · Categoría: calidad / proceso
Ubicación:   sin .github/workflows propio del proyecto
Referencia:  `DO-CI`, `QA-GATE`
```
**Estado:** ✅ resuelto en `e431642` — GitHub Actions ejecuta Pint, PHPStan, PHPUnit y `composer audit`; falta validar el primer run remoto.

#### H-032 — `pallet-frontend/.env` trackeado en git
```
Severidad:   🟠 alto · Categoría: seguridad
Ubicación:   pallet-frontend/.env — sin entrada en .gitignore ni .dockerignore
Referencia:  `DO-ENV`, `SEC-SECRET`
```
**Qué está pasando:** contenido actual inofensivo (`VITE_API_BASE_URL=/api/v1`), pero el patrón de protección está roto para este archivo específico.
**Resolución:** el archivo real fue retirado del índice sin borrar la copia local, Git y Docker lo ignoran, y `pallet-frontend/.env.example` conserva sólo `VITE_API_BASE_URL=/api/v1` como documentación pública.
**Estado:** ✅ resuelto en `7cf1875`; Actions run `40967326459` verde.

#### H-033 — Imagen final incluye el binario de Composer
```
Severidad:   🟡 medio · Categoría: seguridad/calidad
Ubicación:   Dockerfile:38
```
**Resolución:** Composer se usa sólo en `vendor-build`; runtime copia `vendor/` ya resuelto. CI ejecuta `command -v composer` dentro de la imagen y exige resultado vacío.
**Estado:** ✅ resuelto en `0860d46`; Actions run `40981608096`, job `container-security` verde.

#### H-034 — `.dockerignore` incompleto
```
Severidad:   🟡 medio · Categoría: calidad
Ubicación:   .dockerignore (falta *.md, tests/, storage/logs/*, scraper/node_modules)
```
**Resolución:** el contexto excluye documentación, tests backend, logs, caches, secretos, VCS, `scraper/node_modules` y el bot de WhatsApp inactivo. CI acredita que tests y README backend no existen en runtime.
**Estado:** ✅ resuelto en `0860d46`; Actions run `40981608096` verde.

#### H-035 — Imágenes base sin pinear a patch/digest
```
Severidad:   🟡 medio · Categoría: calidad
Ubicación:   Dockerfile (php:8.4-cli-alpine, node:22-alpine, composer:2)
```
**Resolución:** Node 22 Alpine, PHP 8.4 CLI Alpine y Composer 2 quedaron fijados a sus digests oficiales vigentes al 2026-10-29; `D-010` exige actualización revisada y repetición de gates.
**Estado:** ✅ resuelto en `0860d46`; Actions run `40981608096` verde.

#### H-036 — Sin `HEALTHCHECK` en el Dockerfile
```
Severidad:   🟡 medio · Categoría: calidad
Ubicación:   Dockerfile
```
**Resolución:** `/up` conserva liveness y `/ready` verifica DB con respuesta mínima 200/503. Docker declara healthcheck contra readiness y existen tests de disponibilidad y fallo sin fuga de detalles.
**Estado:** ✅ resuelto en `0860d46`; Actions run `40981608096` verde.

#### H-037 — `ResetForProduction` referencia tabla inexistente
```
Severidad:   ⚪ bajo · Categoría: calidad
Ubicación:   app/Console/Commands/ResetForProduction.php (tabla `movements`)
```
**Resolución:** `movements` fue retirada; se incorporaron las tablas operativas vigentes y el comando cancela antes de borrar si el esquema no coincide con lo esperado. Tests cubren falta de confirmación, reset correcto, preflight fallido y ausencia de trigger por entrypoint.
**Estado:** ✅ resuelto en `f908d39`; Actions run `40982444647` verde.

#### H-038 — `nixpacks.toml` de vigencia ambigua junto al Dockerfile
```
Severidad:   ⚪ bajo · Categoría: calidad
Ubicación:   nixpacks.toml
```
**Estado:** 🔲 abierto

---

### 🟡 PERFORMANCE

#### H-040 — `PendingItemController::index` sin paginación
```
Severidad:   🟡 medio · Categoría: performance
Ubicación:   app/Http/Controllers/Api/PendingItemController.php:14-29 (usa ->get(), no ->paginate())
```
**Resolución:** el listado ahora exige un estado válido, pagina en servidor con 25 registros por defecto y máximo 100, y devuelve metadatos estables. El frontend consulta pendientes y resueltos por separado, conserva totales del servidor y ofrece navegación accesible. Un test de contrato cubre límites, páginas y presupuesto constante de consultas.

Con 120 registros sobre SQLite local, el escenario reproducible pasó de 120 a 25 filas por respuesta, de 44.332 a 9.060 bytes y de p95 23,56 ms a 6,63 ms. Es evidencia comparativa local, no una afirmación de latencia productiva; detalle en `benchmarks/results/2026-10-29-h040-pending-items.md`.

**Estado:** ✅ resuelto en commit `1b67a32`; Actions run `41000461628` con `ci`, `postgres-concurrency` y `container-security` verdes.

**N+1 — candidatos identificados, sin medir todavía:** ver `benchmarks/README.md` (5 endpoints priorizados: `orders/{id}`, `public/orders/{token}`, `pallets/{id}`, `orders` listado, webhook de Telegram). `Model::preventLazyLoading()` y Debugbar ya están activos en local (`BE-PERF`) — el baseline con k6 está pendiente de correr.

---

## 3. RESUMEN POR CATEGORÍA

| Categoría | 🔴 | 🟠 | 🟡 | ⚪ | Total |
|---|---|---|---|---|---|
| Seguridad | 1 | 3 | 3 | 0 | 7 |
| Integridad de datos | 7 | 3 | 2 | 2 | 14 |
| Performance | 0 | 0 | 1 | 0 | 1 |
| Calidad | 0 | 3 | 10 | 7 | 20 |
| **Total** | **8** | **9** | **16** | **9** | **42** |

*(algunos hallazgos tocan más de una categoría; se contaron en la categoría dominante — p. ej. H-019 es fuga de información pero se cuenta como seguridad, H-030/H-032 son de Docker/git pero también seguridad)*

---

## 4. BASELINE DE PERFORMANCE

| Endpoint | p50 | p95 | Queries | Fecha |
|---|---|---|---|---|
| `GET /api/v1/pending-items?status=pending&per_page=25` (120 registros, SQLite local) | 5,76 ms | 6,63 ms | 6 | 2026-10-29 |
| _(pendiente — correr `benchmarks/k6-hot-endpoints.js`)_ | | | | |

**Cómo se mide:** ver `benchmarks/README.md`.
