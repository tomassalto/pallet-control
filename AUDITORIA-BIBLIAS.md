# Auditoría de las biblias

**Fecha:** 2026-10-29
**Alcance:** biblias originales y sistema documental asociado.

## Resultado ejecutivo

Las biblias originales aportaban un buen mapa educativo, pero mezclaban teoría, tutorial, portfolio, herramientas coyunturales y reglas no verificables. Tenían pocas fuentes oficiales y Backend concentraba temas que ahora pertenecen a Security y Quality.

## Matriz de tratamiento

| Área original | Conserva | Corrige | Mueve | Elimina | Fuente | GAP | Destino/resultado |
|---|:---:|:---:|:---:|:---:|:---:|:---:|---|
| Auth | ✓ | ✓ | seguridad transversal | — | ✓ | — | `BE-AUTH`, `SEC-IDENTITY` |
| Validación | ✓ | ✓ | seguridad de input | — | ✓ | — | `BE-VALID`, `SEC-INPUT` |
| Errores/API | ✓ | ✓ | logging seguro/contrato | — | ✓ | — | `BE-ERR`, `BE-API`, `QA-CONTRACT` |
| Persistencia | ✓ | ✓ | estrategia de prueba | — | ✓ | — | `BE-DATA`, `QA-DATA` |
| Transacciones | ✓ | ✓ | concurrencia de prueba | — | ✓ | — | `BE-TX`, `QA-INVARIANT` |
| Testing general | — | ✓ | ✓ | — | ✓ | — | `QUALITY-BIBLIA.md` |
| Seguridad mínima | — | ✓ | ✓ | — | ✓ | — | `SECURITY-BIBLIA.md` |
| Performance backend | ✓ | ✓ | presupuesto/evidencia | CV | ✓ | — | `BE-PERF`, `QA-PERF` |
| Docker/configuración | ✓ | ✓ | secretos/supply chain | — | ✓ | — | `DO-ENV`, `DO-CONTAINER` |
| CI/CD/dependencias | ✓ | ✓ | seguridad y quality | — | ✓ | — | `DO-CI`, `DO-SUPPLY` |
| Proveedores/precios | criterios | ✓ | — | precios fijos | ✓ | — | `DO-INFRA` |
| Proxy/TLS | ✓ | ✓ | navegador | snippets universales | ✓ | — | `DO-EDGE`, `SEC-BROWSER` |
| Deploy/migraciones | ✓ | ✓ | resiliencia | — | ✓ | — | `DO-RELEASE`, `QA-RESILIENCE` |
| Observabilidad/backups | ✓ | ✓ | logging/datos | proveedor obligatorio | ✓ | — | `DO-OBS`, `DO-DR` |
| Kubernetes/portfolio | — | — | — | ✓ | — | — | fuera del núcleo actual |
| Frontend | — | nueva autoridad | — | — | ✓ | — | `FRONTEND-BIBLIA.md` |
| Offline/escritorio/mobile/tenancy/device | — | — | — | — | pendiente | ✓ | GAP registrados, sin teoría aún |

## Correcciones destacadas

- Un JWT puede participar de estrategias de revocación; no corresponde afirmar simplemente que “no se puede revocar”.
- Toda colección no acotada necesita límites explícitos; eso no implica una única paginación para todos los casos.
- Un `CHECK` no expresa por sí solo invariantes agregadas entre varias filas; esas invariantes requieren diseño adicional.
- La misma imagen no tiene que ejecutar herramientas de desarrollo en producción; sí debe promoverse un artefacto reproducible.
- Un deploy “rolling” no garantiza cero downtime sin readiness, compatibilidad y coordinación de migraciones.
- Liveness no debe depender de todas las dependencias; readiness sí puede reflejar capacidad de servir tráfico.
- CSP, CORS y cache de datos autenticados necesitan políticas adaptadas al sistema, no snippets universales.

## Fuentes rectoras incorporadas

- Laravel 12, React, TanStack Query y Vite para el stack.
- OWASP ASVS 5.0 y OWASP API Security para controles verificables.
- NIST SSDF 1.1 para desarrollo seguro.
- W3C WCAG 2.2 para accesibilidad.
- Docker y GitHub Actions para entrega y supply chain.

## Resultado

La auditoría se materializa en cinco biblias con propiedad canónica, IDs estables, fuentes fechadas y gates. Los gaps Offline, Desktop, Mobile, Tenancy y Device quedan registrados pero no desarrollados en esta fase.
