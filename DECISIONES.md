# 🧠 DECISIONES — Pallet Control

> **Qué es esto:** por qué el proyecto es como es. Un ADR (Architecture Decision Record) liviano.
> **Por qué importa:** permite reconstruir el contexto y evita que una sesión futura revierta una decisión pensada.
> **Se agrega:** cada vez que se toma una decisión con trade-offs. Nunca se borra — se marca como superada.

---

## Plantilla D-XXX

**Fecha:** _(fecha)_
**Estado:** ✅ vigente / ⚠️ superada por D-0XX

**Contexto**
_(Cuál era el problema o la situación que obligó a decidir.)_

**Opciones evaluadas**
| Opción | A favor | En contra |
|---|---|---|
| A | | |
| B | | |

**Decisión**
_(Qué se eligió.)_

**Por qué**
_(La razón concreta, atada a este proyecto.)_

**Trade-off aceptado**
_(Qué se resigna a cambio. Toda decisión resigna algo — si no encontrás qué, no la pensaste bien.)_

**Referencia normativa:** `BE-*` / `FE-*` / `DO-*` / `SEC-*` / `QA-*`

---

## Decisiones típicas que van a aparecer acá

```
□ Mecanismo de autenticación (sesión vs Sanctum vs JWT) y por qué
□ Enfoque de audit log (tabla propia vs paquete vs events)
□ "Pendiente" calculado vs almacenado
□ Estrategia de locking (pesimista con lockForUpdate vs optimista con versión)
□ Soft delete vs borrado físico en movimientos
□ Render vs VPS propio
□ Postgres vs MySQL
□ Dónde vive la lógica de negocio (controlador vs service vs action)
```

---

## D-001 — No forzar `protobufjs` dentro de Baileys 6

**Fecha:** 2026-08-24
**Estado:** ⚠️ superada parcialmente por la decisión operativa del 2026-08-24: WhatsApp es legado inactivo

**Contexto**
El audit del WhatsApp bot conserva una cadena crítica en
`Baileys 6.7.24 → libsignal 2.0.1 → protobufjs 6.8.8`. El registry no ofrece una
versión corregida de ese `libsignal`; Baileys 7 reemplaza la cadena, pero es una
versión mayor con cambios incompatibles y todavía se distribuye como release candidate.

**Opciones evaluadas**
| Opción | A favor | En contra |
|---|---|---|
| Override de `protobufjs` 6→7 | Audit potencialmente verde | Fuerza una versión mayor no declarada por libsignal; riesgo criptográfico y de sesión |
| Migrar Baileys 6→7 | Elimina la dependencia vulnerable y usa la línea soportada | Requiere migración, pruebas con sesión real y rollout separado |

**Decisión**
No usar `overrides` ni `npm audit fix --force`. El usuario confirmó que el bot de
WhatsApp fue descartado: queda fuera de runtime, deploy y gates activos. Si alguna vez
se reactiva, antes deberá migrarse a una versión soportada y obtener audit 0.

**Por qué**
`DO-SUPPLY` exige no forzar versiones mayores transitivas y validar cada
ecosistema de forma aislada. La cadena maneja cifrado y estado de WhatsApp, por lo
que una falsa corrección del lockfile es más peligrosa que un bloqueo explícito.

**Trade-off aceptado**
El código legado permanece en el repositorio con avisos conocidos, claramente marcado
como inactivo; a cambio no se desvía la modernización activa hacia un componente descartado.

**Referencia:** `DO-SUPPLY`, `SEC-SUPPLY`

---

## D-002 — Autoridades normativas por propiedad canónica

**Fecha:** 2026-10-29
**Estado:** ✅ vigente

**Contexto**
Las biblias originales mezclaban backend, seguridad, testing, frontend, operación y consejos no normativos. Las referencias por número de sección se volvían frágiles al editar los documentos.

**Opciones evaluadas**

| Opción | A favor | En contra |
|---|---|---|
| Una biblia única | Un solo archivo | Contexto enorme, duplicación y ownership ambiguo |
| Una biblia por tecnología concreta | Búsqueda directa | Fragmentación y reglas repetidas |
| Cinco autoridades por responsabilidad | Contexto enrutable, propiedad estable | Requiere gobierno y referencias cruzadas |

**Decisión**
Mantener cinco autoridades: Backend (`BE-*`), Frontend (`FE-*`), DevOps (`DO-*`), Security (`SEC-*`) y Quality (`QA-*`). `ROUTER.md` combina rutas por tarea y los IDs estables sustituyen referencias por línea o numeración editorial.

`DOMINIO.md` y `PRODUCTO.md` describen Pallet Control sin convertirse en biblias técnicas. La teoría nueva entra únicamente por `GAP-XXX`.

**Por qué**
Separa responsabilidades sin impedir rutas transversales y permite cargar sólo el contexto necesario en cada sesión.

**Trade-off aceptado**
Una tarea puede requerir varias secciones de distintos documentos; a cambio cada regla tiene una única autoridad y evidencia definida.

**Referencia:** `BIBLIAS-GOBERNANZA.md`, `ROUTER.md`

---

## D-003 — Serializar ajustes de stock por `order_item`

**Fecha:** 2026-10-29
**Estado:** ✅ vigente

**Contexto**
`adjust-item` podía asignar más unidades que las pedidas y dejar el cambio aplicado si fallaba su auditoría. La cantidad de un item puede estar distribuida entre bases de distintos pallets, por lo que bloquear sólo la fila pivot actual no protege la invariante global.

**Opciones evaluadas**

| Opción | A favor | En contra |
|---|---|---|
| Lock de la asignación actual | Menor contención | No existe al crear y no coordina otros pallets |
| Lock pesimista de `order_items` | Un punto común por item; implementación directa | Serializa los ajustes concurrentes del mismo producto |
| Versión optimista | Menos espera | Requiere versión, reintentos y cambio de contrato |

**Decisión**
En R-01 se bloquea `order_items` con `lockForUpdate()` y, dentro de la misma transacción, se leen en orden de `base_id` todas sus asignaciones, se valida `SUM(asignado) ≤ pedido`, se escribe y se crea el activity log. Un exceso o una pertenencia inválida devuelven `409`.

R-02 reutiliza ese coordinador para crear y actualizar bases. R-03 agrega recursos de contexto antes del coordinador con un orden único: pallets por ID, bases por ID, `order_items` por ID y pivots por `order_item_id/base_id`. Así, migraciones opuestas bloquean los mismos recursos en el mismo orden y cada conflicto se reevalúa después de esperar.

