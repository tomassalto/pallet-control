# Biblia Backend

**Autoridad:** servicios, API, persistencia y ejecución del backend
**Estado:** vigente
**Revisión de todas las secciones:** 2026-10-29
**Stack aplicado:** Laravel 12, PHP 8.4, PostgreSQL/SQLite

Leer mediante `ROUTER.md`. Seguridad transversal pertenece a `SECURITY-BIBLIA.md`; estrategia de pruebas a `QUALITY-BIBLIA.md`.

## BE-ARCH — Límites y capas
**Estado:** vigente
**Revisión:** 2026-10-29
**Aplica a:** controladores, requests, servicios, modelos, jobs e integraciones.

### Propósito y principio
Separar transporte, validación, negocio y persistencia para que las invariantes tengan un dueño claro y sean verificables.

### Requisitos
- Los controladores DEBEN coordinar HTTP, no concentrar reglas de negocio.
- Form Requests DEBEN validar forma y autorización de entrada cuando corresponda.
- Las reglas que abarcan varias entidades DEBEN vivir en un servicio o acción de aplicación.
- Los modelos PUEDEN contener comportamiento cohesivo de una entidad; no deben convertirse en servicios globales.
- Dependencias externas DEBEN quedar detrás de una interfaz o adaptador comprobable.

### Antipatrones y riesgos
Controladores extensos, helpers globales con estado, reglas duplicadas y acceso directo a servicios externos desde cualquier capa.

### Evidencia y gates
Revisión de dependencias, tests del servicio y controladores que sólo traducen entrada/salida.

### Excepciones
CRUD simple puede permanecer en controlador si no duplica invariantes ni transacciones.

### Relaciones
`BE-VALID`, `BE-TX`, `QA-RISK`, `SEC-AUTHZ`.

