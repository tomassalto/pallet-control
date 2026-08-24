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
