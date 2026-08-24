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
Referencia:  BACKEND-BIBLIA §1.6
```
**Qué está pasando:** `GET /public/orders/{code}` no requiere auth ni tiene `throttle`, y `code` es un string libre que en la práctica es numérico y secuencial (`attachOrder` usa regex `^\d+$`).
**Por qué importa acá:** cualquiera puede iterar códigos y extraer cliente, items, precios y fotos de pedidos ajenos, sin límite de tasa.
**Estado:** 🔲 abierto

#### H-002 — Webhook de Telegram falla abierto sin secret configurado
```
Severidad:   🟡 medio
Categoría:   seguridad
Ubicación:   app/Http/Controllers/Api/TelegramBotController.php:33-39
Referencia:  BACKEND-BIBLIA §1.2
```
**Qué está pasando:** el chequeo de `X-Telegram-Bot-Api-Secret-Token` solo se ejecuta `if ($secret)` — si la env var no está seteada, cualquiera puede pegarle al webhook.
**Por qué importa acá:** a diferencia de `BotSecretMiddleware` (que sí falla cerrado), acá una env var faltante deja el bot completamente abierto (puede crear bases, subir fotos, mover cache keys).
**Estado:** 🔲 abierto

#### H-003 — Desactivar usuario no revoca sus tokens
```
Severidad:   🟡 medio
Categoría:   seguridad
Ubicación:   app/Http/Controllers/Api/UserController.php:48-61
Referencia:  BACKEND-BIBLIA §1.4
```
**Qué está pasando:** `toggle-active` cambia `is_active`, pero `is_active` solo se valida en `login()` — un token Sanctum ya emitido sigue funcionando hasta su expiración natural (14 días).
**Por qué importa acá:** un empleado desvinculado sigue teniendo acceso completo a la API durante semanas.
**Estado:** 🔲 abierto

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
Referencia:  BACKEND-BIBLIA §5.6
```
**Qué está pasando:** lee `SUM(qty)` ya asignado en otras bases, calcula `$available` en PHP, y recién después escribe con `sync()`. Sin `DB::transaction()`, sin `lockForUpdate()`.
**Por qué importa acá:** dos operarios asignando el mismo `order_item` a bases distintas al mismo tiempo pueden ambos pasar la validación con el mismo `$available` leído — la suma asignada termina superando lo pedido. Es el riesgo #1 que el propio CLAUDE.md señala para este dominio.
**Estado:** 🔲 abierto

#### H-006 — Sin constraint de DB para "asignado ≤ pedido"
```
Severidad:   🔴 crítico
Categoría:   integridad de datos
Ubicación:   database/migrations/2025_12_20_000003_create_pallet_base_order_items_table.php
Referencia:  BACKEND-BIBLIA §5.6 — CLAUDE.md regla dura #3
```
**Qué está pasando:** no existe ningún CHECK/trigger que impida `SUM(pallet_base_order_items.qty) > order_items.qty`. Toda la garantía depende de que el código PHP de cada endpoint la calcule bien.
**Por qué importa acá:** un bug futuro, un `tinker`, o el propio `adjustItem` (ver H-006b) puede violar la invariante sin que la base de datos lo impida — exactamente lo que CLAUDE.md pide evitar ("va como constraint en DB, no sólo como validación en PHP").
**Estado:** 🔲 abierto

#### H-006b — `adjustItem` no valida contra el total pedido
```
Severidad:   🔴 crítico
Categoría:   integridad de datos
Ubicación:   app/Http/Controllers/Api/PalletBaseController.php:179-223
Referencia:  BACKEND-BIBLIA §5.6
```
**Qué está pasando:** `PATCH .../bases/{base}/adjust-item` escribe la `qty` directo (delete/upsert) sin llamar a `fetchAllocations()` ni comparar contra `order_item.qty` — a diferencia de `createBase`/`updateBase`, este endpoint no tiene NINGÚN techo.
**Por qué importa acá:** es la vía más directa y sin fricción para romper la invariante "asignado ≤ pedido" — ni siquiera hace falta una race condition, un solo request mal armado alcanza. Es el endpoint de escritura de stock con el gap más simple de cerrar primero (candado + validación de disponible).
**Estado:** 🔲 abierto

