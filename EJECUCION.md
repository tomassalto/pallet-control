# Ejecución de la modernización

**Última actualización:** 2026-10-31
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

## Última sesión cerrada — hardening de deploy y contenedor

Rama creada desde el cierre documental del contrato de errores. Se aplicaron `DO-ENV`, `DO-CONTAINER`, `DO-RELEASE`, `DO-SUPPLY`, `SEC-SECRET`, `SEC-SUPPLY`, `QA-SEC` y `QA-RESILIENCE`.

1. `D-009` centraliza el guard productivo para Docker y Nixpacks.
2. Producción fuerza `APP_DEBUG=false` antes de cachear configuración.
3. La imagen y ambos procesos supervisor ejecutan como `www-data`; sólo storage, cache y public reciben ownership de runtime.
4. El `.env` frontend deja de estar versionado y se reemplaza por un ejemplo sin secreto.
5. El nuevo job `container-security` construye el artefacto real, inspecciona usuario/UID y ejecuta el guard de debug.
6. Actions run `40967326459`: `ci`, `postgres-concurrency` y `container-security` verdes.

Evidencia funcional: commits `7cf1875`, `2aadfc6` y `f01bc14`.

`H-028` y `H-029` siguen abiertos porque requieren verificar el estado externo de Render; este cambio no presume ni reemplaza esa comprobación.

## Última sesión cerrada — higiene y operabilidad de imagen

Rama creada desde el cierre de hardening anterior. Se aplicaron `DO-CONTAINER`, `DO-SUPPLY`, `DO-RELEASE`, `SEC-SUPPLY`, `QA-SEC` y `QA-RESILIENCE`.

1. `D-010` define stages separados y política de actualización de digests.
2. Runtime ya no contiene Composer; recibe `vendor/` desde una etapa aislada.
3. Node, PHP y Composer están fijados por tag legible + digest oficial.
4. `.dockerignore` excluye material de desarrollo, secretos y el bot legado.
5. `/up` es liveness; `/ready` comprueba DB y devuelve 503 mínimo ante fallo.
6. Docker declara `HEALTHCHECK` sobre `/ready`.
7. Suite local verde: 94 tests, 278 assertions; Pint, PHPStan, Composer audit, backend npm audit, frontend lint/build y audit desde severidad moderada verdes. Los cuatro bajos frontend siguen gobernados por `D-004`.
8. Actions run `40981608096`: `ci`, `postgres-concurrency` y `container-security` verdes.

Evidencia funcional: commit `0860d46`.

## Última sesión cerrada — comando destructivo

Rama creada desde el cierre de imagen. Se aplicaron `DO-ENV`, `DO-RELEASE`, `DO-DR`, `SEC-SECRET`, `QA-SEC` y `QA-RESILIENCE`.

1. `D-011` elimina el reset automático productivo.
2. `docker-entrypoint.sh` no contiene `RESET_ON_STARTUP` ni invoca `app:reset`.
3. El comando manual exige `--confirm` y cancela antes de borrar si falta una tabla esperada.
4. Tablas DB se vacían dentro de una transacción; archivos se eliminan sólo después del commit.
5. `movements` fue retirada y se agregaron tablas operativas vigentes.
6. Tests prueban confirmación, preservación de productos, limpieza, preflight y entrypoint.
7. Suite local verde: 98 tests, 292 assertions; Pint y PHPStan verdes.
8. Actions run `40982444647`: pipeline verde, incluida construcción e inspección de imagen.

Evidencia funcional: commit `f908d39`.

`H-038` continúa abierto: decidir Docker vs. Nixpacks exige acreditar el entorno real de Render. `H-029` también requiere comprobar storage externo. Ninguno se cierra por inferencia desde el repositorio.

## Última sesión cerrada — baseline y paginación de pendientes

Rama creada desde el cierre del comando destructivo. Se aplicaron `BE-PERF`, `BE-API`, `FE-DATA`, `QA-PERF`, `QA-CONTRACT` y `DO-OBS`.

1. Se midió el contrato original con 120 registros antes de modificarlo.
2. `D-012` establece paginación por estado, 25 filas por defecto, máximo 100 y respuesta `{ data, meta }`.
3. El frontend mantiene páginas independientes, usa totales reales y expone navegación accesible.
4. Tests cubren contrato, validación, páginas y presupuesto constante de seis queries.
5. La comparación local redujo payload 79,6% y p95 71,9%; no se extrapola a producción.
6. Gates locales: Pint, PHPStan, 100 tests/310 assertions más un benchmark condicional, audits, lint y build verdes; las cuatro vulnerabilidades npm bajas siguen aceptadas por `D-004`.
7. Actions run `41000461628`: `ci`, `postgres-concurrency` y `container-security` verdes.

Evidencia funcional: commit `1b67a32`; medición en `benchmarks/results/2026-10-29-h040-pending-items.md`.

## Última sesión cerrada — índices y FK de auditoría

Rama creada desde el cierre documental de H-040. Se aplicaron `BE-DATA`, `BE-VALID`, `DO-RELEASE`, `QA-DATA` y `QA-RESILIENCE`.

1. La auditoría distinguió FK sin cobertura de índices ya cubiertos por prefijos compuestos o unicidad.
2. Se agregaron nueve índices explícitos para H-012, sin duplicar cobertura existente.
3. `activity_logs.pallet_id` ahora referencia a `pallets`, con preflight de huérfanos y `nullOnDelete` según D-013.
4. La reversión retira primero la FK y luego cada índice por nombre estable.
5. Tests inspeccionan el esquema y prueban que eliminar un pallet conserva su log sin referencia inválida.
6. Gates locales: Pint, PHPStan, 110 tests/322 assertions y Composer audit verdes.
7. Actions run `41001431227`: `ci`, `postgres-concurrency` y `container-security` verdes.

Evidencia funcional: commit `da4a7f4`.

## Última sesión cerrada — constraints por fila

Rama creada desde el cierre documental de H-012/H-013. Se aplicaron `BE-DATA`, `BE-VALID`, `BE-TX`, `DOMINIO.md`, `DO-RELEASE`, `QA-DATA`, `QA-INVARIANT` y `QA-RESILIENCE`.

1. D-014 limita H-015 a siete reglas por fila observables; H-006 permanece separado.
2. PostgreSQL agrega checks nombrados como `NOT VALID` y luego los valida.
3. SQLite aplica triggers `BEFORE INSERT/UPDATE` con expresiones equivalentes.
4. El preflight falla antes del cambio si encuentra una fila incompatible.
5. `down()` y reaplicación están probados en ambos motores mediante el gate PostgreSQL.
6. Actualizar un ítem devuelve `409` si el estado final dejaría `done_qty > qty`.
7. Gates locales: Pint, PHPStan, 120 tests/338 assertions y Composer audit verdes.
8. Actions run `41002593369`: `ci`, `postgres-concurrency` y `container-security` verdes.

Evidencia funcional: commit `1020075`.

## Última sesión cerrada — revisión atómica de solicitudes faltantes

Rama creada desde el cierre documental de H-015. Se aplicaron `BE-TX`, `BE-VALID`, `BE-ERR`, `SEC-LOG`, `QA-INVARIANT`, `QA-RESILIENCE` y `DOMINIO.md`.

1. D-015 permite revisar una solicitud exactamente una vez.
2. El servicio bloquea la solicitud y reevalúa su estado dentro de la transacción.
3. Aprobación, pendiente derivado y audit log confirman o revierten juntos.
4. Una revisión repetida devuelve `409` sin duplicar efectos.
5. Fallos forzados al crear pendiente o log acreditan rollback total.
6. PostgreSQL enfrenta dos aprobaciones reales: resultado `200/409`, un pendiente y un log.
7. Gates locales: Pint, PHPStan, 124 tests/358 assertions y Composer audit verdes.
8. Actions run `41003369719`: `ci`, `postgres-concurrency` y `container-security` verdes.

Evidencia funcional: commit `eaadf87`.

## Última sesión cerrada — asociación pedido/pallet atómica

Rama creada desde el cierre documental de solicitudes faltantes. Se aplicaron `BE-ARCH`, `BE-TX`, `BE-VALID`, `BE-ERR`, `SEC-LOG`, `QA-INVARIANT` y `QA-RESILIENCE`.

1. D-016 centraliza los dos endpoints que escriben `order_pallet`.
2. El orden de locks es pallet y luego pedido en ambos recorridos.
3. Pivot y audit log confirman o revierten juntos; crear por código incluye también pedido y log de alta.
4. Una asociación existente devuelve `409` sin nuevo log ni notificación.
5. Telegram se ejecuta después del commit, fuera de los locks.
6. Fallos forzados de auditoría acreditan rollback para pedido nuevo y existente.
7. PostgreSQL cruza ambos endpoints: `201/409`, una fila pivot y un log.
8. Gates locales: Pint, PHPStan, 128 tests/375 assertions y Composer audit verdes.
9. Actions run `41003991454`: `ci`, `postgres-concurrency` y `container-security` verdes.

Evidencia funcional: commit `a59022b`.

## Última sesión cerrada — compensación de subidas de fotos

Rama creada desde el cierre documental de asociación pedido/pallet. Se aplicaron `BE-ARCH`, `BE-TX`, `BE-ERR`, `SEC-FILE`, `SEC-LOG`, `DO-INFRA`, `QA-INVARIANT` y `QA-RESILIENCE`.

1. D-017 define storage primero, transacción DB después y compensación del objeto ante cualquier rollback.
2. Un servicio común aplica la misma regla a pallet, base y ticket tanto en endpoints web como en el servicio usado por Telegram.
3. `ImageConverter` deja de tratar como éxito un `put()` que devuelve `false`.
4. El ticket creado por Telegram ya no puede quedar huérfano si falla la conversión, la foto o la auditoría.
5. Cuatro tests fuerzan el fallo de `activity_logs` y acreditan ausencia de filas y archivos residuales.
6. Gates locales: Pint, PHPStan, 132 tests/388 assertions y Composer audit verdes.
7. Actions run `41005625780`: `ci`, `postgres-concurrency` y `container-security` verdes.

Evidencia funcional: commit `ee41860`.

## Última sesión cerrada — borrado recuperable de fotos y tickets

Rama `hardening/photo-deletion-recovery`, creada desde el cierre de compensación de subidas (`1792aaa`). Se aplicaron `BE-TX`, `BE-ASYNC`, `BE-ERR`, `BE-DATA`, `SEC-FILE`, `SEC-LOG`, `DO-INFRA`, `QA-INVARIANT` y `QA-RESILIENCE`. No existía un mecanismo equivalente reutilizable: sólo borrados directos best-effort.

1. D-018 define una intención durable por objeto (`pending_file_deletions`) registrada en la misma transacción que borra la entidad y crea la auditoría.
2. Foto de pallet, foto de base, foto de ticket, ticket completo, pallet y base bloquean y releen sus filas, borran, auditan y registran intenciones sin llamar al storage.
3. `DeleteStoredFile` se despacha después del commit; `delete()` en `false` con objeto presente o una excepción incrementan intentos con backoff y dejan la intención pendiente. Un objeto inexistente satisface la limpieza.
4. `storage:retry-pending-deletions` recupera intenciones vencidas sin depender de la cola, incluidas las perdidas por crash entre commit y dispatch. Supervisor lo ejecuta cada diez minutos como programa propio; no se activó el scheduler para no encender `pallet:archive`.
5. Tras diez intentos la intención queda agotada, se alerta como crítica y sólo se reprocesa con `--include-exhausted`.
6. Cola: producción usa `database` con worker supervisado; tests usan `sync`, donde un fallo post-commit del job no convierte en 500 un borrado ya confirmado.
7. Dieciocho tests cubren rollback por auditoría o por fallo al crear la intención, intención durable hasta procesarla, fallo y recuperación de storage, idempotencia, archivo inexistente, intenciones agotadas, ticket con varias fotos, pallet y base.
8. Migración probada `up → down → up` sobre SQLite temporal.
9. Gates locales: Pint, PHPStan, 150 tests/517 assertions más un benchmark condicional omitido, y Composer audit verdes.
10. Actions run `41013814687`: `ci`, `postgres-concurrency` y `container-security` verdes sobre `5936d07`; los tests adicionales de `03e4ba8` se acreditan en Actions run `41015014288` sobre `e7ed05b`, con los tres jobs verdes.