R-05 reutiliza el mismo coordinador al reducir la cantidad pedida: bloquea el `order_item`, lee y bloquea sus pivots por `base_id`, y rechaza con `409` cualquier nuevo total menor que la suma ya asignada. La actualización y su activity log confirman en la misma transacción.

**Por qué**
La fila de `order_items` existe antes de cualquier asignación y es compartida por todas las bases y pallets. Esto evita el hueco de bloquear filas pivot inexistentes y define un orden reproducible.

**Trade-off aceptado**
Dos ajustes del mismo item se ejecutan en serie. SQLite verifica comportamiento, rollback y presupuesto de queries; la garantía del lock se acredita separadamente en CI mediante dos procesos Laravel contra PostgreSQL.

**Referencia normativa:** `BE-VALID`, `BE-ERR`, `BE-TX`, `SEC-LOG`, `QA-INVARIANT`

---

## D-004 — Excepción temporal para `deepmerge` transitivo de Workbox

**Fecha:** 2026-10-29
**Estado:** ✅ vigente hasta 2027-01-29

**Contexto**
Tras actualizar el lockfile frontend sin saltos mayores, `npm audit` conserva cuatro hallazgos bajos del mismo advisory `GHSA-gcqx-w48m-w2h4`. La dependencia `deepmerge` llega por `vite-plugin-pwa → workbox-build → @rollup/plugin-node-resolve`. npm sólo propone bajar `vite-plugin-pwa` de 1.3.0 a 0.2.1 mediante `--force`.

**Opciones evaluadas**

| Opción | A favor | En contra |
|---|---|---|
| `npm audit fix --force` | Audit numéricamente vacío | Downgrade mayor incompatible y no revisado |
| Excluir todos los dev advisories | Pipeline simple | Oculta hallazgos altos del tooling |
| Bloquear desde moderado y revisar el bajo | Mantiene visibles los bajos y bloquea severidades relevantes | Requiere vencimiento y seguimiento |

**Decisión**
CI ejecuta `npm audit --audit-level=moderate` para frontend. Los cuatro bajos permanecen visibles. No se fuerza el downgrade. Responsable: mantenedor de Pallet Control. Vencimiento: 2027-01-29 o antes si Workbox publica una cadena corregida.

**Por qué**
La cadena afectada se usa al construir el service worker con fuentes controladas del repositorio y no forma parte del JavaScript servido en runtime. Los advisories altos y moderados sí fueron corregidos; backend npm y Composer quedan en cero.

**Trade-off aceptado**
El audit frontend informa cuatro bajos durante la excepción. A cambio se evita introducir una versión PWA incompatible y se siguen bloqueando hallazgos moderados, altos y críticos.

**Referencia normativa:** `DO-SUPPLY`, `SEC-SUPPLY`, `QA-SEC`

---

## D-005 — EAN único por pedido con lock y constraint

**Fecha:** 2026-10-29
**Estado:** ✅ vigente

**Contexto**
`OrderItemController::store` hacía check-then-insert sin coordinación. Dos requests podían crear el mismo EAN en un pedido y duplicar la cantidad pedida.

**Opciones evaluadas**

| Opción | A favor | En contra |
|---|---|---|
| Sólo comprobar en PHP | Mensaje simple | No protege concurrencia ni escritores alternativos |
| Sólo índice único | Integridad durable | El conflicto depende de capturar una excepción de DB |
| Lock del pedido + índice único | Respuesta estable y defensa durable | Serializa las altas dentro del mismo pedido |

**Decisión**
Bloquear la fila `orders` durante el alta, comprobar el EAN y confirmar item + activity log en una transacción. Agregar `UNIQUE(order_id, ean)` como defensa final. La migración hace preflight y falla si hay duplicados históricos; no elimina ni fusiona información automáticamente.

**Por qué**
La fila del pedido existe antes que sus items y coordina todas las altas del mismo agregado. El índice protege además imports, consola o código futuro que no use el servicio.

**Trade-off aceptado**
Las altas de un mismo pedido se ejecutan en serie y un deploy con datos históricos inconsistentes requiere remediación explícita antes de aplicar la migración.

**Referencia normativa:** `BE-VALID`, `BE-DATA`, `BE-TX`, `BE-ERR`, `SEC-LOG`, `QA-INVARIANT`

---

## D-006 — Token opaco para acceso público a pedidos

**Fecha:** 2026-10-29
**Estado:** ✅ vigente

**Contexto y amenaza**
La vista pública exponía cliente, EAN, cantidades, precios, fotos, pallets y OCR mediante el código comercial del pedido, que suele ser numérico y enumerable. Los actores considerados son el destinatario legítimo del enlace, un tercero que recibe el enlace y un atacante anónimo que prueba códigos o genera tráfico. El activo es información operativa compartida: no es pública por defecto, aunque el producto permita entregarla a quien posea el enlace.

**Opciones evaluadas**

| Opción | A favor | En contra |
|---|---|---|
| Sólo rate limit sobre el código | Cambio pequeño | La enumeración sigue siendo viable y distribuible |
| Login obligatorio | Máxima atribución | Rompe la experiencia de compartir con clientes sin cuenta |
| Token opaco + límites por IP/recurso | Conserva el enlace público y evita enumeración práctica | El enlace funciona como credencial y debe protegerse |

**Decisión**
Separar `orders.code` de un `public_token` aleatorio de 48 caracteres, único y oculto en la serialización general. La API autenticada de detalle entrega el token exclusivamente para construir el link y QR. GET público y solicitud de faltantes resuelven sólo por token; el código comercial deja de ser un localizador válido. La lectura limita 60 requests por IP y 20 por combinación IP/recurso por minuto; la escritura limita 20 y 5 respectivamente, con `429` y `Retry-After`.

La migración asigna tokens a pedidos existentes y los nuevos los generan desde el modelo. Los enlaces históricos basados en `code` se invalidan deliberadamente: sostener compatibilidad mantendría H-001. La aplicación debe regenerar y volver a compartir esos enlaces.

**Riesgo residual y operación**
Quien obtenga el enlace puede consultar la vista hasta que el token sea rotado por operación administrativa; no se registra cada lectura para evitar ruido y datos innecesarios. La detección se basa en patrones de rate limiting. Una interfaz de rotación y expiración temporal podrá evaluarse si el piloto demuestra esa necesidad.

**Referencia normativa:** `SEC-THREAT`, `SEC-AUTHZ`, `SEC-DATA`, `SEC-ABUSE`, `BE-API`, `QA-CONTRACT`, `QA-SEC`

