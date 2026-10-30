# H-040 — Paginación de pendientes

**Fecha:** 2026-10-29  
**Ruta normativa:** `BE-PERF`, `BE-API`, `FE-DATA`, `QA-PERF`, `QA-CONTRACT`, `DO-OBS`  
**Entorno:** Windows local, PHP 8.4, Laravel testing, SQLite `:memory:`, 120 pendientes, una request de calentamiento y 30 iteraciones medidas.

## Comando

```powershell
$env:RUN_PERFORMANCE_BENCHMARKS='1'
php artisan test --filter PendingItemsBenchmarkTest
```

El test crea 80 registros `pending` y 40 `resolved`, autentica un usuario y consulta `GET /api/v1/pending-items` con el mismo dataset antes y después. Las cifras locales no representan producción; permiten comparar una sola variable en el mismo entorno.

## Resultado

| Métrica | Antes: lista ilimitada | Después: página por defecto |
|---|---:|---:|
| Registros devueltos | 120 | 25 |
| Payload | 44.332 bytes | 9.060 bytes |
| Queries | 6 | 6 |
| p50 | 21,85 ms | 5,76 ms |
| p95 | 23,56 ms | 6,63 ms |
| p99 | 23,65 ms | 7,02 ms |
| Errores | 0 | 0 |

## Interpretación

El endpoint no presentaba N+1: las seis queries permanecen constantes gracias al eager loading. El problema era el crecimiento no acotado de filas, serialización y payload. Con página por defecto de 25, el payload observado se redujo 79,6 % y el p95 local 71,9 %. Estos porcentajes sólo describen este benchmark reproducible; no son una promesa de latencia productiva.

El contrato nuevo acepta únicamente `status=pending|resolved`, `page >= 1` y `per_page` entre 1 y 100. El frontend consulta cada estado por separado, incluye estado y página en la query key y muestra navegación con totales del servidor.
