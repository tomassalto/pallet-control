# Ejecución de la modernización

**Última actualización:** 2026-10-29
**Entrada obligatoria:** `ROUTER.md`

Una sesión aborda una tarea acotada en una rama/worktree propio. Antes de planear se combinan todas las rutas del router. Al cerrar se ejecutan gates y se actualizan evidencia, decisiones y plan.

## Sesión 0 — Riesgos operativos manuales

Verificar en Render antes de un deploy:

- `RESET_ON_STARTUP` ausente o `false` (`H-028`).
- `FILESYSTEM_DISK=r2` (`H-029`).
- `APP_DEBUG=false` (`H-025`).

Ruta: `DO-ENV`, `DO-INFRA`, `SEC-SECRET`, `DO-DR`.

## Fase Fundacional — cerrada

Resultado: gobierno común, Backend/Frontend/DevOps/Security/Quality, dominio, producto, auditoría documental y router por IDs estables.

No reabrir teoría durante una feature: si falta, aplicar `GAP-XXX` según `BIBLIAS-GOBERNANZA.md`.

## Sesión R-01 — `adjust-item` cerrada

Rama: `hardening/r01-adjust-item`.

Ruta obligatoria:

- `DOMINIO.md` — invariante asignado ≤ pedido.
- `BE-VALID`, `BE-ERR`, `BE-TX`.
- `SEC-AUTHZ`, `SEC-LOG`.
- `QA-INVARIANT`, `QA-RISK`, `BE-GATE`.
- `PLAN.md` R-01 y `AUDITORIA.md` H-006b, H-023, H-017.

Implementado:

1. Techo global y pertenencia bajo transacción.
2. Lock del `order_item` y locks pivot por `base_id`.
3. Conflictos de disponibilidad y pertenencia con `409`.
4. Activity log atómico con rollback acreditado.
5. Suite focal SQLite: 28 tests, 60 assertions.
6. Decisión registrada en D-003.
7. Dependencias afectadas por advisories actualizadas sin salto mayor; `composer audit` quedó en cero.
8. Presupuesto de 9 queries constante con 20 bases, guardado en `benchmarks/results/2026-10-29-r01-adjust-item.md`.
9. Gate PostgreSQL agregado a CI: dos servidores Laravel compiten por el mismo item; se exige un `200`, un `409`, total 6 y un solo activity log.
10. Concurrencia PostgreSQL verde en Actions run `40826632779`. El CI general reveló que faltaba compilar la PWA antes de probar `/`; se agregó el build y se actualizaron lockfiles npm.

Evidencia final: Actions run `40827127268`, commit `78786b1`, jobs `ci` y `postgres-concurrency` verdes.

## Sesión cerrada — R-02 bases con items

Crear la rama desde el cierre de R-01, nunca desde `master`. Aplicar `DOMINIO.md`, `BE-VALID`, `BE-ERR`, `BE-TX`, `SEC-AUTHZ`, `SEC-LOG`, `QA-INVARIANT`, `QA-RISK` y `BE-GATE`.

Implementado localmente:

1. `createBase()` y `updateBase()` ejecutan base, pivots y activity log en una sola transacción.
2. Locks deterministas de `order_items` y asignaciones reutilizan D-003 y calculan disponibilidad global entre pallets.
3. Conflictos de pertenencia, pedido finalizado y sobreasignación responden `409`; items duplicados responden `422` desde el Form Request.
4. Fallas forzadas del activity log revierten base, nombre y cantidades.
5. Suite backend completa verde: 57 tests, 135 assertions; Pint, PHPStan, Composer audit, npm audit activo, lint y build verdes.
6. Creación medida en 14 queries constantes con 20 bases; resultado en `benchmarks/results/2026-10-29-r02-create-base.md`.
7. Gate PostgreSQL extendido: dos `POST bases` concurrentes deben producir un `201`, un `409`, total asignado 6, una sola base y dos logs.

Evidencia final: commit `87d6a4b`, Actions run `40919324208`, jobs `ci` y `postgres-concurrency` verdes.

## Sesión cerrada — R-03 migración entre bases

