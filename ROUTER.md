# Router normativo de Pallet Control

**Entrada obligatoria antes de planear o modificar código.**
**Última revisión:** 2026-10-29

Este archivo selecciona contexto; no contiene teoría. La propiedad, precedencia y protocolo de cambio están en `BIBLIAS-GOBERNANZA.md`.

## Protocolo

1. Clasificar la tarea con las tablas.
2. Combinar todas las rutas que apliquen.
3. Leer las secciones por ID estable, no por número de línea.
4. Abrir `DOMINIO.md` o `PRODUCTO.md` si cambia comportamiento.
5. Consultar la rebanada de `PLAN.md`, los H-XXX de `AUDITORIA.md` y D-XXX vigentes.
6. Definir riesgos, implementación, pruebas y gates desde esas fuentes.
7. Si falta teoría, detenerse y abrir `GAP-XXX`.
8. Cerrar con gates y actualización documental.

## Autoridades

| Documento | Autoridad |
|---|---|
| `BACKEND-BIBLIA.md` | Servicios, API, persistencia y ejecución backend (`BE-*`) |
| `FRONTEND-BIBLIA.md` | Arquitectura y experiencia del cliente web (`FE-*`) |
| `DEVOPS-BIBLIA.md` | Entrega, infraestructura y operación (`DO-*`) |
| `SECURITY-BIBLIA.md` | Seguridad transversal (`SEC-*`) |
| `QUALITY-BIBLIA.md` | Verificación y evidencia (`QA-*`) |
| `DOMINIO.md` | Vocabulario e invariantes de Pallet Control |
| `PRODUCTO.md` | Usuario, objetivos y límites funcionales |
| `DECISIONES.md` | Adaptaciones vigentes con trade-offs |
| `PLAN.md` | Prioridad y estado actual |
| `AUDITORIA.md` | Evidencia y hallazgos del código/sistema |
| `EJECUCION.md` | Orden operativo de sesiones |

## Backend

| Tarea o señal | Teoría obligatoria | Gate mínimo |
|---|---|---|
| Capas, controlador, servicio, dependencia | `BE-ARCH`, `QA-RISK`, `QA-STATIC` | responsabilidades + tests de capa |
| Feature o nuevo límite de confianza | `SEC-THREAT`, `QA-RISK`, autoridad técnica aplicable | threat cases y riesgos trazados |
| Login, Sanctum, tokens, sesión | `BE-AUTH`, `SEC-IDENTITY`, `QA-SEC` | 401, expiración/revocación y abuso |
| Roles, policies, ownership | `SEC-AUTHZ`, `BE-API`, `QA-SEC` | 403 y acceso cruzado |
| Form Request, DTO, invariante | `BE-VALID`, `SEC-INPUT`, `QA-INVARIANT` | feliz, inválido, límite y conflicto |
| Excepciones, logging, respuesta fallida | `BE-ERR`, `SEC-LOG`, `QA-CONTRACT` | status, forma, redacción y correlación |
| Rutas, recursos, filtros, paginación | `BE-API`, `SEC-AUTHZ`, `QA-CONTRACT` | límites y compatibilidad |
| Modelos, índices, migraciones | `BE-DATA`, `DO-RELEASE`, `QA-DATA` | migración y recuperación |
| Transacciones, locks, stock | `BE-TX`, `BE-VALID`, `QA-INVARIANT`, `DOMINIO.md` | atomicidad y concurrencia PostgreSQL |
| Colas, OCR, Telegram, webhook | `BE-ASYNC`, `SEC-SECRET`, `SEC-ABUSE`, `QA-RESILIENCE` | retry, idempotencia y fallo |
| N+1, cache, latencia | `BE-PERF`, `QA-PERF`, `DO-OBS` | baseline antes/después |
| Tipo/nivel de test o caracterización | `QA-LEVELS`, `QA-RISK` | nivel que prueba el límite real |
| Test intermitente o timeout | `QA-FLAKY`, `QA-DATA` | causa eliminada y repetición estable |
| Cierre backend | `BE-GATE`, `QA-GATE`, `SEC-GATE` según riesgo | Pint, PHPStan, tests, audit y build |

## Frontend

