# Code Review — Pallet Control
> Revisión completa del estado del código. Actualizado: 2026-05-08 ART (ronda 8: performance — lazy loading, React Query, vendor chunks)

---

## Resumen ejecutivo

La app está en buen estado para un MVP. La arquitectura es sólida, no hay vulnerabilidades críticas obvias,
y los flujos principales funcionan bien. Los problemas encontrados son mayoritariamente de **mantenimiento**
(código duplicado, archivos enormes) y un par de **issues de deuda técnica** (logs de debug en prod,
tokens sin expiración). Nada que bloquee, pero hay cosas que conviene atacar antes de que el sistema
escale en usuarios y datos.

**Categorías:** 🔴 Crítico · 🟡 Importante · 🟢 Menor · 💡 Sugerencia

---

## BACKEND

### 🔴 Lógica `canFinalize` duplicada 5 veces

**Archivos:** `OrderController.php`, `PalletController.php`

La validación de "puede finalizar" está copiada y pegada en:
1. `OrderController::canFinalize()` — calcula si todos los ítems tienen qtys distribuidas
2. `OrderController::finalize()` — repite exactamente el mismo cálculo
3. `OrderController::canFinalizeBatch()` — tercera copia para múltiples órdenes
4. `PalletController::canFinalize()` — lógica equivalente para pallets (bases, fotos, productos)
5. `PalletController::finalize()` — repite la validación antes de finalizar

**Riesgo:** Si cambia una regla de negocio (ej: "se necesitan al menos 2 fotos por base"),
hay que acordarse de cambiarlo en 5 lugares. Ya pasó algo similar (el requisito de
2+ pallets fue agregado en canFinalize pero hay que verificar que finalize también lo valida).

**Solución propuesta:** Extraer a un `OrderService` / `PalletService`:
```php
// app/Services/OrderService.php
public function canFinalize(Order $order): array  // returns ['can' => bool, 'reason' => string|null]
```

**Estado:** ✅ Resuelto — `app/Services/OrderService.php` + `app/Services/PalletService.php`

---

### 🟡 Logs de debug en producción — `PalletBasePhotoController`

**Archivo:** `app/Http/Controllers/Api/PalletBasePhotoController.php` — líneas 27, 43, 116

Hay 3 `Log::info()` con datos de diagnóstico que se escriben en **cada** subida de foto en producción:
- Detalles del request (headers, keys, content-type)
- Información del archivo (nombre, tamaño, mime, pathnames)

Esto es ruido en los logs de Railway y puede exponer metadata innecesaria.
Las líneas 62–67 también devuelven un objeto `debug` en las respuestas de error 422, visible al cliente.

**Solución:** Eliminar los 3 `Log::info()` de diagnóstico. Dejar solo el `Log::error()` del catch final
(que es útil). Quitar el key `'debug'` del JSON de respuesta de error (línea 62).

**Estado:** ✅ Resuelto — logs de diagnóstico eliminados, respuesta 422 limpia, lógica de file-detection simplificada

---

### 🟡 Tokens Sanctum sin expiración

**Archivo:** `config/sanctum.php` línea 51: `'expiration' => null`

Los tokens de acceso nunca expiran. Un token robado (de `localStorage`) es válido para siempre.
En producción con datos reales, esto es un riesgo.

**Opciones:**
- `'expiration' => 10080` → expiran en 7 días (recomendado)
- `'expiration' => 43200` → expiran en 30 días (más laxo)
- Con expiración hay que hacer que el frontend detecte 401 y redirija al login

**Nota:** El frontend actualmente no maneja 401 de forma consistente (si el token vence,
el usuario verá errores raros en lugar de ser redirigido al login).

**Estado:** ✅ Resuelto — `config/sanctum.php` expiration = 14 días (configurable via `SANCTUM_TOKEN_EXPIRATION`)

---

### 🟡 `REGISTRATION_ENABLED` usa `env()` directo en Controller

**Archivo:** `app/Http/Controllers/Api/AuthController.php`

```php
if (! $isFirst && ! env('REGISTRATION_ENABLED', true)) {
```

Laravel recomienda no usar `env()` fuera de los archivos de configuración (cuando la
config está cacheada, `env()` devuelve `null`). Si se corre `php artisan config:cache`
en producción, esta condición siempre usará `true` ignorando el env var.

**Solución:**
```php
// config/app.php
'registration_enabled' => env('REGISTRATION_ENABLED', true),

// AuthController.php
if (! $isFirst && ! config('app.registration_enabled')) {
```

