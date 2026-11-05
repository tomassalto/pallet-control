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
**Resolución transversal:** D-020 agrega triggers en PostgreSQL y SQLite que bloquean el `order_item` y rechazan cualquier asignación o reducción de cantidad que deje la suma por encima de lo pedido. El inventario encontró dos escritores fuera de D-003: el import de pedidos, que ahora bloquea y responde `409` sin escribir, y la desvinculación de pallet, que ahora es transaccional.
**Evidencia:** tests SQLite de guarda directa, límite exacto, preflight, import y desvinculación; tests PostgreSQL de carrera real entre dos conexiones, import contra adjust, recorridos cruzados sin deadlock y migración reversible.
**Estado:** ✅ resuelto en commits `39a45ab`, `24b5eea` y `6b4b48b`; Actions run `41024434735` con los tres jobs verdes.

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

Los borrados de fotos de pallet, base y ticket, de tickets completos, de pallets y de bases bloquean y releen sus filas, eliminan la entidad, auditan y registran una intención durable por objeto en `pending_file_deletions` dentro de una transacción (D-018). El borrado físico ocurre después del commit mediante un job y un barrido supervisado con backoff, intentos acotados y alerta crítica al agotarse. Tests acreditan que un fallo de auditoría conserva filas y archivos sin intención, que un fallo de storage conserva la intención para reintento y que reprocesar es idempotente.

**Estado:** ✅ resuelto para los flujos auditados: solicitudes faltantes en `eaadf87`, asociaciones pedido/pallet en `a59022b`, subidas de fotos en `ee41860` y borrados de fotos/tickets en `60a0c6c`; Actions run `41013814687` con los tres jobs verdes. `pallet:archive` quedó fuera de este cambio; su orden inseguro se trata en H-048 y el comando ya no borra nada.

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
**Resolución:** las altas web y el pallet destino de una migración registran al usuario autenticado; una migración con preflight agrega FK `nullOnDelete` e índice. No es autorización ni tenancy (D-021). Los históricos conservan `null`.
**Estado:** ✅ resuelto en commit `7cc6b07`; Actions run `41026830057` con los tres jobs verdes.

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
**Verificación:** SQLite y PostgreSQL 17 tienen un único índice `activity_logs_order_id_index`; la segunda migración no crea nada por `IF NOT EXISTS`. Su `down()` sí elimina el índice de la primera, y ese riesgo de rollback queda documentado en D-021. Un test fija exactamente un índice que empieza por `order_id` en ambos motores.
**Estado:** ✅ cerrado sin migración: no existe duplicado (commit `7cc6b07`; Actions run `41026830057`).

#### H-017 — `ActivityLogger::log()` no es atómico con el cambio que audita
```
Severidad:   ⚪ bajo · Categoría: integridad de datos
Ubicación:   app/Helpers/ActivityLogger.php
```
**Qué está pasando:** al no correr dentro de la misma transacción que el cambio de negocio (ver H-011), una falla del insert de auditoría después de un commit ya aplicado pierde esa entrada del log silenciosamente.
**Estado:** ✅ resuelto para `adjust-item` en R-01, crear/actualizar bases en R-02, revisión de solicitudes en `eaadf87`, asociación pedido/pallet en `a59022b` y subidas web/Telegram en `ee41860` y borrados de fotos/tickets/pallets/bases según D-018. Tests fuerzan la falla de `activity_logs` y acreditan rollback del estado asociado y compensación del objeto subido.

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
**Avance:** R-04 introdujo `StoreOrderItemRequest`. Las tres subidas web de fotos comparten ahora un contrato único (D-022): jpeg/png/webp verificados por contenido, 20 MB, mensajes en español y `422` estándar. Se retiró el fallback de base, que según los tests de caracterización nunca producía un alta.
**Avance — pendientes:** `PendingItemController` (index, store y update) usa `IndexPendingItemsRequest`, `StorePendingItemRequest` y `UpdatePendingItemRequest`, con las mismas reglas, en el mismo orden y sin mensajes propios. La autorización sigue en `auth:sanctum`, `user.active` y `has.role` (`authorize()` devuelve `true`). La regla de pertenencia ítem/pedido sigue en el controlador, después de la validación, con su `422` y su cuerpo sin `errors`. 20 tests de caracterización (97 assertions) pasaron sin modificaciones antes y después del refactor: `401` en los cinco endpoints, `403` sin rol aun con cuerpo inválido, `404` de binding antes del `403` y de la validación, `422` con cuerpo exacto y orden de claves de `errors`, pertenencia sin efectos, allowlist de asignación masiva y re-resolución idempotente. No existe ningún `409` en este controlador.

**Avance — solicitudes de faltantes:** `MissingItemRequestController` (index, store público y update/revisión) usa `IndexMissingItemRequestsRequest`, `StoreMissingItemRequestRequest` y `UpdateMissingItemRequestRequest`, con las mismas reglas en el mismo orden y sin mensajes propios. La autorización sigue en `auth:sanctum`, `user.active` y `admin` (`authorize()` devuelve `true`), y el endpoint público conserva su throttle `public-order-write` y la resolución del token antes de la validación: un pedido desconocido responde `404` `{"message":"Pedido no encontrado"}` aunque el payload sea inválido (el Form Request lo garantiza en `prepareForValidation` con `HttpResponseException`, que reproduce el cuerpo exacto). Pertenencia ítem/pedido, techo de cantidad, anti-duplicado `409`, inmutabilidad de pedido `done` y revisión atómica quedan en sus límites previos, sin mover lógica transaccional a los Form Requests. Dos cambios de contrato deliberados: `order_item_id` no numérico pasa de `validation.exists` a `validation.integer` (H-050) y un `status` de index fuera del allowlist pasa de `200` con lista vacía a `422` `validation.in` (antes el filtro se leía sin validar; decisión gobernada en `D-032`). La caracterización fijó primero el contrato previo con 29 tests; tras el refactor, 27 pasan sin modificaciones y 2 se actualizaron deliberadamente para fijar esos dos cambios, más los 4 tests preexistentes de revisión atómica. El conjunto acredita rate limit `429`, rollback completo ante fallo de pendiente o auditoría, orden determinista de claves de `errors` y cero efectos ante entrada inválida.

**Avance — asociación pedido/pallet:** `OrderController::attachPallet` usa `AttachPalletToOrderRequest` con la misma regla `pallet_id` más `bail` (H-050), `authorize()` en `true` (la autorización sigue en `auth:sanctum`, `user.active` y `has.role`) y el controlador consume `validated()`. La caracterización congeló 16 tests (58 assertions): `401` anónimo e inactivo, `403` de `has.role` aun con payload inválido, `404` de binding antes del `403` y de la validación, `422` con cuerpo exacto, cero pivot y cero activity logs ante validación fallida, `201` con `message`/`pallet`/`pallets`, `409` de asociación repetida y de pedido o pallet `done`, campos extra ignorados y Telegram sólo en el éxito. Todos pasaron sin cambios tras el refactor: el contrato observable no cambió (ver H-050).

**Inventario restante (2026-11-03):** 12 controladores con `$request->validate()` inline, agrupados por familia:
- Auth: `AuthController::register`, `login`. Usuarios: `UserController::updateRole`.
- Pedidos: `OrderController::updateStatus`, `canFinalizeBatch`; `OrderImportController::import`.
- Pallets: `PalletController::store`, `updateStatus`, `attachOrder`.
- Tickets y OCR: `OrderTicketController::store`. Su `storePhoto`, igual que `PalletBasePhotoController::store`, aplica a propósito las reglas estáticas de un Form Request después de la pertenencia (D-022).
- Anotaciones: `PhotoAnnotationController::store`. Productos: `ProductController::store`, `ProductImageController::bulkUpdate`, `checkExisting`.
- Clientes: `CustomerController::store`.
- Bot legado: `BotController::uploadPhoto` (fuera de alcance).