#### H-007 — `migrate` tiene transacción pero lee stock antes de abrirla
```
Severidad:   🔴 crítico
Categoría:   integridad de datos
Ubicación:   app/Http/Controllers/Api/PalletBaseController.php:52-175
Referencia:  BACKEND-BIBLIA §5.6
```
**Qué está pasando:** `$sourceRecords` (cantidades disponibles) se lee en líneas 72-76, **antes** de que arranque el `DB::transaction()` de la línea 89. La transacción da atomicidad a la escritura pero no aísla contra una lectura ya obsoleta.
**Por qué importa acá:** dos migraciones concurrentes sobre la misma base+item pueden ambas leer el mismo disponible y, aunque cada una sea atómica por separado, el resultado combinado viola la invariante igual.
**Estado:** 🔲 abierto

#### H-008 — Race check-then-insert al agregar producto a un pedido
```
Severidad:   🔴 crítico
Categoría:   integridad de datos
Ubicación:   app/Http/Controllers/Api/OrderItemController.php:42-65
Referencia:  BACKEND-BIBLIA §5.6
```
**Qué está pasando:** busca si ya existe un `order_item` con ese EAN en el pedido y, si no, lo crea — sin transacción, sin lock, y sin `unique(order_id, ean)` en DB que lo respalde.
**Por qué importa acá:** dos requests concurrentes agregando el mismo producto duplican la fila, duplicando efectivamente la cantidad pedida.
**Estado:** 🔲 abierto

#### H-009 — Cero soft deletes en todo el esquema
```
Severidad:   🟠 alto
Categoría:   integridad de datos
Ubicación:   todas las migraciones (`cascadeOnDelete()` en todos lados)
Referencia:  BACKEND-BIBLIA §5.2 — CLAUDE.md regla dura
```
**Qué está pasando:** `grep -rn "SoftDeletes|deleted_at"` → 0 resultados en todo el proyecto. Todo borrado es físico y en cascada.
**Por qué importa acá:** borrar un pallet/base destruye para siempre el registro de qué se asignó dónde — contradice la regla dura explícita de CLAUDE.md ("nunca borrado físico de movimientos").
**Estado:** 🔲 abierto

#### H-010 — Reducir `qty` de un item no valida contra lo ya asignado
```
Severidad:   🟠 alto
Categoría:   integridad de datos
Ubicación:   app/Http/Controllers/Api/OrderItemController.php:138-194 — app/Http/Requests/UpdateOrderItemRequest.php
Referencia:  BACKEND-BIBLIA §2.2 (capa de negocio)
```
**Qué está pasando:** `PATCH /order-items/{item}` solo valida `qty >= 1`. No chequea cuánto de ese item ya está en `pallet_base_order_items`.
**Por qué importa acá:** un solo request bien formado (sin concurrencia) puede dejar `asignado > pedido` — mismo resultado final que H-005/H-008 por una vía totalmente distinta.
**Estado:** 🔲 abierto

#### H-011 — Escrituras multi-tabla sin transacción
```
Severidad:   🟠 alto
Categoría:   integridad de datos
Ubicación:   PalletController::attachOrder:135-182 · MissingItemRequestController::update:79-124 · OrderController::attachPallet:210-238 · controladores de fotos (PalletPhotoController, PalletBasePhotoController, OrderTicketController)
Referencia:  BACKEND-BIBLIA §5.6
```
**Qué está pasando:** cada uno hace 2-4 escrituras en tablas distintas (crear/actualizar registro + `ActivityLogger::log()` + a veces `Storage`) sin `DB::transaction()`.
**Por qué importa acá:** el más grave es `MissingItemRequestController::update` — si `PendingItem::create()` falla después de marcar la solicitud "approved", la aprobación queda registrada sin su pendiente correspondiente, silenciosamente.
**Estado:** 🔲 abierto

#### H-012 — Foreign keys sin índice (Postgres no las indexa solo)
```
Severidad:   🟡 medio
Categoría:   integridad de datos / performance
Ubicación:   orders.customer_id · order_tickets.order_id · order_ticket_photos.ticket_id · pending_items.* (las 4 FKs) · missing_item_requests.order_item_id/reviewed_by
Referencia:  BACKEND-BIBLIA §5.3
```
**Qué está pasando:** a diferencia de MySQL, Postgres no crea un índice automático al agregar un FK constraint. Estas columnas no tienen `->index()` explícito ni están cubiertas por el prefijo izquierdo de un índice compuesto.
**Por qué importa acá:** joins y cascade-deletes sobre estas tablas van a full scan a medida que crecen — y un cascade-delete lento amplía la ventana de las race conditions de arriba.
**Estado:** 🔲 abierto