**Estado:** ✅ Resuelto — clave en `config/app.php`, `AuthController` usa `config()`

---

### 🔴 `TicketOcrService.php` — 8 llamadas a `env()` en código de aplicación

**Archivo:** `app/Services/TicketOcrService.php` — líneas 67, 122, 248, 268, 290, 291, 560, 564–566

Cuando se ejecuta `php artisan config:cache` en producción (recomendado en Railway/Docker),
`env()` devuelve `null` en todo el código fuera de `config/`. Esto afecta **directamente
a la lógica de OCR**:

- `env('OCR_PROVIDER', 'tesseract')` → `null` → el provider queda vacío, falla silenciosamente
- `env('AZURE_VISION_ENDPOINT', '')` / `env('AZURE_VISION_KEY', '')` → `null` → Azure OCR no funciona
- `env('TESSERACT_PATH')` → `null` → Tesseract usa el PATH del sistema (puede funcionar o no)
- `env('OCR_FALLBACK_TESSERACT', true)` → `null` → cast a bool = `false` → fallback desactivado

**Riesgo:** En producción con config cacheada, el OCR falla sin error claro. El operador
ve que el escaneo "corre" pero los resultados son vacíos.

**Solución:** Crear las claves en `config/services.php` (o `config/ocr.php`) y leer con `config()`:
```php
// config/ocr.php
return [
    'provider'          => env('OCR_PROVIDER', 'tesseract'),
    'tesseract_path'    => env('TESSERACT_PATH'),
    'fallback'          => env('OCR_FALLBACK_TESSERACT', true),
    'azure_endpoint'    => env('AZURE_VISION_ENDPOINT', ''),
    'azure_key'         => env('AZURE_VISION_KEY', ''),
    'python_bin'        => env('OCR_PYTHON_BIN', 'python'),
    'paddle_lang'       => env('PADDLE_OCR_LANG', 'en'),
    'process_timeout'   => env('OCR_PROCESS_TIMEOUT', 120),
];
// TicketOcrService.php → config('ocr.provider'), config('ocr.azure_key'), etc.
```

**Estado:** ✅ Resuelto — `config/ocr.php` creado, 8 `env()` reemplazados por `config('ocr.*')`

---

### 🟢 `Storage::disk('public')` hardcodeado en TicketOcrService (línea 48)

**Archivo:** `app/Services/TicketOcrService.php` línea 48

```php
$imagePath = Storage::disk('public')->path($photo->path);
```

Esto está dentro del branch `if ($disk === 'public')` — es correcto como está.
Pero es confuso visualmente porque parece que no sigue el patrón disk-agnostico.
Un comentario lo aclararía.

**Estado:** ✅ Correcto — agregar comentario explicativo

---

### 🟢 `debug` key expuesto en respuesta 422 de PalletBasePhotoController

**Archivo:** `app/Http/Controllers/Api/PalletBasePhotoController.php` líneas 62–67

Cuando no se recibe archivo, la respuesta incluye:
```json
{
  "debug": { "has_file": false, "all_files": [], "content_type": "...", "content_length": "..." }
}
```

Esto expone detalles de implementación al cliente. En producción no debería estar.

**Estado:** ✅ Resuelto — eliminado junto con los Log::info, respuesta 422 simplificada

---

### 💡 Sanctum: considerar `stateful` solo si hace falta

Los dominios `stateful` en `config/sanctum.php` probablemente están en los defaults.
En una SPA con Bearer token no se usan cookies, así que la lista de stateful es irrelevante
pero podría expandirse accidentalmente. Verificar que `SANCTUM_STATEFUL_DOMAINS` no esté
en el `.env` de producción a menos que sea necesario.

---

## CÓDIGO MUERTO / FEATURES SIN CONECTAR

### 🟡 `MovementController` + modelo `Movement` — feature abandonado → **eliminar**

**Archivos:** `app/Http/Controllers/Api/MovementController.php`, `app/Models/Movement.php`,
rutas `GET/POST /pallets/{pallet}/movements` en `api.php`

El controller implementa un sistema de movimientos de inventario (ADD/REMOVE/ADJUST) distinto
al "migrar bases" (`PalletBaseController::migrate`) y al "organizar" (`adjustItem`).
Ninguna página del frontend llama a `/movements`. No aparece en ningún workflow.

**Decisión:** eliminar — `MovementController`, `Movement` model, migración, y las dos rutas en `api.php`.