---

## D-007 — Ciclo de vida de sesiones Sanctum

**Fecha:** 2026-10-29
**Estado:** ✅ vigente

**Contexto**
La PWA usa tokens bearer de Laravel Sanctum. El token se almacena hoy en `localStorage`, se envía en `Authorization` y se persiste hasheado por Sanctum. El riesgo inmediato era que desactivar una cuenta no revocaba credenciales y que los cambios de rol conservaban el mismo identificador de sesión.

**Decisión**
Mantener Sanctum bearer para el cliente actual, con emisión sólo tras login válido, revocación del token actual en logout y revocación de todos los tokens al desactivar una cuenta o cambiar su rol. Toda ruta autenticada verifica además `is_active`, de modo que una credencial residual no habilita operación. Login inexistente, password incorrecto y cuenta inactiva comparten el mismo `401` genérico y el límite de intentos existente.

Los cambios de estado y privilegio se bloquean por usuario, confirman junto con la revocación y el activity log, y obligan a iniciar una sesión nueva. Telegram conserva una credencial independiente y falla cerrado cuando su secreto no está configurado.

**Trade-off y riesgo residual**
Un cambio administrativo expulsa al usuario de todos sus dispositivos. `localStorage` mantiene exposición ante XSS; migrar a cookies `HttpOnly` requiere una decisión coordinada de dominios, CSRF y despliegue y no se improvisa dentro de R-07.

**Referencia normativa:** `BE-AUTH`, `BE-ERR`, `SEC-IDENTITY`, `SEC-SECRET`, `SEC-LOG`, `QA-SEC`, `QA-CONTRACT`

---

## D-008 — Contrato de errores inesperados de API

**Fecha:** 2026-10-29
**Estado:** ✅ vigente

**Contexto**
La API combinaba respuestas esperadas de dominio con excepciones internas renderizadas por Laravel y varios controladores devolvían mensajes crudos. El frontend consume principalmente `message`; cambiar todos los errores a una envoltura nueva habría roto contratos 401/403/404/409/422 sin aportar valor al fallo interno.

**Decisión**
Conservar los contratos esperados actuales y normalizar en el handler global toda excepción que termine en 5xx bajo `/api/*`. La respuesta contiene sólo `message` genérico y un UUID `error_id`; la misma referencia se agrega al contexto del log y al header `X-Error-Id`. La normalización se aplica incluso con debug habilitado.

Los controladores no capturan una excepción inesperada para convertirla en 422 ni exponen su texto. Las excepciones de dominio diseñadas para usuario mantienen su status y `message`. Telegram muestra un texto recuperable genérico y conserva el detalle únicamente en el log técnico. El endpoint legado de carga del bot queda sin desarrollo funcional, pero tampoco puede filtrar una excepción si recibe tráfico residual.

**Trade-off aceptado**
El contrato común mínimo es `message`; `errors`, campos de conflicto y `error_id` son extensiones según la categoría. No se fuerza una envoltura idéntica para todos los 4xx porque son contratos activos del frontend y requieren una migración separada si se desea cambiarlos.

**Referencia normativa:** `BE-ERR`, `BE-API`, `SEC-LOG`, `QA-CONTRACT`, `QA-SEC`, `FE-DATA`

---

## D-009 — Guard productivo común y runtime no root

**Fecha:** 2026-10-29
**Estado:** ✅ vigente

**Contexto**
El repositorio mantiene dos caminos de despliegue, Docker y Nixpacks. Docker ejecutaba supervisor como root y ninguno de los dos caminos garantizaba que una variable externa no activara debug en producción.

**Decisión**
Centralizar la normalización productiva en `deploy-env.sh`, consumido por ambos caminos. Cuando `APP_ENV=production` o no está definido, el script fuerza `APP_DEBUG=false` antes de cachear configuración o iniciar la aplicación. Docker declara `USER www-data`, asigna sólo los directorios de escritura necesarios y ejecuta web y worker con ese usuario.

El `.env` local del frontend deja de ser un artefacto versionado; `.env.example` documenta exclusivamente el valor público esperado y Git/Docker excluyen los archivos reales. CI construye la imagen y prueba tanto UID no root como el guard de debug.

**Trade-off aceptado**
Una intención de depurar producción se ignora deliberadamente y debe reproducirse en un entorno controlado. La imagen tarda más en CI porque el gate compila el artefacto real; esa evidencia es necesaria para no declarar seguridad sólo por inspección del Dockerfile.

**Referencia normativa:** `DO-ENV`, `DO-CONTAINER`, `DO-RELEASE`, `DO-SUPPLY`, `SEC-SECRET`, `SEC-SUPPLY`, `QA-SEC`, `QA-RESILIENCE`

---

## D-010 — Imagen reproducible y readiness mínimo

**Fecha:** 2026-10-29
**Estado:** ✅ vigente

**Contexto**
La imagen productiva incluía Composer, dependía de tags mutables y sólo exponía `/up`, que acredita proceso vivo pero no capacidad de consultar la base de datos. El contexto de build enviaba tests, documentación y componentes inactivos innecesarios.

**Decisión**
Separar cuatro etapas: frontend, base PHP, resolución de vendor y runtime. Composer existe únicamente en `vendor-build`; runtime recibe `vendor/` ya resuelto. Node 22 Alpine, PHP 8.4 CLI Alpine y Composer 2 conservan tags legibles y quedan fijados además al digest oficial consultado el 2026-10-29.

`/up` permanece como liveness y `/ready` comprueba una consulta mínima a la base. Readiness responde sólo estado y servicio, con 503 genérico ante fallo. El `HEALTHCHECK` de Docker consume `/ready`. `.dockerignore` excluye secretos, VCS, tests, logs, documentación, caches y el bot legado.

CI construye la imagen real y verifica que no contenga Composer, tests ni documentación backend, que el healthcheck apunte a `/ready`, que runtime no sea root y que el guard productivo siga vigente.

**Trade-off aceptado**
Los digests aportan reproducibilidad pero no reciben actualizaciones implícitas. Deben renovarse mediante cambio revisado cuando exista parche de seguridad o actualización planificada, ejecutando nuevamente build, audits y gates. Readiness sólo acredita la dependencia crítica inmediata —base de datos— y no convierte R2, OCR o Telegram en requisitos para servir tráfico general.

**Referencia normativa:** `DO-CONTAINER`, `DO-SUPPLY`, `DO-RELEASE`, `SEC-SUPPLY`, `QA-SEC`, `QA-RESILIENCE`

