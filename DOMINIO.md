# Dominio de Pallet Control

**Estado:** descripción inicial validada contra el modelo actual
**Última revisión:** 2026-10-29

Este documento describe el lenguaje y las invariantes del negocio. No prescribe patrones técnicos.

## Entidades

| Entidad | Significado |
|---|---|
| Usuario | Persona identificada que opera o administra el sistema. |
| Cliente | Destinatario comercial asociado a pedidos. |
| Pedido | Conjunto identificado de artículos solicitados a preparar. |
| Ítem de pedido | Producto, cantidad requerida y estado dentro de un pedido. |
| Producto | Referencia identificable, normalmente mediante EAN. |
| Pallet | Unidad logística que agrupa pedidos y bases. |
| Base | Subdivisión física de un pallet que contiene cantidades asignadas. |
| Asignación | Cantidad de un ítem ubicada en una base. |
| Pendiente | Diferencia o incidencia que necesita resolución. |
| Ticket y foto | Evidencia documental asociada al proceso. |
| Registro de actividad | Evidencia de una acción significativa y su actor. |

## Estados actuales

- Pedido: `open`, `paused`, `done`.
- Pallet: `open`, `paused`, `done`.
- Ítem: `pending`, `done`, `removed`.

Cambiar estados o transiciones requiere una decisión de dominio y tests de transición.

## Invariantes conocidas

- Una cantidad no puede ser negativa.
- La cantidad total asignada de un ítem no puede superar la cantidad pedida.
- Reducir la cantidad pedida no puede dejar asignaciones por encima del nuevo total.
- Una base pertenece a un pallet.
- Un ítem sólo aparece una vez por base.
- Un EAN identifica un producto y sólo puede aparecer una vez dentro del mismo pedido; puede repetirse en pedidos distintos.
- Una acción significativa debe ser atribuible y auditable.
- Una finalización no debe ocultar conflictos o pendientes incompatibles con ese estado.

## Preguntas abiertas

- Ciclo completo y transiciones permitidas de pedidos y pallets.
- Política de eliminación y conservación histórica (`GAP-RETENTION`, H-009, H-048).
- Propiedad de datos en un futuro modelo multiempresa (`GAP-TENANCY`).
- Resolución de operaciones simultáneas u offline (`GAP-OFFLINE`).

Las preguntas abiertas no autorizan comportamiento nuevo; deben resolverse mediante decisión y, si requieren teoría, mediante GAP.