**Estado:** ✅ Eliminado 2026-05-05 19:39 ART — controller, modelo, migración y 2 rutas borrados

---

### 🟢 Telegram — dos piezas activas, falta documentar

**Archivos:**
- `app/Helpers/TelegramNotifier.php` — **saliente**: manda notificaciones al grupo cuando se crea/finaliza un pedido o pallet. Llamado desde `OrderController` y `PalletController`.
- `app/Http/Controllers/Api/TelegramBotController.php` — **entrante**: webhook que recibe fotos con caption `p`/`b`/`t` del bot de Telegram y las adjunta al pallet/base/pedido correspondiente. Mismo protocolo que el bot de WhatsApp.

Ambas piezas están activas. Solo falta documentar en WORKFLOWS.md como Workflow 2b (canal alternativo a WhatsApp).

**Nota sobre el secret:** La comparación en línea 37 del controller usa `!==` en lugar de `hash_equals` para el `X-Telegram-Bot-Api-Secret-Token` — mismo problema que BotSecretMiddleware (ya corregido allí).

**Estado:** ✅ 2026-05-05 20:12 ART — `hash_equals` corregido + Workflow 2b agregado en WORKFLOWS.md

---

### 🟢 `Icons.Link` — ícono definido y nunca usado

**Archivo:** `src/ui/ActionList.jsx` — objeto `Icons` exportado al final del archivo

El ícono `Icons.Link` (SVG de cadena/enlace) está definido en el export de `Icons` pero
ningún componente en el frontend lo importa o usa. Los 8 íconos usados en producción son:
`Import`, `AssignOrder`, `Plus`, `Gallery`, `History`, `Pallet`, `Trash`, `Check`.

**Solución:** Eliminar la entrada `Link` del objeto `Icons`. 3 líneas de código muerto.

**Estado:** ⏳ Trivial — 1 minuto

---

## FRONTEND

### 🔴 `OrderDetail.jsx` — 2302 líneas → refactorizado a 996 ✅ (en progreso)

**Problema original:** Un solo archivo contenía toda la lógica de ítems, pallets, tickets,
OCR, organización en bases y modales inline.

**Extraído hasta ahora:**
- `src/features/tickets/TicketSection.jsx` — TicketCard, AddTicketModal, OcrBadge, OcrTerminal
- `src/features/order-items/ItemCard.jsx` — tarjeta de producto
- `src/features/order-items/ItemActionModal.jsx` — modal de acción sobre un ítem
- `src/hooks/useOrganize.js` — lógica del modal "Organizar en pallet" (10 funciones + 2 estados)
- `src/hooks/useItemAction.js` — lógica de marcar ítems y resolver conflictos qty

**Resultado:** 2302 → 996 líneas. El archivo ahora es un page component normal:
declara qué hooks usa y delega a componentes externos.

**Lo que queda en el archivo:** los modales de "Asociar pallet", "Agregar producto",
"Desvincular pallet" son simples (~60-115 líneas cada uno) — se evaluó y se decidió
que la extracción adicional tiene retorno decreciente.

**Estado:** ✅ Suficientemente refactorizado

---

### ~~🟡 `PalletDetail.jsx` — 1310 líneas~~ ✅ Refactorizado

**Extraído:**
- `src/hooks/useMigrate.js` — lógica completa de migración de bases
- `src/hooks/useBaseMutations.js` — CRUD de bases (crear, editar, eliminar) + form state
- `src/features/pallet-bases/MigrateModal.jsx` — modal de migración (267 líneas originales)
- `src/features/pallet-bases/BaseCard.jsx` — tarjeta de base con links de acción
- `src/features/pallet-bases/BaseFormModal.jsx` — modal crear/editar base
- `OrderChip` convertido de closure inline a componente con props explícitas

**Resultado:** 1310 → ~503 líneas. `handleDeletePallet` bug corregido (usaba `apiPost` en vez de `apiDelete`).

**Estado:** ✅ 2026-05-05 20:45 ART

---

### 🟡 Páginas sobre el umbral de 400 líneas

Según CODE-HEALTH.md, el umbral de alerta para páginas JSX es 400 líneas.