### Fuentes oficiales
[Laravel Service Container](https://laravel.com/docs/12.x/container), [Laravel Controllers](https://laravel.com/docs/12.x/controllers).

## BE-AUTH — Identidad aplicada por el backend
**Estado:** vigente
**Revisión:** 2026-10-29
**Aplica a:** Sanctum, sesiones, tokens y resolución del usuario.

### Propósito y principio
El backend autentica cada solicitud protegida y ofrece mecanismos explícitos de emisión, expiración y revocación.

### Requisitos
- Elegir cookies de sesión o tokens según clientes, dominios y necesidad de revocación; documentarlo en D-XXX.
- Tokens persistidos DEBEN poder revocarse y almacenarse de forma no recuperable cuando el framework lo soporte.
- Desactivar un usuario DEBE impedir nuevas solicitudes y revocar credenciales activas según la política.
- Login, logout, recuperación y verificación DEBEN tener rate limit y respuestas no enumerables.
- No asumir que JWT implica ausencia total de estado: expiración corta, listas de revocación o refresh tokens son decisiones válidas según el riesgo.

### Antipatrones y riesgos
Tokens sin expiración o revocación, secretos en logs, confiar en identidad enviada por el cliente.

### Evidencia y gates
Tests 401, expiración/revocación y usuario desactivado; revisión contra `SEC-IDENTITY`.

### Excepciones
Sólo endpoints explícitamente públicos y documentados.

### Relaciones
`SEC-IDENTITY`, `SEC-ABUSE`, `FE-SESSION`.

### Fuentes oficiales
[Laravel Authentication](https://laravel.com/docs/12.x/authentication), [Laravel Sanctum](https://laravel.com/docs/12.x/sanctum).

## BE-VALID — Validación e invariantes
**Estado:** vigente
**Revisión:** 2026-10-29
**Aplica a:** toda entrada y cambio de estado.

### Propósito y principio
Validar por capas: contrato, autorización, negocio y base de datos. El cliente mejora UX, pero no es autoridad.

### Requisitos
- Consumir sólo datos validados; evitar asignación masiva de campos no permitidos.
- Validar estructura y límites antes de ejecutar negocio costoso.
- Las invariantes entre entidades DEBEN comprobarse dentro del límite transaccional adecuado.
- Usar `NOT NULL`, `UNIQUE`, FK y `CHECK` cuando expresen correctamente la regla por fila.
- Una invariante agregada entre filas NO DEBE atribuirse a un `CHECK` simple; requiere transacción, lock, trigger u otro diseño aprobado.

### Antipatrones y riesgos
`$request->all()`, validación sólo visual, check-then-write sin protección concurrente.

### Evidencia y gates
Casos feliz, inválido, límite y conflicto; migraciones probadas en motores soportados.

### Excepciones
Ninguna entrada externa omite validación; fuentes internas pueden reutilizar un objeto ya validado.

### Relaciones
`BE-TX`, `DOMINIO.md`, `QA-INVARIANT`, `SEC-INPUT`.

### Fuentes oficiales
[Laravel Validation](https://laravel.com/docs/12.x/validation), [PostgreSQL Constraints](https://www.postgresql.org/docs/current/ddl-constraints.html).

## BE-ERR — Errores y contrato fallido
**Estado:** vigente
**Revisión:** 2026-10-29
**Aplica a:** respuestas API y excepciones.

### Propósito y principio
Distinguir fallos esperados de fallos internos y ofrecer un contrato estable sin filtrar detalles.

### Requisitos
- 401 para falta de autenticación, 403 para prohibición, 404 para ausencia, 409 para conflicto de estado y 422 para entrada semánticamente inválida.
- El handler global DEBE traducir excepciones conocidas a un formato consistente.
- Errores 5xx DEBEN devolver mensaje genérico y referencia correlacionable.
- No devolver stack, query, ruta interna ni mensaje crudo de una excepción inesperada.
- Mantener compatibilidad del contrato con el frontend.

### Antipatrones y riesgos
`try/catch` repetido, status arbitrarios y `$e->getMessage()` como respuesta genérica.

### Evidencia y gates
Tests de status y forma; logs correlacionados; revisión `SEC-LOG`.

### Excepciones
Mensajes de excepciones de dominio pueden exponerse sólo si fueron diseñados para usuario.

### Relaciones
`FE-DATA`, `SEC-LOG`, `QA-CONTRACT`.

### Fuentes oficiales
[RFC 9110 HTTP Semantics](https://www.rfc-editor.org/rfc/rfc9110), [Laravel Error Handling](https://laravel.com/docs/12.x/errors).

## BE-API — Diseño y evolución de API
**Estado:** vigente
**Revisión:** 2026-10-29
**Aplica a:** `/api/v1` y consumidores actuales o futuros.

### Propósito y principio
Ofrecer contratos coherentes, acotados y evolutivos; el estilo REST es un medio, no un sustituto del dominio.

### Requisitos
- Versionar cambios incompatibles y mantener contrato mientras existan consumidores soportados.
- Colecciones potencialmente no acotadas DEBEN tener paginación, cursor o límite explícito.
- Filtros y ordenamientos permitidos DEBEN estar en allowlist.
- Escrituras reintentables o futuras operaciones offline requieren idempotencia diseñada; hasta `GAP-OFFLINE`, no inventar protocolo.
- Usar recursos de respuesta cuando estabilicen serialización y oculten campos internos.

### Antipatrones y riesgos
Listas ilimitadas, campos internos expuestos, cambios silenciosos y endpoints que mezclan varias operaciones sin semántica documentada.

### Evidencia y gates
Tests de contrato, límites, autorización y compatibilidad; OpenAPI podrá adoptarse mediante decisión.

### Excepciones
Catálogos demostrablemente pequeños pueden usar respuesta completa con límite documentado.

### Relaciones
`QA-CONTRACT`, `SEC-AUTHZ`, `FE-DATA`.

### Fuentes oficiales
[RFC 9110](https://www.rfc-editor.org/rfc/rfc9110), [Laravel API Resources](https://laravel.com/docs/12.x/eloquent-resources).

## BE-DATA — Datos y migraciones
**Estado:** vigente
**Revisión:** 2026-10-29
**Aplica a:** esquema, Eloquent, índices y cambios productivos.

### Propósito y principio
La base protege integridad durable y el esquema evoluciona mediante migraciones revisables.

### Requisitos
- Definir tipos, nullabilidad, FK y unicidad desde las invariantes de dominio.
- Diseñar índices desde consultas medidas; una FK no garantiza el índice adecuado en todos los motores.
- No editar migraciones ya aplicadas en producción; crear una nueva migración.
- `down()` DEBE ser funcional cuando la reversión sea segura; si no lo es, documentar estrategia de recuperación.
- Cambios destructivos usan expand/migrate/contract y compatibilidad con versiones coexistentes.
- Evitar N+1 mediante carga explícita y detectarlo en desarrollo/tests.

### Antipatrones y riesgos
Cambios manuales, índices por intuición, cascadas no evaluadas y migraciones que bloquean tablas sin plan.

### Evidencia y gates
Migración sobre copia representativa, plan de rollback/forward, query plan cuando el cambio sea de performance.

### Excepciones
SQLite de test puede requerir adaptación explícita sin ocultar diferencias con PostgreSQL.

### Relaciones
`DO-RELEASE`, `QA-DATA`, `BE-PERF`.

### Fuentes oficiales
[Laravel Migrations](https://laravel.com/docs/12.x/migrations), [PostgreSQL Indexes](https://www.postgresql.org/docs/current/indexes.html).

## BE-TX — Transacciones, locks y concurrencia
**Estado:** vigente
**Revisión:** 2026-10-29
**Aplica a:** stock y escrituras multi-entidad.

### Propósito y principio
Leer, validar y escribir el estado protegido dentro de una unidad atómica con un orden de locks definido.

### Requisitos
- Toda escritura multi-tabla que representa una operación DEBE ser atómica.
- La lectura usada para decidir stock DEBE ocurrir dentro de la transacción y bajo el control de concurrencia elegido.
- Locks pesimistas DEBEN adquirirse en orden determinista y mantenerse el menor tiempo posible.
- Reintentos por deadlock o serialización sólo se hacen sobre operaciones idempotentes o reconstruibles.
- El activity log de una operación crítica DEBE confirmar o revertir junto a ella.
- La estrategia concreta se registra en `DECISIONES.md`.

### Antipatrones y riesgos
Validar antes de abrir la transacción, llamadas de red dentro del lock y capturar una excepción para confirmar estado parcial.

### Evidencia y gates
Tests de conflicto y atomicidad; prueba en PostgreSQL para concurrencia real; rollback al fallar auditoría.

### Excepciones
Lecturas sin decisión de escritura pueden ejecutarse fuera de transacción según consistencia requerida.

### Relaciones
`DOMINIO.md`, `QA-INVARIANT`, `SEC-LOG`.

### Fuentes oficiales
[Laravel Database Transactions](https://laravel.com/docs/12.x/database#database-transactions), [PostgreSQL Explicit Locking](https://www.postgresql.org/docs/current/explicit-locking.html).

## BE-ASYNC — Colas e integraciones
**Estado:** vigente
**Revisión:** 2026-10-29
**Aplica a:** OCR, Telegram, imágenes y trabajos diferibles.

### Propósito y principio
Separar trabajo lento o fallable sin perder trazabilidad ni duplicar efectos.

### Requisitos
- Definir timeout, intentos, backoff, idempotencia y estado fallido.
- Pasar identificadores serializables, no estado obsoleto completo.
- No ejecutar efectos externos antes de confirmar la transacción que los origina; usar after-commit/outbox cuando corresponda.
- Webhooks DEBEN autenticarse, validar payload y tolerar reentregas.
- Fallos permanentes requieren alerta y procedimiento de recuperación.

### Antipatrones y riesgos
Reintentos infinitos, efectos duplicados y jobs sin observabilidad.

### Evidencia y gates
Tests de retry/idempotencia, cola fallida visible y métricas de latencia/fallo.

### Excepciones
Trabajo corto puede ser síncrono si tiene presupuesto y failure mode aceptables.

### Relaciones
`DO-OBS`, `SEC-INTEGRATION`, `QA-RESILIENCE`.

### Fuentes oficiales
[Laravel Queues](https://laravel.com/docs/12.x/queues), [Laravel HTTP Client](https://laravel.com/docs/12.x/http-client).

## BE-PERF — Performance backend
**Estado:** vigente
**Revisión:** 2026-10-29
**Aplica a:** endpoints, consultas, cache y recursos.

### Propósito y principio
Optimizar desde mediciones reproducibles y preservar corrección.

### Requisitos
- Capturar baseline, carga, dataset, p50/p95/p99, errores y recursos.
- Priorizar número de round-trips, queries y payload antes de microoptimizaciones.
- Cachear sólo con dueño, clave, TTL, invalidación y aislamiento definidos.
- Trabajo pesado debe evaluarse para cola sin esconder latencia operacional.
- Toda optimización incluye comparación y test de regresión.

### Antipatrones y riesgos
Números sin evidencia, cache de datos privados compartido y optimización que cambia semántica.

### Evidencia y gates
Resultados en `benchmarks/`, query count/plan y gates `QA-PERF`.

### Excepciones
Una corrección obvia puede aplicarse sin benchmark sólo si no se presenta como mejora cuantificada.

### Relaciones
`FE-PERF`, `QA-PERF`, `DO-OBS`.

### Fuentes oficiales
[Laravel Cache](https://laravel.com/docs/12.x/cache), [PostgreSQL EXPLAIN](https://www.postgresql.org/docs/current/using-explain.html).

## BE-GATE — Gates de backend
**Estado:** vigente
**Revisión:** 2026-10-29
**Aplica a:** cualquier cambio en `pallet-backend/`.

### Principio y requisitos
Ejecutar Pint en modo check, PHPStan/Larastan, tests pertinentes y suite completa proporcional al riesgo, `composer audit` y build integrado cuando cambie contrato o assets. Migraciones se prueban en ida y recuperación.

### Antipatrones y riesgos
Arreglar una regresión cambiando el test sin justificar contrato; aceptar baseline nuevo para ocultar errores.

### Evidencia y gates
Salida exitosa en CI y referencias de hallazgos/decisiones actualizadas.

### Excepciones
Un gate no disponible debe registrarse con causa, riesgo, responsable y vencimiento.

### Relaciones
Toda `QUALITY-BIBLIA.md`, `DO-CI`, `SEC-SUPPLY`.

### Fuentes oficiales
[Laravel Testing](https://laravel.com/docs/12.x/testing), [PHPStan User Guide](https://phpstan.org/user-guide/getting-started).
