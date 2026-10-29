# Biblia Quality

**Autoridad:** estrategia de verificación y evidencia de calidad
**Estado:** vigente
**Revisión de todas las secciones:** 2026-10-29

## QA-RISK — Estrategia basada en riesgo
**Estado:** vigente
**Revisión:** 2026-10-29
**Aplica a:** planificación de todo cambio.

### Propósito y principio
La profundidad de verificación depende de impacto, probabilidad, detectabilidad y reversibilidad.

### Requisitos
- Identificar comportamiento, usuarios y datos afectados antes de elegir tests.
- Priorizar seguridad, integridad/pérdida de datos y contratos antes de estética interna.
- Cubrir camino feliz, límites, inválidos, permisos y fallos de dependencias aplicables.
- Cambios de alto riesgo requieren caracterización previa y plan de recuperación.
- La ausencia de test debe ser una decisión explícita, no omisión accidental.

### Antipatrones y riesgos
Contar tests como calidad, perseguir cobertura sin riesgo y probar sólo implementación.

### Evidencia y gates
Matriz riesgo-escenario-gate en plan o PR.

### Excepciones
Cambio documental puro usa validación documental.

### Relaciones
`QA-LEVELS`, `SEC-THREAT`, biblias técnicas.

### Fuentes oficiales
[NIST SSDF](https://csrc.nist.gov/pubs/sp/800/218/final), [OWASP ASVS](https://asvs.owasp.org/).

## QA-LEVELS — Niveles de prueba
**Estado:** vigente
**Revisión:** 2026-10-29
**Aplica a:** unit, integración, feature/HTTP, contrato y E2E.

### Propósito y principio
Usar el nivel más bajo que demuestre el riesgo sin falsificar límites reales.

### Requisitos
- Unitarias para lógica pura y transiciones aisladas.
- Integración para DB, filesystem, framework y adaptadores.
- Feature/HTTP para middleware, validación, autorización y contrato.
- Contrato para consumidores entre frontend/backend o terceros.
- E2E para pocos recorridos críticos completos.
- Caracterización antes de refactorizar comportamiento legacy incierto.

### Antipatrones y riesgos
Mockear la unidad que se necesita verificar, E2E para cada detalle y tests acoplados al DOM/SQL interno.

### Evidencia y gates
Cada test declara comportamiento observable y falla por la razón esperada antes del fix cuando sea regresión.

### Excepciones
Una prueba manual puede cubrir hardware/cámara si deja pasos y evidencia repetibles.

### Relaciones
`BE-GATE`, `FE-GATE`, `QA-CONTRACT`.

### Fuentes oficiales
[Laravel Testing](https://laravel.com/docs/12.x/testing), [Testing Library Guiding Principles](https://testing-library.com/docs/guiding-principles/).

## QA-CONTRACT — Contratos y compatibilidad
**Estado:** vigente
**Revisión:** 2026-10-29
**Aplica a:** API, errores, eventos, archivos y rutas públicas.

### Propósito y principio
Los consumidores dependen de contratos observables, no de implementación interna.

### Requisitos
- Verificar status, forma, campos requeridos, nullabilidad, paginación y error.
- Cambios incompatibles requieren versión o migración coordinada.
- Fixtures/esquemas se actualizan junto al productor y consumidor.
- Probar versiones coexistentes durante rollout cuando aplique.
- No convertir snapshots masivos en aprobación automática.

### Antipatrones y riesgos
Tests sólo del controlador, snapshots sin revisión y campos accidentales considerados contrato.

### Evidencia y gates
Suite de contrato y build del consumidor.

### Excepciones
Campos explícitamente experimentales deben estar marcados y no ser críticos.

### Relaciones
`BE-API`, `BE-ERR`, `FE-DATA`, `DO-RELEASE`.

### Fuentes oficiales
[OpenAPI Specification](https://spec.openapis.org/oas/latest.html), [RFC 9110](https://www.rfc-editor.org/rfc/rfc9110).

## QA-INVARIANT — Integridad y concurrencia
**Estado:** vigente
**Revisión:** 2026-10-29
**Aplica a:** stock, estados y operaciones multi-entidad.

### Propósito y principio
Demostrar que una invariante sobrevive a límites, fallos y operaciones simultáneas.

### Requisitos
- Test feliz, límite exacto, por debajo/encima, rollback y repetición.
- Concurrencia real se prueba en el motor productivo; SQLite no acredita locks de PostgreSQL.
- Inyectar fallo después de cada efecto crítico para verificar atomicidad.
- Verificar audit log junto al estado.
- Los tests de regresión no se cambian para aceptar el bug corregido sin documentar el cambio de contrato.

### Antipatrones y riesgos
Simular concurrencia con llamadas secuenciales y afirmar protección sólo porque existe `transaction()`.

### Evidencia y gates
Escenario reproducible y estado final/invariantes comprobados.

### Excepciones
CI principal puede usar SQLite para velocidad si existe gate PostgreSQL para comportamiento específico.

### Relaciones
`BE-TX`, `DOMINIO.md`, `SEC-LOG`.

### Fuentes oficiales
[PostgreSQL Transaction Isolation](https://www.postgresql.org/docs/current/transaction-iso.html), [Laravel Database Testing](https://laravel.com/docs/12.x/database-testing).

## QA-DATA — Datos de prueba y aislamiento
**Estado:** vigente
**Revisión:** 2026-10-29
**Aplica a:** factories, seeders, fixtures y entornos.

### Propósito y principio
Cada prueba controla sus datos, puede ejecutarse en cualquier orden y no toca producción.

### Requisitos
- Factories producen defaults válidos y estados explícitos.
- Tests crean sólo datos relevantes y limpian/rollbackean.
- IDs, tiempo, azar y servicios externos se controlan cuando afectan determinismo.
- Datos sensibles reales no se copian sin anonimización y autorización.
- Seeders demo no se ejecutan automáticamente en producción.

### Antipatrones y riesgos
Dependencia del orden, fixtures globales mutables y sleeps para sincronización.

### Evidencia y gates
Suite aleatorizada/repetida y ejecución paralela cuando sea viable.

### Excepciones
Dataset grande de benchmark se versiona o genera de forma reproducible fuera de unit tests.

### Relaciones
`SEC-DATA`, `QA-FLAKY`, `BE-DATA`.

### Fuentes oficiales
[Laravel Factories](https://laravel.com/docs/12.x/eloquent-factories), [PHPUnit Fixtures](https://docs.phpunit.de/en/11.5/fixtures.html).

## QA-FLAKY — Determinismo y tiempos
**Estado:** vigente
**Revisión:** 2026-10-29
**Aplica a:** tests intermitentes y asíncronos.

### Propósito y principio
Un gate no confiable deja de proteger; se repara, no se ignora indefinidamente.

### Requisitos
- Esperar condiciones observables, no tiempos arbitrarios.
- Congelar/controlar reloj, zona horaria y azar cuando importen.
- Aislar red externa con contrato/adaptador; mantener pruebas integradas separadas.
- Registrar tasa y causa de flakiness; quarantine sólo con ticket, dueño y vencimiento.
- Reintentos de CI no convierten fallo en éxito sin evidencia.

### Antipatrones y riesgos
`sleep`, aumentar timeout a ciegas y tests dependientes de internet.

### Evidencia y gates
Repetición N veces, diagnóstico preservado y cero flaky conocido bloqueante.

### Excepciones
Tests de resiliencia pueden introducir fallos aleatorios con semilla registrada.

### Relaciones
`QA-DATA`, `BE-ASYNC`, `DO-CI`.

### Fuentes oficiales
[PHPUnit Test Doubles](https://docs.phpunit.de/en/11.5/test-doubles.html), [Playwright Auto-waiting](https://playwright.dev/docs/actionability).

## QA-STATIC — Calidad estática y revisión
**Estado:** vigente
**Revisión:** 2026-10-29
**Aplica a:** lint, tipos, análisis y code review.

### Propósito y principio
Automatizar defectos mecánicos y reservar revisión humana para diseño, dominio y riesgo.

### Requisitos
- Formato/lint en modo check, análisis estático con nivel explícito y baseline sólo para deuda existente.
- Código nuevo no aumenta baseline sin decisión.
- Review verifica alcance, contratos, invariantes, seguridad, tests y operación.
- Dependencias nuevas justifican necesidad, mantenimiento y superficie.
- Código generado se identifica y valida por su generador.

### Antipatrones y riesgos
Baseline regenerado automáticamente, review sólo de estilo y comentarios que repiten código.

### Evidencia y gates
Pint, PHPStan, ESLint y diff revisado; hallazgos trazables.

### Excepciones
Supresión puntual incluye motivo y alcance mínimo.

### Relaciones
`BE-GATE`, `FE-GATE`, `DO-CI`.

### Fuentes oficiales
[PHPStan Baseline](https://phpstan.org/user-guide/baseline), [ESLint Core Concepts](https://eslint.org/docs/latest/use/core-concepts/).

## QA-A11Y — Verificación de accesibilidad
**Aplica a:** flujos frontend.

### Propósito y principio
Automatización detecta una parte; conformidad exige revisión funcional y humana.

### Requisitos
- Objetivo WCAG 2.2 AA para flujos críticos.
- Ejecutar análisis automático, teclado, foco, zoom/reflow y lector de pantalla representativo.
- Probar estados dinámicos, errores, modal, scanner y alternativas de canvas.
- Cada hallazgo referencia criterio WCAG e impacto.

### Antipatrones y riesgos
Declarar accesibilidad por Lighthouse/axe o añadir ARIA sin semántica.

### Evidencia y gates
Checklist fechado, resultados automáticos y recorrido manual.

### Excepciones
Conformidad parcial requiere barrera, alternativa, responsable y fecha.

### Relaciones
`FE-A11Y`, `QA-COMPAT`.

### Fuentes oficiales
[WCAG 2.2](https://www.w3.org/TR/WCAG22/), [WAI Evaluating Web Accessibility](https://www.w3.org/WAI/test-evaluate/).

## QA-PERF — Presupuestos y regresión
**Estado:** vigente
**Revisión:** 2026-10-29
**Aplica a:** frontend, API, jobs y recursos.

### Propósito y principio
Medir escenarios representativos y bloquear regresiones significativas, no perseguir un número universal.

### Requisitos
- Definir dataset, carga, entorno, percentiles y tasa de error.
- Guardar baseline y comparar una variable por vez.
- Presupuestos frontend incluyen bundle y Core Web Vitals; backend incluye latencia, throughput y recursos.
- Calentamiento/cache y variabilidad se documentan.
- Corrección prevalece sobre mejora de performance.

### Antipatrones y riesgos
Promedios solos, localhost como producción y comparar entornos distintos.

### Evidencia y gates
`benchmarks/` con comando, versión, datos y resultado antes/después.

### Excepciones
No se exige benchmark completo a cambios sin camino caliente, pero sí ausencia de aumento evidente de bundle/query.

### Relaciones
`BE-PERF`, `FE-PERF`, `DO-OBS`.

### Fuentes oficiales
[web.dev Web Vitals](https://web.dev/articles/vitals), [k6 Test Types](https://grafana.com/docs/k6/latest/testing-guides/test-types/).

## QA-SEC — Verificación de seguridad
**Estado:** vigente
**Revisión:** 2026-10-29
**Aplica a:** controles de `SECURITY-BIBLIA.md`.

### Propósito y principio
Combinar revisión de diseño, tests negativos y herramientas; ningún scanner acredita seguridad completa.

### Requisitos
- Mapear controles aplicables a ASVS y threat cases.
- Probar 401/403, acceso cruzado, inyección, uploads, abuso y redacción según alcance.
- SCA/SAST/secret scan con política de severidad y excepciones.
- Hallazgo reproducible, impacto, evidencia y remediación verificable.

### Antipatrones y riesgos
Pen test como única actividad, false positives ignorados sin análisis.

### Evidencia y gates
Matriz control-prueba, reports y retest de hallazgos.

### Excepciones
Riesgo residual aprobado en D-XXX con vencimiento.

### Relaciones
`SEC-GATE`, `DO-SUPPLY`, `QA-RISK`.

### Fuentes oficiales
[OWASP ASVS 5.0](https://asvs.owasp.org/), [NIST SSDF](https://csrc.nist.gov/pubs/sp/800/218/final).

## QA-RESILIENCE — Fallos y recuperación
**Estado:** vigente
**Revisión:** 2026-10-29
**Aplica a:** dependencias, colas, deploy y datos.

### Propósito y principio
Probar comportamiento degradado y recuperación, no sólo disponibilidad ideal.

### Requisitos
- Simular timeout, respuesta inválida, DB/objeto/cola no disponible y proceso interrumpido.
- Verificar reintento, idempotencia, backoff, estado visible y no duplicación.
- Probar rollback/forward recovery y restauración.
- Confirmar alertas y runbooks.

### Antipatrones y riesgos
Mocks que siempre responden, reintentos infinitos y recuperación sólo documentada.

### Evidencia y gates
Ejercicio reproducible con estado inicial/final y tiempos observados.

### Excepciones
Dependencia no crítica puede degradar funcionalidad si el usuario lo entiende.

### Relaciones
`BE-ASYNC`, `DO-RELEASE`, `DO-DR`.

### Fuentes oficiales
[NIST Contingency Planning](https://csrc.nist.gov/pubs/sp/800/34/r1/final), [Laravel Queue Failures](https://laravel.com/docs/12.x/queues#dealing-with-failed-jobs).

## QA-COMPAT — Compatibilidad
**Estado:** vigente
**Revisión:** 2026-10-29
**Aplica a:** navegadores, viewports, motores DB y versiones coexistentes.

### Propósito y principio
Soportar una matriz explícita basada en usuarios y plataforma, no “todo”.

### Requisitos
- Declarar navegadores/dispositivos y versiones soportadas.
- Probar capacidades críticas con matriz mínima y fallbacks.
- Diferencias SQLite/PostgreSQL se identifican.
- PWA update y API coexistente forman parte de compatibilidad.

### Antipatrones y riesgos
Compatibilidad implícita y detectar en producción que una API no existe.

### Evidencia y gates
Matriz fechada y resultados por release relevante.

### Excepciones
Plataforma no soportada recibe mensaje claro, no fallo silencioso.

### Relaciones
`FE-RESPONSIVE`, `FE-PWA`, `BE-DATA`.

### Fuentes oficiales
[MDN Browser Compatibility Data](https://github.com/mdn/browser-compat-data), [Vite Browser Compatibility](https://vite.dev/guide/build#browser-compatibility).

## QA-GATE — Definition of Done
**Estado:** vigente
**Revisión:** 2026-10-29
**Aplica a:** cierre de toda tarea significativa.

### Propósito y principio
Terminado significa comportamiento, evidencia, operación y documentación coherentes.

### Requisitos
- Alcance y criterios cumplidos; tests por riesgo en verde.
- Lint/análisis/audit/build aplicables en verde.
- Seguridad, accesibilidad, performance y compatibilidad evaluadas según ruta.
- Migración, rollback, observabilidad y documentación actualizados cuando apliquen.
- Hallazgos cerrados con commit; decisiones y plan reflejan realidad.

### Antipatrones y riesgos
“Funciona en mi máquina”, TODO sin ticket y documentación que declara futuro como hecho.

### Evidencia y gates
CI más evidencia manual reproducible y cierre documental.

### Excepciones
Omisión sólo con riesgo, responsable y vencimiento explícitos.

### Relaciones
`BE-GATE`, `FE-GATE`, `DO-GATE`, `SEC-GATE`.

### Fuentes oficiales
[NIST SSDF](https://csrc.nist.gov/pubs/sp/800/218/final), [GitHub Required Reviews](https://docs.github.com/en/pull-requests/collaborating-with-pull-requests/incorporating-feedback-about-your-code/approving-a-pull-request-with-required-reviews).