| Página | Líneas | Descripción | Prioridad |
|---|---|---|---|
| ~~`PalletDetail.jsx`~~ | ~~1310~~ → 503 | Refactorizado ✅ | ✅ Hecho |
| `PalletPublicView.jsx` | 763 | Vista pública QR — mucho JSX de overlay de anotaciones | 🟢 Baja |
| `PendingItems.jsx` | 723 | Feature de pendientes — bastante UI inline | 🟢 Baja |
| `BaseGallery.jsx` | 699 | Galería de fotos con anotaciones — compleja pero cohesiva | 🟢 Baja |
| `MyOrders.jsx` | 472 | Lista de órdenes — acepta refactor leve | 🟢 Baja |

`PalletPublicView`, `BaseGallery` y `PendingItems` son grandes pero sus funciones están
bien delimitadas — son candidatos de segunda prioridad después de `PalletDetail`.

**Estado:** 💡 Documentado para próxima ronda de refactor

---

### 🟡 Token en `localStorage` — riesgo XSS

**Archivo:** `src/api/client.js` y `src/auth/AuthContext.jsx`

El token de Sanctum se guarda en `localStorage`. Esto es lo más común en SPAs pero
es vulnerable a XSS (si algún script inyectado lee `localStorage`, roba el token).

La alternativa más segura es `httpOnly cookie`, pero requiere cambiar el backend (CORS,
cookies, Sanctum stateful mode). Es un cambio grande.

**Para este proyecto:** El riesgo es bajo porque:
- No hay contenido de usuario sin sanitizar renderizado
- React escapa por defecto
- No hay inputs que acepten HTML

**Recomendación:** Agregar `Content-Security-Policy` header en Laravel para reducir
la superficie de XSS. No migrar a cookies por ahora (complejidad no justificada).

**Estado:** 💡 Bajo riesgo — considerar CSP header en futuro

---

### 🟡 `Login.jsx` duplica lógica de guardado de token

**Archivos:** `src/pages/Login.jsx` línea 35, `src/auth/AuthContext.jsx` líneas 31 y 51

`Login.jsx` hace `localStorage.setItem("token", res.token)` directamente, además de
llamar a `AuthContext`. Esto significa que hay dos lugares donde se guarda el token.
Si el formato cambia algún día, hay que actualizarlo en ambos lados.

**Solución:** `Login.jsx` debería solo llamar a `login()` del contexto y dejar que
`AuthContext` maneje el storage.

**Estado:** ⏳ Menor — refactor limpio

---

### 🟢 `RequireAuth.jsx` lee localStorage directamente

**Archivo:** `src/auth/RequireAuth.jsx` línea 4

```jsx
const token = localStorage.getItem("token");
```

En lugar de usar el `useAuth()` hook del contexto. Si algún día se cambia el mecanismo
de auth (ej: cookie), hay que acordarse de actualizar este archivo también.

**Solución:** Usar `const { user } = useAuth()` en lugar de leer localStorage.

**Estado:** ⏳ Menor — 5 minutos de refactor

---

### 🟢 Timeouts de fetch: 15s/30s hardcodeados en client.js

**Archivo:** `src/api/client.js` líneas 21–22

Los timeouts están hardcodeados. Está bien para la mayoría de los casos, pero el
timeout de 15s para GETs puede ser justo si el servidor está frío (Railway cold start).
No es un bug, pero conviene documentarlo o hacerlo configurable.

**Estado:** ✅ Aceptable — documentar en CODE-HEALTH.md

---

### 💡 No hay manejo global de errores 401

Si un token expira (cuando se configure expiración), las páginas mostrarán errores
de red en lugar de redirigir al login. Habría que agregar un interceptor en `apiFetch`:

```js
if (!res.ok && res.status === 401) {
  localStorage.removeItem("token");
  window.location.href = "/login";
}
```

**Estado:** ✅ Resuelto — interceptor 401 en `apiFetch`: limpia token y redirige a `/login`

---

## SEGURIDAD

### 🟡 Rate limiting solo en login/register

**Archivo:** `routes/api.php`

`throttle:10,1` (10 req/min por IP) está en login y register. Bien.
Sin embargo, no hay rate limiting en endpoints que consumen recursos costosos:
- `POST /orders/{order}/tickets/{ticket}/photos/{photo}/trigger-ocr` (llama a Azure)
- `POST /orders/{order}/import` (OCR masivo)
- `POST /pallets/{pallet}/photos` (subida de archivos)

Un usuario malintencionado (o un loop accidental) podría hacer muchas llamadas OCR.

**Solución:** Agregar `throttle:20,1` o `throttle:5,1` en las rutas de OCR/import.

**Estado:** ✅ Resuelto — `throttle:10,1` agregado en `trigger-ocr` (Azure tiene costo por request)

---