Crear la rama desde el cierre de R-02, nunca desde `master`. Aplicar `DOMINIO.md`, `BE-VALID`, `BE-ERR`, `BE-TX`, `SEC-AUTHZ`, `SEC-LOG`, `QA-INVARIANT`, `QA-RISK` y `BE-GATE`.

Implementado localmente:

1. La operación salió del controlador y vive en `PalletBaseService::migrateBase()`.
2. Disponibilidad, validación, débito, crédito, asociación del pedido y activity log comparten una transacción.
3. Orden de locks: pallets por ID, bases por ID, `order_items` por ID y asignaciones por item/base; extiende D-003 sin invertir locks con R-02.
4. Destino incoherente, misma base, pallet finalizado o stock insuficiente devuelven `409`; items duplicados o base sin pallet destino devuelven `422`.
5. Rollback forzado acredita que origen, destino y `order_pallet` no quedan parciales si falla auditoría.
6. Suite completa local verde: 65 tests, 165 assertions; Pint, PHPStan, audits, lint y build verdes.
7. Presupuesto de 15 queries con 20 bases, guardado en `benchmarks/results/2026-10-29-r03-migrate-base.md`.
8. Gate PostgreSQL agregado: dos migraciones simultáneas de 6 sobre 10 deben producir `200/409`, origen 4, destino 6 y un solo log.

Evidencia final: commit `28e6cf6`, Actions run `40922987827`, jobs `ci` y `postgres-concurrency` verdes.

## Última sesión cerrada — R-04 unicidad de ítems por pedido

Crear la rama desde el cierre de R-03, nunca desde `master`. Aplicar `DOMINIO.md`, `BE-VALID`, `BE-ERR`, `BE-TX`, `BE-DATA`, `SEC-AUTHZ`, `SEC-LOG`, `QA-INVARIANT`, `QA-RISK` y `BE-GATE`.

Implementado localmente:

1. `StoreOrderItemRequest` reemplaza validación inline y exige formatos numéricos válidos.
2. `OrderItemService` bloquea el pedido y confirma item + activity log en una transacción.
3. Una migración agrega `UNIQUE(order_id, ean)`; su preflight detiene el deploy si existen duplicados históricos, sin fusionarlos ni borrarlos.
4. La misma EAN sigue permitida en pedidos diferentes; un duplicado dentro del pedido devuelve `409`.
5. Fallo forzado de auditoría revierte el item; la DB rechaza una inserción directa duplicada.
6. Migración probada localmente en secuencia `up → down → up` sobre SQLite temporal.
7. Suite completa local verde: 71 tests, 177 assertions; Pint, PHPStan, audits, lint y build verdes.
8. Presupuesto de 7 queries con 20 items, guardado en `benchmarks/results/2026-10-29-r04-add-order-item.md`.
9. Gate PostgreSQL agregado: dos altas simultáneas deben producir `201/409`, un item y un log.

Evidencia final: commit funcional `82af27c`; Actions run `40929456582` con jobs `ci` y `postgres-concurrency` verdes. El commit `2ffc4de` limita los reintentos de `composer audit` a fallos operativos de red; los hallazgos de vulnerabilidades continúan fallando de inmediato. Antes de un deploy real, ejecutar el preflight de duplicados en una copia o ventana controlada.

## Última sesión cerrada — R-05 reducción segura de cantidad

Crear la rama desde el cierre documental de R-04, nunca desde `master`. Volver a recorrer `ROUTER.md` y aplicar como mínimo `DOMINIO.md`, `BE-VALID`, `BE-ERR`, `BE-TX`, `BE-DATA`, `SEC-AUTHZ`, `SEC-LOG`, `QA-INVARIANT`, `QA-RISK` y `BE-GATE`. El objetivo es impedir que `PATCH /order-items/{item}` reduzca `qty` por debajo de la cantidad ya asignada a pallets, incluso ante concurrencia.

Implementado localmente:

1. `OrderItemService::update()` bloquea el item y sus asignaciones por `base_id` dentro de una transacción.
2. Una reducción menor que `SUM(pallet_base_order_items.qty)` devuelve `409` con `assigned` y `requested`; el límite exacto está permitido.
3. Cambio de item y activity log confirman juntos; un fallo forzado de auditoría revierte la actualización.
4. El controlador sólo adapta request, resultado y respuesta; se retiraron dos supresiones obsoletas del baseline PHPStan.
5. Suite completa local verde: 75 tests, 185 assertions; Pint, PHPStan, audits, lint y build verdes.
6. Presupuesto de 6 queries con 20 asignaciones, guardado en `benchmarks/results/2026-10-29-r05-update-order-item.md`.
7. Gate PostgreSQL agregado: una reducción a 5 compite con una asignación que llevaría el total de 4 a 6; sólo una operación puede confirmar y siempre queda `asignado ≤ pedido`.

Evidencia final: commit `85daa84`; Actions run `40931851828` con jobs `ci` y `postgres-concurrency` verdes.

## Última sesión cerrada — R-06 acceso público a pedidos

Crear la rama desde el cierre documental de R-05, nunca desde `master`. Recorrer `ROUTER.md` y aplicar `PRODUCTO.md`, `SEC-THREAT`, `SEC-AUTHZ`, `SEC-ABUSE`, `SEC-DATA`, `BE-API`, `BE-ERR`, `QA-RISK`, `QA-SEC` y `QA-CONTRACT`. Antes de cambiar el contrato público, registrar la decisión sobre código enumerable, token opaco y compatibilidad.

Implementado localmente:

1. `D-006` clasifica datos, actores, amenazas, compatibilidad y riesgo residual.
2. `orders.public_token` usa 48 caracteres aleatorios, índice único, backfill de existentes y generación automática para nuevos pedidos.
3. El modelo oculta el token por defecto; sólo store/show autenticados lo hacen visible para link y QR.
4. GET público y POST de faltantes resuelven únicamente por token. Los links históricos basados en código quedan revocados.
5. Límites nombrados combinan cuota por IP y por IP/recurso; el contrato de exceso es `429` con `Retry-After`.
6. Frontend, QR, compartir y formulario de faltantes usan el token sin mostrarlo en errores ni en la respuesta pública.
7. Migración probada `up → down → backfill de pedido legacy → up → down` sobre SQLite temporal.
8. Tests focalizados cubren token, código enumerable rechazado, entrega autenticada, faltantes y rate limit.
9. Presupuesto de 6 queries con 20 ítems, guardado en `benchmarks/results/2026-10-29-r06-public-order.md`.
10. Suite completa local verde: 82 tests, 226 assertions; Pint, PHPStan, audits, lint y build verdes.

Evidencia final: commit `f344770`; Actions run `40933450650` con jobs `ci` y `postgres-concurrency` verdes.

## Última sesión cerrada — R-07 autenticación y Telegram

Crear la rama desde el cierre documental de R-06, nunca desde `master`. Volver a recorrer `ROUTER.md` y aplicar `BE-AUTH`, `BE-ASYNC`, `BE-ERR`, `SEC-IDENTITY`, `SEC-SECRET`, `SEC-ABUSE`, `SEC-LOG`, `QA-RISK`, `QA-SEC`, `QA-CONTRACT` y `QA-RESILIENCE`. El bot activo es Telegram dentro del backend; `whatsapp-bot/` permanece fuera de alcance.

Implementado localmente:

1. `D-007` registra el ciclo de vida actual de tokens Sanctum y su riesgo residual.
2. Login inexistente, password incorrecto y cuenta inactiva devuelven el mismo `401` genérico.
3. `user.active` protege toda ruta autenticada aunque sobreviva una credencial residual.
4. Desactivar una cuenta bloquea la fila, revoca todos sus tokens y audita dentro de una transacción; reactivar también se audita.
5. Cambiar un rol revoca sesiones y audita el cambio de privilegio en la misma transacción.
6. Un fallo forzado de auditoría revierte estado y tokens.
7. Telegram devuelve `503` y registra un error crítico si falta `TELEGRAM_WEBHOOK_SECRET`; un valor incorrecto devuelve `401` y sólo el correcto permite procesar el update.
8. `.env.example` documenta Telegram como integración activa y el secreto obligatorio. WhatsApp no fue modificado.
9. Reglas administrativas prohibidas usan `403`; un admin no puede degradar ni desactivar a un superadmin.
10. Suite completa local verde: 90 tests, 256 assertions; Pint, PHPStan, audits, lint y build verdes.