Filtros de listados leídos sin validar: `OrderController::index`, `PalletController::index`, `CustomerController::index`, `ActivityLogController::index` y `Admin\StorageController`.

**Estado:** 🟡 parcial: subidas de fotos en `e987c13` (Actions run `41028487256`); pendientes en `ac4f871` (Actions run `41186832405`). El resto del inventario sigue validando inline.

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
**Resolución final:** ajustar un ítem en un pallet finalizado devuelve `409` (D-025); los servicios transaccionales ya usaban `409` para disponibilidad y pertenencia.
**Estado:** ✅ resuelto en R-01 a R-03 y en commit `7344135`; Actions run `41036574975` con los tres jobs verdes.

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
**Resolución:** finalizar pedido, finalizar pallet y reabrir pallet expresan el conflicto de estado con `409` y conservan su cuerpo (D-025).
**Estado:** ✅ resuelto en commit `7344135`; Actions run `41036574975` con los tres jobs verdes.

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

**Actualización 2026-11-03 (rama `hardening/frontend-supply-chain`, base `e85a32d`):**
los advisories `GHSA-3g3m-2fjr-6pw4` (glob `9.0.0`–`14.0.1` → `14.0.2`) y
`GHSA-g3hv-75wg-m249` (minimatch `<11.0.1` → `11.0.1`), publicados el 2026-10-27,
introdujeron 13 moderados que mantenían rojo `npm audit --audit-level=moderate` en CI.

| Advisory | Severidad | Paquete | Antes | Después | Cadena | Alcance |
|---|---|---|---|---|---|---|
| GHSA-3g3m-2fjr-6pw4 | moderada | glob | 11.1.0 | 14.0.3 (override) | `vite-plugin-pwa → workbox-build → glob` | tooling de build (`generateSW`), no runtime |
| GHSA-g3hv-75wg-m249 | moderada | minimatch | 3.1.5 / 5.1.9 / 10.2.5 | 11.0.4 (override) | `eslint`, `@eslint/config-array`, `@eslint/eslintrc` → `minimatch@3`; `workbox-build → @trickfilm400/rollup-plugin-off-main-thread → ejs → jake → filelist → minimatch@5`; `glob@11 → minimatch@10` | tooling de lint/build, no runtime |
| (transitivo) | moderada | ejs/jake/filelist/@eslint-* | — | resuelto por el override | los paquetes se marcaban sólo por su dependencia en minimatch | tooling |
| GHSA-gcqx-w48m-w2h4 | baja ×4 | deepmerge | 4.3.1 | 4.3.1 (sin cambio) | `vite-plugin-pwa → workbox-build → @rollup/plugin-node-resolve → deepmerge` | build-time; gobernado por `D-004` |

Ambos advisories exigen patrones glob controlados por un atacante; el consumo real es
sobre fuentes propias del repositorio (config ESLint y `globPatterns` del service worker),
por lo que el riesgo es de supply chain del tooling, no de explotación remota.
Remediado con overrides acotados en `D-029`; antes: 2 bajos + 13 moderados, después:
4 bajos, 0 moderados. No se aplicó `npm audit fix --force` (proponía un downgrade mayor
de `vite-plugin-pwa`).

**Actualización 2026-11-04 (rama `security/symfony-advisories-2026-11-04`, base `7ccd2e0`):**
una ola de advisories Symfony publicada el 2026-11-04 dejó `composer audit` en rojo con
8 advisories en 5 paquetes (`DO-SUPPLY`/`SEC-SUPPLY`). Se aplicó la actualización mínima
compatible dentro de cada línea — `7.4.22 → 7.4.23` y `8.1.5`/`8.1.10 → 8.1.11` — sin
cambios en `composer.json`, sin upgrades de major y sin ignores. Fuentes oficiales:
`symfony.com/blog/symfony-7-4-23-released` (2026-11-04) y el changelog `v8.1.10...v8.1.11`
de `symfony/symfony`; los parches están en 5.4.56/6.4.50/7.4.23/8.1.11 según cada rama.

| Advisory | Paquete | Antes | Después | Superficie vulnerable | Alcance en el proyecto |
|---|---|---|---|---|---|
| GHSA-hwfg-qwvh-2wfx | symfony/yaml | 8.1.10 | 8.1.11 | tiempos de parseo exponenciales/cuadráticos; decodificación CSV/YAML en HttpKernel sin `acceptFormat` | `symfony/yaml` sólo llega por `laravel/sail` (`require-dev`, fuera de la imagen `--no-dev`); la app no usa `#[MapRequestPayload]` ni formatos YAML |
| GHSA-wg5q-m2ww-f3c9 | symfony/http-kernel | 7.4.22 | 7.4.23 | fragmentos inline (ESI/HInclude) + headers `Forwarded-*` no confiables en el request principal | Laravel no usa el fragment renderer de Symfony; no hay `_fragment` ni ESI configurado |
| GHSA-xg2q-4rrq-657q | symfony/http-foundation | 7.4.22 | 7.4.23 | el shortcut `private_ranges` de trusted proxies aceptaba IPv4-mapped/6to4/Teredo/NAT64 como proxies | `TrustProxies` de Laravel nunca pasa el shortcut: usa IPs explícitas o `*`→`REMOTE_ADDR`; la app no configura `trustProxies()` |
| CVE-2026-118514 | symfony/http-foundation | 7.4.22 | 7.4.23 | `MethodRequestMatcher` (HEAD bypassea `methods` de access_control) — fix incompleto de CVE-2026-45075 | `symfony/security-http` no está instalado; el matcher es del firewall Symfony, no del router de Laravel |
| GHSA-3h27-hjv7-fqjx | symfony/http-foundation | 7.4.22 | 7.4.23 | path rules frente a PHP bypasseables agregando el path al script name | idem: feature del firewall Symfony Security, sin equivalente en Laravel |
| GHSA-5mx8-mwrp-5693 | symfony/http-foundation | 7.4.22 | 7.4.23 | URLs generadas podían apuntar a un host elegido por el cliente (`Request::getBaseUrl()`) | código alcanzable — todo request Laravel lo ejecuta — pero el host real lo fija el proxy de OnRender; corregido de todas formas |
| GHSA-6prc-h956-hxjq | symfony/filesystem | 8.1.5 | 8.1.11 | cualquier prefijo antes de `://` tratado como raíz, paths podían escapar de su base | llega por `aws/aws-sdk-php` (cliente R2 en producción); las rutas de upload pasan por `league/flysystem` y validación propia, no por `Path::isAbsolute` con input externo |
| CVE-2026-118516 | symfony/routing | 7.4.22 | 7.4.23 | host placeholders en matchers compilados podían matchear el inicio del path, bypasseando control de acceso por path | Laravel usa su propio router; `symfony/routing` es dependencia transitiva declarada por `laravel/framework` pero no despacha requests |

Evaluación de alcanzabilidad: la mayoría de las superficies vulnerables no se alcanzaban
directamente desde el código de la aplicación — los advisories pegan en componentes
Symfony que Laravel no usa para seguridad de requests (firewall, fragments, matchers
compilados), en una dependencia sólo-dev (`yaml`/`sail`) o en paths que no reciben input
externo (`filesystem` vía SDK). La excepción es `GHSA-5mx8-mwrp-5693`:
`Request::getBaseUrl()` sí se ejecuta en cada request Laravel, aunque el host real lo
fija el proxy de OnRender (mitigación de despliegue) y la versión instalada ya incluye
el parche. Se parchó todo igual porque existía versión compatible, como exige
`DO-SUPPLY` (la alcanzabilidad informa prioridad, no excusa el audit rojo).