Evidencia funcional: commit `60a0c6c`.

Pendiente al cierre de esa sesión (histórico): `pallet:archive` borraba objetos antes que filas y no se ejecutaba sólo porque no había scheduler activo; desde D-028 el comando es un inventario de sólo lectura sin programación (H-048). Nixpacks no corre worker ni barrido (H-038); las intenciones completadas aún no tienen política de retención.

## Última sesión cerrada — operabilidad de colas y limpieza

Rama `hardening/storage-cleanup-operability`, creada desde `hardening/photo-deletion-recovery` (`122aea8`). Se aplicaron `BE-ASYNC`, `BE-ERR`, `DO-CONTAINER`, `DO-RELEASE`, `DO-OBS`, `DO-DR`, `SEC-LOG` y `QA-RESILIENCE`.

1. D-019 fija `retry_after=150` por encima del timeout del worker (120) y de cada job; un test lo verifica contra `supervisord.conf`.
2. `ProcessTicketOcr` declara dos intentos, backoff de 30 y timeout de 110, y registra su fallo terminal sin mensaje interno.
3. El borrado de objetos relanza una excepción saneada: `failed_jobs` y logs no reciben mensajes ni URLs del proveedor.
4. La transición a intención agotada y su activity log confirman juntos; reprocesar agotadas queda auditado.
5. `storage:cleanup-status` informa pendientes, antigüedad, agotadas, completadas y jobs fallidos, y falla al superar `STORAGE_CLEANUP_ALERT_MINUTES` o con agotadas. El barrido emite log crítico con el mismo criterio.
6. El procedimiento de recuperación tras crash está en D-019; `/ready` conserva el alcance de D-010.
7. Dieciséis tests cubren consistencia de timeouts, retry, job duplicado, registro resuelto, proceso interrumpido, saneamiento, fallo terminal auditable con rollback, estado y alerta.
8. Gates locales: Pint, PHPStan, 166 tests/570 assertions y Composer audit verdes.

Evidencia funcional: commit `f485f1b`; Actions run `41016913079` sobre `60b2b76` con `ci`, `postgres-concurrency` y `container-security` verdes.

Pendiente: conectar `storage:cleanup-status` a un monitor externo depende de la plataforma real (H-029/H-038); `composer dev` no ejecuta el barrido, que en desarrollo se corre manualmente.

## Última sesión cerrada — invariante agregada asignado ≤ pedido

Rama `hardening/aggregate-stock-invariant`, creada desde `hardening/storage-cleanup-operability` (`a069e22`). Se aplicaron `DOMINIO.md`, `BE-TX`, `BE-DATA`, `BE-VALID`, `BE-ERR`, `SEC-LOG`, `DO-RELEASE`, `QA-INVARIANT`, `QA-DATA` y `QA-RESILIENCE`.

1. Inventario de escritores de `pallet_base_order_items` y `order_items.qty`: adjust-item, crear/actualizar/migrar base, actualizar y crear ítem, import, desvinculación de pallet, cascadas y reset manual. El import y la desvinculación estaban fuera de la regla D-003.
2. D-020 fija el orden global de locks `pallets → bases → order_items → orders → asignaciones`; los recorridos existentes ya lo respetan.
3. Triggers en PostgreSQL bloquean el `order_item`, recalculan la suma y rechazan con `23514` tanto asignaciones excesivas como reducciones de cantidad. SQLite aplica triggers equivalentes.
4. La migración hace preflight y no crea la guarda si ya existe sobreasignación; `down()` la retira por nombre.
5. El import bloquea ítems y pedido, devuelve `409` con `conflicts` sin escribir nada y audita `order_imported` en la misma transacción.
6. La desvinculación de pallet confirma asignaciones, pivot y auditoría juntos; Telegram queda después del commit.
7. Un fixture de migración entre bases partía de un estado sobreasignado (13 sobre 10). Se corrigió la preparación sin cambiar ninguna aserción.
8. Tests SQLite: guarda directa, límite exacto, preflight, import (conflicto, límite, éxito, rollback) y desvinculación (éxito, rollback, 404). Tests PostgreSQL: triggers, carrera real entre dos conexiones, import contra adjust, recorridos cruzados sin deadlock y migración `down → up`.
9. Gates locales: Pint, PHPStan, 178 tests/617 assertions y Composer audit verdes. Las pruebas PostgreSQL corren en CI y contra un PostgreSQL 17 portable local.
10. La corrida `41021357373` sobre `1a8562b` reveló, mediante la anotación nueva, un deadlock intermitente entre desvinculación y adjust-item: las FKs toman `FOR KEY SHARE` sobre el padre después del lock del ítem. Se reprodujo de forma determinista con dos conexiones SQL locales. `6b4b48b` adquiere `FOR SHARE` sobre los padres antes del ítem en seis recorridos y agrega la carrera migrar contra adjust. Repetir 30 veces cada carrera cruzada no produjo fallos.
11. Presupuestos de queries actualizados por los locks nuevos: crear base 14→15, adjust-item 9→11, agregar ítem 7→8, actualizar ítem 6→8. Las mediciones históricas de `benchmarks/` conservan los valores originales.
12. Actions run `41024434735` sobre `9d1e11d`: `ci`, `postgres-concurrency` y `container-security` verdes.

10. La corrida `41021357373` sobre `1a8562b` reveló, mediante la anotación nueva, un deadlock intermitente entre desvinculación y adjust-item: las FKs toman `FOR KEY SHARE` sobre el padre después del lock del ítem. Se reprodujo de forma determinista con dos conexiones SQL locales. `6b4b48b` adquiere `FOR SHARE` sobre los padres antes del ítem en seis recorridos y agrega la carrera migrar contra adjust. Repetir 30 veces cada carrera cruzada no produjo fallos.
11. Presupuestos de queries actualizados por los locks nuevos: crear base 14→15, adjust-item 9→11, agregar ítem 7→8, actualizar ítem 6→8. Las mediciones históricas de `benchmarks/` conservan los valores originales.
12. Actions run `41024434735` sobre `9d1e11d`: `ci`, `postgres-concurrency` y `container-security` verdes.

Evidencia funcional: commits `39a45ab` y `24b5eea`. Actions run `41018979777` sobre `39a45ab` falló en `postgres-concurrency` sin logs accesibles sin permisos de administrador; el gate no falló en cinco corridas locales contra PostgreSQL 17. La causa más probable, no confirmada, es el plazo de 10 segundos para observar la espera de lock en la carrera entre conexiones. `24b5eea` amplía ese plazo a 30 segundos sin cambiar aserciones y publica los fallos del gate como anotaciones legibles por API. Actions run `41020938251` sobre `24b5eea`: `ci`, `postgres-concurrency` y `container-security` verdes.

Pendiente: el import sigue eliminando ítems ausentes junto con sus asignaciones (política abierta en H-009). Los borradores de fotos y tickets bloquean la foto antes de que el activity log tome `FOR KEY SHARE` sobre pallet o pedido. Frente a un borrado simultáneo del pallet o la base puede producirse un deadlock detectado, que termina en `5xx` y rollback completo sin corrupción. Los borradores de fotos y tickets bloquean la foto antes de que el activity log tome `FOR KEY SHARE` sobre pallet o pedido; frente a un borrado simultáneo del pallet o la base puede producirse un deadlock detectado, que termina en `5xx` y rollback completo sin corrupción.

## Última sesión cerrada — limpieza de esquema

Rama `hardening/schema-cleanup`, creada desde `hardening/aggregate-stock-invariant` (`10c17b0`). Se aplicaron `BE-DATA`, `DO-RELEASE`, `SEC-LOG`, `QA-DATA`, `QA-RESILIENCE` y `DOMINIO.md`.

1. H-014 se confirmó: `pallets.created_by` siempre era `null` y nadie lo leía. D-021 lo puebla con el usuario autenticado, agrega FK `nullOnDelete` e índice con preflight, y prohíbe usarlo para autorización.
2. El test de caracterización que fijaba `created_by = null` se actualizó como cambio de contrato documentado en D-021. El frontend no consume ese campo.
3. H-016 no se reprodujo: ambos motores tienen un único índice sobre `activity_logs.order_id`. El `down()` histórico que elimina el índice ajeno queda documentado, sin editar una migración aplicada.
4. Tests SQLite y PostgreSQL de FK, `nullOnDelete`, índice, preflight e índice único de auditoría.
5. Migración probada `up → down → up` en SQLite temporal y en PostgreSQL 17 local.
6. Gates locales: Pint, PHPStan, 184 tests/635 assertions, Composer audit y grupo PostgreSQL (16 tests/56 assertions) verdes.

Evidencia funcional: commits `7cc6b07` y `6461327` (formato de un test PostgreSQL detectado por Pint en la corrida `41026362144`). Actions run `41026830057` sobre `6461327`: `ci`, `postgres-concurrency` y `container-security` verdes.

## Última sesión cerrada — validación de subidas de fotos

Rama `hardening/photo-request-validation`, creada desde `hardening/schema-cleanup` (`0a8d46e`). Se aplicaron `BE-API`, `BE-VALID`, `BE-ERR`, `SEC-FILE`, `SEC-INPUT` y `QA-CONTRACT`.

1. `f3aeb3f` caracteriza el contrato previo de las tres subidas: éxito, 401, archivo ausente, contenido no imagen, extensión falsa, tamaño, nota, gif y el fallback de base.
2. La caracterización acredita que el fallback de base nunca producía un alta y que base y ticket responden `404` de pertenencia antes de validar.
3. D-022 define una allowlist jpeg/png/webp verificada por contenido, 20 MB y mensajes en español en `StorePhotoRequest`. Pallet la usa como Form Request; base y ticket aplican las mismas reglas después del `404` para no cambiar el orden.
4. `e987c13` actualiza los tests afectados como cambios de contrato documentados: gif en pallet pasa a `422`, y base usa el `422` estándar sin `error_code`.
5. Gates locales: Pint, PHPStan, 199 tests/678 assertions y Composer audit verdes.

Evidencia funcional: commits `f3aeb3f` y `e987c13`. Actions run `41028487256` sobre `211f267`: `ci`, `postgres-concurrency` y `container-security` verdes. `f3aeb3f` se publicó junto a la implementación y no tuvo corrida propia; su verde contra el código anterior se acreditó localmente.

Pendiente: el resto de H-020 (otros controladores con validación inline) y el endpoint legado del bot, fuera de alcance.

## Última sesión cerrada — base de tests frontend

Rama `quality/frontend-test-foundation`, creada desde `hardening/photo-request-validation` (`85e1647`). Se aplicaron `FE-GATE`, `FE-DATA`, `FE-STATE`, `QA-LEVELS`, `QA-CONTRACT`, `QA-DATA`, `QA-FLAKY`, `QA-GATE`, `DO-CI`, `DO-SUPPLY` y `SEC-SUPPLY`. La elección de herramienta es una decisión de proyecto según `BIBLIAS-GOBERNANZA.md`; no hubo GAP teórico.

