# R-03 — presupuesto de queries al migrar entre bases

**Fecha:** 2026-10-29

**Endpoint:** `POST /api/v1/pallets/{pallet}/bases/{base}/migrate`

**Motor medido:** SQLite en memoria (suite feature)

**Escenario:** migrar un ítem entre bases existentes con otras 20 bases que contienen asignaciones del mismo ítem.

## Resultado

- 15 queries totales del request.
- El número permanece independiente de la cantidad de bases porque las asignaciones se bloquean y leen en conjunto.
- El test `test_migrate_mantiene_un_numero_acotado_de_queries_con_muchas_bases` fija el presupuesto.

## Comando

`php artisan test --filter=test_migrate_mantiene_un_numero_acotado_de_queries_con_muchas_bases`

Este resultado acredita el presupuesto local. La serialización de dos migraciones simultáneas se acredita por separado con PostgreSQL en CI.