---

## D-011 — Reset productivo exclusivamente manual

**Fecha:** 2026-10-29
**Estado:** ✅ vigente

**Contexto**
El entrypoint ejecutaba un borrado irreversible cuando `RESET_ON_STARTUP=true`. Una variable olvidada podía repetir el reset en cada deploy y el comando continuaba aunque una tabla no existiera o un `DELETE` fallara.

**Decisión**
Eliminar por completo el reset automático del arranque. El único camino admitido es ejecutar `php artisan app:reset --confirm` desde una sesión de mantenimiento explícitamente autorizada y después de verificar un backup.

El comando hace preflight de todas las tablas esperadas antes de modificar datos, incluye tablas operativas vigentes y ya no referencia `movements`. Los `DELETE` se ejecutan dentro de una transacción y sólo después del commit se eliminan archivos de pallets y pedidos. Productos permanecen fuera del reset por definición funcional.

**Trade-off aceptado**
El reset deja de poder automatizarse con una variable de Render y requiere acceso operativo consciente. Base de datos y object storage no comparten transacción: si falla la limpieza de archivos después del commit, el comando falla y puede dejar objetos huérfanos, pero nunca borra archivos antes de saber que la base confirmó.

**Referencia normativa:** `DO-ENV`, `DO-RELEASE`, `DO-DR`, `SEC-SECRET`, `QA-SEC`, `QA-RESILIENCE`

---

## D-012 — Listados de pendientes paginados por estado

**Fecha:** 2026-10-30
**Estado:** ✅ vigente

**Contexto**
`GET /pending-items` cargaba y serializaba todo el historial, y el frontend descargaba el conjunto completo para separar pendientes y resueltos. El costo crecía con datos que la pantalla no mostraba y los contadores visibles dependían sólo de lo descargado.

**Decisión**
El contrato acepta `status`, `page` y `per_page`; valida el estado, usa 25 filas por defecto y limita cada página a 100. La respuesta estable es `{ data, meta }`, con página actual, última página, tamaño y total. El orden agrega `id` como desempate determinista.

El frontend mantiene paginación independiente para pendientes y resueltos, utiliza los totales del servidor e invalida ambas consultas después de mutaciones. Backend y único consumidor versionado migran juntos; no se conserva temporalmente la respuesta plana porque no existe otro consumidor activo acreditado.

**Trade-off aceptado**
Cambiar de pestaña puede requerir una consulta adicional, a cambio de acotar transferencia, serialización y memoria. La medición SQLite permite comparar el cambio en un entorno controlado, pero no sustituye observabilidad ni un baseline productivo.

**Referencia normativa:** `BE-API`, `BE-PERF`, `FE-DATA`, `QA-CONTRACT`, `QA-PERF`, `DO-OBS`

---

## D-013 — Cobertura explícita de FK y conservación del audit log

**Fecha:** 2026-10-30
**Estado:** ✅ vigente

**Contexto**
Varias claves foráneas carecían de un índice cuyo primer campo fuera la propia FK en PostgreSQL. `activity_logs.pallet_id` tenía índice compuesto, pero no constraint, por lo que podía conservar referencias imposibles.

**Decisión**
Agregar índices nombrados únicamente a las nueve FK sin cobertura existente. No duplicar índices cuando una clave única o compuesta ya comienza por la misma columna.

Antes de agregar `activity_logs.pallet_id → pallets.id`, la migración busca huérfanos y falla sin modificar el esquema si encuentra uno. Al eliminar un pallet, la referencia del log pasa a `null`: la evidencia histórica se conserva y no bloquea el borrado de la entidad operativa.

**Trade-off aceptado**
Cada índice aumenta almacenamiento y costo de escritura; se limita el cambio a FK concretas usadas en joins o acciones referenciales. `nullOnDelete` pierde el enlace navegable al pallet eliminado, pero evita borrar el registro de auditoría. La política general de borrado histórico sigue abierta en H-009.

**Referencia normativa:** `BE-DATA`, `DO-RELEASE`, `QA-DATA`, `QA-RESILIENCE`

---

## D-014 — Constraints por fila equivalentes en PostgreSQL y SQLite

**Fecha:** 2026-10-30
**Estado:** ✅ vigente

**Contexto**
Cantidades y estados dependían de validación HTTP. Escrituras internas o directas podían crear cantidades nulas o negativas, completar más unidades que las pedidas o introducir estados fuera del contrato.

**Decisión**
Proteger en base las siete reglas por fila acreditadas en H-015. PostgreSQL recibe constraints `CHECK` nombrados, agregados primero como `NOT VALID` y validados después del preflight. SQLite, que no permite agregar checks a tablas existentes, usa triggers `BEFORE INSERT/UPDATE` con la misma expresión y nombres estables.

La migración admite sólo los dos motores soportados, aborta ante datos incompatibles antes de crear reglas y su `down()` elimina constraints o triggers. La aplicación conserva validación de entrada y devuelve `409` para el conflicto `done_qty > qty` antes de depender del error de persistencia.

**Trade-off aceptado**
SQLite y PostgreSQL usan mecanismos distintos, pero comparten comportamiento probado. Los triggers SQLite no se presentan como arquitectura productiva. La suma global asignada entre bases queda fuera: H-006 requiere coordinación transaccional o una defensa agregada, no un `CHECK` simple.

**Referencia normativa:** `BE-DATA`, `BE-VALID`, `BE-TX`, `DO-RELEASE`, `QA-DATA`, `QA-INVARIANT`, `QA-RESILIENCE`

---

## D-015 — Revisión única y atómica de solicitudes faltantes

**Fecha:** 2026-10-30
**Estado:** ✅ vigente

**Contexto**
La aprobación actualizaba primero la solicitud y luego creaba un pendiente y su audit log. Un fallo intermedio dejaba una aprobación sin pendiente; dos administradores simultáneos podían crear efectos duplicados.

**Decisión**
Bloquear la fila `missing_item_requests` dentro de una transacción y reevaluar su estado después de adquirir el lock. Sólo `pending_review` puede transicionar a `approved` o `rejected`. Estado, metadatos de revisión, pendiente derivado y audit log confirman o revierten juntos.

Una revisión posterior o concurrente devuelve `409` y no cambia la decisión previa. No se reintenta automáticamente una operación que ya pudo esperar por el lock.

**Trade-off aceptado**
La revisión queda serializada por solicitud, no globalmente. Un administrador que intenta cambiar una decisión ya confirmada debe usar un flujo futuro explícito de corrección; reutilizar el endpoint de revisión ocultaría historia y podría duplicar pendientes.