1. D-023 adopta Vitest 5.0.5, jsdom 30.1.3, Testing Library React 16.3.3, DOM 10.4.2 y user-event 14.6.8, todas publicadas al menos siete días antes y compatibles con Vite 7, React 19 y Node 22.
2. `vitest.config.js` es independiente para que el plugin PWA no corra en tests. El setup común limpia DOM, `localStorage`, temporizadores y globals; cada test crea su propio `QueryClient` sin reintentos.
3. Nueve tests del cliente API cubren token, headers, FormData, errores, 401 con redirección y timeouts de 15 y 30 segundos con reloj controlado.
4. Cinco tests de pendientes paginados cubren carga, totales desde `meta`, vacío, error recuperable y navegación de página, con `fetch` reemplazado en el límite.
5. El test de error reveló que los callbacks `onError` de `useQuery` no existen en React Query v5: un fallo mostraba el estado vacío. Ahora la pantalla muestra una alerta con "Reintentar" y el toast se emite una vez por fallo.
6. CI ejecuta ESLint, tests y build frontend en el job `ci`; antes no corría ESLint frontend.
7. Audit frontend sin hallazgos nuevos: siguen los cuatro bajos de D-004.
8. Gates locales: lint, 14 tests en tres corridas deterministas, build y audit desde moderado verdes.

Evidencia funcional: commit `b40db93`. El workflow sólo se disparaba en `master`, `modernizacion/**` y `hardening/**`, por lo que `e717409` no tuvo corrida. `67888c3` agrega `quality/**` al trigger. Actions run `41032563385` sobre `67888c3`: `ci` (incluye lint, tests y build frontend), `postgres-concurrency` y `container-security` verdes.

Pendiente: `MyClients.jsx` conserva callbacks `onError` muertos en sus queries; se tratará con los flujos críticos.

## Última sesión cerrada — flujos críticos frontend

Rama `quality/frontend-critical-flows`, creada desde `quality/frontend-test-foundation` (`bbbe30c`). Se aplicaron `FE-DATA`, `FE-STATE`, `FE-SESSION`, `BE-ERR`, `BE-ASYNC`, `QA-LEVELS`, `QA-CONTRACT`, `QA-RESILIENCE` y `QA-A11Y` (los IDs solicitados `FE-ERR`, `FE-AUTH` y `QA-ACCESS` no existen; se usaron sus equivalentes vigentes).

1. `376b3f9` caracteriza login, sesión expirada, `RequireAuth`, crear base con `409` sin perder el formulario, apertura del modal de conflicto de cantidad, subida de foto con `FormData` y `422` de D-022, polling OCR y visibilidad de opciones administrativas por rol (sólo presentación; la autorización real es el `403` backend).
2. La caracterización expuso dos defectos: un `401` por contraseña incorrecta se mostraba como "Sesión expirada", y el polling OCR no tenía límite de tiempo ni de errores.
3. D-024 limita el polling a cinco minutos o cinco errores consecutivos, con mensaje recuperable; el flujo de sesión expirada corre sólo para peticiones con token. Logout y `401` autenticado cancelan y limpian la cache de queries. `MyClients.jsx` emite sus toasts desde efectos.
4. Los tests que fijaban los defectos se actualizaron al comportamiento corregido, y se agregaron casos de corte por tiempo, corte por errores, reinicio del contador y limpieza de cache.
5. Gates locales: lint, 32 tests frontend en tres corridas, build y audit desde moderado verdes; el backend no cambió.

Evidencia funcional: commits `376b3f9` y `46d4f54`. Actions run `41034937602` sobre `5307801`: `ci` (lint, tests y build frontend incluidos), `postgres-concurrency` y `container-security` verdes.

Pendiente: un E2E con navegador real para cámara, escáner y service worker.

## Última sesión cerrada — consistencia de status HTTP

Rama `hardening/api-status-consistency`, creada desde `quality/frontend-critical-flows` (`570b7c5`). Se aplicaron `BE-API`, `BE-ERR`, `BE-VALID`, `FE-DATA`, `QA-CONTRACT` y `SEC-LOG`.

1. El inventario de `4xx` confirmó H-021 y H-022 cerrados en R-07, y servicios transaccionales que ya usaban `409`.
2. D-025 cambia a `409` cinco conflictos de estado: finalizar y reabrir pallet, finalizar pedido, ajustar ítem en un pallet finalizado y solicitud de faltante duplicada. Los cuerpos no cambian.
3. Los `422` de entrada semánticamente inválida se conservan; un test fija ese límite.
4. Tests verifican status, mensaje, detalles y ausencia de cambios de estado o auditoría. El test previo de adjust en pallet finalizado se actualizó como cambio de contrato documentado.
5. El frontend ya mostraba el `message`; un test nuevo lo acredita para finalizar pallet.
6. Gates locales: Pint, PHPStan, 205 tests/706 assertions, Composer audit, grupo PostgreSQL 16/56, y frontend con lint, 33 tests y build, todos verdes.

Evidencia funcional: commit `7344135`. Actions run `41036574975` sobre `cb0ff6f`: `ci`, `postgres-concurrency` y `container-security` verdes.

Pendiente: finalizar y reabrir siguen sin lock ni transacción; el resto de H-020; H-029, H-038, branch protection y backups requieren evidencia externa.

## Última sesión cerrada — correcciones de la auditoría posterior

Rama `hardening/devin-review-fixes`, creada desde `hardening/api-status-consistency` (`127b828`). Se aplicaron `FE-SESSION`, `FE-STATE`, `FE-DATA`, `SEC-IDENTITY`, `BE-TX`, `BE-DATA`, `BE-VALID`, `BE-ERR`, `SEC-LOG`, `QA-CONTRACT`, `QA-INVARIANT`, `QA-DATA`, `QA-RESILIENCE` y `DOMINIO.md`. El ID solicitado `FE-ERR` no existe; sus requisitos están cubiertos por `FE-DATA` y `BE-ERR`.

1. H-043: un `401` autenticado borraba el token y la cache, pero `AuthProvider` conservaba `user`. `6e888bf` concentra el cierre en `endSession` del provider, usado por logout, arranque fallido y `401`. El cliente invalida sólo si el token sigue siendo el de la petición y deja la navegación a `RequireAuth`. `setNavigate` y el callback de `main.jsx` desaparecen. Revisión de D-024.
2. Tests frontend: sesión visible y `401` posterior (token, usuario, cache y contenido protegido eliminados; termina en login), dos `401` concurrentes sin errores ni reintentos, login con contraseña incorrecta sin flujo de expiración, e invalidación única y token reemplazado en el cliente. Los tests que esperaban navegación desde el cliente se actualizaron como cambio de contrato documentado.
3. H-044: el import eliminaba ítems ausentes del archivo y la cascada borraba sus asignaciones. `03b9c9f` devuelve `409` con `requested: 0` y `reason: "removed"` para los ausentes asignados, junto con las reducciones (`reason: "reduced"`). No escribe nada ante un conflicto y borra sólo ítems bloqueados sin asignaciones. El orden de locks de D-020 no cambió. Revisión de D-020.
4. Tests backend: ausente asignado, tres conflictos juntos (incluida la suma entre dos bases), ausente sin asignación eliminado con `removed = 1` en la auditoría, archivo vacío o ininterpretable con `422`, límite exacto y rollback por fallo de auditoría ya existentes. PostgreSQL: un import que omite un ítem compite con una asignación sobre ese ítem; nunca confirman ambos ni hay `5xx`. 10 repeticiones locales estables.
5. Gates locales: Pint, PHPStan, 209 tests/740 assertions (1 skip PostgreSQL en SQLite), Composer audit y grupo PostgreSQL 17/61 verdes. Frontend: lint, 38 tests en tres corridas, build y audit desde moderado (sólo los cuatro bajos de D-004).

Evidencia funcional: commits `6e888bf` y `03b9c9f`. Actions run `41101014456` sobre `c33ab9f`: `ci`, `postgres-concurrency` y `container-security` verdes.

Pendiente: la pantalla de import muestra sólo el `message` del `409`, no el detalle de `conflicts`. La conservación histórica de ítems eliminados sigue en H-009.

## Última sesión cerrada — atomicidad de finalización

Rama `hardening/finalization-atomicity`, creada desde `hardening/devin-review-fixes` (`0cf0516`, informada por el usuario). Se aplicaron `BE-ARCH`, `BE-TX`, `BE-VALID`, `BE-ERR`, `BE-ASYNC`, `SEC-LOG`, `QA-INVARIANT`, `QA-RESILIENCE` y `DOMINIO.md`.

1. Inventario de escritores de estado: los tres endpoints de transición, más `PATCH /pallets/{pallet}` y `PATCH /orders/{order}`, que escriben `status` sin reglas (H-046, registrado y no tratado).
2. Inventario de escritores de requisitos y sus locks: para el pallet, bases, fotos de base y asignaciones; para el pedido, ítems, cantidades, asociaciones y asignaciones. Dos desvíos: adjust-item comprobaba `done` fuera de su transacción y el borrado de foto de base bloqueaba la foto antes que el pallet.
3. D-026: `FinalizationService` bloquea en el orden D-020 extendido con fotos de base, re-evalúa con los evaluadores puros de `can-finalize`, confirma estado y auditoría juntos y deja Telegram después del commit. Repetir una transición responde `409` sin log. La auditoría registra el estado anterior real.
4. Carreras analizadas: adjust-item, crear/actualizar/eliminar base, migrar, asociar/desasociar, agregar/actualizar ítem, import y subir/eliminar foto de base. Todas se serializan con la transición por el pallet, los ítems o las asignaciones, sin ciclos de locks.
5. Tests SQLite (20): éxito, `paused`, requisitos, repetición, reapertura, rollback por auditoría ×3, Telegram post-commit ×3 y 10 queries constantes. PostgreSQL (6 escenarios × 3 iteraciones, con compuerta de lock que fuerza superposición real): finalizar pallet contra adjust-item y contra borrar foto, finalizar pedido contra cambio de cantidad, dobles finalizaciones y finalizar contra reabrir.
6. Gates locales: Pint, PHPStan (baseline sólo reduce conteos), 229 tests/840 assertions (1 skip), Composer audit y grupo PostgreSQL 23 tests/163 assertions; el archivo nuevo repetido 5 veces sin fallos. Frontend sin cambios.

Evidencia funcional: commit `74586a3`. Actions run `41173522786` sobre `01f01b7`: `postgres-concurrency` y `container-security` verdes. En `ci` pasaron Pint, PHPStan, tests, Composer audit y el audit npm del backend; falló sólo `npm audit --audit-level=moderate` del frontend. Ese audit encontró avisos moderados nuevos de `glob` (GHSA-3g3m-2fjr-6pw4) y `minimatch` (GHSA-g3hv-75wg-m249), transitivos de eslint y workbox-build. La rama no tocó el frontend, ni su lockfile. Remediarlos es un cambio de dependencias (`DO-SUPPLY`) para otra sesión, y no se bajó el umbral de D-004.

Pendiente: los avisos npm del frontend que bloquean el job `ci`. H-046. Los recorridos que siguen permitidos sobre una entidad finalizada, como bases y fotos en un pallet `done` o cantidades en un pedido `done`, requieren una decisión de dominio sobre el ciclo de transiciones. El resto de H-020 y H-009 sigue abierto.

## Última sesión cerrada — ciclo de vida de la limpieza de storage

