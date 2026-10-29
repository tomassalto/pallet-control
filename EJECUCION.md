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

## Sesión R-01 — `adjust-item` en validación final

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

Pendiente para cerrar la rebanada:

1. Ejecutar el job `postgres-concurrency` en GitHub Actions; localmente se descubre y salta porque este runtime no tiene `pdo_pgsql` ni PostgreSQL.
2. Si queda verde, registrar su ejecución y marcar R-01 terminada.
3. Avanzar a R-02.

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