Comando: `composer update symfony/http-foundation symfony/http-kernel symfony/routing
symfony/filesystem symfony/yaml` → 5 updates, 0 installs, 0 removals, 0 paquetes
ajenos. `composer validate` OK, `composer install` desde lock sin cambios pendientes,
`composer audit` → 0 advisories. Gates: Pint `--test` verde, PHPStan sin errores ni
baseline nuevo, suite SQLite 378 tests/1479 assertions (2 skips preexistentes).
PostgreSQL y container-security quedan acreditados por el job `postgres-concurrency` y
`container-security` de CI (sin servidor local en esta estación).

#### H-043 — Un `401` autenticado no invalidaba el usuario en memoria
```
Severidad:   🟡 medio · Categoría: seguridad (sesión frontend)
Ubicación:   pallet-frontend/src/api/client.js · src/main.jsx · src/auth/AuthContext.jsx
Referencia:  `FE-SESSION`, `FE-STATE`, `FE-DATA`, `SEC-IDENTITY`
Origen:      auditoría posterior sobre `127b828`
```
**Qué estaba pasando:** ante un `401` de una petición con token, el cliente API borraba el token, vaciaba la cache de React Query mediante un callback global registrado en `main.jsx` y navegaba a `/login`, pero `AuthProvider` conservaba `user`. `RequireAuth` podía seguir viendo una sesión válida sin token. La limpieza de logout y la de `401` estaban duplicadas en dos lugares.
**Resolución:** `AuthProvider` es la única autoridad de cierre de sesión: `endSession` borra el token, cancela y vacía las queries y pone `user` en `null`; lo usan logout, el arranque fallido y el `401` autenticado, registrado por el propio provider en el cliente. El cliente sólo invalida si el token sigue siendo el de la petición, por lo que dos `401` concurrentes ejecutan la invalidación una vez y un `401` tardío no cierra una sesión nueva. La navegación queda a cargo de `RequireAuth`. Un `401` de login sin token sigue mostrando el mensaje del backend. Ver revisión de D-024.
**Evidencia:** tests integrados con el `AuthProvider` real: ruta protegida visible, `401` posterior, token y usuario eliminados, cache vacía, contenido protegido ausente y pantalla de login; variante con dos `401` concurrentes sin errores ni reintentos; login con contraseña incorrecta conserva la cache y no muestra "Sesión expirada"; tests del cliente para invalidación única y token reemplazado.
**Estado:** ✅ resuelto en `6e888bf`; Actions run `41101014456` con `ci`, `postgres-concurrency` y `container-security` verdes.

#### H-044 — El import eliminaba ítems ausentes junto con sus asignaciones
```
Severidad:   🟠 alto · Categoría: integridad de datos
Ubicación:   pallet-backend/app/Services/OrderImportService.php
Referencia:  `BE-TX`, `BE-VALID`, `BE-ERR`, `QA-INVARIANT`, `DOMINIO.md`
Origen:      auditoría posterior sobre `127b828`
```
**Qué estaba pasando:** D-020 comparaba la nueva cantidad sólo para los EAN presentes en el archivo. Los ítems ausentes se borraban con `whereNotIn('ean', ...)` y la cascada eliminaba sus filas de `pallet_base_order_items`: una base perdía unidades asignadas sin conflicto ni rastro. El borrado tampoco se limitaba a los ítems bloqueados.
**Resolución:** un ítem bloqueado ausente del archivo con cantidad asignada mayor que cero produce un conflicto `{ean, assigned, requested: 0, reason: "removed"}`; las reducciones llevan `reason: "reduced"`. Todos se informan juntos en el `409` existente y no se escribe nada. Los ítems ausentes sin asignaciones se siguen eliminando, ahora sólo entre los bloqueados por el import. El orden de locks de D-020 no cambió. Un archivo vacío o ininterpretable sigue respondiendo `422` antes del servicio. Ver revisión de D-020.
**Evidencia:** tests SQLite de ausente asignado, varios conflictos juntos (incluida la suma entre dos bases), ausente sin asignación eliminado con `removed = 1`, límite exacto, archivo vacío/ininterpretable y rollback por fallo de auditoría. PostgreSQL enfrenta un import que omite un ítem con una asignación concurrente sobre ese ítem: nunca confirman ambos, no hay `5xx` y la asignación nunca se pierde en silencio.
**Estado:** ✅ resuelto en `03b9c9f`; Actions run `41101014456` con los tres jobs verdes. La política general de conservación sigue en H-009.

#### H-045 — Finalizar y reabrir sin transacción ni lock
```
Severidad:   🟠 alto · Categoría: integridad de datos
Ubicación:   pallet-backend/app/Http/Controllers/Api/PalletController.php (finalize, reopen), OrderController.php (finalize)
Referencia:  `BE-TX`, `BE-VALID`, `BE-ASYNC`, `SEC-LOG`, `QA-INVARIANT`, `DOMINIO.md`
Origen:      riesgo residual de D-025
```
**Qué estaba pasando:** los requisitos se verificaban sobre el modelo de la ruta y el estado se escribía después sin lock ni transacción. Bases, fotos, asignaciones, cantidades o asociaciones podían cambiar entre lectura y escritura. Dos finalizaciones o una finalización y una reapertura podían decidir con datos obsoletos. Un fallo de auditoría dejaba el estado aplicado. Repetir finalizar respondía éxito con un log nuevo. adjust-item comprobaba `done` fuera de su transacción, y eliminar una foto de base bloqueaba la foto antes que el pallet.
**Resolución:** `FinalizationService` bloquea entidad y dependencias en el orden extendido de D-020, re-evalúa estado y requisitos dentro de la transacción, confirma estado y auditoría juntos y deja Telegram después del commit. Las repeticiones responden `409` sin log. adjust-item re-evalúa el estado bajo lock y el borrado de foto de base bloquea primero sus padres. Ver D-026.
**Evidencia:** 20 tests SQLite cubren éxito, `paused`, requisitos faltantes, repetición, reapertura, asignaciones en pallets no asociados, rollback por fallo de auditoría en las tres transiciones, Telegram con la transacción ya confirmada y 10 queries constantes con 2 y 20 bases o ítems. En PostgreSQL, una compuerta de lock fuerza la superposición real con tres iteraciones por escenario: finalizar pallet contra adjust-item y contra eliminar foto, finalizar pedido contra cambio de cantidad, dos finalizaciones de pallet, dos de pedido, y finalizar contra reabrir. Sin `5xx` ni deadlock, un único log exitoso y orden de logs coherente con el estado final. Cinco repeticiones locales estables.
**Estado:** ✅ resuelto en `74586a3`; Actions run `41173522786` con `postgres-concurrency` y `container-security` verdes. En `ci` pasaron todos los gates backend; el job falla por avisos npm nuevos del frontend, ajenos a este cambio (ver `EJECUCION.md`).

