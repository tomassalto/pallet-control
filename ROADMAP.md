# Pallet Control — Roadmap

Formato: [estado] Tarea — notas

Estados: [ ] pendiente | [~] en progreso | [x] hecho | [!] bloqueado

---

## 🚀 Alta prioridad

[x] **Cloudflare R2 — persistencia de imágenes**
    Integrado. Disco `r2` en config/filesystems.php (driver s3, use_path_style_endpoint=true).
    ImageConverter, todos los modelos (url accessor), todos los controllers y
    PhotoUploadService usan config('filesystems.default'). TicketOcrService
    descarga temp file desde R2 antes de correr OCR local.
    En Railway: FILESYSTEM_DISK=r2 + vars R2_* en el dashboard.

[x] **My Pallets / My Orders — UI más intuitiva en las cards**
    Cards expandibles con animación CSS smooth. Barra de progreso real
    (unidades en bases / total). Imágenes de productos via EAN lookup.
    Fotos de pallets en sección expandible. Último pallet + último pedido en home.
    Botón "Finalizar pedido" con estética ActionItem (ya no solid green).

---

## 🧪 Testing pendiente

[x] **Verificar highlights OCR con qty parcial en pallet**
    RESULTADO: CORRECTO. El highlight muestra qty de las bases del pallet,
    no el total del pedido. Ej: QUILMES 96/192 → muestra 96. PICADILLO 12/24 → muestra 12.
    Testeado via API el 2026-05-05 con pedido TEST-ANTARTIDA-001 / PAL-20260504-0001.

---

## 🛠️ Media prioridad

[x] **Performance — lazy loading + React Query + vendor chunks** _(2026-05-08)_
    - `PhotoPreview` (Konva ~313kB) extraído de `BaseGallery` y lazy-loaded
    - `BarcodeScanner` (html5-qrcode ~335kB) lazy-loaded en `ProductLookup`
    - `loading="lazy"` en imágenes de galería (BaseGallery, PalletGallery, MyPallets)
    - Vendor chunk splitting en Vite: react / router / query / konva / scanner
    - Bundle `index` 299kB → 224kB. `BaseGallery` 328kB → 13.5kB. `ProductLookup` 338kB → 2.8kB.
    - React Query en todas las páginas: Home, MyClients, PendingItems, AllLogs, MyPallets, MyOrders
    - Caché 30s: navegación sin spinner, mutations sin refetch extra

[ ] **Prefetch de rutas en el sidebar**
    Al hacer hover sobre un link del sidebar, pre-cargar el chunk JS de esa página con `import()`.
    Cero trabajo de backend. Mejora percepción de velocidad en primera navegación a cada ruta.
    Implementación: `onMouseEnter` en cada `<Link>` del sidebar llama `import('../pages/X.jsx')`.

[ ] **OrderDetail — reducir chunk de 92kB**
    Es la página más pesada después de los vendors. Revisar si `PhotoAnnotator` tiene deps
    pesadas que ya están en `vendor-konva` (y si es así, el peso real puede ser menor de lo que parece).
    Evaluar extraer el modal de organización en base como lazy si pesa demasiado.