Rama `hardening/storage-cleanup-lifecycle`, creada desde `hardening/finalization-atomicity` (`1dd07d0`). Se aplicaron `BE-ASYNC`, `BE-TX`, `BE-DATA`, `DO-OBS`, `DO-DR`, `SEC-LOG`, `QA-RESILIENCE` y `QA-DATA`. No hubo GAP: `DO-DR` y `SEC-LOG` exigen retención definida, y `BE-TX` prohíbe llamadas de red dentro del lock. El plazo y el mecanismo son decisión de proyecto (D-027), porque D-018 y D-019 dejaban la retención pendiente.

1. H-047: completadas retenidas para siempre, job y barrido procesando la misma intención, e incrementos de `attempts` perdidos en fallos concurrentes.
2. D-027: reclamo por lease de 300 s con un `UPDATE` condicional, sin transacción durante el storage. La completitud es idempotente sin exigir el token. El fallo incrementa bajo lock corto y sólo el dueño vigente reprograma.
3. Migración nueva `claim_token`/`claimed_until`, probada `up → down → up` en SQLite temporal y en PostgreSQL 17 local.
4. Retención de 30 días (mínimo 2), configurable con `STORAGE_CLEANUP_RETENTION_DAYS`. `storage:prune-deletions` es manual, con dry-run y auditoría `file_deletions_pruned` sin rutas, por decisión del usuario; no se conectó a supervisor. Pendientes, reintentando, en proceso y agotadas nunca se purgan.
5. `storage:cleanup-status` suma procesando, leases vencidos, retenidas, purgables y retención; el criterio de alerta de D-019 no cambió. El barrido informa `omitidas`.
6. Tests: 15 SQLite con reloj controlado y 2 PostgreSQL con dos barridos reales bajo compuerta, tres iteraciones cada uno. El test de fallo concurrente deja 1 intento con el código anterior y 2 con el nuevo. Los tests previos de limpieza pasan sin cambios.
7. Gates locales: Pint, PHPStan sin crecer la baseline, 244 tests/932 assertions (1 skip PostgreSQL en SQLite), Composer audit, archivo nuevo repetido 5 veces y grupo PostgreSQL 25 tests/223 assertions (archivo nuevo 3 veces más). Frontend sin cambios.

Evidencia funcional: commit `249b3d1`. Actions run `41183803393` sobre `eb784ce`: `postgres-concurrency` (incluye las carreras nuevas) y `container-security` verdes. En `ci` pasaron frontend lint/tests/build, Pint, PHPStan, tests, Composer audit y el audit npm del backend. Falló sólo `npm audit --audit-level=moderate` del frontend, por los mismos avisos transitivos de la sesión anterior; la rama no toca el frontend ni su lockfile.

Pendiente: la purga depende de un operador o cron externo (H-029/H-038). Los avisos npm moderados del frontend siguen bloqueando el último paso del job `ci`, como en la sesión anterior; por decisión del usuario no se tratan en este bloque.

## Última sesión cerrada — seguridad de `pallet:archive`

Rama `hardening/archive-old-data-safety`, creada desde `hardening/storage-cleanup-lifecycle` (`85b57e0`). Se aplicaron `BE-TX`, `BE-DATA`, `BE-ASYNC`, `SEC-FILE`, `SEC-LOG`, `SEC-DATA`, `DO-DR`, `DO-RELEASE`, `QA-RESILIENCE` y `DOMINIO.md`.

1. Auditoría (H-048): el comando estaba programado mensualmente en `routes/console.php`, sin runtime que ejecute el scheduler. Borraba objetos antes que filas, vaciaba `ocr_log`, purgaba todo `activity_logs` de más de seis meses y cortaba por `orders.updated_at`. "Archive" significaba borrado físico.
2. No existe una política de retención del negocio. Se abrió `GAP-RETENTION` en `PLAN.md`, con ruta en `ROUTER.md`, y se detuvo la parte destructiva. Los tests de fallo de DB, fallo de storage con intención y lote parcial quedan como criterios del rediseño (D-028), no se implementaron.
3. D-028: se retiró la programación de `pallet:archive`. El comando pasó a ser un inventario de sólo lectura: `--dry-run` informa los conteos y sin la opción falla con código 1 sin modificar nada. Ninguna opción ni variable de entorno habilita el borrado.
4. Tests (7): sin confirmación no modifica, dry-run no modifica y reporta conteos exactos, datos dentro y fuera del corte y logs históricos permanecen, repetición segura, `pallet:archive` ausente del scheduler, guarda sobre los runtimes versionados conocidos (`supervisord.conf`, `docker-entrypoint.sh`, `nixpacks.toml`, `Dockerfile`) sin `schedule:` ni `pallet:archive`, y guarda estática sin escrituras, storage, `pluck()` ni `env()` en el comando. La guarda de runtimes no cubre crons externos ni configuración de plataforma fuera del repositorio. El inventario cuenta en la base mediante subconsultas, sin materializar listas de IDs.
5. Gates locales con PHP 8.4: Pint, PHPStan sin cambios de baseline, 251 tests/1004 assertions (1 skip) y el archivo nuevo repetido 3 veces. Frontend sin cambios.

Evidencia: commits `9279c5e` (contención) y `d3658d1` (conteos en base y guardas acotadas). Actions run `41185684370` sobre `630aca3`: `postgres-concurrency` y `container-security` verdes. En `ci` pasaron frontend build, Pint, PHPStan, tests, Composer audit y el audit npm del backend. Falló sólo `npm audit --audit-level=moderate` del frontend, el mismo paso que en las dos sesiones anteriores; la rama no toca `pallet-frontend/`.

Pendiente: `GAP-RETENTION` (decisión de dominio) y los avisos npm del frontend (`DO-SUPPLY`).

## Última sesión cerrada — validación de pendientes (H-020)

Rama `hardening/request-validation-next-slice`, creada desde `hardening/archive-old-data-safety` (`28e53ee`). Se aplicaron `BE-API`, `BE-VALID`, `BE-ERR`, `SEC-INPUT`, `SEC-AUTHZ` y `QA-CONTRACT`.

1. Inventario de validación inline restante: 13 controladores agrupados por familia, más listados con filtros sin validar (ver H-020 en `AUDITORIA.md`). Se eligió sólo la familia de pendientes: un controlador, tres validaciones y sin pertenencia previa a la validación.
2. Caracterización antes del refactor: `PendingItemValidationContractTest` (20 tests, 97 assertions) con `assertExactJson` y orden de claves de `errors`. Valores registrados:
   - `401` en los cinco endpoints.
   - `403` de `has.role` para escrituras sin rol, aun con cuerpo inválido.
   - `404` de binding antes del `403` y de la validación, incluso para un usuario sin rol.
   - `422` estándar para entrada inválida, y `422` `{"message":"El producto no pertenece a ese pedido"}` sin `errors` ni efectos para la pertenencia, que sólo se evalúa si la validación pasa.
   - Campos fuera del allowlist ignorados.
   - Ningún `409`: re-resolver un resuelto responde `200` y conserva `resolved_at`/`resolved_by`.
3. Refactor: tres Form Requests con `authorize()` en `true` y reglas idénticas. El controlador consume `validated()` y conserva la pertenencia y la lógica de estado. El mismo archivo de tests pasó sin cambios antes y después. La única entrada de baseline PHPStan afectada cambió de tipo (`array` → `array<string, mixed>`), sin crecer.
4. Hallazgos nuevos, no corregidos para no cambiar el contrato: H-049, mensajes de validación como claves sin traducir; H-050, `exists` sin `integer` produce `500` en PostgreSQL 17 local, frente a `422` en SQLite. El `404` de binding expone el nombre de la clase del modelo en `message`; es comportamiento preexistente de Laravel y no se cambió.
5. Gates locales con PHP 8.4: Pint, PHPStan sin errores, tests focalizados `--filter Pending` (30 tests, 1 skip de benchmark), suite completa 271 tests/1101 assertions (1 skip) y Composer audit sin avisos. Frontend sin cambios.

Evidencia funcional: commit `ac4f871`. Actions run `41186832405` sobre `3e6dc31`: `postgres-concurrency` y `container-security` verdes. En `ci` pasaron Pint, PHPStan, tests, Composer audit y el audit npm del backend; falló sólo `npm audit --audit-level=moderate` del frontend, por los mismos avisos transitivos de las sesiones anteriores. La rama no toca el frontend ni su lockfile.

Pendiente: resto del inventario de H-020 por familia, H-049 y H-050.

## Última sesión cerrada — baseline backend de endpoints calientes

Rama `performance/backend-baseline`, creada desde `hardening/request-validation-next-slice`. Se aplicaron `BE-PERF`, `QA-PERF` y `DO-OBS`. Bloque de sólo medición: ningún cambio funcional.

1. Se creó `tests/Feature/Performance/HotEndpointsBenchmarkTest.php` con dataset fijo reproducible (20 pedidos × 12 ítems × 3 pallets × 3 bases × fotos/asignaciones/tickets) y medición por endpoint: queries (query log aislado por request), payload, p50/p95/p99 en 30 iteraciones y memoria.
2. Cubre los cinco candidatos de `benchmarks/README.md`: `/orders`, `/orders/{id}`, `/public/orders/{token}`, `/pallets/{id}` y la rama de sólo lectura del webhook de Telegram (`/vermas`). El rate limit público se limpia entre iteraciones sin desactivarlo.
3. Resultado guardado en `benchmarks/results/2026-11-02-backend-baseline.md` con hardware, versiones, metodología, limitaciones y budgets. PHP 8.4.27 NTS / Laravel 12.70.2 / SQLite `:memory:`.
4. Hallazgo acotado: con dos cardinalidades (4 y 20 pedidos) el conteo de queries es constante en los cinco caminos — el test lo afirma con `assertSame` chico/grande y budgets `≤ observado+1` como assertions. No se afirma ausencia general de N+1 fuera del shape medido. Latencias observacionales (rango de 3 corridas), sin umbral artificial.
5. `/public/orders/{token}` mide 11 queries aquí vs. 6 del baseline R-06; la diferencia es de dataset y quedó registrada como observación, no como regresión.
6. Budgets locales definidos sólo para queries (≤ observado+1, como assertions del test). La latencia es observacional — se documenta como rango de corridas, sin umbral. Nada se extrapola a producción.
7. Siguiente hot path recomendado: `GET /api/v1/orders` (mayor p50/p95 y payload; 5 subqueries correlacionadas por fila).
8. Script reproducible: `benchmarks/run-backend-baseline.ps1`. k6 no está instalado en la máquina; sigue siendo la vía para concurrencia real.
9. Gates locales: Pint y PHPStan verdes sobre el archivo nuevo; benchmark verde con `RUN_PERFORMANCE_BENCHMARKS=1` (skipeado por defecto, no afecta la suite).

## Última sesión cerrada — ids no numéricos en pendientes (H-050)

Rama `hardening/pending-id-validation`, creada desde `origin/performance/backend-baseline` (`20e5d19`, verificado como ancestro). Se aplicaron `BE-VALID`, `BE-ERR`, `BE-API`, `BE-GATE`, `SEC-INPUT`, `SEC-LOG`, `SEC-GATE`, `QA-CONTRACT`, `QA-DATA`, `QA-RISK` y `QA-GATE`. Alcance: sólo la familia de pendientes; H-049 quedó sin tocar.

