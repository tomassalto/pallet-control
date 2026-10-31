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

Evidencia funcional: commit `ac4f871`.

Pendiente: resto del inventario de H-020 por familia, H-049 y H-050.

## Próxima sesión

Sin bloque autónomo asignado. Candidatos: `GAP-RETENTION` (requiere decisión de negocio), avisos npm del frontend (`DO-SUPPLY`), H-046 (previa decisión de dominio), siguiente familia de H-020 (con H-050 si se decide su contrato), H-049 y política H-009.

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