Evidencia final: commit `9bd77b7`; Actions run `40965649678` con jobs `ci` y `postgres-concurrency` verdes. Con R-07 quedan cerradas las siete rebanadas verticales de Fase 2.

## Última sesión cerrada — contrato global de errores

Rama creada desde el cierre documental de R-07. Se aplicaron `BE-ERR`, `BE-API`, `SEC-LOG`, `QA-CONTRACT`, `QA-SEC` y `FE-DATA`.

1. `D-008` conserva los contratos 4xx esperados y fija `message` como campo común.
2. Toda excepción API 5xx devuelve mensaje genérico, UUID `error_id` y `X-Error-Id` aun con debug habilitado.
3. La misma referencia se agrega al contexto del log para correlación.
4. Los cuatro controladores de H-019 dejaron de exponer mensajes o clases de excepción; Telegram tampoco muestra el detalle técnico al usuario.
5. Tests verifican forma, redacción, correlación y compatibilidad del 422 existente.
6. Suite local verde: 92 tests, 272 assertions; Pint, PHPStan, Composer audit, backend npm audit, lint y build verdes. El audit frontend conserva los cuatro bajos aprobados por `D-004`.

Evidencia funcional: commit `ce48ca3`; Actions run `40966390873` con jobs `ci` y `postgres-concurrency` verdes.

## Próxima sesión — hardening de deploy y contenedor

Crear la rama desde el cierre documental del contrato de errores, nunca desde `master`. Recorrer `ROUTER.md` y aplicar `DO-ENV`, `DO-CONTAINER`, `DO-RELEASE`, `DO-SUPPLY`, `SEC-SECRET`, `SEC-SUPPLY`, `QA-SEC` y `QA-RESILIENCE`. Atender `H-025`, `H-030` y `H-032` sin asumir el estado del dashboard de Render; `H-028` y `H-029` siguen requiriendo verificación operativa separada.

## Sesiones R-02 a R-07

- R-02 bases: reutilizar decisiones de R-01; `BE-TX`, `QA-INVARIANT`.
- R-03 migrate: mover lectura bajo transacción; `BE-TX`, `QA-INVARIANT`.
- R-04 item/EAN: unicidad y conflicto; `BE-DATA`, `BE-TX`, `BE-ERR`.
- R-05 reducir cantidad: proteger asignaciones; `BE-VALID`, `DOMINIO.md`.
- R-06 pedido público: `SEC-THREAT`, `SEC-AUTHZ`, `SEC-ABUSE`.
- R-07 auth/Telegram: `BE-AUTH`, `BE-ASYNC`, `SEC-IDENTITY`, `SEC-SECRET`.

Cada sesión debe repetir la ruta completa del router; esta lista no la reemplaza.

## Integridad transversal

Después de R-01–R-05:

- índices/FK: `BE-DATA`;
- constraint o mecanismo de invariante agregada: `BE-VALID`, `BE-TX`, `QA-INVARIANT`;
- migraciones y recuperación: `DO-RELEASE`, `QA-RESILIENCE`.

## Errores y contratos

Para H-018/H-024/H-019/H-021/H-022/H-027 combinar `BE-ERR`, `BE-API`, `SEC-LOG`, `QA-CONTRACT` y `FE-DATA`.

## Performance

Para H-040 y hot paths combinar `BE-PERF`, `FE-PERF`, `QA-PERF`, `DO-OBS`. Guardar baseline y resultado en `benchmarks/`.

## Infraestructura y presentación

Seguir `DO-*`, `SEC-*` y `QA-*` indicados por router. Ninguna migración a VPS, desktop, offline, mobile o tenancy se inicia sin sus GAP aprobados.

## Cierre estándar

1. Ejecutar `QA-GATE` y gates técnicos de la ruta.
2. Actualizar H-XXX con evidencia y hash.
3. Registrar D-XXX si hubo decisión o excepción.
4. Actualizar fase, rebanada y siguiente acción en `PLAN.md`.
5. Confirmar que no se introdujo teoría fuera de las biblias.

Frenar si se toca alcance no autorizado, se cambia un test para aceptar un bug, se propone reescritura sin evidencia o se citan mejoras de performance no medidas.