1. Inventario previo: en `StorePendingItemRequest`, `order_id` y `order_item_id` combinaban `required` + `exists` sin chequeo de tipo. `UpdatePendingItemRequest` e `IndexPendingItemsRequest` no tienen reglas `exists`. El inventario completo de reglas `exists` sin `bail` en otras familias quedó registrado en `AUDITORIA.md` (H-050), junto con el route binding de `/pending-items/{pendingItem}`, que produce el mismo `500` en PostgreSQL con id no numérico.
2. Causa: las reglas combinaban `required` + `exists` sin chequeo de tipo, así que el id no numérico llegaba a la consulta `exists` y PostgreSQL 17 respondía `SQLSTATE[22P02]` al comparar `bigint` con un string; el handler global lo traduce a `500` genérico. SQLite acepta la comparación y devuelve `422` `validation.exists`.
3. Corrección: `['bail', 'required', 'integer', 'exists:...']` en ambos ids. `integer` es lo que impide que el valor inválido llegue a `exists` (Laravel 12 además omite `exists`/`unique` tras un fallo previo del mismo atributo — verificado en la rebanada de attachPallet); `bail` explicita el cortocircuito y uniforma el patrón. Cambio de contrato acotado: para ids no numéricos el `422` pasa de `validation.exists` a `validation.integer`; todo lo demás se conserva (401/403/404, pertenencia, allowlist, enters inexistentes → `validation.exists`, strings numéricos → 201, orden determinista de claves de `errors` igual al orden de declaración de reglas, sin efectos ante validación fallida).
4. Tests: `PendingItemValidationContractTest` pasó de 20 a 24 tests (114 assertions) con `assertExactJson`, conteos de `pending_items`/`activity_logs` en cero y caso de strings numéricos que crean el pendiente. `PendingItemIdValidationTest` nuevo en el gate `postgres` (2 tests, 13 assertions): falla con `500` sobre las reglas anteriores y devuelve `422` con la corrección — acreditado localmente contra PostgreSQL 17.11 real (binarios EDB, SHA-256 verificado), antes y después del cambio.
5. Gates locales con PHP 8.4.27: Pint verde, PHPStan sin errores ni baseline nuevo, tests focalizados `--filter PendingItemValidationContractTest` verdes, suite completa 275 tests/1118 assertions (2 skips preexistentes), grupo `postgres` completo 27 tests/239 assertions verde y `composer audit` sin avisos. Frontend sin cambios (build local sólo para correr `ExampleTest`, como en CI).

Evidencia: commit `cd185f4` (código) y `43af706` (documentación). Actions runs `41526955046`, `41527716296` y `41528468113`: `postgres-concurrency` y `container-security` verdes en los tres. En `ci` pasaron frontend build, Pint, PHPStan, tests, Composer audit y el audit npm del backend; falló sólo `npm audit --audit-level=moderate` del frontend, el mismo aviso transitivo conocido de las sesiones anteriores. La rama no toca `pallet-frontend/`.

Pendiente: el resto del inventario de H-050 (otras familias con `exists` sin `bail` y el route binding no numérico), resto de H-020 por familia y H-049.

## Sesión cerrada — supply chain del frontend (advisories glob/minimatch)

Rama `hardening/frontend-supply-chain`, creada desde `origin/hardening/pending-id-validation` (`e85a32d`, verificado descendiente de `20e5d19` con working tree limpio). Se aplicaron `DO-SUPPLY`, `DO-CI`, `DO-GATE`, `SEC-SUPPLY`, `SEC-GATE`, `QA-SEC`, `QA-RISK`, `QA-GATE`, `QA-STATIC`, `FE-GATE`, `FE-PWA` y `FE-PERF`. Alcance: sólo `pallet-frontend/` y documentación operativa.

1. Diagnóstico con `npm ci` limpio sobre `e85a32d`: 15 vulnerabilidades (2 low, 13 moderate). Los moderados venían de dos advisories publicados el 2026-10-27: `GHSA-3g3m-2fjr-6pw4` (glob `>=9.0.0 <=14.0.1`, parche sólo en `14.0.2`) vía `vite-plugin-pwa → workbox-build → glob@11.1.0`, y `GHSA-g3hv-75wg-m249` (minimatch `<11.0.1`, parche sólo en `11.0.1`) vía `eslint@9 → minimatch@3.1.5`, `@eslint/config-array`/`@eslint/eslintrc → minimatch@3`, `workbox-build → @trickfilm400/rollup-plugin-off-main-thread → ejs → jake → filelist → minimatch@5.1.9` y `glob@11 → minimatch@10.2.5`.
2. Ningún consumidor tiene rango corregido: `workbox-build@7.4.1` es la última versión y exige `glob@^11`; `eslint@10` aún usa `minimatch@^10`; `vite-plugin-pwa@2.0.0` sigue trayendo `workbox-build@^7.4.1`. La única propuesta de npm era el downgrade mayor `vite-plugin-pwa@1.1.0`, rechazado.
3. Remediación: overrides acotados `"glob": "14.0.3"` y `"minimatch": "11.0.4"` en `package.json` (D-029). Compatibilidad verificada por consumo real: `workbox-build` sólo usa `globSync`; ESLint/`config-array`/`eslintrc`/`filelist` sólo usan `Minimatch`/`minimatch.match`, todos presentes en las versiones parcheadas. Sin cambios de dependencias directas ni de código.
4. Gates locales (Node 24, npm 11): `npm ci`, `npm ls --all` sin inválidos ni extraneous (sólo UNMET OPTIONAL de plataformas ajenas de Tailwind Oxide), `npm run lint` verde, `npm test` 38 tests/8 archivos verdes, `npm run build` verde con `generateSW`: `sw.js`, `workbox-3a3a58c4.js`, `manifest.webmanifest` y 48 entradas de precache en `pallet-backend/public/app/` (output ignorado por git, como antes). `npm audit --audit-level=moderate` exit 0.
5. PWA sin cambios funcionales: misma configuración `generateSW`/`autoUpdate`, `runtimeCaching` idéntico (`photos-cache` CacheFirst, `api-cache` NetworkFirst), `navigateFallback` y denylist conservados. El bundle de aplicación no cambió: los overrides sólo afectan tooling (workbox-build corre en Node durante el build; minimatch en ESLint).
6. Estado del audit: antes 2 low + 13 moderate; después 4 low + 0 moderate. Los 4 bajos son `deepmerge` (`GHSA-gcqx-w48m-w2h4`), gobernados por `D-004` con vencimiento 2027-01-29. `npm audit --json` confirma `{"low":4,"moderate":0,"high":0,"critical":0}`.

Evidencia: commits `aed1002` (overrides y lockfile) y `fd30929` (documentación); Actions run `41576492263` con los tres jobs verdes: `ci` (incluye `npm audit --audit-level=moderate` del frontend), `postgres-concurrency` y `container-security`.

## Última sesión cerrada — caches privadas de la PWA (H-051)

Rama `hardening/pwa-private-cache`, creada desde `origin/hardening/frontend-supply-chain` (`e0a9883`, verificado descendiente de `e85a32d` con working tree limpio). Se aplicaron `FE-PWA`, `FE-SESSION`, `FE-DATA`, `FE-GATE`, `SEC-DATA`, `SEC-BROWSER`, `SEC-IDENTITY`, `SEC-AUTHZ`, `SEC-GATE`, `QA-SEC`, `QA-CONTRACT`, `QA-RISK`, `QA-COMPAT`, `QA-GATE`, `DO-EDGE`, `DO-SUPPLY`, `DO-GATE`, `DOMINIO.md`, `D-023`, `D-024` y `D-029`. Alcance: `pallet-frontend/`, el paso frontend del job `ci` y documentación operativa. Sin dependencias nuevas ni cambios en los overrides de `D-029`.

1. Caracterización: `npm run build` sobre `e0a9883` generó un `sw.js` con `registerRoute` `NetworkFirst` → `cacheName:"api-cache"` (`networkTimeoutSeconds:10`, `maxAgeSeconds:86400`) sobre `/api/v1/*` y `CacheFirst` → `cacheName:"photos-cache"` (`maxAgeSeconds:604800`) sobre `/storage/*.webp`. Las claves son sólo URL; logout/401 no tocaban Cache Storage; `cleanupOutdatedCaches` no elimina caches no-precache, así que las caches legacy sobreviven a la actualización del SW.
2. Corrección: `runtimeCaching` retirado; configuración Workbox extraída a `pwa-workbox.config.js` (fuente única). `clearPrivateCaches()` (`src/auth/privateCaches.js`) borra exactamente `api-cache`/`photos-cache`, es idempotente y nunca rechaza sin Cache API. Integrada en `main.jsx` (arranque, purga legacy), `endSession` (logout, `401` autenticado, `/auth/me` fallido — misma autoridad de D-024) y `login`/auto-registro.
3. Verificación: `scripts/verify-sw.mjs` inspecciona el `sw.js` generado tras el build y se enchufó al paso frontend del job `ci`. Falla si aparecen `api-cache`, `photos-cache`, `NetworkFirst`, `CacheFirst` o `api/v1`, o si falta precache del shell o `manifest.webmanifest`.
4. Gates locales (Node 24, npm 11): `npm ci`, `npm run lint` verde, `npm test` 51 tests/10 archivos verdes, `npm run build` verde (`generateSW`, 48 entradas de precache, `sw.js` + `workbox-*.js` + `manifest.webmanifest`), `node scripts/verify-sw.mjs` OK, `npm audit --audit-level=moderate` exit 0, `npm audit --json` → `{"low":4,"moderate":0,"high":0,"critical":0}` (deepmerge, `D-004`), `npm ls --all` sin inválidos ni extraneous.
5. El `sw.js` nuevo no contiene `api-cache`, `photos-cache`, `NetworkFirst`, `CacheFirst` ni `api/v1`; conserva `precacheAndRoute`, `cleanupOutdatedCaches` y la ruta de navegación con `denylist:[/^\/api/,/^\/storage/]`.
6. Limitación declarada: jsdom no ejecuta un service worker real; la actualización desde caches legacy se acredita por la purga de arranque, la evaluación del script de `activate` y la inspección del artefacto. Verificación manual DevTools recomendada en el próximo deploy.
7. Documentación: `H-051` en `AUDITORIA.md`, `D-030` en `DECISIONES.md`, `PLAN.md` actualizado. `GAP-OFFLINE` continúa abierto; no se promete uso offline de datos de negocio.
8. Corrección post-revisión (carrera de publicación de identidad): la primera versión corría la purga fire-and-forget y confiaba sólo en el JS de la página. Ahora `bootstrapApp` espera la purga antes de montar React; `login()` y el auto-registro esperan antes de publicar token/usuario; `endSession` queda fire-and-forget por decisión (la barrera de la identidad nueva la dan bootstrap y login); y `public/sw-cleanup.js` se importa en el worker vía `importScripts` de `generateSW` para borrar las dos caches legacy en `activate`. `verify-sw.mjs` exige ese `importScripts` y el archivo en el artefacto. 59 tests verdes, incluidos los de promesas diferidas (sin montaje ni publicación antes de la purga) y los de `activate` con `self`/`caches` falsos. Gates repetidos tras la corrección: lint, tests, build, verify-sw, `npm audit --audit-level=moderate` y `npm ls --all` verdes; `sw.js` generado contiene `importScripts("sw-cleanup.js")` y `sw-cleanup.js` llega al artefacto con `activate` y ambos nombres. CI de la corrección: Actions run `41580895922` sobre `b502bea` con los tres jobs verdes.

Commits: `3ddfe07` (caracterización y tests), `0938c88` (corrección PWA y limpieza legacy), `0e114f1` (documentación). Evidencia CI remoto: Actions run `41580029108` sobre `hardening/pwa-private-cache` con `ci`, `postgres-concurrency` y `container-security` verdes; el job `ci` ejecutó `verify-sw.mjs` tras el build.

## Última sesión cerrada — inmutabilidad de pedidos y pallets finalizados (H-046)

