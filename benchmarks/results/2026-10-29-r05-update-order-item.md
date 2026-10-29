# R-05 — presupuesto de queries al actualizar un ítem

**Fecha:** 2026-10-29

**Endpoint:** `PATCH /api/v1/order-items/{item}`

**Motor medido:** SQLite en memoria (suite feature)

**Escenario:** reducir la cantidad pedida al límite exacto de 20 unidades con 20 asignaciones en bases distintas.

## Resultado

- 6 queries totales del request.
- El número permanece independiente de la cantidad de bases porque las asignaciones se leen en una única consulta ordenada.
- El test `test_actualizar_item_mantiene_queries_acotadas_con_muchas_asignaciones` fija el presupuesto.

## Comando

`php artisan test --filter=test_actualizar_item_mantiene_queries_acotadas_con_muchas_asignaciones`

La serialización entre una reducción y una asignación simultáneas se acredita por separado con PostgreSQL en CI.
