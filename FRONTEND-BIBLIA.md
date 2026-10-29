# Biblia Frontend

**Autoridad:** arquitectura y experiencia técnica del cliente web
**Estado:** vigente
**Revisión de todas las secciones:** 2026-10-29
**Stack aplicado:** React 19, Vite 7, React Router 7, TanStack Query 5, Tailwind 4

## FE-ARCH — Arquitectura por feature
**Estado:** vigente
**Revisión:** 2026-10-29
**Aplica a:** `pallet-frontend/src`.

### Propósito y principio
Organizar por capacidad de producto y mantener dependencias dirigidas hacia utilidades compartidas estables.

### Requisitos
- Páginas coordinan rutas; features contienen comportamiento; `ui/` contiene piezas compartidas sin negocio.
- Acceso HTTP pasa por `src/api/client.js` y hooks/adaptadores, no por `fetch` disperso.
- Un componente debe tener responsabilidad identificable; extraer por cohesión, no por cantidad arbitraria de líneas.
- Lazy-load de rutas/capacidades pesadas cuando reduzca trabajo inicial medido.
- Evitar ciclos entre features y barriles que oculten dependencias.

### Antipatrones y riesgos
Componentes “Dios”, lógica duplicada, estado remoto copiado a múltiples contextos y abstracciones sin dos usos reales.

### Evidencia y gates
Mapa de dependencias revisable, ESLint y build; límites cubiertos por tests `QA-LEVELS`.

### Excepciones
Un componente local pequeño puede permanecer junto a su página.

### Relaciones
`FE-STATE`, `FE-DATA`, `QA-STATIC`.

