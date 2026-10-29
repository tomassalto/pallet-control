# R-02 — presupuesto de queries al crear una base

**Fecha:** 2026-10-29

**Endpoint:** `POST /api/v1/pallets/{pallet}/bases`

**Motor medido:** SQLite en memoria (suite feature)
**Escenario:** un item con 20 asignaciones previas en 20 bases; crear una base nueva con cantidad disponible.

## Resultado

- 14 queries totales del request.
- El número permanece independiente de la cantidad de bases porque las asignaciones se bloquean y agregan en consultas por conjunto.
- El test `test_crear_base_mantiene_un_numero_acotado_de_queries_con_muchas_bases` fija el presupuesto y detecta regresiones.

## Comando

`php artisan test --filter=test_crear_base_mantiene_un_numero_acotado_de_queries_con_muchas_bases`

Este resultado acredita el presupuesto local. La corrección de locks y concurrencia se acredita por separado con PostgreSQL en CI.
