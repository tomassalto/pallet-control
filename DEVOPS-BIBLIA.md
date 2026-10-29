# Biblia DevOps e Infraestructura

**Autoridad:** entrega, infraestructura y operación
**Estado:** vigente
**Revisión de todas las secciones:** 2026-10-29

No define controles de aplicación (`SECURITY-BIBLIA.md`) ni estrategia de pruebas (`QUALITY-BIBLIA.md`).

## DO-ENV — Entornos, configuración y secretos
**Estado:** vigente
**Revisión:** 2026-10-29
**Aplica a:** desarrollo, CI, staging y producción.

### Propósito y principio
Separar artefacto de configuración y hacer explícitas las diferencias entre entornos.

### Requisitos
- Configuración por variables o archivos gestionados; secretos fuera del repositorio y del artefacto.
- `.env.example` documenta claves sin valores sensibles ni defaults inseguros.
- Validar configuración obligatoria al iniciar y fallar de forma visible.
- Definir propiedad, rotación y revocación de cada secreto.
- Promover el mismo artefacto construido; herramientas de desarrollo pueden usar imágenes/targets distintos.

### Antipatrones y riesgos
Secretos en git, defaults productivos peligrosos, reconstruir un artefacto diferente por ambiente.

### Evidencia y gates
Secret scan, arranque con configuración mínima, inventario de secretos y rotación probada.

### Excepciones
Credenciales locales ficticias pueden documentarse si no otorgan acceso real.

### Relaciones
`SEC-SECRET`, `DO-CI`, `DO-RELEASE`.

