# R-01 — presupuesto de queries de `adjust-item`

**Fecha:** 2026-10-29  
**Commit funcional medido:** `dea6631` más el gate incorporado en esta sesión  
**Motor de medición local:** SQLite en memoria, PHP 8.4  
**Ruta normativa:** `BE-PERF`, `QA-PERF`, `QA-INVARIANT`

## Objetivo

R-01 corrige integridad y concurrencia; no afirma reducir latencia. El riesgo de performance introducido es que validar el total global genere una consulta por cada base. El presupuesto verifica que la cantidad de queries sea constante frente a muchas asignaciones.

## Dataset y comando

- Un `order_item` con cantidad pedida 100.
- Una base a modificar.
- Veinte bases adicionales con una unidad asignada en cada una.
- Ajuste solicitado: 10 unidades.

```powershell
php artisan test --filter test_adjust_item_mantiene_un_numero_acotado_de_queries_con_muchas_bases
```

## Resultado

| Métrica | Resultado | Gate |
|---|---:|---:|
| Queries del request autenticado | 9 | 9 |
| Queries adicionales por cada base | 0 | 0 |
| Respuesta | 200 | 200 |

La lectura de todas las asignaciones se resuelve con una consulta ordenada; no hay N+1. El aumento respecto del comportamiento inseguro es deliberado: pertenencia, suma global, transacción y auditoría atómica. Una comparación de latencia “antes/después” no se usa porque este cambio no se presenta como optimización y mezclaría el costo de controles nuevos con una supuesta mejora inexistente.

## Gate pendiente separado

El presupuesto SQLite no acredita locks. La concurrencia real se ejecuta con `phpunit.postgres.xml` en el job `postgres-concurrency` de CI, usando dos procesos Laravel y una base PostgreSQL compartida.