#### H-046 — `PATCH` de pallet y pedido escribe `status` sin reglas
```
Severidad:   🟡 medio · Categoría: integridad de datos
Ubicación:   pallet-backend/app/Http/Controllers/Api/PalletController.php::updateStatus, OrderController.php::updateStatus
Referencia:  `BE-VALID`, `BE-TX`, `SEC-LOG`, `DOMINIO.md`
Origen:      inventario de escritores de estado de D-026
```
**Qué está pasando:** `PATCH /pallets/{pallet}` y `PATCH /orders/{order}` aceptan `status` en `open`, `paused` o `done` y lo escriben sin requisitos, lock ni auditoría. Pueden finalizar o reabrir sin pasar por D-026.
**Por qué importa:** existe un segundo camino de transición que omite la autoridad transaccional y no deja evidencia.
**Resolución:** `PATCH` corre en transacción con `FOR UPDATE` sobre la entidad: entidad `done` → `409`, `status` destino `done` → `409` apuntando al endpoint dedicado. `open`↔`paused` se conserva y ahora registra `order_status_changed`/`pallet_status_changed`. Se agregó `POST /orders/{order}/reopen` (reapertura auditada simétrica a pallets). La decisión de dominio D-031 hizo inmutable todo `done` y cubrió además todos los escritores directos e indirectos: bases, fotos, asignaciones, ítems, tickets, OCR, pendientes, faltantes, anotaciones, attach/detach, borrados web/admin/bot. Ver D-031.
**Evidencia:** `FinalizedEntityImmutabilityTest` (≈35 tests SQLite): ambos PATCH rechazan `done` con `409` sin escribir, `paused` sigue funcionando con auditoría, reapertura de pedido con estado+log+Telegram post-commit, rechazo `409` en cada escritor inventariado sin efectos (incluida compensación de archivos en subidas), y restauración de mutabilidad tras reapertura. PostgreSQL suma dos escenarios con compuerta: finalize pallet vs crear base y finalize pedido vs PATCH de estado — sin `5xx`, un solo ganador por turno.
**Estado:** ✅ resuelto (D-031).

#### H-047 — Intenciones de borrado sin retención ni exclusión entre procesos
```
Severidad:   🟡 medio · Categoría: integridad de datos / operación
Ubicación:   pallet-backend/app/Services/StorageCleanupService.php, app/Jobs/DeleteStoredFile.php, comandos storage:*
Referencia:  `BE-TX`, `BE-ASYNC`, `BE-DATA`, `DO-OBS`, `DO-DR`, `SEC-LOG`, `QA-RESILIENCE`, `QA-DATA`
Origen:      trade-off pendiente de D-018
```
**Qué estaba pasando:** las intenciones completadas quedaban indefinidamente en `pending_file_deletions`. El job y el barrido podían procesar la misma intención a la vez y llamar dos veces al storage. Cada fallo guardaba `attempts` desde su copia en memoria, así que dos fallos simultáneos registraban un solo intento y retrasaban el agotamiento y su auditoría.
**Resolución:** un reclamo con lease de 300 s mediante `UPDATE` condicional, sin transacción durante la llamada al storage. La completitud se confirma de forma idempotente, y el incremento de intentos ocurre bajo lock corto y siempre cuenta. Las completadas se retienen 30 días (mínimo 2). `storage:prune-deletions` purga sólo las completadas anteriores al corte, con dry-run y auditoría `file_deletions_pruned` sin rutas. `storage:cleanup-status` informa leases y retención. Ver D-027.
**Evidencia:** 15 tests SQLite con reloj controlado cubren:
- reclamo exclusivo con una sola llamada efectiva, y job ante lease ajeno;
- fallo concurrente con lease vencido que conserva ambos intentos (con el código anterior quedaba 1);
- lease vencido recuperado con aviso, y crash entre delete y persistencia;
- archivo inexistente y agotadas excluidas del job;
- purga de completadas viejas que conserva recientes, pendientes, reintentando, agotadas y en proceso;
- dry-run sin escrituras, auditoría sin rutas incluso con cero filas, y retención mínima;
- métricas antes y después de purgar, la guarda lease/timeout/intervalo, el saneamiento de errores y un job tardío sobre una intención purgada.

En PostgreSQL, dos barridos reales compiten bajo compuerta `FOR UPDATE`, con intención nueva o con lease vencido de un worker muerto, tres iteraciones cada uno: exactamente uno completa y el otro omite.
**Estado:** ✅ resuelto en `249b3d1`; Actions run `41183803393` con `postgres-concurrency` y `container-security` verdes. En `ci` pasaron todos los gates backend; falla sólo el audit npm del frontend, ajeno a este cambio (ver `EJECUCION.md`).

#### H-048 — `pallet:archive` borra evidencia sin orden seguro ni política de retención
```
Severidad:   🟠 alto · Categoría: integridad de datos / pérdida de evidencia
Ubicación:   pallet-backend/app/Console/Commands/ArchiveOldData.php, routes/console.php
Referencia:  `BE-TX`, `BE-DATA`, `BE-ASYNC`, `SEC-FILE`, `SEC-LOG`, `SEC-DATA`, `DO-DR`, `DO-RELEASE`, `QA-RESILIENCE`, `DOMINIO.md`
Origen:      pendiente declarado en D-018 y en la sesión de borrado recuperable
```
**Registro y ejecución:** el comando se autodescubre en `app/Console/Commands` y estaba **programado** en `routes/console.php` (`monthlyOn(1, '03:00')`, `runInBackground`, salida a `storage/logs/archive.log`). Ninguno de los runtimes versionados invoca `schedule:run` ni `schedule:work`: `supervisord.conf` corre `web`, `worker` y `file-cleanup`; `docker-entrypoint.sh` y `nixpacks.toml` no lo llaman, y el `Dockerfile` no instala cron. Un cron externo o configuración de la plataforma (Render) fuera del repositorio no es verificable desde el código. El riesgo era latente: activar el scheduler para cualquier otra tarea lo encendía. Ningún endpoint ni test lo invocaba.

**Qué eliminaba, sin confirmación y en este orden:**
1. `order_ticket_photos.ocr_log := null` para fotos de pedidos `done` con `orders.updated_at` de más de 3 meses. `UPDATE` masivo, sin auditoría.
2. Objetos de storage de todas las fotos de ticket de pedidos `done` con `updated_at` de más de 6 meses, uno por uno, capturando y descartando el error. Recién después, `DELETE` de esas filas, aunque el objeto no se hubiera borrado.
3. `DELETE` de **todo** `activity_logs` con `created_at` de más de 6 meses, sin filtro por acción.

**Grafo afectado:** ninguna tabla referencia `order_ticket_photos` (las anotaciones dependen de `pallet_base_photos`), así que no había cascadas más allá de la propia fila. `order_tickets` quedaba vivo y vacío. `activity_logs` no es referenciado por nadie, pero contiene la auditoría de negocio y operativa: auth, privilegios, finalizaciones, borrados, `file_deletion_exhausted` y `file_deletions_pruned`. `pending_file_deletions` no se usaba.

**Por qué importa:**
- Storage antes que la base, sin transacción ni intención durable: un fallo de DB deja filas apuntando a objetos inexistentes, y un fallo de storage borra la fila igual y deja objetos huérfanos sin rastro. Es exactamente el patrón que D-018 eliminó en los demás borrados.
- Sin auditoría de la corrida ni confirmación, sin preflight, sin lotes, sin recuperación.
- El corte usa `orders.updated_at`, que no es una fecha de finalización: cualquier escritura lo mueve y un pedido reabierto y refinalizado (D-026) reinicia el reloj. No existe columna de finalización.
- Purgar `activity_logs` contradice D-013 y D-027 (el audit log se conserva) y la invariante de `DOMINIO.md` de que toda acción significativa sea auditable. También borraría la única evidencia de los propios borrados.
- "Archive" en el código significa borrado físico; no conserva nada en otro lugar. Las fotos de ticket son evidencia documental (`DOMINIO.md`) y no existe una regla de negocio que autorice descartarlas, ni definición de qué pasa con las copias de backup (`SEC-DATA`, `DO-DR`).
- `ROADMAP.md` lo describe como deseo, sin decisión: no es una política de retención aprobada.

