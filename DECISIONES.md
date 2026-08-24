# 🧠 DECISIONES — Pallet Control

> **Qué es esto:** por qué el proyecto es como es. Un ADR (Architecture Decision Record) liviano.
> **Por qué importa:** es lo que te salva en la entrevista cuando te preguntan *"¿por qué elegiste esto?"*.
> Y es lo que evita que la IA (o vos en tres meses) revierta una decisión pensada.
> **Se agrega:** cada vez que se toma una decisión con trade-offs. Nunca se borra — se marca como superada.

---

## D-001 — _(título de la decisión)_

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

**Referencia:** BACKEND-BIBLIA §X / DEVOPS-BIBLIA §Y

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
**Estado:** ✅ vigente

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
No usar `overrides` ni `npm audit fix --force`. Mantener R-01 sin iniciar hasta
completar una rebanada específica de migración del bot a Baileys 7 y obtener audit 0.

**Por qué**
DEVOPS-BIBLIA §3.6 exige no forzar versiones mayores transitivas y validar cada
ecosistema de forma aislada. La cadena maneja cifrado y estado de WhatsApp, por lo
que una falsa corrección del lockfile es más peligrosa que un bloqueo explícito.

**Trade-off aceptado**
La modernización de negocio se demora hasta disponer de una migración verificable
del bot; a cambio no se arriesgan sesiones ni mensajes productivos.

**Referencia:** DEVOPS-BIBLIA §3.6