**Referencia normativa:** `BE-TX`, `BE-VALID`, `BE-ERR`, `SEC-LOG`, `QA-INVARIANT`, `QA-RESILIENCE`

---

## D-016 — Asociación pedido/pallet única y atómica

**Fecha:** 2026-10-30
**Estado:** ✅ vigente

**Contexto**
Dos endpoints asociaban la misma relación con implementaciones distintas. `syncWithoutDetaching` ocultaba duplicados, pero cada repetición emitía un nuevo audit log y Telegram. La creación por código tampoco confirmaba junto a pivot y auditoría.

**Decisión**
Centralizar ambos recorridos en un servicio. Toda operación bloquea primero el pallet y luego el pedido, comprueba la fila pivot, inserta la relación y registra auditoría dentro de una transacción. Una asociación existente devuelve `409` sin efectos secundarios.

Cuando el recorrido por código crea un pedido, creación, log de alta, pivot y log de asociación confirman juntos. Telegram se envía únicamente después de que la transacción termina y nunca mientras se mantienen locks.

**Trade-off aceptado**
Repetir la misma asociación deja de ser un éxito idempotente silencioso y pasa a ser conflicto explícito, evitando evidencia duplicada. La notificación Telegram es best-effort posterior al commit: su fallo no revierte una asociación durable ni mantiene abierta la transacción.

**Referencia normativa:** `BE-ARCH`, `BE-TX`, `BE-VALID`, `BE-ERR`, `SEC-LOG`, `QA-INVARIANT`, `QA-RESILIENCE`

---

## D-017 — Subidas de fotos con compensación explícita

**Fecha:** 2026-10-30
**Estado:** ✅ vigente

**Contexto**
Las subidas escribían primero el object storage y luego la fila de foto y el audit log sin una unidad de confirmación. Una falla de base dejaba archivos huérfanos; el alta de tickets por bot además creaba el ticket antes de convertir la imagen. El driver de storage puede devolver `false` sin lanzar una excepción.

**Decisión**
Guardar y convertir el objeto antes de abrir la transacción de base. Después, confirmar fila de negocio y auditoría en una única transacción. Ante cualquier excepción de esa transacción, intentar eliminar el objeto recién creado y relanzar el error original. Si la compensación falla y el objeto continúa presente, registrar un evento crítico con disco y ruta, sin filtrar esa información al cliente.

Aplicar el mismo servicio a los endpoints web y al servicio activo usado por Telegram. Para tickets creados por el bot, usar una ruta que no dependa del identificador del ticket y crear ticket, foto y auditoría juntos después de almacenar el objeto. Tratar un `put()` con resultado `false` como fallo de almacenamiento.

**Trade-off aceptado**
La compensación no convierte DB y object storage en una transacción distribuida: una caída durante el cleanup aún puede dejar un objeto huérfano, que queda señalado críticamente para reconciliación. El patrón sirve para altas; los borrados requieren intención durable y reintento, y se resuelven por separado.

**Referencia normativa:** `BE-ARCH`, `BE-TX`, `BE-ERR`, `SEC-FILE`, `SEC-LOG`, `DO-INFRA`, `QA-INVARIANT`, `QA-RESILIENCE`

---

## D-018 — Borrado de archivos mediante intención durable (outbox)

**Fecha:** 2026-10-30
**Estado:** ✅ vigente

**Contexto**
Los borrados de fotos de pallet, base y ticket, y de tickets completos, eliminaban primero el objeto y después la fila y el audit log, sin transacción. Un fallo de base dejaba filas apuntando a archivos inexistentes o borrados sin auditoría; un fallo de storage —`delete()` puede devolver `false` o lanzar— dejaba objetos huérfanos sin rastro. Borrar un pallet o una base eliminaba las fotos por cascada sin limpiar sus objetos. DB y object storage no comparten transacción.

La cola de producción usa el driver `database` con un worker supervisado; los tests usan `sync`. Un job despachado entre el commit y el `dispatch` se pierde si el proceso cae, y no existe scheduler activo en el runtime: `schedule:work` no corre y activarlo encendería además `pallet:archive`, cuya política de retención depende de H-009.

**Opciones evaluadas**

| Opción | A favor | En contra |
|---|---|---|
| Borrar objeto dentro de la transacción | Simple | Llamada remota con transacción abierta; si la DB revierte, el archivo ya no existe |
| Job after-commit sin registro | Sin tabla nueva | Un crash entre commit y dispatch pierde la intención; sin estado visible |
| Intención en tabla propia + job after-commit + barrido | Durable, visible, reintentable e idempotente | Tabla y proceso adicionales; limpieza eventual, no inmediata |

**Decisión**
La base de datos es la fuente autoritativa. Cada borrado bloquea y relee las filas afectadas, elimina la entidad, crea el audit log y registra una intención por objeto en `pending_file_deletions` (disco y ruta, únicos) dentro de la misma transacción. Si cualquiera falla, no queda entidad borrada, auditoría ni intención.

Después del commit se despacha `DeleteStoredFile` con el identificador de la intención. El borrado físico se considera satisfecho si `delete()` confirma o si el objeto ya no existe. Un `false` con objeto presente o una excepción incrementa intentos, registra la clase del error, programa backoff acotado y deja la intención pendiente. Reintentar una intención completada no tiene efecto.

`storage:retry-pending-deletions` procesa intenciones vencidas sin depender de la cola y recupera trabajos perdidos por crash. Corre como programa supervisado propio cada diez minutos, sin activar el scheduler. Tras diez intentos la intención queda agotada: se excluye del barrido automático, se alerta como crítica y sólo se reprocesa con `--include-exhausted` después de diagnosticar el storage.

El mismo mecanismo cubre borrado de pallet y de base, cuyas fotos se eliminaban por cascada sin limpiar objetos. `pallet:archive` y el reset manual de D-011 quedan fuera de este cambio.

**Trade-off aceptado**
El borrado físico es eventual: durante una caída de R2 el objeto sigue existiendo, inaccesible desde la aplicación porque ninguna fila operativa lo referencia, hasta que un reintento confirme su eliminación. El usuario recibe éxito cuando la base confirmó. Las intenciones completadas se conservan como evidencia; su depuración requiere una política de retención posterior. Nixpacks no ejecuta worker ni barrido, por lo que en ese camino la limpieza queda pendiente hasta resolver H-038.