[x] **OCR Highlights — mapa de distribución del pedido**

    **Concepto:** Vista final de auditoría que muestra la foto del ticket con cada producto
    coloreado según en qué pallet está. Confirma visualmente cómo quedó repartido el pedido.

    **No requiere OCR nuevo:** usa el `ocr_data` ya guardado (EAN + bbox) cruzado con
    la distribución actual en `pallet_base_order_items`. Solo nuevo endpoint + UI.

    ---

    **Precondiciones para que aparezca el botón (OCULTO hasta cumplirse ambas):**

    1. **Todas las unidades de todos los ítems del pedido están organizadas en bases.**
       Es decir: `qty_distributed == qty_order` para cada `order_item`.
       (Misma lógica que `OrderService::canFinalize()`, sin necesidad de que el pedido esté finalizado.)

    2. **Hay 2 o más pallets distintos con unidades de este pedido en sus bases.**
       Si hay un solo pallet, la vista pública QR ya cumple esa función — no tiene sentido duplicarla.

    Si no se cumplen ambas condiciones, el botón directamente no se renderiza.
    Cuando aparece, el usuario sabe que el mapa siempre está "limpio" y completo.

    Como consecuencia de la precondición, en esta vista **nunca puede haber**:
    - Productos con 0 unidades distribuidas
    - Bordes punteados o estados "incompleto"
    - El estado "sin asignar" simplemente no existe aquí

    ---

    **Sistema visual — un solo sistema de color, tres estados posibles:**

    | Situación | Visual | Label en overlay |
    |-----------|--------|-----------------|
    | Todo el producto en un solo pallet | Borde sólido color del pallet | "24 u." |
    | Producto dividido entre 2+ pallets | Borde sólido color neutro (gris oscuro) | "A:6 · B:18 u." |
    | EAN detectado por OCR, no pertenece a este pedido | Borde gris tenue, sin relleno | "No en pedido" |

    No hay bordes punteados, no hay rojo/ámbar/verde. Un sistema, tres casos.

    ---

    **Leyenda — lo más descriptiva posible:**

    Debajo de la foto, panel con cada pallet del pedido:

    ```
    🔵 PAL-20260501-0001
         3 bases · 8 productos · 192 u. de este pedido

    🟠 PAL-20260501-0003
         2 bases · 5 productos · 96 u. de este pedido

    ◈  Dividido entre pallets
         El producto está repartido entre 2 o más pallets.
         Tocá el highlight para ver el desglose exacto.

    ░  Detectado por OCR — no pertenece a este pedido
         El OCR encontró este código de barras en la foto
         pero no forma parte de los ítems de este pedido.
    ```

    Tap en cualquier highlight → bottom sheet con desglose completo:
    ```
    QUILMES 473ml  ·  EAN 7792798003716
    ────────────────────────────────────
    🔵 PAL-20260501-0001 / Base 2 → 6 u.
    🟠 PAL-20260501-0003 / Base 1 → 18 u.
    ────────────────────────────────────
    Total en pedido: 24 u.  ·  Distribuidas: 24 u. ✓
    ```

    ---

    **Backend** — nuevo endpoint:
    `GET /orders/{order}/tickets/{ticket}/photos/{photo}/highlights`

    Respuesta:
    ```json
    {
      "ready": true,
      "pallets": [
        {
          "id": 1, "code": "PAL-20260501-0001", "color_index": 0,
          "base_count": 3, "product_count": 8, "total_qty": 192
        },
        {
          "id": 3, "code": "PAL-20260501-0003", "color_index": 1,
          "base_count": 2, "product_count": 5, "total_qty": 96
        }
      ],
      "highlights": [
        {
          "ean": "7792798003716",
          "description": "QUILMES 473ml",
          "bbox": { "left": 100, "top": 200, "right": 300, "bottom": 230 },
          "img_w": 2000, "img_h": 3000,
          "qty_order": 24,
          "is_split": true,
          "pallet_color_index": null,
          "pallet_breakdown": [
            { "pallet_id": 1, "pallet_code": "PAL-20260501-0001", "base_name": "Base 2", "qty": 6 },
            { "pallet_id": 3, "pallet_code": "PAL-20260501-0003", "base_name": "Base 1", "qty": 18 }
          ]
        }
      ]
    }
    ```

    Si las precondiciones no se cumplen, devuelve `{ "ready": false, "reason": "..." }`
    (aunque en ese caso el botón ni siquiera existe en la UI).

    `pallet_color_index` es resuelto server-side (0–4). El frontend mapea índice → color CSS:
    `["#3B82F6", "#F97316", "#A855F7", "#10B981", "#EC4899"]` (azul, naranja, morado, verde, rosa).
    Si `is_split: true`, `pallet_color_index` es `null` y se usa color neutro.

    ---

    **Frontend — componente `HighlightOverlay`**
    - Divs absolutos sobre la imagen (o SVG), coordenadas escaladas al tamaño real renderizado
    - Recalcula posiciones en cada resize con `ResizeObserver` + ratio imagen-original / imagen-pantalla
    - Borde 2.5px sólido, esquinas redondeadas, fondo semi-transparente del color del pallet
    - Label en chip pequeño en esquina inferior del rect
    - Tap → `BottomSheet` con el desglose del producto

    **Dónde aparece el botón:**
    En `TicketCard` dentro de `OrderDetail`, al lado del badge "✓ Escaneado".
    Botón `"🗺 Ver mapa"` — oculto si las precondiciones no se cumplen, visible si sí.
    Al tocarlo abre el `PhotoViewer` con overlay activado.
    Si el pedido tiene varias fotos escaneadas, navegar entre ellas mantiene el overlay.

[ ] **OCR — ticket largo dividido en dos fotos**
    Caso de uso: cuando el ticket de Carrefour es muy largo y no entra en una sola foto,
    el operador saca dos fotos del mismo ticket (parte superior e inferior).
    Hoy el sistema trata cada foto de forma independiente: el OCR de cada una detecta
    sus propios EANs sin saber que forman parte del mismo documento.
    Preguntas a responder antes de implementar:
    - ¿El parsing de EANs funciona correctamente con cada mitad por separado? (probable que sí)
    - ¿Los highlights en la vista pública se superponen o se muestran en fotos separadas? (separadas → probablemente OK)
    - ¿Hay EANs que quedan cortados entre las dos fotos? (el corte físico del papel podría partir un número)
    - ¿Falta algún mecanismo para "agrupar" fotos de un mismo ticket lógico?
    Acción: testear con un ticket real partido en dos, verificar que la suma de EANs detectados
    sea equivalente a los de la foto completa. Documentar el resultado aquí.