Rama `hardening/finalized-entity-immutability`, creada desde `origin/hardening/pwa-private-cache` (`7f35674`, verificado descendiente de `e0a9883` con working tree limpio). Se aplicaron `BE-VALID`, `BE-TX`, `BE-API`, `BE-ERR`, `BE-DATA`, `BE-ARCH`, `SEC-AUTHZ`, `SEC-INPUT`, `SEC-LOG`, `QA-INVARIANT`, `QA-CONTRACT`, `QA-DATA`, `QA-RISK`, `QA-RESILIENCE`, `DOMINIO.md`, `FE-MUTATION`/`FE-DATA`/`FE-FORM` (revisión de consumidores), D-020, D-025, D-026 y H-045/H-046. Decisión de negocio ya aprobada por el usuario: `done` es inmutable, `paused` sin cambios, sin GAP.

1. Guarda central: `FinalizationService::lockPalletForWrite`/`lockOrderForWrite` toman `FOR SHARE` sobre la autoridad y releen el estado (serializa contra el `FOR UPDATE` de finalize/reopen); `assert*Writable` lanza `DomainConflictException` → `409` para callbacks de subida (compensa el archivo). `POST /orders/{order}/reopen` agrega la reapertura auditada que faltaba.
2. H-046: PATCH de estado corre en transacción con `FOR UPDATE`; `done` actual u objetivo → `409`; `open`↔`paused` conservado con nuevos logs `*_status_changed`.
3. Escritores cubiertos: attach/detach (ambos lados), import, items add/update, bases create/update/destroy/migrate/adjust (ítems de pedido `done` incluidos), fotos pallet/base/ticket, anotaciones, tickets, trigger-ocr + job OCR (`writePhotoWhileOrderOpen`: lock pedido + lock foto + escritura en una sola transacción; descarta resultado si el pedido finalizó), pendientes, faltantes públicos y su revisión, borrados de entidad web/admin (admin storage ahora con lock real dentro de transacción), uploads del bot y creación de base por Telegram (ahora vía `PalletBaseService`).
4. Frontend (revisión de consumidores): `updateStatus` apuntaba a `/…/status` inexistente → corregido a `/orders/{id}` y `/pallets/{id}`; acción "Reabrir pedido" en `OrderDetailActions` con `useOrderDetail::reopen`.
5. Tests: `FinalizedEntityImmutabilityTest` (≈35 casos SQLite: rechazo 409 sin efectos en cada escritor, compensación de archivos, paused con auditoría, mutabilidad restaurada tras reopen). PostgreSQL: escenarios con compuerta corregidos — finalize pallet vs delete foto ya no acepta escritura posterior (done ⇒ delete 409 y foto intacta; delete primero ⇒ finalize reevalúa) y finalize pedido vs PATCH item igual — más dos nuevos (finalize pallet vs crear base; finalize pedido vs PATCH estado) y dos de OCR (`test:ocr-persist`, comando exclusivo de `APP_ENV=testing`, invoca `writePhotoWhileOrderOpen`): finalize confirma primero por cola FIFO y la persistencia incremental y final se descartan, ×5 iteraciones.

Corrección post-revisión: Pint aplicado a los 4 archivos en rojo; OCR reescrito al patrón transaccional (se eliminó el check-then-write); guards auditados — todos los locks comparten transacción con su escritura, incluido `StorageController` admin que ahora bloquea dentro de `DB::transaction`.

Segunda corrección: `admin/storage` ya no ejecuta `Storage::delete()` con la transacción abierta — dentro de la tx corta sólo quedan lock FOR UPDATE de la entidad, re-lectura del estado `done`, delete de filas, activity log y `StorageCleanupService::scheduleDeletion` (intenciones durables + dispatch `afterCommit`, D-018/D-027); un crash recupera por `storage:retry-pending-deletions`. Tests nuevos: `AdminStorageDeletionTest` (6 casos: 409 sin efectos, intención por archivo, cero I/O en tx con `Queue::fake`, recuperación por barrido, idempotencia, auditoría). Se corrigieron además el mensaje `409` de adjust-item (contrato existente), los conteos acotados de queries (+1 lock `orders` en adjust y migrate), el import faltante de `ActivityLogger` y los errores PHPStan (`ticket()` genérico, `getKey()` en uniones Model, baseline reducido 2→1 en TelegramBotController). Gates locales con PHP 8.4.27: `pint --test` verde, PHPStan sin errores, suite SQLite completa 322 tests verdes (2 skipped por flag), `composer audit` sin advisories.

Corrección del gate PostgreSQL (run 41585098217 falló): `test_finalize_de_pedido_vs_patch_de_estado` exigía exactamente {200,409}, pero el PATCH puede tomar el FOR UPDATE primero, escribir `paused` y finalizar luego — `paused` no bloquea la finalización, así que ambos 200 es un orden legal (la escritura precede al commit de finalize). Contrato corregido: finalize siempre 200 con pedido `done`, PATCH 200-auditado (ganó el lock) o 409; ramas delete-foto y crear-base ahora exigen que el finalize perdedor responda 409. Todos los escenarios repiten ×5.

Evidencia CI: Actions run `41587490864` sobre `3388488` con `ci`, `postgres-concurrency` y `container-security` verdes.

## Última sesión cerrada — inventario de route bindings bigint (H-050)

Rama `audit/bigint-route-bindings`, creada desde `origin/security/symfony-advisories-2026-11-04` (`734af12`, verificado como HEAD de la base con working tree limpio). Se aplicaron `BE-API`, `BE-ERR`, `BE-DATA`, `BE-VALID`, `SEC-INPUT`, `SEC-AUTHZ`, `SEC-LOG`, `QA-CONTRACT`, `QA-RISK`, `QA-DATA`, `QA-GATE`, `BIBLIAS-GOBERNANZA.md` y D-031. Alcance: sólo auditoría y caracterización — dos suites nuevas, trigger `audit/**` en el workflow y documentación. Sin solución transversal, sin `whereNumber()` masivo, sin modelos tocados, sin avance de H-020 y sin frontend.

1. Inventario: 17 parámetros dinámicos en `routes/api.php` clasificados en binding implícito sobre bigint (`{order}` ×18 rutas, `{pallet}` ×22, `{customer}`, `{item}`, `{ticket}` ×6, `{photo}` tickets ×4, `{base}` ×4, `{user}` ×2, `{missingItemRequest}`), resolución manual sobre bigint (`{base}` ×4, `{photo}` ×4 en fotos de pallet/base y anotaciones), `{pendingItem}` ya protegido con `resolveRouteBinding`, `{id}` de admin storage con type-hint `int`, `{id}` de `/auth/verify` (método inexistente), `{token}` con regex y `{ean}` escalar. Matriz completa en `AUDITORIA.md` H-050.
2. Caracterización SQLite (`tests/Feature/Routing/BigintRouteBindingCharacterizationTest.php`, 16 tests/122 assertions): todo segmento no numérico (`abc`, `12abc`, `-1`, `1.5`, overflow) devuelve `404` en bindings implícitos y resoluciones manuales — la comparación `id = 'abc'` simplemente no encuentra fila. Registrado como divergencia, no como evidencia de seguridad.
3. Caracterización PostgreSQL (`tests/Integration/Postgres/BigintRouteBindingCharacterizationTest.php`, grupo `postgres`): cada caso captura la excepción real sin handler (`QueryException` SQLSTATE `22P02`/`22003` para bindings y resoluciones manuales, `TypeError` para el `int $id`, `Error` para la ruta muerta, `ModelNotFoundException` para `-1` e ids inexistentes) y luego acredita el `500` genérico con `error_id` — ningún `500` ajeno se cuenta como `22P02`. Nominados para reemplazo por tests de contrato 404 durante la corrección.
4. Precedencia medida: `auth:sanctum` (401) → binding (404/500) → `user.active` (401) → `has.role`/`admin` (403) → Form Request. Anónimo recibe 401 antes del binding; inactivo, sin-rol y no-admin reciben el resultado del binding antes que su 401/403. Por eso `whereNumber()` queda descartado para rutas autenticadas (convertiría el 401 en 404 en el matching).
5. Pertenencia de anidados: Laravel resuelve hijos por id global; controladores/servicios verifican padre y responden `404` — ningún hijo ajeno se opera como propio. Sin hallazgo de ownership.
6. Hallazgos nuevos: `{id}` int de admin storage devuelve `500` por `TypeError` en **ambos** motores (variante PHP del defecto, no depende de PostgreSQL) y las rutas `/auth/verify/{id}` + `/auth/email/resend` apuntan a métodos inexistentes → nuevo hallazgo H-052. H-050 pasa de cerrado a **parcial** en `AUDITORIA.md`/`PLAN.md` con la matriz separando protegido/vulnerable/no aplica; el plan de corrección por familias y las alternativas (resolución por modelo, binding explícito, mecanismo común, `whereNumber`) quedan registrados sin elegir arquitectura.
7. Corrección documental menor: la evaluación de alcanzabilidad de H-042 decía "ninguna" superficie Symfony era ejercida; corregido a "la mayoría no se alcanzaba directamente" con `Request::getBaseUrl()` sí alcanzable por request, mitigado por proxy y ya parchado. Sin tocar el cierre ni la evidencia.
8. Gates locales (PHP 8.4.24, Composer 2.10.2): `pint --test` verde, PHPStan 89 archivos sin errores ni baseline nuevo, suite SQLite completa 394 tests/1601 assertions (2 skips preexistentes de benchmark), `composer audit` → 0 advisories. Grupo `postgres` y build de imagen no corren en esta estación (sin servidor PG ni Docker); los acredita CI.

Commits: `6c3e886` (inventario y tests de caracterización + trigger `audit/**`), `744d3c0` (documentación, matriz H-050, plan de corrección y corrección H-042/H-052) y el de evidencia CI. Actions run `41965492370` sobre `744d3c0` con `ci`, `postgres-concurrency` y `container-security` verdes; el run `41965294771` sobre `6c3e886` también quedó verde en los tres jobs — allí el grupo `postgres` acreditó el `500`/`22P02` vigente en PostgreSQL 17 (los tests de caracterización afirman la excepción real y el cuerpo genérico).

## Sesión anterior — advisories Symfony del 2026-11-04 (H-042)

Rama `security/symfony-advisories-2026-11-04`, creada desde `origin/hardening/pending-item-route-binding` (`7ccd2e0`, verificado como HEAD de la base con working tree limpio). Se aplicaron `DO-SUPPLY`, `DO-CI`, `DO-GATE`, `SEC-SUPPLY`, `SEC-GATE`, `QA-SEC`, `QA-RISK`, `QA-GATE`, `BE-GATE` y D-004/D-029 como precedentes (aquí hubo parche directo: no aplicó excepción). Alcance: sólo `pallet-backend/composer.lock` y documentación; sin cambios de código, constraints ni frontend.