**Referencia normativa:** `BE-TX`, `BE-ASYNC`, `BE-ERR`, `BE-DATA`, `SEC-FILE`, `SEC-LOG`, `DO-INFRA`, `QA-INVARIANT`, `QA-RESILIENCE`

---

## D-019 — Operabilidad de colas y limpieza de archivos

**Fecha:** 2026-10-30
**Estado:** ✅ vigente

**Contexto**
D-018 dejó intenciones durables de borrado, un job y un barrido supervisado, pero la operación no era verificable: el worker productivo corre con `--timeout=120` mientras la conexión `database` reserva trabajos por `retry_after=90`, de modo que un OCR largo podía ejecutarse dos veces. `ProcessTicketOcr` no declaraba intentos, backoff ni timeout. El job de borrado relanzaba la excepción original, cuyo texto termina en `failed_jobs`. Una intención agotada no dejaba auditoría y no existía un conteo operativo ni un criterio de antigüedad para detectar worker o barrido detenidos.

**Opciones evaluadas**

| Opción | A favor | En contra |
|---|---|---|
| Hacer fallar `/ready` si hay pendientes viejos | Señal automática de plataforma | Un R2 caído o un barrido detenido dejaría sin servicio a toda la aplicación |
| Job único con lock de cache | Evita duplicados en cola | Depende del cache store y no reemplaza la idempotencia ya existente |
| Comando de estado con umbral + auditoría de fallos terminales | Señal accionable, no afecta servicio, verificable en tests | Requiere un chequeo externo que lo invoque |

**Decisión**
- `retry_after` de la conexión `database` pasa a 150 segundos por defecto, mayor que el timeout del worker (120) y de cada job. Un test protege esa relación leyendo `supervisord.conf`.
- `ProcessTicketOcr` declara explícitamente el comportamiento efectivo vigente: dos intentos, backoff de 30 segundos y timeout de 110; su fallo terminal se registra sin mensaje interno.
- El borrado de objetos relanza una excepción saneada con la clase original y el identificador de la intención, sin mensaje ni excepción encadenada.
- Cuando una intención alcanza el máximo de intentos, el cambio de estado y un activity log `file_deletion_exhausted` confirman juntos. Reprocesar agotadas con `--include-exhausted` deja un activity log `file_deletion_exhausted_retried`.
- `storage:cleanup-status` informa pendientes, antigüedad del más viejo, agotadas, completadas en 24 horas y jobs fallidos de borrado. Sale con error si existe alguna agotada o si el pendiente más antiguo supera `STORAGE_CLEANUP_ALERT_MINUTES` (60 por defecto). El barrido emite un log crítico con el mismo criterio.
- Se reutiliza `storage:retry-pending-deletions` como comando de reconciliación; no se crea un segundo comando equivalente. Duplicados en cola se resuelven por idempotencia, sin `ShouldBeUnique`.
- `/ready` conserva el alcance de D-010: la limpieza es eventual y no condiciona servir tráfico.

**Recuperación operativa**
1. Ejecutar `php artisan storage:cleanup-status`.
2. Si hay pendientes viejos, verificar que los programas `worker` y `file-cleanup` estén vivos y ejecutar `php artisan storage:retry-pending-deletions`.
3. Si hay agotadas, diagnosticar el storage y luego ejecutar `php artisan storage:retry-pending-deletions --include-exhausted`.
4. Los jobs de borrado en `failed_jobs` pueden reintentarse con `php artisan queue:retry` o descartarse: la intención en base sigue siendo la fuente autoritativa.
5. Después de un crash no hace falta acción manual: el barrido retoma intenciones cuyo job nunca se encoló o cuyo resultado no llegó a registrarse.

**Trade-off aceptado**
La alerta existe como comando y log crítico; conectarla a un monitor externo depende de la plataforma y queda pendiente con H-029/H-038. Un worker caído no vuelve no-ready la aplicación. El OCR conserva el reintento ya vigente y su costo potencial en el proveedor.

**Referencia normativa:** `BE-ASYNC`, `BE-ERR`, `DO-CONTAINER`, `DO-RELEASE`, `DO-OBS`, `DO-DR`, `SEC-LOG`, `QA-RESILIENCE`

---

## D-020 — Invariante agregada asignado ≤ pedido en base de datos

**Fecha:** 2026-10-30
**Estado:** ✅ vigente

**Contexto**
D-003 protege la invariante en los recorridos R-01 a R-05 mediante el lock del `order_item`, y D-014 dejó fuera la suma entre filas por no ser un `CHECK` por fila. El inventario de escritores de `pallet_base_order_items` y de `order_items.qty` encontró dos recorridos fuera de esa regla: el import de pedidos reescribe `qty` sin locks ni comparación con lo asignado, y la desvinculación de pallet borra asignaciones, pivot y auditoría sin transacción, de modo que una asignación concurrente puede quedar huérfana. Cualquier escritor directo o futuro también puede violar la invariante.

**Opciones evaluadas**

| Opción | A favor | En contra |
|---|---|---|
| Sólo validación en cada servicio | Sin cambios de esquema | Depende de que cada escritor futuro recuerde la regla |
| Vista materializada o columna de total | Lectura rápida | Duplica estado y exige mantenerlo consistente |
| Trigger que bloquea el `order_item` y recalcula la suma | Defensa única para todo escritor; serializa por ítem; reutiliza el lock que los servicios ya toman | Lógica en base y en dos dialectos |

**Decisión**
- **Orden global de locks:** `pallets` → `bases` → `order_items` (por id) → `orders` (por id) → asignaciones (por `order_item_id`, `base_id`). Los recorridos existentes ya son subsecuencias de este orden.
- **Trigger en PostgreSQL:** después de insertar o actualizar una asignación, y después de actualizar `order_items.qty`, un trigger bloquea la fila del `order_item` con `FOR UPDATE`, recalcula la suma y rechaza con `SQLSTATE 23514` si la suma supera la cantidad pedida. Los recorridos de aplicación ya poseen ese lock, por lo que el trigger no agrega esperas ni ciclos. Para escritores directos, el lock del ítem serializa la comprobación y evita que dos transacciones lean sumas obsoletas.
- **Triggers en SQLite:** `AFTER INSERT/UPDATE` con `RAISE(ABORT)` aplican la misma expresión. SQLite serializa escrituras y no acredita concurrencia.
- **Trigger inmediato, no diferido:** ningún recorrido sobreasigna transitoriamente. Migrar debita el origen antes de acreditar el destino, y una base tiene una sola fila por ítem.
- **Migración:** un preflight aborta antes de crear los triggers si existen ítems sobreasignados. No corrige datos automáticamente.
- **Import de pedidos:** bloquea los ítems existentes del pedido por id y luego el pedido, compara cada nueva cantidad con lo asignado y responde `409` con `conflicts` (`ean`, `assigned`, `requested`) sin aplicar ningún cambio. Ítems, productos y un activity log `order_imported` confirman en una transacción.
- **Desvincular un pallet:** bloquea pallet, bases, ítems del pedido y pedido en ese orden. Asignaciones, pivot y auditoría confirman juntos, y Telegram se envía después del commit.
- **Respuesta ante el trigger:** si el trigger rechaza una escritura que pasó la validación de aplicación, el error es interno (`5xx`), porque revela un escritor que no respeta la regla.

