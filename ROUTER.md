# ROUTER — entrada obligatoria al contexto de Pallet Control

> Este archivo decide **qué leer antes de planear o tocar código**.
> No contiene teoría: toda regla técnica sale de `BACKEND-BIBLIA.md` o
> `DEVOPS-BIBLIA.md`. Los demás documentos sólo aplican esa teoría al proyecto.

## Protocolo de uso

1. Clasificar la tarea con la tabla de abajo.
2. Leer únicamente las secciones indicadas de las biblias.
3. Abrir la rebanada vigente de `PLAN.md` y los H-XXX relacionados de `AUDITORIA.md`.
4. Consultar `DECISIONES.md` para no contradecir decisiones ya tomadas.
5. Definir implementación, riesgos y pruebas desde esas fuentes antes de cambiar código.
6. Ejecutar los gates de la ruta y cerrar la sesión actualizando los documentos operativos.

Si una tarea cruza varias filas, se combinan sus lecturas y gates. Las biblias completas
sólo se leen para una auditoría transversal o para mantenerlas.

## Autoridad documental

| Documento | Responsabilidad | No debe contener |
|---|---|---|
| `BACKEND-BIBLIA.md` | Teoría de backend, datos, API, seguridad, testing y performance | Estado del proyecto |
| `DEVOPS-BIBLIA.md` | Teoría de infraestructura, entrega y operación | Estado del proyecto |
| `ROUTER.md` | Derivar tareas hacia teoría y proceso | Teoría duplicada o estados |
| `PLAN.md` | Prioridad, fase y próxima rebanada | Teoría nueva |
| `AUDITORIA.md` | Evidencia y hallazgos H-XXX | Soluciones sin referencia a las biblias |
| `DECISIONES.md` | Adaptaciones D-XXX con trade-offs | Decisiones sin fundamento en las biblias |
| `EJECUCION.md` | Orden y gates de las sesiones | Patrones técnicos nuevos |
| `REVIEW.md` / `ROADMAP.md` | Historia técnica y backlog funcional | Estado rector de modernización |

En un conflicto documental prevalece la biblia para la teoría, `DECISIONES.md` para una
adaptación vigente y `PLAN.md` para el estado del trabajo.

## Router de backend

| Tarea o señal | Teoría obligatoria | Contexto y gate mínimo |
|---|---|---|
| Login, sesiones, Sanctum, tokens, roles, policies | Backend §1 Autenticación y autorización; §7 Seguridad | Auditar auth completa; tests 401/403 y revocación |
| Validación, Form Request, DTO, invariante | Backend §2 Validación | H-XXX asociado; casos feliz, límite e inválido |
| Excepciones, errores, logging, respuesta fallida | Backend §3 Manejo de errores; §4.2 Códigos HTTP | Contrato consumido por frontend; test de forma y status |
| Rutas, recursos, filtros, paginación, versionado | Backend §4 Diseño de API REST | Compatibilidad del contrato y tests HTTP |
| Modelos, relaciones, índices, migraciones | Backend §5.1–§5.5 Base de datos | Verificar datos existentes; `down()` funcional |
| Transacciones, locks, concurrencia, stock | Backend §2.2; §3.1; §5.6; §6.2 | Rebanada R-01–R-05; test de conflicto y atomicidad |
| Tests, factories, seeders, caracterización | Backend §6 Testing | PHPUnit aislado; comportamiento actual antes del refactor |
| Seguridad transversal o exposición pública | Backend §7 Seguridad; §1 si hay identidad | Threat case, autorización, rate limit y secretos |
| N+1, caché, colas, latencia, benchmark | Backend §8 Performance | Medición reproducible antes y después |
| Checklist de salida backend | Backend §9 Production-ready | Pint, PHPStan, tests, audit y build |

## Router de DevOps

| Tarea o señal | Teoría obligatoria | Contexto y gate mínimo |
|---|---|---|
| Dockerfile, imagen, capas, usuario del contenedor | DevOps §1 Docker | Build de imagen y ejecución no-root |
| `.env`, secretos, credenciales, configuración | DevOps §2 Variables y secretos | Sin secretos versionados; rotación si hubo exposición |
| CI, GitHub Actions, branch protection | DevOps §3 CI/CD | Pipeline completo en push y pull request |
| Dependencias, CVE, `composer audit`, `npm audit` | DevOps §3.6 Auditoría y remediación | Lockfile, dry-run, actualización mínima y todos los gates |
| VPS, SSH, firewall, permisos | DevOps §4 Servidor | Checklist de hardening antes de publicar |
| Nginx, proxy, HTTPS, headers | DevOps §5 Nginx y HTTPS | Config validada, TLS y headers comprobados |
| Deploy, healthcheck, migración, rollback | DevOps §6 Deploy sin downtime | Plan de rollback y migración compatible |
| Logs, métricas, Sentry, uptime, alertas | DevOps §7 Observabilidad | Señal accionable y prueba de alerta |
| Backup y restauración | DevOps §8 Backups | Restauración probada, no sólo archivo generado |
| Dominio y DNS | DevOps §9 Dominio y DNS | Resolución y HTTPS verificados |
| Checklist de salida infraestructura | DevOps §10 | Gates de producción completos |

## Rutas combinadas del repositorio

| Área | Recorrido |
|---|---|
| Frontend que consume API | `AGENTS.md` → Backend §3–§4 → contrato actual → `ROADMAP.md` → lint/build |
| Bot de Telegram | Backend §1, §3, §4 y §7 + DevOps §2 y §7 → tests de secret/webhook |
| Componente legado/inactivo | Confirmar que no entra en build, deploy ni runtime; no bloquea gates activos |
| OCR, imágenes y trabajos asíncronos | Backend §2, §3, §6 y §8.6 → costo, retry, storage y cleanup |
| Stock y organización de pallets | `PLAN.md` R-01–R-05 → H-XXX → Backend §2.2, §3.1, §5.6 y §6.2 |
| CI o deploy que toca código y plataforma | Backend §9 + DevOps §2, §3 y §6 |

## Vacío teórico: protocolo GAP-XXX

Si ninguna sección cubre suficientemente una decisión necesaria:

1. Detener la planificación técnica y no modificar ese comportamiento.
2. Registrar `GAP-XXX` en `PLAN.md`, con la pregunta exacta y su impacto.
3. Investigar sólo fuentes primarias u oficiales para redactar una propuesta de ampliación.
4. Revisar y aprobar la nueva sección de la biblia correspondiente.
5. Actualizar este router para apuntar a esa sección.
6. Recién entonces planear e implementar el cambio.

Una fuente externa puede fundamentar una ampliación de la biblia, pero nunca una
implementación directa que todavía no esté respaldada por ella.

## Cierre de sesión

- Ejecutar los gates indicados por la ruta.
- Marcar H-XXX resueltos en `AUDITORIA.md` con el hash del commit.
- Actualizar fase, rebanada y siguiente acción en `PLAN.md`.
- Agregar D-XXX si hubo una adaptación con trade-offs y citar la sección de la biblia.
- Cambiar este router sólo cuando aparezca una ruta o sección nueva.