### Fuentes oficiales
[React Thinking in React](https://react.dev/learn/thinking-in-react), [Vite Features](https://vite.dev/guide/features).

## FE-STATE — Propiedad del estado
**Estado:** vigente
**Revisión:** 2026-10-29
**Aplica a:** estado local, remoto, formulario y URL.

### Propósito y principio
Cada dato tiene una única fuente de verdad y vive en el nivel mínimo que necesita compartirlo.

### Requisitos
- Estado remoto pertenece a TanStack Query; no duplicarlo en Context salvo identidad derivada mínima.
- Filtros, página y selección compartible deben evaluarse como estado de URL.
- Estado efímero de UI permanece local.
- No guardar como estado lo que pueda derivarse durante render.
- Reducers o máquinas de estado se justifican cuando hay transiciones complejas, no por preferencia.

### Antipatrones y riesgos
Estado redundante/contradictorio, sincronizar props con effects y contextos globales para datos locales.

### Evidencia y gates
Tests de transición, navegación atrás/adelante y ausencia de flashes por estado duplicado.

### Excepciones
Snapshots editables pueden copiar datos remotos si existe reconciliación explícita.

### Relaciones
`FE-DATA`, `FE-FORM`, `QA-LEVELS`.

### Fuentes oficiales
[React Choosing State Structure](https://react.dev/learn/choosing-the-state-structure), [React Sharing State](https://react.dev/learn/sharing-state-between-components).

## FE-DATA — Lecturas, cache y estados remotos
**Estado:** vigente
**Revisión:** 2026-10-29
**Aplica a:** queries y representación de respuestas API.

### Propósito y principio
Mostrar datos con frescura y ownership explícitos, sin confundir cache con persistencia offline.

### Requisitos
- Query keys incluyen identidad suficiente del recurso y filtros; identidad de cuenta/tenant cuando aplique.
- Definir `staleTime`, reintentos e invalidación desde semántica, no globalmente por comodidad.
- Toda pantalla remota contempla carga inicial, refresh, vacío, error recuperable y datos obsoletos.
- Cancelar o ignorar respuestas obsoletas cuando una búsqueda cambia.
- Al cerrar sesión, cancelar y remover cache privado.
- Respuestas autenticadas no deben persistirse en service worker como sustituto de un diseño offline.

### Antipatrones y riesgos
Query keys incompletas, cache compartido entre usuarios, spinner eterno, toast como única representación del error.

### Evidencia y gates
Tests de estados, invalidación y cambio de usuario; inspección de headers/cache y throttling de red.

### Excepciones
Datos públicos inmutables pueden usar cache persistente con versión y expiración.

### Relaciones
`BE-API`, `BE-ERR`, `SEC-DATA`, `FE-PWA`.

### Fuentes oficiales
[TanStack Query Important Defaults](https://tanstack.com/query/latest/docs/framework/react/guides/important-defaults), [Query Invalidation](https://tanstack.com/query/latest/docs/framework/react/guides/query-invalidation).

## FE-MUTATION — Escrituras y conflictos
**Estado:** vigente
**Revisión:** 2026-10-29
**Aplica a:** create/update/delete, uploads y acciones de stock.

### Propósito y principio
Una escritura muestra progreso, evita repetición accidental y reconcilia siempre con la autoridad del servidor.

### Requisitos
- Deshabilitar o deduplicar envíos equivalentes mientras estén pendientes.
- Representar éxito, error y recuperación cerca de la acción.
- UI optimista sólo con snapshot, rollback y reconciliación; no usarla en invariantes críticas sin diseño aprobado.
- 409 se presenta como conflicto accionable, no como error genérico.
- Invalidar o actualizar exactamente los recursos afectados.
- Uploads muestran progreso/estado cuando su duración lo justifica.

### Antipatrones y riesgos
Actualizar UI sin rollback, asumir éxito por status visual y reintentar mutaciones no idempotentes a ciegas.

### Evidencia y gates
Tests de doble clic, fallo, rollback, 409 y respuesta tardía.

### Excepciones
Acciones naturalmente idempotentes pueden reintentarse según contrato.

### Relaciones
`BE-TX`, `BE-ERR`, `QA-INVARIANT`, futuro `GAP-OFFLINE`.

### Fuentes oficiales
[TanStack Query Mutations](https://tanstack.com/query/latest/docs/framework/react/guides/mutations), [Optimistic Updates](https://tanstack.com/query/latest/docs/framework/react/guides/optimistic-updates).

## FE-FORM — Formularios y feedback
**Estado:** vigente
**Revisión:** 2026-10-29
**Aplica a:** captura y edición de datos.

### Propósito y principio
La validación del cliente reduce fricción; el servidor conserva autoridad.

### Requisitos
- Label y ayuda asociados programáticamente; errores vinculados al campo y resumen cuando corresponda.
- Preservar entrada ante error recuperable.
- Diferenciar formato inválido, conflicto de negocio y fallo técnico.
- Foco en primer error y orden de teclado lógico.
- Confirmar acciones destructivas proporcionalmente al impacto.
- No bloquear pegar, gestores de contraseña o tecnologías de asistencia.

### Antipatrones y riesgos
Placeholder como label, borrar formulario tras error y validación sólo por color.

### Evidencia y gates
Teclado completo, lector semántico, tests de validación y errores 422/409.

### Excepciones
Controles nativos pueden aportar validación básica sin reemplazar el contrato del servidor.

### Relaciones
`BE-VALID`, `FE-A11Y`, `SEC-INPUT`.

### Fuentes oficiales
[W3C Forms Tutorial](https://www.w3.org/WAI/tutorials/forms/), [HTML Form Validation](https://developer.mozilla.org/en-US/docs/Learn_web_development/Extensions/Forms/Form_validation).

## FE-SESSION — Routing, sesión y permisos visuales
**Estado:** vigente
**Revisión:** 2026-10-29
**Aplica a:** rutas protegidas, login/logout y menús por rol.

### Propósito y principio
La UI guía al usuario, pero nunca constituye autorización.

### Requisitos
- Rutas protegidas esperan resolución de sesión antes de renderizar contenido privado.
- Logout limpia token, cache, estado sensible y navegación anterior cuando corresponda.
- Ocultar controles no reemplaza verificación backend.
- 401 termina sesión de forma consistente; 403 explica falta de permiso sin revelar datos.
- Redirecciones posteriores al login sólo aceptan destinos internos permitidos.

### Antipatrones y riesgos
Flash de contenido privado, token expuesto en URL y confiar en role del cliente.

### Evidencia y gates
Tests de navegación anónima, expiración, logout y 403.

### Excepciones
Rutas públicas se declaran explícitamente.

### Relaciones
`BE-AUTH`, `SEC-IDENTITY`, `SEC-AUTHZ`.

### Fuentes oficiales
[React Router](https://reactrouter.com/start/declarative/routing), [OWASP ASVS](https://asvs.owasp.org/).

## FE-A11Y — Accesibilidad e interacción
**Aplica a:** toda interfaz, incluida canvas y scanner.

### Propósito y principio
Objetivo WCAG 2.2 nivel AA para flujos principales; automatización complementa revisión manual.

### Requisitos
- HTML semántico antes que roles ARIA; nombre, rol y estado accesibles.
- Operación por teclado, foco visible y foco gestionado en modal/navegación.
- Contraste, zoom/reflow y objetivos táctiles adecuados.
- Mensajes importantes anunciables sin secuestrar foco.
- Canvas, imágenes y scanner ofrecen alternativa textual/operable para la tarea esencial.
- Movimiento respeta preferencias de reducción.

### Antipatrones y riesgos
`div` clickeable, foco atrapado/perdido, información sólo por color o gesto.

### Evidencia y gates
axe/linter, recorrido sólo teclado, zoom 200/400 %, lector de pantalla en flujos críticos.

### Excepciones
Una limitación de librería requiere alternativa equivalente y hallazgo documentado.

### Relaciones
`QA-A11Y`, `FE-FORM`, `FE-UI`.

### Fuentes oficiales
[WCAG 2.2](https://www.w3.org/TR/WCAG22/), [ARIA Authoring Practices](https://www.w3.org/WAI/ARIA/apg/).

## FE-RESPONSIVE — Dispositivos, scanner, canvas e imágenes
**Estado:** vigente
**Revisión:** 2026-10-29
**Aplica a:** desktop, touch y captura visual web.

### Propósito y principio
Diseñar por capacidades y espacio disponible, no por nombres de dispositivos.

### Requisitos
- Flujos críticos funcionan con viewport pequeño, touch y mouse/teclado.
- Permisos de cámara tienen explicación, fallback y recuperación.
- Detener streams y listeners al desmontar.
- Imágenes se dimensionan/comprimen sin ocultar límites del servidor.
- Canvas conserva coordenadas consistentes al escalar y evita trabajo pesado innecesario.
- No asumir orientación fija salvo requerimiento de producto probado.

### Antipatrones y riesgos
Breakpoints por modelo, cámara activa en background, bitmap completo para miniaturas.

### Evidencia y gates
Matriz de viewports/navegadores, permisos rechazados, cámara ausente y archivos límite.

### Excepciones
Una capacidad sin fallback debe declararse requisito del dispositivo.

### Relaciones
`FE-PERF`, `SEC-FILE`, `QA-COMPAT`.

### Fuentes oficiales
[MDN MediaDevices](https://developer.mozilla.org/en-US/docs/Web/API/MediaDevices), [Responsive Images](https://developer.mozilla.org/en-US/docs/Learn_web_development/Extensions/Performance/Multimedia).

## FE-PERF — Rendimiento percibido y carga
**Estado:** vigente
**Revisión:** 2026-10-29
**Aplica a:** bundle, render, red e imágenes.

### Propósito y principio
Medir experiencia real y optimizar el cuello de botella sin degradar claridad.

### Requisitos
- Definir presupuesto para bundle inicial, Core Web Vitals y rutas críticas.
- Dividir capacidades pesadas por ruta/uso; precargar sólo con intención.
- Evitar waterfalls mediante composición de queries/prefetch medido.
- Virtualizar o paginar listas grandes según UX.
- Usar memoización sólo ante costo/re-render medido.
- Medir red lenta y equipos modestos, no sólo localhost.

### Antipatrones y riesgos
Memoización universal, bundle único, imágenes sobredimensionadas y métricas sin escenario.

### Evidencia y gates
Build report, Lighthouse/lab reproducible, Web Vitals reales cuando estén disponibles, comparación antes/después.

### Excepciones
Cambios pequeños pueden usar presupuesto de build sin estudio completo.

### Relaciones
`BE-PERF`, `QA-PERF`, `DO-OBS`.

### Fuentes oficiales
[web.dev Web Vitals](https://web.dev/articles/vitals), [Vite Performance](https://vite.dev/guide/performance), [React Performance Tracks](https://react.dev/reference/dev-tools/react-performance-tracks).

## FE-PWA — Instalación, service worker y cache
**Estado:** vigente
**Revisión:** 2026-10-29
**Aplica a:** manifest, Workbox y actualización.

### Propósito y principio
La PWA mejora instalación y resiliencia del shell; no promete offline de negocio sin protocolo de datos.

### Requisitos
- Manifest, iconos, scope y start URL coherentes con deploy.
- Versionar assets y definir estrategia de actualización visible/segura.
- Precaching sólo del shell necesario; runtime cache por clasificación de dato.
- No cachear respuestas privadas indiscriminadamente ni mezclar usuarios.
- Probar primera visita, actualización, offline del shell y limpieza de caches antiguas.
- No implementar escrituras offline hasta aprobar `GAP-OFFLINE`.

### Antipatrones y riesgos
`NetworkFirst` global para API autenticada, service worker que sirve contrato viejo y “instalable” presentado como “offline completo”.

### Evidencia y gates
Auditoría de cache, DevTools offline/update y logout sin datos privados recuperables.

### Excepciones
Recursos públicos e inmutables pueden usar CacheFirst con expiración/cota.

### Relaciones
`SEC-DATA`, `DO-EDGE`, futuro `GAP-OFFLINE`.

### Fuentes oficiales
[web.dev PWA](https://web.dev/learn/pwa/), [Workbox Strategies](https://developer.chrome.com/docs/workbox/modules/workbox-strategies).

## FE-UI — Sistema visual y feedback
**Estado:** vigente
**Revisión:** 2026-10-29
**Aplica a:** componentes compartidos, estados y lenguaje visual.

### Propósito y principio
Patrones iguales deben verse y comportarse igual; el feedback explica estado y próxima acción.

### Requisitos
- Tokens para color, espaciado, tipografía y capas; componentes compartidos para interacciones repetidas.
- Loading bloqueante sólo cuando la acción realmente lo sea.
- Toast complementa, no reemplaza, estado persistente o error de campo.
- Confirmaciones y severidad coherentes.
- Copia breve, específica y orientada a recuperación.

### Antipatrones y riesgos
Valores mágicos repetidos, z-index arbitrario, éxito visual antes de confirmar servidor.

### Evidencia y gates
Revisión visual responsive, contraste y catálogo de estados críticos.

### Excepciones
Una pantalla especializada puede extender el sistema sin redefinir patrones básicos.

### Relaciones
`FE-A11Y`, `FE-MUTATION`, `PRODUCTO.md`.

### Fuentes oficiales
[W3C Design Systems Accessibility](https://www.w3.org/WAI/ARIA/apg/), [Tailwind Theme Variables](https://tailwindcss.com/docs/theme).

## FE-GATE — Gates frontend
**Estado:** vigente
**Revisión:** 2026-10-29
**Aplica a:** cambios en `pallet-frontend/`.

### Principio y requisitos
Ejecutar ESLint, build, audit de dependencias y pruebas definidas en Quality. Revisar estados UI, teclado/accesibilidad, responsive y presupuesto de performance proporcional al cambio.

### Antipatrones y riesgos
Aceptar build como prueba funcional o revisar sólo el camino feliz.

### Evidencia y gates
Salida automatizada, matriz manual cuando aplique y capturas/mediciones reproducibles.

### Excepciones
Gate ausente se registra como hallazgo con responsable y vencimiento.

### Relaciones
Toda `QUALITY-BIBLIA.md`, `SEC-BROWSER`, `DO-CI`.

### Fuentes oficiales
[Vite Building for Production](https://vite.dev/guide/build), [React Learn](https://react.dev/learn).
