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

## Próxima sesión — R-05 reducción segura de cantidad

Crear la rama desde el cierre documental de R-04, nunca desde `master`. Volver a recorrer `ROUTER.md` y aplicar como mínimo `DOMINIO.md`, `BE-VALID`, `BE-ERR`, `BE-TX`, `BE-DATA`, `SEC-AUTHZ`, `SEC-LOG`, `QA-INVARIANT`, `QA-RISK` y `BE-GATE`. El objetivo es impedir que `PATCH /order-items/{item}` reduzca `qty` por debajo de la cantidad ya asignada a pallets, incluso ante concurrencia.

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