**Trade-off aceptado**
Las escrituras de asignaciones ejecutan una suma por fila afectada. El import se vuelve todo-o-nada ante conflictos. Un ítem nuevo creado por R-04 durante un import puede quedar fuera del primer lock; ese caso extremo puede terminar en un deadlock detectado por PostgreSQL y en un `5xx` reintentable, nunca en sobreasignación. El import conserva la eliminación de ítems ausentes con sus asignaciones; la política de conservación sigue abierta en H-009.

**Corrección tras CI (misma rama)**
PostgreSQL toma `FOR KEY SHARE` sobre la fila padre al insertar o actualizar una fila con FK. Un recorrido que primero bloqueaba el `order_item` y después insertaba asignaciones o activity logs esperaba implícitamente `pallets`/`pallet_bases`/`orders`. Eso invertía el orden global frente a quien bloquea esos padres `FOR UPDATE`, y produjo un deadlock intermitente entre desvinculación y adjust-item. La regla se completa así: todo recorrido adquiere `FOR SHARE` sobre los padres que sus FKs referencian antes del primer lock posterior en el orden. Se aplica en adjust-item, crear, actualizar y eliminar base, actualizar ítem y agregar ítem. El costo es una o dos consultas de lock por operación.

**Referencia normativa:** `BE-TX`, `BE-DATA`, `BE-VALID`, `BE-ERR`, `SEC-LOG`, `DO-RELEASE`, `QA-INVARIANT`, `QA-DATA`, `DOMINIO.md`

---

## D-021 — Atribución de creación de pallets y diagnóstico del índice de auditoría

**Fecha:** 2026-10-30
**Estado:** ✅ vigente

**Contexto**
`pallets.created_by` existe desde la creación de la tabla con la intención declarada de asociarlo a un usuario, pero toda alta lo escribe en `null`, no tiene FK ni índice y nadie lo lee (H-014). `DOMINIO.md` exige que una acción significativa sea atribuible, y el modelo multiempresa sigue sin decidir (`GAP-TENANCY`). H-016 reportaba un índice duplicado en `activity_logs.order_id`. La inspección de SQLite y PostgreSQL 17 muestra un único índice `activity_logs_order_id_index`: la segunda migración usa `CREATE INDEX IF NOT EXISTS` con el mismo nombre y no crea nada, pero su `down()` elimina el índice que pertenece a la primera.

**Opciones evaluadas**

| Opción | A favor | En contra |
|---|---|---|
| Eliminar `created_by` | Esquema mínimo | Descarta una intención de atribución vigente y obliga a recrearla |
| Conservar sin cambios | Sin riesgo de migración | Columna engañosa y siempre vacía |
| Poblar con el usuario autenticado + FK `nullOnDelete` + índice | Atribución durable y consultable | No recupera historia previa |

**Decisión**
Las altas de pallet registran el usuario autenticado en `created_by`. Esto incluye el destino creado al migrar una base; las altas sin usuario quedan en `null`. Una migración agrega FK a `users` con `nullOnDelete` e índice, con preflight que aborta si existe una referencia a un usuario inexistente. No hay backfill: los pallets históricos conservan `null`. `created_by` es atribución, no autorización ni propiedad; ninguna regla de acceso lo consulta hasta resolver `GAP-TENANCY`.

H-016 se cierra sin migración, porque no existe índice duplicado en ningún motor. La asimetría del `down()` histórico no se corrige editando una migración aplicada: un rollback aislado de `2026_04_21_000001` deja `activity_logs.order_id` sin índice y debe evitarse o seguirse de un `migrate`. Un test fija que exista exactamente un índice cuyo primer campo sea `order_id`.

**Trade-off aceptado**
La atribución empieza con esta release. El rollback histórico conserva su riesgo documentado en lugar de reescribir una migración aplicada.

**Referencia normativa:** `BE-DATA`, `DO-RELEASE`, `SEC-LOG`, `QA-DATA`, `QA-RESILIENCE`, `DOMINIO.md`

---

## D-022 — Contrato único de validación para subidas de fotos

**Fecha:** 2026-10-30
**Estado:** ✅ vigente

**Contexto**
Las subidas web de fotos de pallet, de base y de ticket validaban inline con reglas distintas. Pallet usaba `image`, que admite jpg, png, gif, bmp y webp. Base y ticket admitían jpeg, png y webp. Base además buscaba el primer archivo de cualquier campo cuando faltaba `photo`. Ese fallback nunca permitía una subida exitosa, porque luego se exigía `photo`, pero producía mensajes y campos de error propios (`error_code`). El frontend envía siempre `photo` y consume `message`.

**Opciones evaluadas**

| Opción | A favor | En contra |
|---|---|---|
| Form Request por endpoint conservando diferencias | Sin cambio de contrato | Tres fuentes de verdad para el mismo riesgo |
| Form Request común con allowlist amplia (`image`) | Ningún formato rechazado nuevo | Amplía base y ticket a gif/bmp sin necesidad |
| Form Request común con jpeg/png/webp y 20 MB | Una allowlist acotada y verificada por contenido | Pallet deja de aceptar gif y bmp |

**Decisión**
Una clase base de Form Request define una vez el contrato de `photo`: requerido, archivo subido correctamente, contenido jpeg/png/webp detectado por Laravel desde el contenido y máximo 20 MB. `note` es opcional, de hasta 1000 caracteres. Pallet la usa como Form Request, porque su recurso se resuelve por route-model binding. Base y ticket comprueban pertenencia dentro del controlador y hoy responden `404` antes de validar; para conservar ese orden aplican las mismas reglas y mensajes estáticos de la clase después de esa comprobación. Los mensajes están en español y son iguales para los tres recursos.