#### H-013 — `activity_logs.pallet_id` no es FK real
```
Severidad:   🟡 medio
Categoría:   integridad de datos
Ubicación:   database/migrations/2025_12_20_060000_create_activity_logs_table.php:11
Referencia:  BACKEND-BIBLIA §5.2
```
**Qué está pasando:** es `unsignedBigInteger` suelto, mientras que `user_id` y `order_id` en la misma tabla sí están `constrained()`.
**Estado:** 🔲 abierto

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
**Estado:** 🔲 abierto

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
**Estado:** 🔲 abierto

---

### 🟠 CALIDAD / MANEJO DE ERRORES

#### H-018 — Sin handler global de excepciones
```
Severidad:   🟠 alto · Categoría: calidad
Ubicación:   bootstrap/app.php:21-23 (closure vacío) — no existe app/Exceptions/
Referencia:  BACKEND-BIBLIA §3.3
```
**Estado:** 🔲 abierto

#### H-019 — `$e->getMessage()` devuelto al cliente sin importar `APP_DEBUG`
```
Severidad:   🟠 alto · Categoría: seguridad / calidad
Ubicación:   PalletBasePhotoController.php:64,91-100 · OrderImportController.php:153-158 · BotController.php:39 · OrderTicketController.php:107-118
Referencia:  BACKEND-BIBLIA §3.1
```
**Qué está pasando:** varios `catch` devuelven el mensaje de excepción crudo (y en un caso, `get_class($e)`) al cliente, de forma incondicional — no depende de `APP_DEBUG=false`.
**Estado:** 🔲 abierto

#### H-020 — 16 controladores validan inline en vez de Form Request
```
Severidad:   🟡 medio · Categoría: calidad
Ubicación:   ver lista completa en conversación — AuthController, OrderController, PalletController, etc.
Referencia:  BACKEND-BIBLIA §2.3
```
**Estado:** 🔲 abierto

#### H-021 — Login inválido devuelve 422 en vez de 401
```
Severidad:   🟡 medio · Categoría: calidad
Ubicación:   AuthController.php:63-67
```
**Estado:** 🔲 abierto

#### H-022 — Reglas de autorización en UserController devuelven 422 en vez de 403
```
Severidad:   🟡 medio · Categoría: calidad
Ubicación:   UserController.php:38-39,50-56
```
**Estado:** 🔲 abierto

#### H-023 — "Sin stock disponible" devuelve 422 en vez de 409
```
Severidad:   🟡 medio · Categoría: calidad
Ubicación:   PalletBaseController.php:63,85,186 — PalletBaseService.php:48
Referencia:  BACKEND-BIBLIA §3.1 (tabla de clasificación 4xx)
```
**Estado:** 🔲 abierto

#### H-024 — Formato de respuesta de error no estandarizado
```
Severidad:   🟡 medio · Categoría: calidad
Ubicación:   todo app/Http/Controllers/Api/
Referencia:  BACKEND-BIBLIA §3.2
```
**Estado:** 🔲 abierto

