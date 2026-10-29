# R-06 — presupuesto de queries de la vista pública

**Fecha:** 2026-10-29

**Endpoint:** `GET /api/v1/public/orders/{token}`

**Motor medido:** SQLite en memoria (suite feature)

**Escenario:** consultar mediante token opaco un pedido con 20 ítems y sin pallets ni tickets.

## Resultado

- 6 queries totales del request.
- El número permanece independiente de la cantidad de ítems porque las relaciones y productos se cargan por lote.
- El test `test_vista_publica_mantiene_queries_acotadas_con_pedido_grande` fija el presupuesto.

## Comando

`php artisan test --filter=test_vista_publica_mantiene_queries_acotadas_con_pedido_grande`

Los controles de confidencialidad y abuso se acreditan por separado con tests de token, contrato y rate limit.
