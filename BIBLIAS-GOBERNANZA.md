# Gobierno de las biblias de Pallet Control

**Estado:** vigente
**Última revisión:** 2026-10-29
**Responsable:** mantenedores de Pallet Control

## 1. Propósito

Este documento gobierna el conocimiento técnico normativo del proyecto. Su objetivo es que una tarea pueda resolverse con criterio consistente sin depender de la memoria de un chat o de una persona.

```text
AGENTS.md → ROUTER.md → sección normativa → DECISIONES.md
          → PLAN.md/AUDITORIA.md → implementación → gates → EJECUCION.md
```

Las biblias definen principios y controles. No describen el estado del repositorio ni reemplazan evidencia, decisiones o planificación.

## 2. Autoridad y precedencia

1. Las biblias son la autoridad teórica.
2. `DECISIONES.md` adapta esa teoría al proyecto y registra trade-offs.
3. `DOMINIO.md` define vocabulario e invariantes de negocio.
4. `PRODUCTO.md` define usuario, problema y límites funcionales.
5. `PLAN.md` determina prioridad y estado.
6. `AUDITORIA.md` conserva evidencia y hallazgos.
7. `EJECUCION.md` ordena sesiones y gates.

Una decisión no puede contradecir una norma vigente sin registrar primero una excepción o actualizar la norma mediante el protocolo GAP.

## 3. Propiedad canónica

| Prefijo | Documento | Propiedad exclusiva |
|---|---|---|
| `BE-*` | `BACKEND-BIBLIA.md` | servicios, API, persistencia y ejecución de backend |
| `FE-*` | `FRONTEND-BIBLIA.md` | arquitectura y experiencia técnica del cliente web |
| `DO-*` | `DEVOPS-BIBLIA.md` | entrega, infraestructura y operación |
| `SEC-*` | `SECURITY-BIBLIA.md` | controles y riesgos de seguridad transversales |
| `QA-*` | `QUALITY-BIBLIA.md` | estrategia de verificación y evidencia de calidad |

Una biblia puede aplicar otra autoridad, pero no duplicarla. Frontend define cómo representar una sesión; Security cómo protegerla; Backend cómo validarla.

## 4. Plantilla normativa

Cada sección normativa debe incluir:

```markdown
## <ID> — Título
**Estado:** vigente | experimental | retirada
**Revisión:** AAAA-MM-DD
**Aplica a:** alcance concreto

### Propósito
### Principio
### Requisitos
### Antipatrones y riesgos
### Evidencia y gates
### Excepciones
### Relaciones
### Fuentes oficiales
```

Los IDs son estables. Un cambio de título no cambia el ID. Una regla retirada conserva su ID y enlaza a la sustituta.

## 5. Lenguaje normativo

- **DEBE / NO DEBE:** requisito obligatorio salvo excepción aprobada.
- **DEBERÍA / NO DEBERÍA:** recomendación cuyo desvío requiere justificación.
- **PUEDE:** opción permitida, no requisito.

Las reglas absolutas sólo se usan cuando la fuente y el contexto las sostienen. Las herramientas concretas son implementaciones posibles, salvo que una decisión del proyecto las haga obligatorias.

## 6. Fuentes y vigencia

- Usar fuentes primarias u oficiales: estándares, documentación del framework o proveedor y especificaciones.
- Cada sección cita sus fuentes y fecha de revisión.
- No convertir una entrada de blog o preferencia personal en norma.
- Revisar una sección al cambiar de versión mayor, aparecer una amenaza relevante o vencer dos años desde su última revisión.
- La implementación ordinaria no investiga teoría nueva: aplica la norma vigente.

## 7. Ciclo de cambio

1. Registrar el motivo y alcance.
2. Auditar impacto y duplicaciones.
3. Proponer cambio con fuentes oficiales.
4. Revisar propiedad canónica y gates.
5. Aprobar mediante una entrada D-XXX.
6. Actualizar biblia, router y referencias.
7. Auditar el código sólo después de aprobar la norma.

## 8. Protocolo GAP-XXX

Si ninguna sección cubre una decisión:

1. Detener la implementación afectada.
2. Registrar `GAP-XXX` en `PLAN.md` con pregunta, riesgo e impacto.
3. Investigar exclusivamente fuentes primarias u oficiales.
4. Proponer la sección o ampliación normativa.
5. Revisarla y aprobarla.
6. Agregar la ruta en `ROUTER.md`.
7. Recién entonces implementar.

Gaps futuros identificados, todavía sin teoría aprobada:

- `GAP-OFFLINE`: almacenamiento local y sincronización.
- `GAP-DESKTOP`: distribución y actualización de escritorio.
- `GAP-MOBILE`: aplicaciones móviles y ciclo de publicación.
- `GAP-TENANCY`: aislamiento SaaS multiempresa.
- `GAP-DEVICE`: identidad y revocación de dispositivos.

## 9. Validación documental

- IDs únicos y rutas existentes.
- Ausencia de IDs huérfanos en `ROUTER.md`.
- Fuentes y fechas en todas las secciones.
- Sin referencias activas a rutas inexistentes.
- Sin teoría duplicada entre autoridades.
- Un recorrido de ejemplo desde router hasta gate.

## Fuentes oficiales

- [NIST SSDF 1.1](https://csrc.nist.gov/pubs/sp/800/218/final).
- [OWASP ASVS 5.0](https://asvs.owasp.org/).
- [W3C WCAG 2](https://www.w3.org/WAI/standards-guidelines/wcag/docs/).