1. Evidencia previa: `composer audit --format=json` reportó 8 advisories en 5 paquetes, todos publicados el 2026-11-04 — `symfony/http-foundation` 7.4.22 (GHSA-xg2q-4rrq-657q trusted proxies `private_ranges`, CVE-2026-118514 HEAD vs `MethodRequestMatcher`, GHSA-3h27-hjv7-fqjx path rules, GHSA-5mx8-mwrp-5693 host en URLs generadas), `symfony/http-kernel` 7.4.22 (GHSA-wg5q-m2ww-f3c9 fragmentos inline + payloads CSV/YAML), `symfony/routing` 7.4.22 (CVE-2026-118516 host placeholders), `symfony/filesystem` 8.1.5 (GHSA-6prc-h956-hxjq prefijo `://` como raíz) y `symfony/yaml` 8.1.10 (GHSA-hwfg-qwvh-2wfx parseo exponencial/cuadrático). Rangos afectados parcheados en 7.4.23 y 8.1.11.
2. Fuentes oficiales: `symfony.com/blog/symfony-7-4-23-released` (2026-11-04) y changelog `v8.1.10...v8.1.11` de `symfony/symfony`; ambos releases son security releases del mismo día.
3. Alcance de dependencias: `http-foundation`/`http-kernel`/`routing` los exige `laravel/framework ^7.2.0` (rama 7.4); `filesystem` lo exige `aws/aws-sdk-php` (cliente R2, producción); `yaml` sólo llega por `laravel/sail` (`require-dev`, fuera de la imagen `--no-dev`).
4. Actualización mínima: `composer update symfony/http-foundation symfony/http-kernel symfony/routing symfony/filesystem symfony/yaml` → exactamente 5 updates, 0 installs, 0 removals; diff del lock limitado a versión/reference/time de esos 5 paquetes. Sin `--with-all-dependencies` (no hizo falta), sin tocar `composer.json`, sin ignores.
5. Alcanzabilidad evaluada por advisory (detalle en `AUDITORIA.md` H-042): firewall Symfony Security, fragmentos ESI/HInclude, matchers compilados y el shortcut `private_ranges` no son ejercidos por Laravel; `yaml` es dev-only; `filesystem` no recibe paths externos; `getBaseUrl()` sí corre por request pero el host lo fija el proxy. Se parchó todo igual: existía versión compatible y `DO-SUPPLY` no admite "no alcanzable" como excusa del audit.
6. Gates locales (PHP 8.4.27, Composer 2.10.2): `composer validate` OK, `composer install --dry-run` "Nothing to install" (lock sincronizado), `composer audit` → 0 advisories, `pint --test` verde, PHPStan 89 archivos sin errores ni baseline nuevo, suite SQLite completa 378 tests/1479 assertions (2 skips preexistentes). Grupo `postgres` y build de imagen no corren en esta estación (sin servidor PG ni Docker); los acredita CI.
7. Verificación de superficies: `bootstrap/app.php` sólo define aliases de middleware (sin `trustProxies`); `TrustProxies` de Laravel usa IPs explícitas o `*`, nunca `private_ranges`; no hay `MapRequestPayload`, `_fragment` ni uso de `symfony/routing` en `app/`; uploads pasan por `league/flysystem`, no por `symfony/filesystem`.

Commits: `1567882` (lockfile), `6b84017` (documentación) y `c69f87b` (trigger `security/**` en el workflow). El patrón de branches del workflow no cubría `security/*`, así que se agregó de forma aditiva para que el gate exigido pudiera ejecutarse; ningún check se debilitó. Actions run `41947422812` sobre `c69f87b` con `ci` (incluye `composer audit` en 0 advisories), `postgres-concurrency` y `container-security` verdes.

## Sesión anterior — route binding numérico de pending items (H-050)

Rama `hardening/pending-item-route-binding`, creada desde `origin/hardening/order-attach-pallet-validation` (`cc5bedc`, verificado como HEAD de la base con working tree limpio y descendiente de `c25ca82`). Se aplicaron `BE-API`, `BE-VALID`, `BE-ERR`, `BE-DATA`, `SEC-INPUT`, `SEC-AUTHZ`, `SEC-LOG`, `QA-CONTRACT`, `QA-RISK`, `QA-DATA`, `QA-GATE`, `DOMINIO.md` y D-031. Alcance: sólo `PATCH`/`DELETE /pending-items/{pendingItem}` y la resolución de `PendingItem $pendingItem`; H-049 sin tocar, sin GAP.

1. Caracterización previa (`2f2a502`): `PendingItemRouteBindingContractTest` con 11 tests/43 assertions congeló la precedencia vigente — `auth:sanctum` (401) → `SubstituteBindings` (404/500) → `user.active` (401) → `has.role` (403) → Form Request. El binding corre antes que `user.active` y `has.role`: un inactivo o sin-rol recibe el resultado del binding antes que su 401/403. En SQLite todo id inválido (`'abc'`, `'12abc'`, `'1.5'`, `'-1'`, overflow, caracteres codificados) devolvía 404, con cero filas y cero logs.
2. Evidencia del defecto: `PendingItemRouteBindingTest` (gate `postgres`) acreditó el `500` real en PATCH y DELETE para `'abc'` antes del fix — el segmento llega sin tipo a la comparación `bigint` y PostgreSQL responde `SQLSTATE[22P02]`, traducido por el handler global al 500 genérico.
3. Solución (`911a041`): `PendingItem::resolveRouteBinding` devuelve `null` para segmentos que no son un entero no negativo dentro del rango bigint (`filter_var` + `min_range: 0`); el framework emite el `404` estándar (`ModelNotFoundException`) en el mismo punto de precedencia. Se descartó `whereNumber()` porque falla en el matching de ruta, antes de cualquier middleware, y convertiría el `401` de anónimo en `404`. Sin casts permisivos ni captura de `QueryException`.
4. Tests posteriores: el test PostgreSQL se actualizó deliberadamente al contrato 404 (documentado como evidencia antes/después); `'abc'`/`'12abc'`/`'1.5'`/`'-1'`/overflow → 404 en ambos métodos, ids numéricos inexistentes → 404, válidos → flujo normal con auditoría, y el `409` de pedido `done` conservado para ids válidos.
5. Gates locales: `pint --test` verde, PHPStan sin errores ni baseline nuevo, suite SQLite 378 tests/1479 assertions (2 skips benchmark preexistentes), grupo `postgres` completo 37 tests/468 assertions contra PostgreSQL 17.11 local y `composer audit` sin avisos. Frontend sin cambios.

Evidencia funcional: commits `2f2a502`, `911a041` y `2344612`. Actions run `41606237868` sobre `2344612` con `ci`, `postgres-concurrency` y `container-security` verdes.

Pendiente: uniformización de `bail` y guardas de binding en las demás familias (bajo H-020: bindings implícitos `{order}`, `{pallet}`, `{item}`, etc. comparten la misma forma de defecto, no verificados en este bloque) y H-049 abierto para una rebanada de idioma/UX.

## Sesión anterior — validación de attach-pallet (H-020/H-050)

Rama `hardening/order-attach-pallet-validation`, creada desde `origin/hardening/missing-item-request-validation` (`c25ca82`, verificado como HEAD de la base con working tree limpio y descendiente de `055eb45`). Se aplicaron `BE-VALID`, `BE-API`, `BE-ERR`, `BE-TX`, `SEC-INPUT`, `SEC-AUTHZ`, `SEC-LOG`, `QA-CONTRACT`, `QA-INVARIANT`, `QA-RISK`, `QA-DATA`, `QA-GATE`, `DOMINIO.md`, D-020, D-025 y D-031. Alcance: sólo `OrderController::attachPallet`; H-049 quedó sin tocar y no hubo GAP (el patrón `bail`+`integer`+`exists` ya estaba gobernado).

1. Caracterización previa (`1da6eff`): `AttachPalletValidationContractTest` con 16 tests/58 assertions congeló el contrato — `401` anónimo e inactivo, `403` de `has.role` aun con payload inválido, `404` de binding antes del `403` y de la validación, `422` con cuerpo exacto y orden de claves, cero pivot y cero activity logs ante fallo, `201` con `message`/`pallet`/`pallets`, `409` de asociación repetida y de pedido o pallet `done` sin efectos, campos extra ignorados, string numérico que asocia y Telegram sólo en el éxito.
2. Hallazgo de la caracterización: con `integer` antes de `exists`, Laravel 12 ya omite la regla `exists` tras un fallo previo del mismo atributo (`hasNotFailedPreviousRuleIfPresenceRule`, "to avoid possible database type comparison errors"), así que `'abc'` ya devolvía `422` `validation.integer` — el `500` `22P02` de H-050 no se materializaba en este endpoint. El inventario de H-050 se corrigió en consecuencia: las reglas restantes con `integer` previo están cubiertas por la misma guarda y el `500` real sigue sólo en el route binding de `/pending-items/{pendingItem}`.
3. Refactor (`5c3db71`): `AttachPalletToOrderRequest` con `authorize()` en `true` (autorización en `auth:sanctum`, `user.active` y `has.role`), reglas `['bail','required','integer','exists:pallets,id']` como array y el controlador consumiendo `validated()`. `bail` hace explícita la garantía H-050 sin depender del detalle interno del validador. Sin cambio de contrato observable: los 16 tests pasaron sin modificaciones.
4. Evidencia PostgreSQL: `AttachPalletIdValidationTest` (gate `postgres`) prueba `'abc'` → `422` `validation.integer` sin `22P02` ni efectos, entero inexistente → `422` `validation.exists` y string numérico → `201`. La carrera cruzada `attachPallet`/`attachOrder` del gate siguió verde (`201`/`409`, una fila pivot, un log). Grupo `postgres` completo local: 35 tests/450 assertions contra PostgreSQL 17.11.
5. Gates locales con PHP 8.4.24/8.4.27: `pint --test` verde, PHPStan sin errores ni baseline nuevo (`--memory-limit=1G`), suite SQLite completa 367 tests/1436 assertions (2 skips de benchmark preexistentes) y `composer audit` sin avisos. Frontend sin cambios.

Evidencia funcional: commits `1da6eff`, `5c3db71` y `4680292`. Actions run `41598502797` sobre `4680292` con `ci`, `postgres-concurrency` y `container-security` verdes.

Pendiente: resto del inventario de H-020 (12 controladores + listados) y de H-050 (uniformizar `bail` en las familias restantes y el route binding no numérico), y H-049 abierto para una rebanada de idioma/UX.

## Sesión anterior — validación de solicitudes de faltantes (H-020/H-050)

Rama `hardening/missing-item-request-validation`, creada desde `origin/hardening/finalized-entity-immutability` (`055eb45`, verificado como HEAD de la base con working tree limpio). Se aplicaron `BE-VALID`, `BE-API`, `BE-ERR`, `BE-TX`, `SEC-INPUT`, `SEC-AUTHZ`, `SEC-ABUSE`, `SEC-LOG`, `QA-CONTRACT`, `QA-INVARIANT`, `QA-RISK`, `QA-DATA`, `QA-GATE`, `DOMINIO.md` y D-031. Alcance: sólo `MissingItemRequestController`; H-049 quedó sin tocar.

