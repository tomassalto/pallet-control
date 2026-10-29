# Biblia Security

**Autoridad:** riesgos y controles de seguridad transversales
**Estado:** vigente
**Revisión de todas las secciones:** 2026-10-29
**Baseline:** OWASP ASVS 5.0 nivel 2 como objetivo; alcance final se ajusta por riesgo.

## SEC-THREAT — Modelado de amenazas
**Estado:** vigente
**Revisión:** 2026-10-29
**Aplica a:** features nuevas, cambios de confianza y exposición pública.

### Propósito y principio
Identificar activos, actores, límites de confianza, abuso y controles antes de implementar.

### Requisitos
- Enumerar datos/operaciones valiosas, entradas, dependencias y roles.
- Considerar usuario legítimo abusivo además de atacante anónimo.
- Modelar pérdida, alteración, indisponibilidad y exposición.
- Vincular mitigaciones con requisitos verificables y riesgo residual.
- Revisar al cambiar arquitectura, datos sensibles o integración.

### Antipatrones y riesgos
Checklist sin contexto, seguridad sólo al final y asumir red/cliente confiable.

### Evidencia y gates
Threat cases en plan/tests, diagrama de flujo de datos cuando haya nuevos límites.

### Excepciones
Cambio puramente editorial documenta “sin cambio de amenaza”.

### Relaciones
Todas las secciones Security y `QA-RISK`.