Los errores siguen el contrato estándar `422` con `message` y `errors.photo`. Desaparece el fallback de base y su `error_code`; antes del cambio, tests de caracterización acreditan que ese fallback nunca producía un alta. La autorización, el contrato de éxito `201`, el procesamiento en `PhotoPersistenceService` y la re-codificación a WebP no cambian. Telegram y el endpoint legado del bot quedan fuera.

**Trade-off aceptado**
Una foto de pallet en gif o bmp pasa a recibir `422`. El mensaje de error de base cambia de texto aunque conserva status y campo `message`.

**Referencia normativa:** `BE-API`, `BE-VALID`, `BE-ERR`, `SEC-FILE`, `SEC-INPUT`, `QA-CONTRACT`

---

## D-023 — Runner de tests frontend

**Fecha:** 2026-10-30
**Estado:** ✅ vigente

**Contexto**
`FE-GATE` exige ejecutar las pruebas definidas por Quality, pero el frontend no tenía runner. La única verificación automatizada era el build dentro del job backend; CI no ejecutaba ESLint frontend. `BIBLIAS-GOBERNANZA.md` deja la herramienta concreta a una decisión del proyecto. `QA-LEVELS`, `QA-CONTRACT`, `QA-DATA` y `QA-FLAKY` definen niveles, aislamiento de red, determinismo y contratos; `QUALITY-BIBLIA.md` cita los principios de Testing Library como fuente oficial. No existe hueco teórico.

**Opciones evaluadas**

| Opción | A favor | En contra |
|---|---|---|
| Jest + transformador | Ecosistema amplio | Segunda cadena de transformación distinta de Vite; configuración ESM adicional |
| Vitest + jsdom + Testing Library | Reutiliza la configuración de Vite, ESM nativo, API compatible con Jest, consultas orientadas al usuario | Dependencias de desarrollo nuevas |
| Sólo E2E con navegador | Prueba recorridos completos | Lento y contrario a `QA-LEVELS` para contratos de componente |

**Decisión**
Adoptar Vitest con entorno jsdom y `@testing-library/react`, más `@testing-library/user-event` para interacción. Las versiones son estables, compatibles con Vite 7 y React 19, publicadas hace al menos siete días y fijadas por lockfile. No se agregan librerías de mock de red: los tests reemplazan `fetch` en el límite del cliente API. Un setup común limpia DOM, `localStorage`, mocks y temporizadores entre tests. Cada test crea su propio `QueryClient` sin reintentos.

`npm test` ejecuta la suite una vez, sin modo watch. CI ejecuta ESLint, tests y build del frontend en el job `ci`. El audit frontend conserva el umbral moderado y las excepciones de `D-004`; cualquier hallazgo nuevo introducido por estas dependencias se registra, no se oculta.

**Trade-off aceptado**
jsdom no reproduce layout, cámara ni service worker; esos riesgos siguen en revisión manual y en E2E futuros. El job `ci` tarda más por el paso frontend.

**Referencia normativa:** `FE-GATE`, `FE-DATA`, `FE-STATE`, `QA-LEVELS`, `QA-CONTRACT`, `QA-DATA`, `QA-FLAKY`, `QA-GATE`, `DO-CI`, `DO-SUPPLY`, `SEC-SUPPLY`

---

## D-024 — Correcciones acotadas reveladas por los flujos críticos frontend

**Fecha:** 2026-10-30
**Estado:** ✅ vigente

**Contexto**
Al caracterizar los recorridos críticos del frontend aparecieron tres desvíos frente a `FE-DATA` y `QA-RESILIENCE`:
- El polling del OCR consulta cada dos segundos sin límite y silencia todo error. Un job que falla definitivamente sólo deja log en el backend (D-019), así que la pantalla queda escaneando para siempre.
- Logout y una respuesta `401` eliminan el token pero conservan la cache de React Query con datos privados de la sesión anterior.
- `MyClients.jsx` declara callbacks `onError` que React Query v5 ya no ejecuta.

**Decisión**
- El polling OCR termina al confirmarse el resultado, al superar cinco minutos desde el inicio (más que dos intentos de 110 segundos con backoff de 30) o tras cinco errores consecutivos de consulta. En ambos cortes muestra un mensaje recuperable y permite cerrar. No se cambia el contrato backend.
- Logout y `401` cancelan y limpian la cache de queries mediante un callback registrado en el cliente API, igual que la navegación ya existente.
- `MyClients.jsx` emite sus toasts de error desde efectos sobre el estado de la query.
- No hay rediseño visual: los mensajes reutilizan componentes y estilos existentes.

**Trade-off aceptado**
Un OCR legítimo que tarde más de cinco minutos se muestra como demorado aunque luego termine. El resultado sigue disponible al reabrir la foto. Limpiar la cache obliga a recargar datos tras volver a iniciar sesión.

**Referencia normativa:** `FE-DATA`, `FE-STATE`, `FE-SESSION`, `BE-ASYNC`, `QA-RESILIENCE`, `QA-CONTRACT`, `QA-LEVELS`

---

## D-025 — Conflictos de estado con `409`

**Fecha:** 2026-10-30
**Estado:** ✅ vigente

**Contexto**
El inventario de respuestas `4xx` de los controladores encontró conflictos de estado expresados con otros códigos:
- finalizar y reabrir un pallet devuelven `400` (H-027);
- finalizar un pedido devuelve `422` (H-027);
- ajustar un ítem en un pallet finalizado devuelve `422` (resto de H-023);
- repetir una solicitud de faltante dentro de la ventana anti-duplicado devuelve `422`.

Los servicios transaccionales ya usan `409`. El frontend no ramifica por esos status: consume `message` y, donde existen, los campos de detalle.

**Decisión**
Esos cinco casos devuelven `409` y conservan el cuerpo actual (`message` y, en finalizar pallet, los detalles de `canFinalize`). Siguen en `422` las entradas semánticamente inválidas: un ítem de otro pedido en el cuerpo, una cantidad faltante mayor a la pedida, un import que no se puede interpretar o la ausencia de `ean`/`last4`. No hay reemplazo global: cada cambio tiene un test de status y forma, y el frontend se verifica mostrando el `message` del `409`.

**Trade-off aceptado**
Un consumidor externo que ramifique por `400`/`422` en esos endpoints debe adaptarse; no existe ninguno conocido además de la PWA. Finalizar y reabrir siguen sin lock ni transacción: el código de estado se corrige, pero la carrera entre dos finalizaciones queda para una rebanada transaccional propia.

**Referencia normativa:** `BE-API`, `BE-ERR`, `BE-VALID`, `FE-DATA`, `QA-CONTRACT`, `SEC-LOG`