### 🟡 `X-Bot-Secret` validación — timing attack en dos lugares

**Archivos:** `app/Http/Middleware/BotSecretMiddleware.php` línea 15, `app/Http/Controllers/Api/BotController.php` línea 22

Ambos usan `!==` para comparar el secret, lo que permite timing attacks. Además,
`BotController` duplica la lógica de autenticación que debería estar en el middleware.
La ruta `POST /bot/upload` está registrada **fuera** del grupo `bot.secret` en `api.php`,
así que el middleware no la cubre — el controller hace su propia validación inline.

**Solución:**
```php
// En ambos lugares, reemplazar la comparación directa por:
if (!hash_equals((string) config('services.whatsapp_bot.secret', ''), (string) $request->header('X-Bot-Secret', '')))
```
Y mover `/bot/upload` dentro del grupo `bot.secret` para evitar la duplicación.

**Estado:** ✅ Resuelto — `hash_equals` en `BotSecretMiddleware`, ruta movida dentro del grupo `bot.secret`, auth inline de `BotController` eliminada

---

### 🟢 Sin expiración de tokens en whitelist de rutas públicas

Las rutas públicas (`/public/pallets/{code}`, `/products/by-ean/{ean}`) no tienen
rate limiting. Un scraper podría abusar de `/public/pallets/{code}` para enumerar todos los pallets.
Por ahora con datos internos no es crítico, pero si el sistema crece conviene agregar `throttle`.

**Estado:** 💡 Bajo riesgo por ahora

---

## PERFORMANCE

### ✅ Frontend — code splitting y carga diferida (ronda 8)

**Problema original:** el bundle inicial cargaba Konva (~313kB), html5-qrcode (~335kB) y React/router/query en un solo archivo monolítico de ~299kB.

**Resuelto 2026-05-08:**
- **`PhotoPreview`** extraído de `BaseGallery.jsx` → `src/features/base-gallery/PhotoPreview.jsx` y lazy-loaded con `React.lazy()`. Konva solo baja cuando el usuario abre una foto.
- **`BarcodeScanner`** lazy-loaded en `ProductLookup.jsx`. html5-qrcode solo baja cuando se toca "Escanear con cámara".
- **`loading="lazy"`** en imágenes de `BaseGallery`, `PalletGallery` y `MyPallets`.
- **Vendor chunk splitting** en `vite.config.js`: `react`, `react-router-dom`, `@tanstack/react-query`, `konva/react-konva` y `html5-qrcode` tienen cada uno su propio chunk cacheable.
- **Resultado:** bundle `index` bajó de 299kB → 224kB. `BaseGallery` de ~328kB → 13.5kB. `ProductLookup` de ~338kB → 2.8kB.

**Estado:** ✅ Resuelto — commiteado y desplegado

---

### ✅ Frontend — React Query en todas las páginas de lista (ronda 8)

**Problema original:** `Home`, `MyClients`, `PendingItems`, `AllLogs`, `MyPallets`, `MyOrders` usaban `useState + useEffect` sin caché. Cada navegación de vuelta a la página disparaba un fetch y mostraba un spinner.

**Resuelto 2026-05-08:**
- `MyPallets` y `MyOrders` → `useInfiniteQuery`
- `Home` → `useQuery` (dashboard)
- `MyClients` → `useQuery` para lista + `useQuery` con `enabled` para detalle del cliente
- `AllLogs` → `useInfiniteQuery`
- `PendingItems` → `useQuery` + `queryClient.setQueryData` en mutations (sin refetch extra)
- Caché de 30s activo: datos instantáneos en navegación de vuelta, refetch en background después de 30s.

**Estado:** ✅ Resuelto — commiteado y desplegado

---

### 🟢 Dashboard query de pending units

**Archivo:** `DashboardController.php` líneas 21–31

La query de `pendingUnits` usa un `selectOne` con subquery. Correcta, pero si
`order_items` y `pallet_base_order_items` crecen mucho, puede volverse lenta sin índices.

**Verificar que existan:**
- `order_items.order_id` → índice
- `pallet_base_order_items.order_item_id` → índice

**Estado:** 💡 Verificar en migration o con `EXPLAIN`

---

### 🟢 `OrderController::index` carga `items` de cada orden

**Archivo:** `OrderController.php` línea 21

```php
'items' => fn ($q) => $q->select('id', 'order_id', 'description', 'qty', 'status', 'ean'),
```