**Contención (D-028):** se retiró la programación de `pallet:archive`. El comando pasó a ser un inventario de sólo lectura con el criterio legado, contado por la base mediante subconsultas: sin `--dry-run` falla con código 1 sin modificar nada; con `--dry-run` informa conteos. Ninguna opción ni variable de entorno habilita el borrado. Los tests acreditan que `pallet:archive` no está programado. Una guarda verifica además que los runtimes versionados conocidos (`supervisord.conf`, `docker-entrypoint.sh`, `nixpacks.toml`, `Dockerfile`) no invocan el scheduler ni el comando. No es una garantía sobre crons externos.

**Estado:** 🟡 contenido en `9279c5e` y `d3658d1`. Actions run `41185684370`: `postgres-concurrency` y `container-security` verdes; en `ci` pasaron todos los gates backend y falló sólo el audit npm del frontend, ajeno a este cambio (ver `EJECUCION.md`). La parte destructiva queda bloqueada por `GAP-RETENTION` (ver `PLAN.md`). Relacionado con H-009.

#### H-049 — Los mensajes de validación se devuelven como claves sin traducir
```
Severidad:   🟢 bajo · Categoría: calidad / contrato
Ubicación:   pallet-backend/.env.example (APP_LOCALE=es, APP_FALLBACK_LOCALE=es); no existe lang/es
Referencia:  `BE-ERR`, `FE-FORM`, `QA-CONTRACT`
Origen:      caracterización de H-020 (pendientes)
```
**Qué está pasando:** con `APP_LOCALE=es` y sin archivos de traducción, los `422` del validador devuelven `message` y `errors` con la clave cruda: `validation.required`, `validation.min.numeric`, `validation.exists (and 1 more error)`. Los tests y CI usan esa misma configuración (`cp .env.example .env`). Sólo los Form Requests con mensajes propios (D-022) muestran texto en español.
**Por qué importa:** el frontend puede mostrar la clave al usuario. Corregirlo cambia el texto de todos los `422`, y los tests de caracterización fijan los valores actuales.
**Estado:** 🔲 abierto. Requiere decidir idioma y texto (publicar `lang/es` o mensajes propios) y revisar qué muestra el frontend.

#### H-050 — Un id no tipado en una regla `exists` o un route binding sobre `bigint` produce `500` en PostgreSQL
```
Severidad:   🟡 medio · Categoría: calidad / contrato de error
Ubicación:   StorePendingItemRequest (`order_id`, `order_item_id`); revisar el resto de reglas `exists` sin `integer` previo
Referencia:  `BE-VALID`, `BE-ERR`, `SEC-INPUT`
Origen:      caracterización de H-020 (pendientes)
```
**Qué estaba pasando:** `exists:orders,id` sin `integer` previo enviaba el valor a la consulta. En SQLite, `order_id: "abc"` devolvía `422` (`validation.exists`). En PostgreSQL 17 la consulta fallaba con `SQLSTATE[22P02]` (sintaxis inválida para `bigint`) y el handler global respondía `500` genérico.

**Mecanismo verificado (2026-11-03):** Laravel 12 no ejecuta `exists` ni `unique` cuando una regla anterior del mismo atributo ya falló (`hasNotFailedPreviousRuleIfPresenceRule`, "to avoid possible database type comparison errors"). El riesgo real queda acotado a dos formas: (a) `exists` sobre `bigint` sin validación de tipo previa — los `500` observados en PostgreSQL venían de las reglas originales de pendientes y de solicitudes de faltantes, que combinaban `required` + `exists` sin `integer` — y (b) route binding implícito con un segmento no numérico sobre `bigint`, confirmado en `/pending-items/{pendingItem}`. Las reglas con `integer` antes de `exists` ya estaban protegidas por esa guarda: en ellas `bail` es convención explícita y defensiva, no la corrección que por sí sola evita el `22P02`.

**Resolución (familia de pendientes):** `order_id` y `order_item_id` de `StorePendingItemRequest` quedaron `['bail', 'required', 'integer', 'exists:...']` — antes eran `['required', 'exists:...']`, sin chequeo de tipo, y por eso producían el `500` real. Lo que impide que el valor inválido llegue a la consulta es `integer` (reforzado por la guarda de Laravel 12); `bail` explicita el cortocircuito y uniforma el patrón de la familia. Cambio de contrato acotado y deliberado: para ids no numéricos el `422` pasa de `validation.exists` a `validation.integer`. El resto del contrato se conserva y está acreditado en ambos motores: enteros inexistentes → `422` `validation.exists`, strings numéricos → `201`, pertenencia ítem/pedido → `422` sin `errors`, precedencia de `401`/`403`/`404`, ausencia de efectos ante validación fallida y orden determinista de claves de `errors` (el de declaración de reglas).
**Resolución (familia de solicitudes de faltantes):** `order_item_id` de `StoreMissingItemRequestRequest` quedó `['bail', 'required', 'integer', 'exists:order_items,id']` — antes era `['required', 'exists:order_items,id']`, ni siquiera `integer`, y por eso producía el `500` real. Igual que en pendientes, `integer` es lo que impide que el valor llegue a `exists` y `bail` explicita el patrón. Mismo cambio de contrato acotado que en pendientes: id no numérico → `422` `validation.integer` en ambos motores (antes `validation.exists` en SQLite y `500` `22P02` en PostgreSQL); entero inexistente → `422` `validation.exists`; string numérico válido → `201`. La evidencia PostgreSQL la da `MissingItemRequestIdValidationTest` en el gate `postgres`.
**Resolución (`OrderController::attachPallet`):** `pallet_id` quedó `['bail', 'required', 'integer', 'exists:pallets,id']` en `AttachPalletToOrderRequest`. Verificación de la caracterización: Laravel 12 ya omite `exists`/`unique` cuando una regla previa del mismo atributo falló (`hasNotFailedPreviousRuleIfPresenceRule`, "to avoid possible database type comparison errors"), así que `'abc'` ya devolvía `422` `validation.integer` en ambos motores — el `500` `22P02` no se materializaba en este endpoint. `bail` hace la garantía explícita y uniforme con la familia, sin depender de ese detalle interno del validador ni del orden futuro de las reglas. Sin cambio de contrato observable; la evidencia PostgreSQL la da `AttachPalletIdValidationTest` en el gate `postgres`.

**Resolución (route binding de pendientes):** `PATCH`/`DELETE /pending-items/{pendingItem}` resolvían el segmento con el binding implícito, que consultaba `id = 'abc'` y producía `500` `22P02` en PostgreSQL (acreditado antes del cambio; SQLite ya devolvía `404`). `PendingItem::resolveRouteBinding` ahora devuelve `null` para cualquier segmento que no sea un entero no negativo dentro del rango bigint (`filter_var` + `min_range: 0`), antes de la comparación; el framework emite el `404` estándar del binding (`ModelNotFoundException`), en el mismo punto de la precedencia vigente (`auth:sanctum` → binding → `user.active` → `has.role` → Form Request). Se descartó `whereNumber()` porque falla en el matching de la ruta, antes de todo middleware, y convertiría el `401` de anónimo/inactivo en `404`. Caracterización: 11 tests SQLite (43 assertions) congelaron precedencia y contrato; la evidencia PostgreSQL previa mostró `500` en PATCH y DELETE para `'abc'`; tras el fix `PendingItemRouteBindingTest` acredita `404` para `'abc'`, `'12abc'`, `'1.5'`, `'-1'` y overflow de bigint, más `404`/`200` sin cambios para ids inexistentes y válidos.