#### H-025 — `APP_DEBUG` sin safeguard estructural en el deploy
```
Severidad:   🟡 medio · Categoría: seguridad
Ubicación:   docker-entrypoint.sh, nixpacks.toml — nada fuerza `APP_DEBUG=false`
```
**Estado:** 🔲 abierto

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
Referencia:  BACKEND-BIBLIA §6 — CLAUDE.md regla dura #10
```
**Por qué importa acá:** es la causa raíz por la que H-005 a H-011 llegaron a producción sin detectarse — no hay red de seguridad que los hubiera atrapado.
**Estado:** ✅ resuelto en `f114458` + `c44cafd` — factories y 41 tests de caracterización propios cubren los seis endpoints de escritura priorizados.

#### H-041 — Larastan/PHPStan no está instalado
```
Severidad:   🟡 medio · Categoría: calidad
Ubicación:   composer.json (require-dev) — no está, pese a que CLAUDE.md lo lista como tooling obligatorio
```
**Estado:** ✅ resuelto en `468b215` — Larastan/PHPStan nivel 5 con baseline de deuda legacy.

#### H-042 — Dependencias con vulnerabilidades conocidas
```
Severidad:   🔴 crítico
Categoría:   seguridad / supply chain
Ubicación:   composer.lock y package-lock.json de frontend/bot
Referencia:  DEVOPS-BIBLIA §3.6
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
Referencia:  DEVOPS-BIBLIA §1.2
```
**Qué está pasando:** si esa env var queda en `true` en Render, el próximo boot ejecuta `php artisan app:reset --confirm` automáticamente — `--confirm` viene hardcodeado en el script, cero paso humano intermedio.
**Por qué importa acá:** el propio comentario del script ("Acordate de borrar la variable...") indica que ya es un riesgo conocido/casi ocurrido. Borra orders, pallets, bases, items, users, activity_logs — todo excepto `products`.
**Estado:** 🔲 abierto — **verificar HOY en el dashboard de Render que esté ausente/false**

#### H-029 — Fotos sin garantía de persistencia en redeploy
```
Severidad:   🔴 crítico
Categoría:   integridad de datos (pérdida total)
Ubicación:   config/filesystems.php:16 (default `local`) — .env.example (default `public`)
Referencia:  DEVOPS-BIBLIA §1.2
```
**Qué está pasando:** el contenedor no tiene volumen persistente; si `FILESYSTEM_DISK` en Render no está explícitamente en `r2`, todas las fotos (evidencia central del dominio) se pierden en el próximo deploy.
**Por qué importa acá:** no verificable desde el repo — depende 100% de una env var externa sin ningún check en código que lo garantice.
**Estado:** 🔲 abierto — **verificar HOY en el dashboard de Render**

#### H-030 — Contenedor corre como root
```
Severidad:   🟠 alto · Categoría: seguridad
Ubicación:   Dockerfile (sin USER) — supervisord.conf (sin user=)
Referencia:  DEVOPS-BIBLIA §1.6
```
**Estado:** 🔲 abierto

#### H-031 — Sin CI de ningún tipo
```
Severidad:   🟠 alto · Categoría: calidad / proceso
Ubicación:   sin .github/workflows propio del proyecto
Referencia:  DEVOPS-BIBLIA §3.2
```
**Estado:** ✅ resuelto en `e431642` — GitHub Actions ejecuta Pint, PHPStan, PHPUnit y `composer audit`; falta validar el primer run remoto.

#### H-032 — `pallet-frontend/.env` trackeado en git
```
Severidad:   🟠 alto · Categoría: seguridad
Ubicación:   pallet-frontend/.env — sin entrada en .gitignore ni .dockerignore
Referencia:  DEVOPS-BIBLIA §2.2
```
**Qué está pasando:** contenido actual inofensivo (`VITE_API_BASE_URL=/api/v1`), pero el patrón de protección está roto para este archivo específico.
**Estado:** 🔲 abierto

#### H-033 — Imagen final incluye el binario de Composer
```
Severidad:   🟡 medio · Categoría: seguridad/calidad
Ubicación:   Dockerfile:38
```
**Estado:** 🔲 abierto

#### H-034 — `.dockerignore` incompleto
```
Severidad:   🟡 medio · Categoría: calidad
Ubicación:   .dockerignore (falta *.md, tests/, storage/logs/*, scraper/node_modules)
```
**Estado:** 🔲 abierto

#### H-035 — Imágenes base sin pinear a patch/digest
```
Severidad:   🟡 medio · Categoría: calidad
Ubicación:   Dockerfile (php:8.4-cli-alpine, node:22-alpine, composer:2)
```
**Estado:** 🔲 abierto

#### H-036 — Sin `HEALTHCHECK` en el Dockerfile
```
Severidad:   🟡 medio · Categoría: calidad
Ubicación:   Dockerfile
```
**Estado:** 🔲 abierto

#### H-037 — `ResetForProduction` referencia tabla inexistente
```
Severidad:   ⚪ bajo · Categoría: calidad
Ubicación:   app/Console/Commands/ResetForProduction.php (tabla `movements`)
```
**Estado:** 🔲 abierto

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
**Estado:** 🔲 abierto

**N+1 — candidatos identificados, sin medir todavía:** ver `benchmarks/README.md` (5 endpoints priorizados: `orders/{id}`, `public/orders/{code}`, `pallets/{id}`, `orders` listado, webhook de Telegram). `Model::preventLazyLoading()` y Debugbar ya están activos en local (§8.7) — el baseline con k6 está pendiente de correr.

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
| _(pendiente — correr `benchmarks/k6-hot-endpoints.js`)_ | | | | |

**Cómo se mide:** ver `benchmarks/README.md`.
