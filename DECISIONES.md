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