En la lista de órdenes se cargan todos los ítems de cada orden. Si una orden tiene
50 ítems y hay 200 órdenes en pantalla, eso es 10.000 filas. La paginación (20 por página)
lo mitiga, pero conviene evaluar si la lista realmente necesita los ítems o solo los contadores.

**Estado:** 💡 Bajo riesgo con paginación activa

---

## DEUDA TÉCNICA

| # | Área | Descripción | Esfuerzo | Prioridad |
|---|------|-------------|----------|-----------|
| 1 | Backend | ~~Extraer lógica canFinalize a Service~~ | ~~Medio~~ | ✅ Hecho |
| 2 | Backend | ~~Eliminar `Log::info` de diagnóstico en `PalletBasePhotoController`~~ | ~~5 min~~ | ✅ Hecho |
| 3 | Backend | ~~**Mover 8 `env()` de `TicketOcrService` a `config/ocr.php`**~~ | ~~30 min~~ | ✅ Hecho |
| 4 | Backend | ~~Mover `REGISTRATION_ENABLED` a config~~ | ~~10 min~~ | ✅ Hecho |
| 5 | Backend | ~~`hash_equals` en `BotSecretMiddleware` + `BotController` (2 lugares)~~ | ~~10 min~~ | ✅ Hecho |
| 6 | Backend | ~~Rate limiting en rutas OCR~~ | ~~15 min~~ | ✅ Hecho |
| 7 | Backend | ~~Configurar expiración de tokens Sanctum~~ | ~~10 min~~ | ✅ Hecho |
| 8 | Backend | ~~Eliminar `MovementController` + `Movement` model + migración + 2 rutas~~ | ~~10 min~~ | ✅ Hecho |
| 9 | Frontend | ~~Refactor `OrderDetail.jsx`~~ — de 2302 a 996 líneas | ~~Alto~~ | ✅ Hecho |
| 10 | Frontend | ~~Manejar 401 en apiFetch (redirect a login)~~ | ~~15 min~~ | ✅ Hecho |
| 11 | Frontend | ~~Refactor `PalletDetail.jsx` (1310 líneas) en módulos~~ | ~~Medio (1 día)~~ | ✅ Hecho |
| 12 | Frontend | ~~`RequireAuth.jsx` usar `useAuth()` en lugar de `localStorage`~~ | ~~5 min~~ | ✅ Hecho |
| 13 | Frontend | ~~`Login.jsx` no guardar token directamente~~ | ~~10 min~~ | ✅ Hecho |
| 14 | Frontend | ~~Eliminar `Icons.Link` (ícono muerto en `ActionList.jsx`)~~ | ~~1 min~~ | ✅ Hecho |
| 15 | Docs + Backend | ~~Documentar Telegram en WORKFLOWS.md (Workflow 2b) + `hash_equals` en `TelegramBotController`~~ | ~~15 min~~ | ✅ Hecho |
| 16 | Backend | ~~Eliminar relaciones `movements()` fantasma en `Pallet`, `Order`, `Product`~~ | ~~5 min~~ | ✅ Hecho |
| 17 | Backend | ~~Eliminar `public/check-php-config.php` (info disclosure en prod)~~ | ~~1 min~~ | ✅ Hecho |
| 18 | Frontend | ~~`PendingItems.jsx` — try/catch faltante en `handleResolve`, `handleReopen`, `handleDelete`~~ | ~~5 min~~ | ✅ Hecho |
| 19 | Docs | ~~`WORKFLOWS.md` endpoints desactualizados (`import-text` → `import`, `assign-item` → `adjust-item`)~~ | ~~5 min~~ | ✅ Hecho |
| 20 | Backend | ~~`OrderItem.product()` — relación `belongsTo(Product)` presupone `product_id` que no existe~~ | ~~20 min~~ | ✅ Hecho |
| 21 | Backend | ~~`PalletBaseController.php` — duplicación `store`/`update` (bloque `allocations`)~~ | ~~Medio~~ | ✅ Hecho |
| 22 | Backend | ~~`OrderController.php` — 3 clases con FQCN inline (`ActivityLogger`, `Pallet`, `ActivityLog`)~~ | ~~5 min~~ | ✅ Hecho |
| 23 | Frontend | ~~`Home.jsx` — error en carga del dashboard swallow silenciosamente (sin toastError)~~ | ~~5 min~~ | ✅ Hecho |
| 24 | Frontend | ~~`Icons.Link` muerto en `ActionList.jsx`~~ | ~~1 min~~ | ✅ Hecho |
| 25 | Frontend | ~~`RequireAuth.jsx` — usa `localStorage` directo, ignoraba estado `booting`~~ | ~~10 min~~ | ✅ Hecho |
| 26 | Frontend | ~~`Login.jsx` — guardaba token directo en `localStorage` en lugar de delegar a `AuthContext.login()`~~ | ~~10 min~~ | ✅ Hecho |

