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