[ ] **Comando de archivado automático**
    Laravel scheduler mensual que:
    - Borra ocr_log (texto largo) de fotos de pedidos finalizados hace >3 meses
    - Elimina archivos físicos de fotos de pedidos finalizados hace >6 meses
    - Purga activity_logs con más de 6 meses de antigüedad
    - Recomprime imágenes viejas a WebP 60% (actualmente 95%)

[ ] **Limpieza de imágenes de prueba**
    Crear panel en admin para listar y eliminar fotos huérfanas o de
    pedidos/pallets de test, con preview antes de borrar.

[ ] **Mejora UX — indicador de "pendiente" en tickets**
    El ticket de ROLLO DE COCINA tiene nota manuscrita "PENDIENTE 24 UNIDADES".
    El sistema no tiene manera de marcar un ítem de ticket como parcialmente
    entregado. Ver si agregar un campo de nota por ítem o un estado de
    "pendiente de completar" tiene sentido.

---

## 💡 Ideas futuras / backlog

[ ] **Bot Telegram — UX conversacional con teclados inline**
    El bot actual requiere conocer comandos de texto (`p`, `b A1`, `t 12345`).
    Poco intuitivo para compañeros que no crearon el sistema.

    **Idea:** cuando el usuario manda una foto sin caption (o con caption inválido),
    el bot responde con un *teclado inline* (botones adjuntos al mensaje del bot):

    ```
    ¿A dónde adjunto esta foto?
    [ 📦 PLT-001 ]  [ 📦 PLT-002 ]
    [ 🎫 ORD-045 ]  [ 🎫 ORD-046 ]
    ```

    El usuario toca un botón → el bot edita su propio mensaje a "✅ Foto guardada en PLT-001".
    Si el destino es "base", aparece un segundo nivel de botones con las bases de ese pallet.

    **Por qué no genera "basura" en el chat:**
    - Los botones están adjuntos al mensaje del BOT, no al del usuario
    - Tocar un botón no genera un mensaje nuevo en el chat (callback query, no mensaje de texto)
    - El bot edita su propio mensaje en lugar de agregar uno nuevo → el chat queda limpio

    **Requisito técnico:**
    - Guardar la foto temporalmente con el `file_id` de Telegram mientras se espera el callback
    - Usar `editMessageText` + `answerCallbackQuery` en lugar de `sendMessage`
    - Agregar ruta `POST /telegram/callback` o manejar `callback_query` en el webhook existente
    - Mostrar solo los últimos 4-6 pallets/pedidos abiertos para no saturar los botones

    **Mantener comandos como atajo:** los que saben el comando siguen usándolo (`p`, `b A1`).
    Los que no lo saben reciben el menú automáticamente.

[ ] **Notificaciones Telegram cuando OCR termina**
    Ya hay bot de Telegram. Al finalizar un escaneo, notificar con el
    resumen de EANs detectados.

[x] **Dashboard / métricas**
    Vista de resumen: pedidos abiertos, pallets en proceso, items pendientes.
    Implementado: `DashboardController` + `GET /api/v1/dashboard` + `Home.jsx`.
    (El contador de créditos Azure OCR no está implementado — podría agregarse si se necesita.)

[ ] **Exportar pedido a PDF**
    Desde la vista de pedido, generar PDF con el detalle completo para
    compartir con el cliente.

[ ] **Búsqueda global**
    Barra de búsqueda que encuentre pedidos, pallets y clientes por código,
    nombre o EAN de producto.

[ ] **Historial de cambios visible al usuario**
    Mostrar el activity log en la UI de forma amigable (timeline de eventos
    por pedido/pallet).

---

## ✅ Completado

[x] OCR manual por foto con confirmación de costo Azure
    Botón "Escanear" por foto, diálogo de advertencia, terminal en vivo.
    Commit: 90a9f55

[x] Vista pública solo muestra fotos ya escaneadas
    PublicPalletController filtra ocr_processed_at IS NULL.

[x] Múltiples tickets de distintos pedidos en la vista pública del pallet
    ticket_sections agrupa por pedido con EAN map scoped por order.

[x] Sistema de OCR con Azure Computer Vision + highlights en vista QR

[x] Bot de WhatsApp para subir fotos desde el grupo

[x] Auth con roles (user / admin / superadmin) + verificación de email

[x] Importación de pedidos desde texto tabulado (factura del sistema)