---

## RONDA 3 — Hallazgos nuevos (2026-05-05 20:12 ART)

### 🔴 Relaciones `movements()` fantasma en 3 modelos

**Archivos:** `Pallet.php` línea 18, `Order.php` línea 24, `Product.php` línea 12

Después de eliminar `Movement.php`, los tres modelos seguían teniendo un método `movements()`
que referenciaba `Movement::class`. Si algún código (eager load, relación, tinker) invocaba
la relación, se producía un error fatal de clase no encontrada.

**Estado:** ✅ Eliminado 2026-05-05 20:12 ART — métodos `movements()` borrados de los 3 modelos

---

### 🔴 `public/check-php-config.php` — information disclosure en producción

**Archivo:** `pallet-backend/public/check-php-config.php`

Script de diagnóstico accesible públicamente sin autenticación. Expone versión de PHP,
ruta absoluta del `php.ini`, y límites de configuración del servidor. El propio archivo
advierte que debe eliminarse después de usarlo — nunca se borró.

**Estado:** ✅ Eliminado 2026-05-05 20:12 ART

---

### 🟡 `OrderItem.product()` — relación rota silenciosamente

**Archivo:** `app/Models/OrderItem.php` línea 38

```php
public function product()
{
    return $this->belongsTo(Product::class);
}
```

`belongsTo(Product::class)` busca la columna `product_id` en la tabla `order_items`.
Esa columna **no existe en ninguna migración** — los ítems se relacionan con productos
por `ean`, no por FK. La relación devuelve siempre `null` sin lanzar error.

Verificar si algún eager load usa `with('product')` — si lo hace, carga datos vacíos
sin que nadie se dé cuenta.

**Solución:** O agregar una FK `product_id` a `order_items` (más correcto relacionalmente),
o cambiar la relación a `hasOne` con `localKey: 'ean', foreignKey: 'ean'`, o eliminar
el método si no se usa en ningún lado.

**Estado:** ✅ Resuelto 2026-05-05 20:16 ART — relación reemplazada por `hasOne(Product::class, 'ean', 'ean')` para buscar por EAN en lugar de `product_id` inexistente

---

### 🟡 `WORKFLOWS.md` — endpoints desactualizados

**Archivo:** `WORKFLOWS.md` pasos 4 y 5 del Workflow 1

- Paso 4 decía `POST /orders/{order}/import-text` → correcto es `POST /orders/{order}/import`
- Paso 5 decía `POST .../assign-item` → correcto es `PATCH .../adjust-item`

**Estado:** ✅ Corregido 2026-05-05 20:12 ART

---

### 🟢 `PendingItems.jsx` — sin manejo de error en mutaciones

**Archivo:** `src/pages/PendingItems.jsx` líneas 558, 566, 574

`handleResolve`, `handleReopen` y `handleDelete` no tenían `try/catch`. Si la API fallaba,
el estado no se actualizaba y el usuario no veía ningún mensaje de error.

**Estado:** ✅ Corregido 2026-05-05 20:12 ART — try/catch + toastError agregados

---

## PREGUNTAS PARA EL USUARIO

---

## COSAS QUE ESTÁN BIEN ✅

- Arquitectura RESTful limpia y consistente
- Paginación en `/orders` y `/pallets` (evita cargar todo)
- Dashboard endpoint consolidado (1 request en lugar de 3)
- Storage disk-agnostico (`config('filesystems.default', 'public')`) — R2 listo
- Activity logging en todas las acciones importantes
- Middleware `has.role` bien diseñado (GET libre, escritura requiere rol)
- Middleware `admin` con soporte para `superadmin` diferenciado
- ImageConverter centralizado (WebP, max 4000×4000, orientación auto)
- Temp file cleanup en TicketOcrService (con try/finally implícito)
- `ResetForProduction` command seguro (requiere `--confirm`, orden FK-safe)
- Throttle en login/register
- `REGISTRATION_ENABLED` gate para producción
- Telegram notifications en acciones clave
- Frontend: dark mode, PWA, URL-driven filters (`useSearchParams`)
- Sin N+1 queries obvios en los controllers principales (eager loading correcto)