### Fuentes oficiales
[Twelve-Factor Config](https://12factor.net/config), [Docker Build Secrets](https://docs.docker.com/build/building/secrets/).

## DO-CONTAINER — Contenedores y artefactos
**Estado:** vigente
**Revisión:** 2026-10-29
**Aplica a:** Dockerfile, imágenes y runtime.

### Propósito y principio
Producir artefactos mínimos, reproducibles, observables y con privilegio reducido.

### Requisitos
- Builds multi-stage cuando reduzcan superficie o separen toolchain de runtime.
- Runtime como usuario no root salvo justificación documentada.
- Bases y dependencias versionadas; actualizar con proceso controlado, no congelar indefinidamente.
- `.dockerignore` excluye secretos, VCS, caches y material innecesario.
- Datos persistentes viven fuera de la capa efímera.
- Definir señal de parada, límites y healthcheck compatible con la plataforma.

### Antipatrones y riesgos
Compilar en producción, incluir gestores o secretos sin necesidad, escribir datos durables en el contenedor.

### Evidencia y gates
Build limpio, inspección de usuario/capas, scan de imagen y ejecución con filesystem/privilegios esperados.

### Excepciones
Una imagen de desarrollo puede incluir tooling si nunca se promueve a producción.

### Relaciones
`DO-SUPPLY`, `DO-RELEASE`, `SEC-SUPPLY`.

### Fuentes oficiales
[Dockerfile Best Practices](https://docs.docker.com/build/building/best-practices/), [Docker Multi-stage Builds](https://docs.docker.com/build/building/multi-stage/).

## DO-CI — Integración continua y protección
**Estado:** vigente
**Revisión:** 2026-10-29
**Aplica a:** GitHub Actions y política de ramas.

### Propósito y principio
Cada cambio obtiene evidencia automática antes de integrarse.

### Requisitos
- Instalar desde lockfiles y ejecutar lint, análisis estático, tests, auditoría y build aplicables.
- Ejecutar en pull request y ramas protegidas.
- Mínimo privilegio para `GITHUB_TOKEN`; secretos sólo en jobs que los necesitan.
- Fijar acciones de terceros a revisiones verificadas según política de supply chain.
- Proteger la rama integradora con checks requeridos y sin force push.
- No desplegar código cuyo conjunto de gates requerido falló.

### Antipatrones y riesgos
CI sólo de tests, permisos `write-all`, scripts remotos no fijados y warnings usados para ocultar gates.

### Evidencia y gates
Workflow remoto verde, branch protection verificada y fallo intencional que demuestre bloqueo.

### Excepciones
Un gate temporalmente inestable requiere excepción fechada y plan de reparación.

### Relaciones
`QA-GATE`, `DO-SUPPLY`, `SEC-SUPPLY`.

### Fuentes oficiales
[GitHub Actions Security](https://docs.github.com/en/actions/security-for-github-actions), [GitHub Protected Branches](https://docs.github.com/en/repositories/configuring-branches-and-merges-in-your-repository/managing-protected-branches).

## DO-SUPPLY — Dependencias y proveniencia
**Estado:** vigente
**Revisión:** 2026-10-29
**Aplica a:** Composer, npm, acciones, imágenes y artefactos.

### Propósito y principio
Conocer, reproducir y verificar los componentes entregados.

### Requisitos
- Un lockfile por componente activo y actualización por ecosistema.
- Auditar runtime y tooling; priorizar severidad junto a alcanzabilidad y exposición.
- Revisar dry-run y evitar upgrades mayores automáticos.
- Generar y conservar metadatos/SBOM cuando la distribución lo justifique.
- Firmar o atestiguar artefactos destinados a canales no controlados cuando el riesgo lo requiera.
- Excepciones de CVE incluyen ID, alcance, evidencia, responsable y vencimiento.

### Antipatrones y riesgos
`audit fix --force` automático, imágenes mutables sin trazabilidad, ignorar alertas para obtener verde.

### Evidencia y gates
Instalación limpia, audit sin hallazgos bloqueantes y referencia exacta del artefacto desplegado.

### Excepciones
Riesgo aceptado sólo mediante D-XXX y fecha de expiración.

### Relaciones
`SEC-SUPPLY`, `QA-GATE`, `DO-RELEASE`.

### Fuentes oficiales
[Composer audit](https://getcomposer.org/doc/03-cli.md#audit), [npm audit](https://docs.npmjs.com/cli/commands/npm-audit), [GitHub Artifact Attestations](https://docs.github.com/en/actions/security-for-github-actions/using-artifact-attestations).

## DO-INFRA — Infraestructura, capacidad y estado
**Estado:** vigente
**Revisión:** 2026-10-29
**Aplica a:** Render, VPS o plataforma futura.

### Propósito y principio
Elegir topología por requisitos medidos de disponibilidad, operación, datos y costo total.

### Requisitos
- Inventariar compute, DB, almacenamiento, colas, DNS y dependencias externas.
- Definir persistencia y ciclo de vida de cada dato.
- Automatizar configuración repetible mediante IaC cuando reduzca deriva y riesgo.
- Aplicar mínimo privilegio, segmentación y parches.
- Dimensionar con métricas y margen; documentar costo y límites, no precios congelados.
- No adoptar orquestación compleja sin un problema operativo demostrado.

### Antipatrones y riesgos
Base pública, recursos manuales sin inventario, estado en filesystem efímero y complejidad por prestigio.

### Evidencia y gates
Diagrama actualizado, inventario, prueba de persistencia/redeploy y revisión de permisos.

### Excepciones
Recursos manuales de piloto deben quedar documentados y respaldados.

### Relaciones
`DO-DR`, `DO-OBS`, `SEC-SECRET`.

### Fuentes oficiales
[Render Persistent Disks](https://render.com/docs/disks), [Terraform Language](https://developer.hashicorp.com/terraform/language).

## DO-EDGE — Red, proxy y TLS
**Estado:** vigente
**Revisión:** 2026-10-29
**Aplica a:** entrada pública, dominio y tráfico interno.

### Propósito y principio
Exponer sólo los servicios necesarios mediante transporte autenticado y políticas verificables.

### Requisitos
- HTTPS en tráfico público; redirigir HTTP cuando corresponda.
- Renovación de certificados monitoreada.
- Proxy y aplicación coordinan IP original, tamaños, timeouts y headers.
- CSP, HSTS, CORS y cache se diseñan con `SEC-BROWSER`; no copiar snippets universales.
- DB, cache y colas no se exponen públicamente.
- Rate limiting en edge complementa, no reemplaza, controles de aplicación.

### Antipatrones y riesgos
Servidor de desarrollo público, CORS comodín autenticado, cache público de datos personalizados.

### Evidencia y gates
Prueba TLS, headers, origen permitido/rechazado y puertos expuestos.

### Excepciones
HTTP local aislado puede permitirse en desarrollo.

### Relaciones
`SEC-BROWSER`, `SEC-ABUSE`, `FE-PWA`.

### Fuentes oficiales
[Mozilla TLS Guidelines](https://wiki.mozilla.org/Security/Server_Side_TLS), [MDN HTTP Headers](https://developer.mozilla.org/en-US/docs/Web/HTTP/Reference/Headers).

## DO-RELEASE — Deploy, migraciones y rollback
**Estado:** vigente
**Revisión:** 2026-10-29
**Aplica a:** entrega productiva y cambios de esquema.

### Propósito y principio
Una release es identificable, compatible, observable y recuperable.

### Requisitos
- Desplegar artefacto inmutable identificado por digest o commit.
- Separar liveness (proceso vivo) de readiness (capaz de servir); diagnósticos sensibles son privados.
- Coordinar orden de migración y tráfico; usar expand/migrate/contract para coexistencia.
- Definir estrategia recreate, rolling, blue/green o canary según plataforma y SLO.
- Probar rollback de código y forward recovery de datos.
- Registrar versión, actor, momento y resultado.

### Antipatrones y riesgos
`git pull` productivo, migración destructiva junto al código que la necesita, readiness que siempre devuelve 200.

### Evidencia y gates
Runbook ejecutado en entorno representativo, health/readiness y recuperación ensayada.

### Excepciones
Downtime planificado es válido si se comunica y satisface el SLO.

### Relaciones
`BE-DATA`, `DO-OBS`, `DO-DR`, `QA-RESILIENCE`.

### Fuentes oficiales
[Laravel Deployment](https://laravel.com/docs/12.x/deployment), [Kubernetes Probes](https://kubernetes.io/docs/concepts/configuration/liveness-readiness-startup-probes/) como semántica de referencia.

## DO-OBS — Observabilidad, SLO e incidentes
**Estado:** vigente
**Revisión:** 2026-10-29
**Aplica a:** logs, métricas, trazas, alertas y respuesta.

### Propósito y principio
Detectar y explicar fallos con señales accionables ligadas a objetivos de servicio.

### Requisitos
- Logs estructurados a salida gestionada, con correlación y sin secretos.
- Métricas de tráfico, errores, latencia y saturación; trazas donde aporten diagnóstico.
- Definir SLI/SLO antes de alertar; alertas requieren dueño y acción.
- Mantener runbooks y registrar timeline, impacto y aprendizaje de incidentes.
- Observar colas, jobs fallidos, almacenamiento y expiración de certificados.

### Antipatrones y riesgos
Alertar cada excepción, logs locales efímeros y dashboards sin respuesta asociada.

### Evidencia y gates
Prueba de alerta, correlación request-job y simulacro de incidente.

### Excepciones
El piloto puede comenzar con menos señales si cubre caída, errores, latencia y capacidad crítica.

### Relaciones
`SEC-LOG`, `QA-PERF`, `DO-DR`.

### Fuentes oficiales
[OpenTelemetry Concepts](https://opentelemetry.io/docs/concepts/observability-primer/), [Google SRE SLOs](https://sre.google/sre-book/service-level-objectives/).

## DO-DR — Backups y recuperación
**Estado:** vigente
**Revisión:** 2026-10-29
**Aplica a:** DB, archivos, configuración y claves necesarias para restaurar.

### Propósito y principio
Un backup sólo cuenta si es restaurable dentro de objetivos definidos.

### Requisitos
- Definir RPO/RTO por dato y retención.
- Copias automatizadas, cifradas y separadas del fallo principal.
- Incluir DB, objetos, configuración y dependencias necesarias.
- Probar restauración periódica en entorno aislado y verificar integridad funcional.
- Proteger y probar acceso a claves de cifrado.
- Documentar responsables y orden de recuperación.

### Antipatrones y riesgos
Backup en el mismo servidor, retención infinita sin control y restauración nunca probada.

### Evidencia y gates
Reporte fechado de restauración con duración, pérdida observada y acciones correctivas.

### Excepciones
Datos regenerables pueden excluirse si el procedimiento y costo están documentados.

### Relaciones
`DO-INFRA`, `DO-RELEASE`, `SEC-DATA`.

### Fuentes oficiales
[PostgreSQL Backup and Restore](https://www.postgresql.org/docs/current/backup.html), [NIST Contingency Planning Guide](https://csrc.nist.gov/pubs/sp/800/34/r1/final).

## DO-GATE — Gates DevOps
**Estado:** vigente
**Revisión:** 2026-10-29
**Aplica a:** infraestructura, pipeline o release.

### Principio y requisitos
Validar sintaxis/configuración, build reproducible, scans, permisos, health/readiness, persistencia, rollback/forward recovery, observabilidad y documentación de costo/estado.

### Antipatrones y riesgos
Declarar éxito por build sin ejecutar el artefacto o por backup sin restauración.

### Evidencia y gates
Salida automatizada más ensayo operativo proporcional al riesgo.

### Excepciones
Toda omisión lleva riesgo, responsable y vencimiento.

### Relaciones
`QA-GATE`, `SEC-SUPPLY`, `BE-GATE`.

### Fuentes oficiales
[NIST SSDF](https://csrc.nist.gov/pubs/sp/800/218/final), [GitHub Actions](https://docs.github.com/en/actions).