1. Caracterización previa (`ad8043a`): `MissingItemRequestValidationContractTest` con 29 tests/82 assertions congeló el contrato — `401` anónimo, `403` de `admin` con cuerpo `{"error": ...}` (distinto del `message` de sanctum), `404` de binding antes del `403`, `404` de token desconocido antes de validar, `422` estándar con orden de claves según declaración, pertenencia ítem/pedido `422` sin `errors`, techo de cantidad, anti-duplicado `409`, `409` de pedido `done` tanto en el alta pública como en la revisión, `429` del throttle `public-order-write`, allowlist de campos y cero filas/logs ante fallo.
2. Refactor (`9abe2b2`): `IndexMissingItemRequestsRequest`, `StoreMissingItemRequestRequest` y `UpdateMissingItemRequestRequest` con las mismas reglas en el mismo orden y `authorize()` en `true`. El store conserva la precedencia `404` del token resolviéndolo en `prepareForValidation` con `HttpResponseException` (cuerpo exacto `{"message":"Pedido no encontrado"}` en cualquier `APP_DEBUG`). Pertenencia, techo, anti-duplicado y la revisión transaccional quedaron en sus límites; nada de lógica de negocio se movió al Form Request.
3. Cambios de contrato deliberados: `order_item_id` pasó a `['bail','required','integer','exists:order_items,id']` (H-050 — antes no tenía ni `integer`, así que `'abc'` daba `422` `validation.exists` en SQLite y `500` `22P02` en PostgreSQL) y el filtro `status` del index pasó de lectura cruda (valor inválido → `200` lista vacía) a `in:` (`422` `validation.in`), criterio consistente con la familia de pendientes y gobernado en `D-032`. Los tests de caracterización fijaron el `200 []` y el `validation.exists` previos; sólo esos dos se modificaron deliberadamente para fijar el contrato nuevo, el resto pasó sin cambios.
4. Evidencia PostgreSQL: `MissingItemRequestIdValidationTest` (gate `postgres`) prueba `'abc'` → `422` `validation.integer` sin `22P02` ni efectos, entero inexistente → `422` `validation.exists` y string numérico → `201`. El grupo `postgres` completo corrió en CI, incluida la carrera de dos aprobaciones concurrentes (`200`/`409`, un pendiente y un log) — la atomicidad de la revisión no se rompió.
5. Consumidor frontend revisado para `D-032`: `usePendingItemsPage` no envía `status` y trata cualquier error como toast (nunca lista vacía); `RequestCard` sólo emite `approved`/`rejected`. Sin cambios de frontend.
6. Gates locales con PHP 8.4.27: `pint --test` verde, PHPStan sin errores ni baseline nuevo (`--memory-limit=1G`), suite SQLite completa 351 tests/1378 assertions (2 skips de benchmark preexistentes) y `composer audit` sin avisos. Frontend sin cambios.

Evidencia funcional: commits `ad8043a` y `9abe2b2`. Actions run `41591367510` sobre `9abe2b2` con `ci`, `postgres-concurrency` y `container-security` verdes.

Pendiente: resto del inventario de H-020 (12 controladores + listados) y de H-050 (reglas `exists` sin `bail` en otras familias y el route binding no numérico), y H-049 abierto para una rebanada de idioma/UX.

## Última sesión cerrada — familia admin de bindings bigint (H-050)

Rama `hardening/admin-bigint-route-bindings`, creada desde `origin/audit/bigint-route-bindings` (`1cebe12`, verificado como HEAD de la base con working tree limpio). Se aplicaron `BE-API`, `BE-ERR`, `BE-DATA`, `BE-ARCH`, `SEC-INPUT`, `SEC-AUTHZ`, `SEC-LOG`, `QA-CONTRACT`, `QA-RISK`, `QA-DATA`, `QA-GATE`, D-007, D-031 y la matriz H-050 de `AUDITORIA.md`. Alcance: sólo `{user}` (PATCH role, POST toggle-active), `{missingItemRequest}` (PATCH review) y el refactor de `{pendingItem}` al mecanismo común; sin frontend, sin H-052 ni H-020/H-049.

1. Mecanismo (D-033): trait `App\Models\Concerns\RejectsInvalidIntegerRouteBindings`. La guarda sólo aplica cuando el binding apunta a la PK entera del modelo (campo nulo → route key = key name, `getKeyType() === 'int'`): `filter_var(FILTER_VALIDATE_INT, min_range: 0)` rechaza con `null` → `ModelNotFoundException` → `404` dentro de `SubstituteBindings`, antes de la consulta por PK; los válidos se normalizan a `int` y delegan en `resolveRouteBindingQuery`. Bindings con campo explícito y PKs string conservan la resolución por defecto; `resolveSoftDeletableRouteBinding` recibe la misma guarda vía `withoutGlobalScope(SoftDeletingScope::class)` (equivalente al `withTrashed` del framework, expresión tipada para PHPStan). `whereNumber()` descartado: rechaza en el matching de ruta y convertiría el `401` de anónimo en `404`.
2. Aplicación: `User`, `MissingItemRequest` y `PendingItem` usan el trait; la `resolveRouteBinding` duplicada de `PendingItem` se eliminó conservando su matriz (inválido/inexistente → 404, válido → flujo, precedencias intactas). Sin `BaseModel` ni cambio de herencia.
3. Tests: `AdminRouteBindingContractTest` nuevo en SQLite (14 tests) y espejo en grupo `postgres` — segmentos inválidos (`abc`, `12abc`, `1.5`, `-1`, `01`, overflow) → `404` con prueba de no-consulta por `DB::listen`, id numérico inexistente → `404` con la consulta emitida, precedencias `401`/`404`/`403`/`422`, flujos admin con revocación de tokens y auditoría D-007, revisión de faltante con pendiente derivado y log, `409` de segunda revisión y de pedido `done`, cero efectos ante binding inválido y casos límite del filtro (`+id`, espacios, ceros a la izquierda). `BigintRouteBindingCharacterizationTest` (SQLite y `postgres`) quedó sólo con familias pendientes: los casos admin/`{pendingItem}` migraron a contrato 404 y los no corregidos siguen caracterizando el `500`/`22P02` temporal.
4. Gates locales (PHP 8.4.27): `pint --test` verde, PHPStan sin errores ni baseline nuevo (`--memory-limit=1G`), suite SQLite completa 408 tests/1668 assertions (2 skips de benchmark preexistentes), grupo `postgres` completo 52 tests/708 assertions contra PostgreSQL 17.11 local y `composer audit` → 0 advisories. Frontend sin cambios.
5. Deuda restante exacta: 7 bindings implícitos sin protección (`{order}` ×18 rutas, `{pallet}` ×22, `{ticket}` ×6, `{photo}` de tickets ×4, `{base}` ×4, `{customer}` ×1, `{item}` ×1), las resoluciones manuales de `{base}`/`{photo}` en fotos y anotaciones, y el `{id}` int de admin storage (`TypeError`, ambos motores). H-050 sigue **parcial**; el trabajo por familias continúa bajo H-020.

Commits: `6e67373` (trait + tests), `39fb906` (documentación) y el de evidencia CI. Actions run `41970190863` sobre `39fb906` con `ci`, `postgres-concurrency` y `container-security` verdes.

Pendiente: siguientes familias de bindings bigint con el trait (núcleo anidado `{order}`/`{pallet}`/`{ticket}`/`{photo}`, simples `{customer}`/`{item}`/`{base}`), resoluciones manuales `{base}`/`{photo}` (normalización propia en cada punto), `{id}` de admin storage, resto de H-020 y H-049.

## Última sesión cerrada — núcleo order/pallet de bindings bigint (H-050)

Rama `hardening/order-pallet-bigint-bindings`, creada desde `origin/hardening/admin-bigint-route-bindings` (`042fac7`, verificado como HEAD de la base con working tree limpio). Se aplicaron `BE-API`, `BE-ERR`, `BE-DATA`, `BE-ARCH`, `BE-TX`, `SEC-INPUT`, `SEC-AUTHZ`, `SEC-LOG`, `QA-CONTRACT`, `QA-RISK`, `QA-DATA`, `QA-GATE`, `DOMINIO.md`, `BIBLIAS-GOBERNANZA.md`, D-006, D-020, D-026, D-031, D-033 y la matriz H-050 de `AUDITORIA.md`. Alcance: sólo `{order}` (18 rutas) y `{pallet}` (22 rutas, una compartida con `{order}`); sin `{ticket}`/`{photo}`/`{base}`/`{customer}`/`{item}`, sin resoluciones manuales, sin admin storage `{id}`, sin H-052, sin H-020/H-049 y sin frontend.

1. Mecanismo: el trait existente `RejectsInvalidIntegerRouteBindings` (D-033) aplicado a `Order` y `Pallet` — sin copiar `resolveRouteBinding`, sin tocar el trait, controladores ni servicios, sin `whereNumber()` ni scoped bindings. La guarda sólo aplica a la PK entera: segmento no entero ≥ 0 dentro del rango bigint → `null` → `ModelNotFoundException` → `404` en `SubstituteBindings`, antes de la consulta por PK.
2. Inventario automático: `route:list` + test estructural — exactamente 18 rutas con `{order}` y 22 con `{pallet}` (39 únicas, `detach-pallet` tiene ambos); cada acción declara el parámetro con el type-hint `Order`/`Pallet` correspondiente, que es lo que habilita el binding implícito protegido.
3. Tests: `OrderPalletRouteBindingContractTest` nuevo en SQLite (14 tests/550 assertions) y espejo en grupo `postgres` (6 tests/439 assertions). Segmentos inválidos (`abc`, `12abc`, `1.5`, `-1`, `01`, overflow `99999999999999999999`/`9223372036854775808`) → `404` en las 40 rutas con prueba de no-consulta por `DB::listen` (`orders`/`pallets` y, en anidadas, `order_tickets`/`order_ticket_photos`/`pallet_bases`); numérico inexistente → `404` con la consulta emitida; válido → flujo normal; precedencias `401`/`404`/`403`/`422`/`409` (`done` sólo después de resolver el binding); casos límite del filtro (`+id`, espacios aceptados; ceros a la izquierda rechazados). `excepcionDe` en PostgreSQL acredita `ModelNotFoundException` — las clases 22 (`22P02`/`22003`) desaparecieron para esta familia.
4. `BigintRouteBindingCharacterizationTest` (SQLite y `postgres`) quedó sólo con familias vulnerables: los casos `{order}`/`{pallet}` migraron a contrato 404 y las precedencias/deuda se re-expresaron sobre `{customer}`, `{item}`, `{ticket}`, `{photo}`, `{base}` y las resoluciones manuales.
5. Gates locales (PHP 8.4.27): `pint --test` verde, PHPStan sin errores ni baseline nuevo (`--memory-limit=1G`), suite SQLite completa 422 tests/2207 assertions (2 skips de benchmark preexistentes), grupo `postgres` completo 58 tests/1090 assertions contra PostgreSQL local (incluye `FinalizationConcurrencyTest`, `FinalizedEntityImmutabilityTest`, `OrderPalletAssociationTest`, stock/migración y OCR/fotos sin cambios de contrato) y `composer audit` → 0 advisories. Frontend sin cambios.
6. Deuda restante exacta: 5 bindings implícitos sobre bigint sin protección — `{ticket}` (6 rutas), `{photo}` de tickets (4), `{base}` de PalletBaseController (4), `{customer}` (1), `{item}` (1) — las resoluciones manuales de `{base}`/`{photo}` en fotos y anotaciones, y el `{id}` int de admin storage (`TypeError` en ambos motores). H-050 sigue **parcial**; el trabajo por familias continúa bajo H-020.

Commits: `pendiente` (trait + tests), `pendiente` (documentación) y el de evidencia CI. Evidencia CI: pendiente de Actions.

Pendiente: siguientes familias de bindings bigint con el trait (`{ticket}`/`{photo}` de tickets, `{base}` de PalletBaseController, `{customer}`, `{item}`), resoluciones manuales `{base}`/`{photo}` (normalización propia en cada punto), `{id}` de admin storage, resto de H-020 y H-049.

## Próxima sesión

Sin bloque autónomo asignado. Candidatos: siguiente familia de bindings bigint con el trait `RejectsInvalidIntegerRouteBindings` (`{ticket}`/`{photo}` de tickets, `{base}` de PalletBaseController, `{customer}` o `{item}`, según priorización de H-050/H-020), resoluciones manuales `{base}`/`{photo}`, `{id}` de admin storage, perfilado de `GET /api/v1/orders` (hot path del baseline 2026-11-02), `GAP-RETENTION` (requiere decisión de negocio), retiro de los overrides de `D-029` cuando upstream fije rangos parcheados, resto de H-020, H-052 (implementar o retirar las rutas de verificación), H-049 y política H-009.

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