**Inventario restante de la misma falla:** las reglas `exists` sin `bail` (`PhotoAnnotationController`, `AdjustPalletBaseItemRequest`, `MigratePalletBaseRequest`, `StoreOrderRequest`, `StorePalletBaseRequest`) tienen `integer` previo y están cubiertas por la guarda de Laravel 12; su uniformización de `bail` sigue bajo H-020. Los demás bindings implícitos sobre `bigint` quedaron como sospechosos no verificados hasta la auditoría dedicada que sigue.

**Auditoría de bindings bigint (2026-11-05, rama `audit/bigint-route-bindings`, base `734af12`):**
inventario completo de los parámetros dinámicos de `routes/api.php` con caracterización
en ambos motores. Todas las PKs de negocio son `$table->id()` → `bigint`. El binding
implícito corre en `SubstituteBindings` dentro de la precedencia vigente
`auth:sanctum` (401) → binding (404/500) → `user.active` (401) → `has.role`/`admin`
(403) → Form Request (422). En SQLite todo segmento no numérico responde 404 (la
comparación `id = 'abc'` simplemente no encuentra fila); en PostgreSQL la misma
comparación aborta con `SQLSTATE[22P02]` (`22003` en overflow de bigint) y el handler
global devuelve el `500` genérico con `error_id`. Evidencia:
`tests/Feature/Routing/BigintRouteBindingCharacterizationTest.php` (16 tests en
SQLite — el 404 local **no** es evidencia de seguridad para PostgreSQL) y su espejo
`tests/Integration/Postgres/BigintRouteBindingCharacterizationTest.php` (grupo
`postgres`), que captura la excepción real antes del handler para que ningún `500` de
otro origen se cuente como `22P02`.

**Matriz de parámetros dinámicos (`routes/api.php`, 2026-11-05):**

| Parámetro | Destino / PK | Rutas | Resolución | Protección | Estado PostgreSQL |
|---|---|---|---|---|---|
| `{order}` | `Order` · orders · bigint | 18 (show, patch, attach/detach, activity-logs, can-finalize, finalize, reopen, tickets anidados, import, items) | implícito + trait `RejectsInvalidIntegerRouteBindings` | ✅ entero ≥ 0 o `null` → 404 | 🟢 protegido |
| `{pallet}` | `Pallet` · pallets · bigint | 22 (show, patch, delete, activity-logs, can-finalize, finalize, reopen, attach-order, photos, bases anidadas, anotaciones, detach-pallet) | implícito + trait `RejectsInvalidIntegerRouteBindings` | ✅ entero ≥ 0 o `null` → 404 | 🟢 protegido |
| `{customer}` | `Customer` · customers · bigint | 1 (GET show) | implícito | ninguna | 🔴 vulnerable |
| `{item}` | `OrderItem` · order_items · bigint | 1 (PATCH order-items) | implícito | ninguna | 🔴 vulnerable |
| `{ticket}` | `OrderTicket` · order_tickets · bigint | 6 (destroy, photos, ocr-status, highlights, trigger-ocr, destroyPhoto) | implícito, sin scope de padre | segmento sin protección; pertenencia al pedido verificada en controlador (404) | 🔴 vulnerable |
| `{photo}` (tickets) | `OrderTicketPhoto` · bigint | 4 (ocr-status, highlights, trigger-ocr, destroyPhoto) | implícito | segmento sin protección; pertenencia verificada en controlador | 🔴 vulnerable |
| `{base}` (PalletBaseController) | `PalletBase` · bigint | 4 (update, adjust-item, destroy, migrate) | implícito | segmento sin protección; pertenencia verificada en controlador/servicio | 🔴 vulnerable |
| `{base}` (fotos/anotaciones) | `PalletBase::findOrFail($baseId)` | 4 (store/destroy foto de base, index/store anotaciones) | manual | ninguna | 🔴 vulnerable (misma forma de defecto) |
| `{photo}` (pallet/base/anotaciones) | `->find($photoId)` manual | 4 (destroy foto pallet, destroy foto base, index/store anotaciones) | manual | ninguna | 🔴 vulnerable (misma forma de defecto) |
| `{pendingItem}` | `PendingItem` · bigint | 2 (PATCH, DELETE) | implícito + trait `RejectsInvalidIntegerRouteBindings` | ✅ entero ≥ 0 o `null` → 404 | 🟢 protegido |
| `{user}` | `User` · users · bigint | 2 (PATCH role, POST toggle-active) | implícito + trait `RejectsInvalidIntegerRouteBindings` | ✅ entero ≥ 0 o `null` → 404 | 🟢 protegido |
| `{missingItemRequest}` | `MissingItemRequest` · bigint | 1 (PATCH review) | implícito + trait `RejectsInvalidIntegerRouteBindings` | ✅ entero ≥ 0 o `null` → 404 | 🟢 protegido |
| `{id}` (admin storage) | `Pallet`/`Order` por `whereKey` | 2 (DELETE storage/pallets, storage/orders) | type-hint `int $id` | coerción PHP | 🟠 500 por `TypeError` en **ambos** motores |
| `{id}` (`/auth/verify`) | — | 1 (GET verify) | método inexistente | — | ⚪ no aplica → H-052 |
| `{token}` | `public_token` de Order | 2 (GET order público, POST missing-items) | escalar + `where('[A-Za-z0-9]{48}')` | ✅ regex de ruta | 🟢 protegido |
| `{ean}` | `Product::where('ean')` | 1 (GET by-ean, público) | escalar saneado a dígitos | ✅ string, sin bigint | ⚪ no aplica |

**Hallazgos de la caracterización:**
- Precedencia: anónimo recibe `401` antes del binding; inactivo, sin-rol y no-admin
  reciben el resultado del binding (`404` SQLite / `500` PostgreSQL) antes que su
  `401`/`403`. `whereNumber()` movería ese corte al matching de ruta y convertiría
  también el `401` de anónimo en `404` — mismo motivo por el que se descartó para
  `{pendingItem}`.
- Pertenencia: los hijos anidados se resuelven por id global (Laravel no scopea
  bindings implícitos por defecto) y el controlador/servicio verifica el padre
  (ticket↔order, photo↔ticket, base↔pallet) respondiendo `404`: ningún hijo ajeno se
  opera como propio. Sin hallazgo de ownership.
- Efectos secundarios: cero filas y cero `activity_logs` ante cualquier segmento
  inválido; el `500` expone sólo el cuerpo genérico con `error_id` correlacionado.
- `{id}` de admin storage produce `500` por `TypeError` en ambos motores — variante
  PHP del mismo defecto, independiente del motor de base de datos.
- `/auth/verify/{id}` y `/auth/email/resend` apuntan a métodos inexistentes de
  `AuthController` (`500` por `Error`, independiente del motor): registrado como H-052.

**Plan de corrección recomendado (inventario hecho; arquitectura por decidir):**
- Familia 1 — admin: `{user}`, `{missingItemRequest}` (escrituras privilegiadas).
- Familia 2 — núcleo anidado: `{order}`, `{pallet}`, `{ticket}`, `{photo}` de tickets.
- Familia 3 — simples: `{customer}`, `{item}`, `{base}` implícito.
- Familia 4 — resoluciones manuales `{base}`/`{photo}` (normalizar el segmento antes
  de `findOrFail`/`find`, o migrarlas a bindings tipados al tocar cada familia de H-020).
- Familia 5 — `{id}` de admin storage (`int` → normalización explícita o restricción).
- Alternativas evaluadas: (a) `resolveRouteBinding` tipado por modelo — conserva la
  precedencia y es el patrón ya probado en `PendingItem`, pero toca ~9 modelos;
  (b) `Route::bind` explícito en un provider — efecto equivalente, centralizado fuera
  del modelo; (c) mecanismo común reutilizable (trait o clase base con la regla
  entero ≥ 0 compartida) — cubre todos los implícitos de una vez, pero no las
  resoluciones manuales ni el `int $id`; (d) `whereNumber()` — cambia `401`/`403` a
  `404` al fallar en matching de ruta: aceptable en rutas públicas, no en las
  autenticadas. **Elegida para implícitos: (c) como trait
  `RejectsInvalidIntegerRouteBindings` (D-033), aplicada por familia sobre (a)**;
  las manuales exigen normalización propia en cada punto.

