# R-04 — presupuesto de queries al agregar un ítem

**Fecha:** 2026-10-29

**Endpoint:** `POST /api/v1/orders/{order}/items`

**Motor medido:** SQLite en memoria (suite feature)

**Escenario:** agregar un EAN nuevo a un pedido que ya contiene 20 ítems.

## Resultado

- 7 queries totales del request.
- El número permanece independiente de la cantidad de ítems porque la detección de duplicado consulta por `order_id` y `ean`.
- El test `test_agregar_item_mantiene_un_numero_acotado_de_queries_con_pedido_grande` fija el presupuesto.

## Comando

`php artisan test --filter=test_agregar_item_mantiene_un_numero_acotado_de_queries_con_pedido_grande`

La unicidad durable y la serialización de dos altas simultáneas se acreditan por separado con PostgreSQL en CI.