---

## 📋 Historial de cambios

| Fecha/Hora ART | Qué se hizo |
|---|---|
| 2026-05-05 | Ronda 1: refactor `OrderDetail.jsx` 2302→996 líneas (hooks `useOrganize`, `useItemAction`; componente `ItemActionModal`) |
| 2026-05-05 | Ronda 2: code review completo vs CODE-HEALTH.md + WORKFLOWS.md |
| 2026-05-05 19:xx | Fix: `config/ocr.php` + 8 `env()` en `TicketOcrService` reemplazados por `config()` |
| 2026-05-05 19:xx | Fix: `PalletBasePhotoController` — 3 `Log::info` eliminados, respuesta 422 limpia |
| 2026-05-05 19:xx | Fix: `REGISTRATION_ENABLED` movido a `config/app.php` |
| 2026-05-05 19:xx | Fix: `hash_equals` en `BotSecretMiddleware`; ruta `/bot/upload` movida al grupo `bot.secret`; auth duplicada en `BotController` eliminada |
| 2026-05-05 19:xx | Fix: `throttle:10,1` en ruta `trigger-ocr` (Azure cobra por request) |
| 2026-05-05 19:xx | Fix: `config/sanctum.php` expiración 14 días (`SANCTUM_TOKEN_EXPIRATION`) |
| 2026-05-05 19:xx | Fix: interceptor 401 en `apiFetch` — limpia token y redirige a `/login` |
| 2026-05-05 19:39 | Decisión: `MovementController` eliminado (dead code, no conectado al frontend) |
| 2026-05-05 19:39 | Decisión: `TelegramBotController` activo (bot entrante de fotos) + `TelegramNotifier` (saliente) — documentar en WORKFLOWS.md |
| 2026-05-05 20:12 | Ronda 3: eliminadas relaciones `movements()` fantasma en 3 modelos; eliminado `check-php-config.php`; WORKFLOWS.md corregido (2 endpoints); try/catch en PendingItems.jsx; Workflow 2b (Telegram) documentado |
| 2026-05-05 20:16 | Ronda 4: `OrderItem.product()` corregida (ean→ean en lugar de product_id); `Home.jsx` toastError; `Icons.Link` eliminado; `RequireAuth` usa `useAuth()` + maneja booting; `Login.jsx` delega a `AuthContext.login()` |
| 2026-05-05 20:45 | Refactor `PalletDetail.jsx` 1310→503 líneas: 5 extracciones (useMigrate, useBaseMutations, MigrateModal, BaseCard, BaseFormModal); bug `handleDeletePallet` corregido (`apiPost→apiDelete`) |
| 2026-05-06 | Refactor `PalletBaseController.php`: extraídos `fetchAllocations()` + `fetchOrderData()` (query duplicada eliminada de store/update); import `Storage` muerto removido |
| 2026-05-06 | Refactor `OrderController.php`: 3 FQCNs inline (`ActivityLogger`, `Pallet`, `ActivityLog`) movidos a `use` statements |
| 2026-05-06 | Ronda 7: Feature "Mapa de distribución" completo — `OrderTicketController::photoHighlights`, ruta registrada, `HighlightOverlay.jsx` (zoom/pan/pinch, modal mobile centrado, dark/light mode), `TicketSection.jsx` botón "🗺 Mapa" con guard `highlights_ready`, `OrderDetail.jsx` prop passthrough. `PublicPalletController` + `TicketOcrService::buildHighlights` extendidos con `qty_order_total` para mostrar "X de Y u." en public view. |
| 2026-05-06 | Fix dark mode `PalletPublicView`: usaba clave `"pallet-theme"` en lugar de `"theme"` (misma que `ThemeContext`) → inicialización desfasada, requería 2 clics para cambiar modo. Corregido leyendo localStorage con clave unificada `"theme"`. |
| 2026-05-08 | Ronda 8 — Performance: lazy-load `PhotoPreview` (Konva) extraído de `BaseGallery.jsx`; lazy-load `BarcodeScanner` en `ProductLookup.jsx`; `loading="lazy"` en galerías; vendor chunk splitting (react/router/query/konva/scanner). Bundle index 299kB→224kB. |
| 2026-05-08 | Ronda 8 — React Query: migración de `Home`, `MyClients`, `PendingItems`, `AllLogs` (+ `MyPallets`, `MyOrders` ya migradas). Caché 30s, navegación sin spinner, mutations con `setQueryData`. |