| Tarea o señal | Teoría obligatoria | Gate mínimo |
|---|---|---|
| Componentes, features, hooks | `FE-ARCH`, `FE-STATE`, `QA-STATIC` | lint, build y límites claros |
| React Query, carga, cache, errores | `FE-DATA`, `BE-API`, `BE-ERR`, `QA-CONTRACT` | loading/refresh/vacío/error/usuario |
| Escrituras, UI optimista, 409 | `FE-MUTATION`, `BE-TX`, `QA-INVARIANT` | doble envío, rollback y conflicto |
| Formularios | `FE-FORM`, `BE-VALID`, `SEC-INPUT` | teclado, 422/409 y persistencia de entrada |
| Rutas, login, permisos visuales | `FE-SESSION`, `SEC-IDENTITY`, `SEC-AUTHZ` | anónimo, expiración, logout y 403 |
| Accesibilidad | `FE-A11Y`, `QA-A11Y` | automático + teclado + revisión manual |
| Responsive, scanner, canvas, imágenes | `FE-RESPONSIVE`, `SEC-FILE`, `QA-COMPAT` | matriz y fallos de capacidad |
| Bundle, render, listas, carga | `FE-PERF`, `QA-PERF` | presupuesto y medición |
| PWA/service worker/cache | `FE-PWA`, `SEC-BROWSER`, `SEC-DATA`, `DO-EDGE` | instalación, update, offline shell y logout |
| Sistema visual/feedback | `FE-UI`, `FE-A11Y`, `PRODUCTO.md` | estados coherentes y contraste |
| Cierre frontend | `FE-GATE`, `QA-GATE`, `SEC-GATE` según riesgo | lint, build, tests y revisión UI |

## DevOps y operación

| Tarea o señal | Teoría obligatoria | Gate mínimo |
|---|---|---|
| `.env`, configuración, secretos | `DO-ENV`, `SEC-SECRET` | secret scan, validación y rotación |
| Dockerfile, imagen, runtime | `DO-CONTAINER`, `DO-SUPPLY`, `SEC-SUPPLY` | build, usuario, scan y persistencia |
| CI, Actions, branch protection | `DO-CI`, `DO-SUPPLY`, `QA-GATE` | pipeline remoto y bloqueo probado |
| Dependencias/CVE | `DO-SUPPLY`, `SEC-SUPPLY`, `QA-SEC` | dry-run, cambio mínimo y todos los gates |
| Render, VPS, storage, capacidad | `DO-INFRA`, `SEC-DATA`, `DO-DR` | inventario y persistencia probada |
| Proxy, TLS, headers, CORS | `DO-EDGE`, `SEC-BROWSER` | TLS, headers, origen y cache |
| Deploy, health, migración, rollback | `DO-RELEASE`, `BE-DATA`, `QA-RESILIENCE` | readiness y recuperación ensayada |
| Logs, métricas, alertas | `DO-OBS`, `SEC-LOG`, `QA-PERF` | correlación y alerta probada |
| Backup y restauración | `DO-DR`, `SEC-DATA`, `QA-RESILIENCE` | restauración con RPO/RTO observados |
| Cierre DevOps | `DO-GATE`, `QA-GATE`, `SEC-GATE` | evidencia automatizada y operativa |

## Rutas combinadas del producto

| Área | Recorrido |
|---|---|
| Stock y organización | `DOMINIO.md` → `BE-VALID` → `BE-TX` → `SEC-AUTHZ`/`SEC-LOG` → `QA-INVARIANT` |
| Frontend que consume API | `FE-DATA`/`FE-MUTATION` → `BE-API`/`BE-ERR` → `QA-CONTRACT` → `SEC-AUTHZ` |
| Telegram | `BE-ASYNC` → `SEC-SECRET`/`SEC-ABUSE` → `DO-OBS` → `QA-RESILIENCE` |
| Fotos, OCR y anotaciones | `SEC-FILE` → `BE-ASYNC` → `FE-RESPONSIVE` → `DO-INFRA` → `QA-RESILIENCE` |
| Componente legado/inactivo | confirmar exclusión de build/deploy/runtime; aplicar `DO-SUPPLY` si se reactiva |
| Offline/escritorio/mobile/multiempresa | detenerse: `GAP-OFFLINE`, `GAP-DESKTOP`, `GAP-MOBILE` o `GAP-TENANCY` |
| Retención, archivado o purga de datos de negocio | detenerse: `GAP-RETENTION`; sólo inventario de sólo lectura (D-028) |

## Ejemplos de recorrido

### Pipeline
`ROUTER → DO-CI + DO-SUPPLY + QA-GATE + SEC-SUPPLY → D-XXX → PLAN/H-XXX → workflow → pipeline remoto verde`.

### Ajuste de stock
`ROUTER → DOMINIO.md + BE-VALID + BE-TX + SEC-AUTHZ + SEC-LOG + QA-INVARIANT → R-01/H-XXX → implementación → concurrencia/atomicidad`.

### Pantalla con carga remota
`ROUTER → PRODUCTO.md + FE-DATA + FE-A11Y + BE-API + QA-CONTRACT → implementación → loading/vacío/error/teclado/build`.

## GAP-XXX

Aplicar exactamente `BIBLIAS-GOBERNANZA.md §8`: detener, registrar pregunta e impacto, investigar fuentes oficiales, aprobar norma, actualizar este router y recién implementar.

## Cierre

- Ejecutar gates combinados.
- Cerrar H-XXX con evidencia y hash cuando corresponda.
- Registrar D-XXX si hubo trade-off.
- Actualizar `PLAN.md` y `EJECUCION.md`.
- Confirmar que documentación y código describen el mismo estado.