**Corrección (familia admin + mecanismo común, 2026-11-05, rama
`hardening/admin-bigint-route-bindings`, base `1cebe12`):** se implementó el
trait `App\Models\Concerns\RejectsInvalidIntegerRouteBindings` y se aplicó a
`User`, `MissingItemRequest` y `PendingItem`; la implementación duplicada de
`PendingItem::resolveRouteBinding` se eliminó sin cambiar su contrato. La
guarda sólo actúa cuando el binding apunta a la PK entera del modelo (campo
nulo → route key = key name, `getKeyType() === 'int'`): un segmento que no es
un entero no negativo dentro del rango bigint se rechaza con `filter_var`
(`FILTER_VALIDATE_INT`, `min_range: 0`) devolviendo `null` →
`ModelNotFoundException` → `404` dentro de `SubstituteBindings`, antes de
emitir la consulta por PK; los ids válidos se normalizan a `int` y delegan en
`resolveRouteBindingQuery` sin cambios. Bindings sobre campos alternativos
(`{modelo:campo}`) y PKs string conservan la resolución por defecto.
`resolveSoftDeletableRouteBinding` recibe la misma guarda
(`withoutGlobalScope(SoftDeletingScope::class)`, equivalente al `withTrashed`
del framework) para futuros modelos con SoftDeletes. No se creó `BaseModel` ni
se tocó la herencia del dominio.

Contrato fijado en ambos motores (`tests/Feature/Admin/AdminRouteBindingContractTest.php`
y su espejo `tests/Integration/Postgres/AdminRouteBindingContractTest.php`,
grupo `postgres`; `PendingItemRouteBindingContractTest` como regresión del
trait): `abc`, `12abc`, `1.5`, `-1`, `01`, overflow (`99999999999999999999`,
`9223372036854775808`) → `404` **sin emitir la consulta por PK** (probado con
`DB::listen`, no sólo por el código de estado); numérico inexistente → `404`
con la consulta emitida; válido sin admin → `403`; válido con admin → flujo
normal (cambio de rol con revocación de tokens D-007 y auditoría, toggle
active con revocación, revisión de faltante con pendiente derivado y log,
segunda revisión `409`, pedido `done` `409`); inválido → cero cambios, cero
pendientes derivados y cero `activity_logs`. La precedencia
`auth:sanctum` → binding → `user.active` → `admin` → Form Request quedó
acreditada: anónimo `401`, inactivo `404`/`401`, no-admin `404`/`403`. Los
casos límite del filtro (`+id`, espacios, ceros a la izquierda) quedaron
caracterizados en ambos contratos. En PostgreSQL las excepciones de clase 22
(`22P02`/`22003`) desaparecieron para esta familia: `excepcionDe` acredita
`ModelNotFoundException` y las pruebas de no-consulta confirman que la PK
bigint nunca se consulta con un segmento inválido.

**Corrección (núcleo `{order}`/`{pallet}`, 2026-11-05, rama
`hardening/order-pallet-bigint-bindings`, base `042fac7`):** se aplicó el
mismo trait `RejectsInvalidIntegerRouteBindings` (D-033) a `Order` y
`Pallet`, sin copiar `resolveRouteBinding` ni tocar el trait, controladores
ni servicios. Las 40 rutas afectadas (18 con `{order}` + 22 con `{pallet}`,
una compartida: `DELETE /orders/{order}/detach-pallet/{pallet}`) resuelven
por binding implícito sobre la PK bigint y quedan cubiertas por la guarda:
un segmento que no es entero ≥ 0 dentro del rango devuelve `null` →
`ModelNotFoundException` → `404` dentro de `SubstituteBindings`, antes de
emitir la consulta por PK. `{order}` precede a `{ticket}`/`{photo}` en las
rutas anidadas y `{pallet}` precede a `{base}`: un padre inválido corta
antes de intentar los bindings hijos, que siguen sin protección (deuda).
Las resoluciones manuales `{base}`/`{photo}` de fotos y anotaciones no se
ven afectadas: reciben ids válidos o escalares y conservan su forma de
defecto.

Contrato fijado en ambos motores
(`tests/Feature/Routing/OrderPalletRouteBindingContractTest.php` — 14
tests/550 assertions en SQLite — y su espejo
`tests/Integration/Postgres/OrderPalletRouteBindingContractTest.php`,
grupo `postgres` — 6 tests/439 assertions): las 18 rutas `{order}` y las
22 `{pallet}` se ejercitaron con segmentos inválidos
(`abc`, `12abc`, `1.5`, `-1`, `01`, overflow) → `404` **sin emitir la
consulta por PK** (probado con `DB::listen` sobre `orders`/`pallets` y,
en rutas anidadas, sobre `order_tickets`/`order_ticket_photos`/
`pallet_bases`); numérico inexistente → `404` con la consulta emitida;
válido → flujo normal (show, PATCH de estado, tickets, attach/detach,
items, import hasta validación, finalize/reopen, bases, photos,
attach-order, DELETE). La precedencia quedó acreditada: anónimo `401`,
inactivo `404`/`401`, sin-rol `404`/`403`, binding antes del Form Request
(`404` con payload inválido) y entidad `done` `409` sólo después de
resolver el binding. Un test estructural inventaría las rutas: exige 18
`{order}` + 22 `{pallet}`, el type-hint `Order`/`Pallet` en cada acción y
el trait en ambos modelos — una ruta futura que escape del binding
esperado lo rompe. En PostgreSQL las excepciones de clase 22
(`22P02`/`22003`) desaparecieron para esta familia: `excepcionDe` acredita
`ModelNotFoundException` y las pruebas de no-consulta confirman que la PK
bigint nunca se consulta con un segmento inválido. Los casos de
`{order}`/`{pallet}` de `BigintRouteBindingCharacterizationTest` (SQLite y
`postgres`) pasaron a contrato 404 y la caracterización quedó sólo con
las familias todavía vulnerables.

**Estado:** 🟡 parcial. Cerradas: las reglas `exists` no tipadas de pendientes y
solicitudes de faltantes (`bail` + `integer`) y los bindings de `{pendingItem}`,
`{user}`, `{missingItemRequest}` y el núcleo `{order}`/`{pallet}` con el trait
común `RejectsInvalidIntegerRouteBindings` (`2f2a502`/`911a041` pendientes;
familias admin y order/pallet en `hardening/*-bigint-route-bindings`, D-033).
Abiertas: 5 bindings implícitos sobre bigint sin protección — `{ticket}` (6
rutas), `{photo}` de tickets (4), `{base}` de PalletBaseController (4),
`{customer}` (1), `{item}` (1) — las resoluciones manuales de
`{base}`/`{photo}` en fotos y anotaciones, y el `{id}` int de admin storage
(`TypeError` en ambos motores); el trabajo por familias sigue bajo H-020
con evidencia del grupo `postgres` en Actions run `41965294771`.