### Fuentes oficiales
[OWASP Threat Modeling](https://owasp.org/www-community/Threat_Modeling), [OWASP ASVS](https://asvs.owasp.org/).

## SEC-IDENTITY — Autenticación y sesión
**Estado:** vigente
**Revisión:** 2026-10-29
**Aplica a:** login, tokens, cookies, recuperación y dispositivos.

### Propósito y principio
Credenciales y sesiones tienen ciclo completo: emisión, uso, expiración, rotación y revocación.

### Requisitos
- Contraseñas con algoritmo adaptativo del framework y política basada en riesgo.
- Cookies sensibles `Secure`, `HttpOnly` y `SameSite` adecuado; tokens fuera de URL/logs.
- Renovar identificador tras autenticación/cambio de privilegio.
- Revocar por logout, desactivación, recuperación o sospecha.
- Respuestas de login/recuperación reducen enumeración y tienen anti-automatización.
- Autenticación offline/dispositivo queda bloqueada hasta `GAP-DEVICE`.

### Antipatrones y riesgos
Tokens largos sin revocación, secretos en localStorage por defecto, preguntas de seguridad débiles.

### Evidencia y gates
Tests de robo/reuso/revocación, atributos de cookie/token y eventos de seguridad.

### Excepciones
Almacenamiento cliente diferente requiere threat model y D-XXX.

### Relaciones
`BE-AUTH`, `FE-SESSION`, `SEC-LOG`.

### Fuentes oficiales
[OWASP Authentication Cheat Sheet](https://cheatsheetseries.owasp.org/cheatsheets/Authentication_Cheat_Sheet.html), [Session Management Cheat Sheet](https://cheatsheetseries.owasp.org/cheatsheets/Session_Management_Cheat_Sheet.html).

## SEC-AUTHZ — Autorización y aislamiento
**Estado:** vigente
**Revisión:** 2026-10-29
**Aplica a:** todo recurso y acción protegidos.

### Propósito y principio
Denegar por defecto y autorizar servidor-side por acción, recurso y contexto.

### Requisitos
- Autorización en cada request y job, no sólo en rutas o UI.
- Verificar ownership/alcance después de autenticar.
- IDs no son secretos; códigos públicos requieren riesgo de enumeración evaluado.
- Roles otorgan capacidades mínimas y cambios de privilegio se auditan.
- Aislamiento multiempresa no se diseña hasta `GAP-TENANCY`.

### Antipatrones y riesgos
IDOR/BOLA, filtros opcionales de tenant, admin implícito y ocultar botón como control.

### Evidencia y gates
Tests 401/403 y acceso cruzado por cada recurso; matriz rol-acción.

### Excepciones
Recursos públicos tienen clasificación, campos y rate limit explícitos.

### Relaciones
`BE-API`, `FE-SESSION`, `QA-SEC`.

### Fuentes oficiales
[OWASP Authorization Cheat Sheet](https://cheatsheetseries.owasp.org/cheatsheets/Authorization_Cheat_Sheet.html), [OWASP API Security](https://api-security.owasp.org/).

## SEC-INPUT — Entrada, salida e inyección
**Estado:** vigente
**Revisión:** 2026-10-29
**Aplica a:** HTTP, DB, plantillas, OCR, imports y comandos.

### Propósito y principio
Validar estructura al entrar y codificar según contexto al salir; usar APIs parametrizadas.

### Requisitos
- Allowlist de campos, tipos, tamaño, rango y formato.
- ORM/queries parametrizadas; no concatenar input en SQL, shell o rutas.
- React escapa texto por defecto; HTML crudo exige sanitización especializada y revisión.
- Validar redirects, URLs y nombres de archivo.
- Limitar parsers/imports antes de trabajo intensivo.

### Antipatrones y riesgos
Blacklist, sanitización genérica para todos los contextos y confiar en MIME/nombre enviado.

### Evidencia y gates
Tests de payload límite/malicioso, SAST y revisión de sinks peligrosos.

### Excepciones
Contenido rico aprobado usa librería mantenida, CSP y casos adversariales.

### Relaciones
`BE-VALID`, `FE-FORM`, `SEC-FILE`.

### Fuentes oficiales
[OWASP Input Validation](https://cheatsheetseries.owasp.org/cheatsheets/Input_Validation_Cheat_Sheet.html), [OWASP XSS Prevention](https://cheatsheetseries.owasp.org/cheatsheets/Cross_Site_Scripting_Prevention_Cheat_Sheet.html).

## SEC-BROWSER — Navegador, origen y transporte
**Estado:** vigente
**Revisión:** 2026-10-29
**Aplica a:** SPA/PWA, cookies, CORS, CSRF, CSP y headers.

### Propósito y principio
Usar controles del navegador en conjunto; ninguno compensa autorización ausente.

### Requisitos
- CORS con orígenes/métodos/headers mínimos; nunca reflejar origen sin validación.
- Autenticación por cookie requiere protección CSRF adecuada.
- CSP empieza en report-only, se mide y se endurece sin `unsafe-*` indiscriminado.
- HTTPS y HSTS productivo con alcance probado.
- Headers de cache impiden almacenamiento compartido de respuestas privadas.
- Dependencias de terceros se minimizan y evalúan.

### Antipatrones y riesgos
CORS `*` con credenciales, CSP copiada, secretos confiados a frontend.

### Evidencia y gates
Pruebas cross-origin/CSRF, informe CSP y headers efectivos en edge.

### Excepciones
Origen de desarrollo se permite sólo fuera de producción.

### Relaciones
`DO-EDGE`, `FE-PWA`, `SEC-IDENTITY`.

### Fuentes oficiales
[OWASP CSRF Prevention](https://cheatsheetseries.owasp.org/cheatsheets/Cross-Site_Request_Forgery_Prevention_Cheat_Sheet.html), [MDN CSP](https://developer.mozilla.org/en-US/docs/Web/HTTP/CSP).

## SEC-DATA — Datos, privacidad y criptografía
**Estado:** vigente
**Revisión:** 2026-10-29
**Aplica a:** DB, backups, logs, cache, navegador y storage.

### Propósito y principio
Recolectar y conservar sólo lo necesario, con protección proporcional y ciclo de vida explícito.

### Requisitos
- Clasificar datos, dueño, ubicación, retención y acceso.
- Cifrado en tránsito; cifrado en reposo cuando amenaza/plataforma lo requiera.
- Usar primitivas y librerías mantenidas; claves separadas de datos.
- Cache, exportaciones y backups heredan clasificación.
- Borrado/anonimización debe contemplar copias y obligaciones del producto.

### Antipatrones y riesgos
Criptografía propia, datos indefinidos en logs, backups sin control de acceso.

### Evidencia y gates
Inventario de datos, revisión de acceso/retención y prueba de restauración segura.

### Excepciones
Datos públicos se clasifican explícitamente; no se asumen públicos por ausencia de etiqueta.

### Relaciones
`DO-DR`, `FE-DATA`, `SEC-LOG`.

### Fuentes oficiales
[OWASP Cryptographic Storage](https://cheatsheetseries.owasp.org/cheatsheets/Cryptographic_Storage_Cheat_Sheet.html), [ASVS V14 Data Protection](https://asvs.owasp.org/).

## SEC-FILE — Archivos, imágenes y uploads
**Estado:** vigente
**Revisión:** 2026-10-29
**Aplica a:** fotos, tickets, OCR e importaciones.

### Propósito y principio
Tratar cada archivo como contenido hostil y separar validación, transformación, storage y entrega.

### Requisitos
- Límite de tamaño, dimensión, cantidad y formato antes de procesamiento costoso.
- Verificar contenido real y decodificar/re-encodear imágenes cuando corresponda.
- Nombre generado, storage fuera de ejecución y autorización al descargar.
- Evitar traversal, sobrescritura y referencias a objetos de otro usuario.
- Procesamiento asíncrono tiene timeout y recursos acotados.

### Antipatrones y riesgos
Confiar en extensión/MIME, servir uploads como ejecutables o URL pública predecible sin política.

### Evidencia y gates
Archivos corruptos, polyglot, dimensiones extremas, acceso cruzado y cleanup.

### Excepciones
Publicación intencional exige clasificación y URL/caching definidos.

### Relaciones
`BE-ASYNC`, `FE-RESPONSIVE`, `DO-INFRA`.

### Fuentes oficiales
[OWASP File Upload Cheat Sheet](https://cheatsheetseries.owasp.org/cheatsheets/File_Upload_Cheat_Sheet.html), [ASVS V5 File Handling](https://asvs.owasp.org/).

## SEC-ABUSE — Abuso, disponibilidad y rate limiting
**Estado:** vigente
**Revisión:** 2026-10-29
**Aplica a:** auth, endpoints públicos, búsquedas, OCR, Telegram y recursos caros.

### Propósito y principio
Limitar abuso por identidad, recurso y costo sin bloquear operación legítima.

### Requisitos
- Límites diferenciados por endpoint y actor; respuesta 429 y retry semantics.
- Cuotas para acciones costosas, uploads y jobs.
- Códigos públicos no deben permitir enumeración viable.
- Alertar patrones, no cada rechazo individual.
- Diseñar degradación y límites de concurrencia.

### Antipatrones y riesgos
Un límite global único, confiar sólo en IP y colas sin backpressure.

### Evidencia y gates
Tests de burst/sostenido, costo máximo y recuperación.

### Excepciones
Integraciones confiables usan credenciales y cuota propias, no ausencia de límite.

### Relaciones
`BE-AUTH`, `BE-ASYNC`, `DO-OBS`.

### Fuentes oficiales
[OWASP Denial of Service](https://cheatsheetseries.owasp.org/cheatsheets/Denial_of_Service_Cheat_Sheet.html), [Laravel Rate Limiting](https://laravel.com/docs/12.x/rate-limiting).

## SEC-SECRET — Secretos e integraciones
**Estado:** vigente
**Revisión:** 2026-10-29
**Aplica a:** `.env`, CI, Telegram, OCR, R2 y proveedores.

### Propósito y principio
Un secreto tiene propósito mínimo, dueño, canal seguro, rotación y revocación.

### Requisitos
- Nunca versionar secretos ni incluirlos en imágenes/logs.
- Fallar cerrado cuando un webhook requiere secreto; ausencia de config no desactiva el control silenciosamente.
- Preferir credenciales cortas/federadas cuando la plataforma lo permita.
- Rotar tras exposición y eliminar del historial no reemplaza revocación.
- Validar TLS, timeouts y respuestas de integraciones.

### Antipatrones y riesgos
Secretos compartidos indefinidos, valores default productivos y comparación insegura.

### Evidencia y gates
Secret scan, inventario, prueba de rotación y webhook sin secreto rechazado.

### Excepciones
Claves públicas y valores ficticios deben estar claramente identificados.

### Relaciones
`DO-ENV`, `BE-ASYNC`, `SEC-LOG`.

### Fuentes oficiales
[OWASP Secrets Management](https://cheatsheetseries.owasp.org/cheatsheets/Secrets_Management_Cheat_Sheet.html), [GitHub Secrets](https://docs.github.com/en/actions/security-for-github-actions/security-guides/using-secrets-in-github-actions).

## SEC-SUPPLY — Cadena de suministro
**Estado:** vigente
**Revisión:** 2026-10-29
**Aplica a:** dependencias, CI, imágenes y releases.

### Propósito y principio
Reducir componentes y verificar origen, integridad, mantenimiento y vulnerabilidades.

### Requisitos
- Lockfiles, reviews de upgrades, audits y política de excepción.
- Permisos mínimos de CI y acciones fijadas según riesgo.
- No instalar paquetes sugeridos por input no confiable sin validación.
- Registrar artefacto y dependencias; atestiguar cuando corresponda.
- Remover componentes inactivos de gates/deploy o asumir su mantenimiento.

### Antipatrones y riesgos
Paquetes por conveniencia mínima, scripts postinstall ignorados, acciones flotantes.

### Evidencia y gates
`DO-SUPPLY`, audit limpio/baseline aprobado, revisión de reachability.

### Excepciones
Vulnerabilidad aceptada con vencimiento y mitigación compensatoria.

### Relaciones
`DO-SUPPLY`, `QA-GATE`.

### Fuentes oficiales
[NIST SSDF](https://csrc.nist.gov/pubs/sp/800/218/final), [OpenSSF Scorecard](https://scorecard.dev/).

## SEC-LOG — Logging, auditoría e incidentes
**Estado:** vigente
**Revisión:** 2026-10-29
**Aplica a:** logs técnicos y actividad de negocio.

### Propósito y principio
Registrar evidencia suficiente, íntegra y minimizada para detectar y explicar eventos.

### Requisitos
- Nunca registrar contraseñas, tokens, secretos o cuerpos sensibles completos.
- Eventos de auth, privilegio, acceso denegado y cambios críticos tienen actor, objetivo, resultado y correlación.
- Audit log crítico confirma atómicamente con negocio cuando representa el mismo hecho.
- Logs protegidos de alteración y acceso indebido, con retención definida.
- Incidentes tienen clasificación, contención, recuperación y postmortem sin culpa.

### Antipatrones y riesgos
Audit log como `try/catch` silencioso, PII innecesaria y stack al cliente.

### Evidencia y gates
Tests de redacción/atomicidad, búsqueda por correlación y simulacro de incidente.

### Excepciones
Debug ampliado sólo temporal, controlado y fuera de datos reales.

### Relaciones
`BE-ERR`, `BE-TX`, `DO-OBS`.

### Fuentes oficiales
[OWASP Logging Cheat Sheet](https://cheatsheetseries.owasp.org/cheatsheets/Logging_Cheat_Sheet.html), [NIST Incident Handling](https://csrc.nist.gov/pubs/sp/800/61/r2/final).

## SEC-GATE — Gate de seguridad
**Estado:** vigente
**Revisión:** 2026-10-29
**Aplica a:** cambios que tocan identidad, datos, exposición o dependencias.

### Principio y requisitos
Threat case, tests negativos, revisión de autorización, input/output, secretos, abuso, logging y supply chain. Hallazgos se priorizan por impacto y exposición; excepciones tienen responsable y vencimiento.

### Antipatrones y riesgos
Confiar sólo en scanner o declarar seguridad por ausencia de hallazgos automáticos.

### Evidencia y gates
Matriz ASVS aplicable, pruebas y salida de audits/scans.

### Excepciones
Sólo riesgo aceptado en D-XXX.

### Relaciones
`QA-SEC`, `DO-CI`, `BIBLIAS-GOBERNANZA.md`.

### Fuentes oficiales
[OWASP ASVS 5.0](https://asvs.owasp.org/), [OWASP API Security](https://api-security.owasp.org/).