#### H-051 — El service worker persistía respuestas autenticadas y evidencia privada entre sesiones
```
Severidad:   � medio · Categoría: seguridad (datos privados en navegador)
Ubicación:   pallet-frontend/vite.config.js (workbox.runtimeCaching); cierre en src/auth/AuthContext.jsx
Referencia:  `FE-PWA`, `FE-DATA`, `FE-SESSION`, `SEC-DATA`, `SEC-BROWSER`, `SEC-IDENTITY`, `DO-EDGE`, `QA-SEC`, `QA-COMPAT`
Origen:      rebanada dedicada de hardening PWA sobre `e0a9883`
```
**Qué estaba pasando:** `generateSW` registraba dos rutas runtime en el `sw.js` generado (verificado inspeccionando el artefacto del build, no sólo la configuración):
- `NetworkFirst` sobre `/api/v1/*` → cache `api-cache`, `networkTimeoutSeconds: 10`, hasta 100 entradas y 24 h. Ante timeout o fallo de red servía la respuesta cacheada: pedidos, pallets, clientes, pendientes y `/auth/me` consultados por un usuario quedaban disponibles para el siguiente.
- `CacheFirst` sobre `/storage/*.webp` → cache `photos-cache`, hasta 500 entradas y 7 días. Las fotos y tickets son evidencia documental (`DOMINIO.md`), no datos públicos por tener URL de storage.

Las claves de Cache Storage son la URL: no distinguen usuario ni bearer token. El cierre de sesión (D-024) limpiaba token, usuario y React Query, pero nunca Cache Storage. `cleanupOutdatedCaches` de Workbox sólo elimina caches de precache (`workbox-precache-*`), así que quitar `runtimeCaching` de la configuración **no** borra las caches ya instaladas en dispositivos con la PWA previa.

**Escenarios caracterizados:** usuario A → logout → usuario B con la misma URL obtiene datos de A ante fallo de red; usuario A → logout → navegador offline recibe respuestas autenticadas de A; un `401` autenticado cerraba la sesión lógica pero dejaba las caches llenas; una nueva sesión heredaba las entradas del token anterior; una instalación PWA actualizada conservaba ambas caches legacy por no ser precaches.

**Resolución:** política sin fallback offline de datos mientras `GAP-OFFLINE` siga abierto (D-030):
- `runtimeCaching` retirado por completo; la configuración de Workbox vive en `pwa-workbox.config.js` como fuente única testeable. Sobreviven precache del shell estático, manifest, instalación y `navigateFallback` con denylist de `/api` y `/storage`.
- `src/auth/privateCaches.js` es la única autoridad que elimina por nombre exacto `api-cache` y `photos-cache`. Idempotente, lista cerrada (nunca borra caches ajenas ni `workbox-precache-*`) y nunca rechaza: la ausencia de Cache API no rompe arranque, login, logout ni `401`.
- Integración: `endSession` (logout, `401` autenticado y `/auth/me` fallido comparten la autoridad de D-024), bootstrap de `main.jsx` y login/registro con auto-login.

**Revisión 2026-11-03 — carrera de publicación de identidad corregida:**
la primera versión ejecutaba la purga fire-and-forget (`void clearPrivateCaches()`) y publicaba identidad sin esperarla, y confiaba sólo en el JS de la página para las instalaciones que actualizaban: un service worker viejo podía seguir controlando la primera carga y recrear las caches legacy hasta que el nuevo se activara. Correcciones:
- `bootstrapApp` espera `clearPrivateCaches()` antes de montar React: ninguna sesión ni request autenticada comienza con restos en Cache Storage. Un fallo de Cache API no bloquea el arranque (resuelve y monta).
- `login()` y el auto-login de `register()` esperan la purga antes de guardar el token o publicar `user`.
- `endSession` queda fire-and-forget por diseño (ver revisión de D-030): el borrado es inherentemente asíncrono y la barrera para la identidad nueva la dan bootstrap y login.
- `public/sw-cleanup.js` se inyecta en el worker generado vía `importScripts` de `generateSW` y elimina exactamente `api-cache`/`photos-cache` durante `activate`: la limpieza ocurre dentro del ciclo de vida del worker nuevo, aunque la aplicación todavía no haya montado. No se migró a `injectManifest`.

**Evidencia:** inspección del `sw.js` anterior con las dos rutas y sus expiraciones vs. el nuevo sin ninguna y con `importScripts("sw-cleanup.js")`. 59 tests: unitarios de `clearPrivateCaches` (nombres exactos, cache ajena preservada, idempotencia, sin Cache API, fallos de `keys`/`delete`), de política sobre `pwa-workbox.config.js` y `vite.config.js`, de barrera con promesas diferidas (React no monta ni login/registro publican token/usuario antes de la purga; fallo de Cache API no bloquea el arranque), de `activate` evaluando `sw-cleanup.js` con `self`/`caches` falsos (borra exactamente las dos caches, preserva precache y ajenas, tolera Cache API rota), e integrados con `AuthProvider` (logout y `401` purgan además de token/usuario/queries). `scripts/verify-sw.mjs` falla el build de CI si `sw.js` contiene `api-cache`, `photos-cache`, `NetworkFirst`, `CacheFirst` o `api/v1`, si falta precache del shell, `manifest.webmanifest`, el `importScripts` de `sw-cleanup.js` o el propio archivo con `activate` y ambos nombres. Limitación: jsdom no ejecuta un service worker real; la limpieza en `activate` se acredita evaluando el script con el contrato del evento y verificando su inclusión en el artefacto, no por una corrida E2E del SW viejo → nuevo.
**Estado:** ✅ resuelto en `0938c88` y la carrera corregida en `51b476d`; Actions run `41580895922` con `ci`, `postgres-concurrency` y `container-security` verdes.

#### H-052 — Las rutas de verificación de email apuntan a métodos inexistentes
```
Severidad:   🟢 bajo · Categoría: calidad / contrato
Ubicación:   routes/api.php — GET /auth/verify/{id} → AuthController::verifyEmail,
             POST /auth/email/resend → AuthController::resendVerification
Referencia:  `BE-API`, `BE-ERR`
Origen:      inventario de bindings bigint (H-050, 2026-11-05)
```
**Qué está pasando:** ambas rutas están registradas pero `AuthController` no define
`verifyEmail` ni `resendVerification`: cualquier request llega al dispatcher y produce
`Error` ("undefined method") → `500` genérico en ambos motores, independiente del
segmento. El frontend no consume estos endpoints (sin referencias en `src/`) y el
primer usuario salta la verificación por diseño, así que hoy no hay flujo roto visible;
la ruta nombrada `verification.verify` existe por si se habilita `MustVerifyEmail`.
**Estado:** 🔲 abierto. Opciones: implementar los métodos (con el contrato de
verificación de Laravel) o retirar ambas rutas junto con su `name()`.

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
| Seguridad | 1 | 3 | 5 | 0 | 9 |
| Integridad de datos | 7 | 6 | 3 | 2 | 18 |
| Performance | 0 | 0 | 1 | 0 | 1 |
| Calidad | 0 | 3 | 10 | 7 | 20 |
| **Total** | **8** | **12** | **19** | **9** | **48** |

*(H-043 y H-044 provienen de la auditoría posterior sobre `127b828`; H-045 y H-046, de la rebanada de finalización; H-048, de la auditoría de `pallet:archive`; H-051, de la rebanada PWA.)*

*(algunos hallazgos tocan más de una categoría; se contaron en la categoría dominante — p. ej. H-019 es fuga de información pero se cuenta como seguridad, H-030/H-032 son de Docker/git pero también seguridad)*

---

## 4. BASELINE DE PERFORMANCE

| Endpoint | p50 | p95 | Queries | Fecha |
|---|---|---|---|---|
| `GET /api/v1/pending-items?status=pending&per_page=25` (120 registros, SQLite local) | 5,76 ms | 6,63 ms | 6 | 2026-10-29 |
| _(pendiente — correr `benchmarks/k6-hot-endpoints.js`)_ | | | | |

**Cómo se mide:** ver `benchmarks/README.md`.
