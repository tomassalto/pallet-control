import { useEffect, useState } from "react";

const INDIGO = { hex: "#6366f1" };

// ── Lightbox ─────────────────────────────────────────────────────────────────
export function Lightbox({ url, onClose }) {
  useEffect(() => {
    function onKey(e) { if (e.key === "Escape") onClose(); }
    window.addEventListener("keydown", onKey);
    return () => window.removeEventListener("keydown", onKey);
  }, [onClose]);

  return (
    <div
      className="fixed inset-0 z-50 bg-black/90 flex items-center justify-center p-4"
      onClick={onClose}
    >
      <button
        onClick={onClose}
        className="absolute top-4 right-4 w-10 h-10 rounded-full bg-white/10 hover:bg-white/20 flex items-center justify-center text-white text-xl transition-colors"
      >✕</button>
      <img
        src={url}
        alt=""
        className="max-w-full max-h-full object-contain rounded-xl shadow-2xl"
        onClick={(e) => e.stopPropagation()}
      />
    </div>
  );
}

// ── Galería horizontal ────────────────────────────────────────────────────────
export function PhotoStrip({ photos }) {
  const [selected, setSelected] = useState(null);
  if (!photos?.length) return null;
  return (
    <>
      <div className="flex gap-2 overflow-x-auto pb-1" style={{ scrollbarWidth: "none" }}>
        {photos.map((p) => (
          <button key={p.id} onClick={() => setSelected(p.url)} className="shrink-0 active:scale-95 transition-transform">
            <img src={p.url} alt="" className="h-28 w-28 object-cover rounded-2xl border border-gray-200 dark:border-gray-600 shadow-sm" />
          </button>
        ))}
      </div>
      {selected && <Lightbox url={selected} onClose={() => setSelected(null)} />}
    </>
  );
}

// ── Fila de producto ──────────────────────────────────────────────────────────
function ProductRow({ item, badgeCls = "bg-indigo-600" }) {
  const [err, setErr] = useState(false);
  return (
    <div className="flex items-center gap-3 py-2.5 border-b border-gray-100 dark:border-gray-700/50 last:border-0">
      <div className="w-12 h-12 shrink-0 rounded-xl overflow-hidden bg-gray-100 dark:bg-gray-700 flex items-center justify-center">
        {item.image_url && !err
          ? <img src={item.image_url} alt={item.description} className="w-full h-full object-contain" onError={() => setErr(true)} />
          : <span className="text-2xl">📦</span>}
      </div>
      <div className="flex-1 min-w-0">
        <p className="text-sm font-medium leading-snug line-clamp-2 text-gray-900 dark:text-gray-100">{item.description}</p>
        <p className="text-xs text-gray-400 dark:text-gray-500 mt-0.5 font-mono">{item.ean}</p>
        {item.units_per_bulto != null && (
          <p className="text-[10px] text-gray-400 font-mono mt-0.5">×{item.units_per_bulto} u/bulto</p>
        )}
      </div>
      <div className={`shrink-0 min-w-[48px] text-center py-1.5 px-2 rounded-xl ${badgeCls} text-white`}>
        <div className="text-xl font-bold leading-none">{item.qty}</div>
        <div className="text-[9px] uppercase tracking-wide opacity-75 mt-0.5">unid.</div>
      </div>
    </div>
  );
}

// ── Viewer fullscreen de ticket con highlights + zoom/pan ────────────────────
function TicketViewer({ photo, onClose }) {
  const [scale, setScale] = useState(1);
  const [position, setPosition] = useState({ x: 0, y: 0 });
  const [isDragging, setIsDragging] = useState(false);
  const [dragStart, setDragStart] = useState({ x: 0, y: 0 });
  const [selected, setSelected] = useState(null);
  const [showHL, setShowHL] = useState(true);
  const c = INDIGO;

  useEffect(() => {
    const onKey = (e) => { if (e.key === "Escape") onClose(); };
    window.addEventListener("keydown", onKey);
    return () => window.removeEventListener("keydown", onKey);
  }, [onClose]);

  const handleTouchStart = (e) => {
    if (e.touches.length === 2) {
      const d = Math.hypot(e.touches[1].clientX - e.touches[0].clientX, e.touches[1].clientY - e.touches[0].clientY);
      e.currentTarget.dataset.initialDistance = d;
      e.currentTarget.dataset.initialScale = scale;
    } else if (e.touches.length === 1 && scale > 1) {
      setIsDragging(true);
      setDragStart({ x: e.touches[0].clientX - position.x, y: e.touches[0].clientY - position.y });
    }
  };

  const handleTouchMove = (e) => {
    if (e.touches.length === 2) {
      const d = Math.hypot(e.touches[1].clientX - e.touches[0].clientX, e.touches[1].clientY - e.touches[0].clientY);
      const initD = parseFloat(e.currentTarget.dataset.initialDistance || d);
      const initS = parseFloat(e.currentTarget.dataset.initialScale || 1);
      setScale(Math.max(1, Math.min(5, (d / initD) * initS)));
    } else if (e.touches.length === 1 && isDragging && scale > 1) {
      setPosition({ x: e.touches[0].clientX - dragStart.x, y: e.touches[0].clientY - dragStart.y });
    }
  };

  const handleWheel = (e) => {
    e.preventDefault();
    const next = Math.max(1, Math.min(5, scale * (e.deltaY > 0 ? 0.9 : 1.1)));
    setScale(next);
    if (next <= 1) setPosition({ x: 0, y: 0 });
  };

  const handleDoubleClick = () => {
    if (scale > 1) { setScale(1); setPosition({ x: 0, y: 0 }); } else { setScale(2); }
  };

  const highlights = photo.highlights || [];

  return (
    <div className="fixed inset-0 z-50 bg-black/95 flex flex-col" style={{ touchAction: "none" }} onClick={onClose}>
      <div className="absolute top-4 right-4 z-10 flex gap-2">
        {highlights.length > 0 && (
          <button
            onClick={(e) => { e.stopPropagation(); setShowHL((v) => !v); }}
            className={`px-4 py-2 rounded-lg backdrop-blur-sm text-sm transition-colors ${showHL ? "bg-indigo-600 text-white" : "bg-white/20 text-white/70 hover:bg-white/30"}`}
          >
            {showHL ? "Ocultar marcas" : "Mostrar marcas"}
          </button>
        )}
        <button onClick={onClose} className="px-4 py-2 bg-white/20 hover:bg-white/30 text-white rounded-lg backdrop-blur-sm text-sm">Cerrar</button>
      </div>
      {scale > 1 && (
        <div className="absolute top-4 left-4 z-10 bg-black/60 text-white text-xs px-3 py-2 rounded-lg backdrop-blur-sm">
          Zoom: {Math.round(scale * 100)}%
        </div>
      )}

      <div
        className="flex-1 flex items-center justify-center overflow-hidden"
        onClick={(e) => e.stopPropagation()}
        onTouchStart={handleTouchStart}
        onTouchMove={handleTouchMove}
        onTouchEnd={() => setIsDragging(false)}
        onWheel={handleWheel}
        style={{ touchAction: "none" }}
      >
        <div
          className="relative"
          style={{
            transform: `scale(${scale}) translate(${position.x / scale}px, ${position.y / scale}px)`,
            transition: isDragging ? "none" : "transform 0.1s",
            maxWidth: "100vw",
            maxHeight: "100vh",
          }}
          onDoubleClick={handleDoubleClick}
        >
          <img src={photo.url} alt="Ticket" className="max-w-full max-h-[100vh] object-contain select-none block" draggable={false} />
          {showHL && highlights.map((h, i) => {
            const { bbox, img_w, img_h } = h;
            const left = (bbox.left / img_w) * 100;
            const top = (bbox.top / img_h) * 100;
            const width = ((bbox.right - bbox.left) / img_w) * 100;
            const height = ((bbox.bottom - bbox.top) / img_h) * 100;
            const isSelected = selected?.ean === h.ean;
            return (
              <div key={i}>
                <div
                  style={{ left: `${left}%`, top: `${top}%`, width: `${width}%`, height: `${height}%`, borderColor: c.hex, backgroundColor: isSelected ? `${c.hex}66` : `${c.hex}3F` }}
                  className="absolute border-2 rounded cursor-pointer transition-all"
                  onClick={(e) => { e.stopPropagation(); setSelected(isSelected ? null : h); }}
                />
                {isSelected && (
                  <div
                    style={{ left: `${Math.min(left + width + 1, 55)}%`, top: `${top}%` }}
                    className="absolute z-20 bg-gray-900 text-white text-xs rounded-xl px-3 py-2 shadow-xl max-w-[180px] pointer-events-none"
                  >
                    <p className="font-semibold leading-snug mb-1">{h.description}</p>
                    <p className="font-bold" style={{ color: c.hex }}>{h.qty_in_pallet} unid.</p>
                    {h.units_per_bulto != null && <p className="text-gray-400 font-mono text-[10px] mt-0.5">×{h.units_per_bulto} u/bulto</p>}
                  </div>
                )}
              </div>
            );
          })}
        </div>
      </div>

      {showHL && highlights.length > 0 && (
        <div className="shrink-0 bg-black/80 backdrop-blur-sm px-4 py-3 overflow-x-auto" onClick={(e) => e.stopPropagation()}>
          <div className="flex gap-2">
            {highlights.map((h, i) => (
              <button
                key={i}
                onClick={() => setSelected(selected?.ean === h.ean ? null : h)}
                className={`shrink-0 flex items-center gap-2 rounded-xl px-3 py-2 text-xs transition-colors ${selected?.ean === h.ean ? "bg-indigo-600 text-white" : "bg-white/10 text-white/80 hover:bg-white/20"}`}
              >
                <span className="font-semibold truncate max-w-[120px]">{h.description}</span>
                <span className="font-bold" style={{ color: selected?.ean === h.ean ? "#fff" : c.hex }}>{h.qty_in_pallet}</span>
              </button>
            ))}
          </div>
        </div>
      )}
    </div>
  );
}

// ── Ticket: foto con overlays de EAN detectados ───────────────────────────────
function TicketPhotoHighlight({ photo }) {
  const [tooltip, setTooltip] = useState(null);
  const [imgLoaded, setImgLoaded] = useState(false);
  const [viewerOpen, setViewerOpen] = useState(false);
  const hasHighlights = photo.highlights?.length > 0;
  const c = INDIGO;

  return (
    <div className="space-y-2">
      <div className="relative w-full rounded-2xl overflow-hidden border border-gray-200 dark:border-gray-700 bg-gray-100 dark:bg-gray-800 cursor-zoom-in" onClick={() => setViewerOpen(true)}>
        <img src={photo.url} alt="Ticket" className="w-full h-auto block" onLoad={() => setImgLoaded(true)} />
        {imgLoaded && hasHighlights && photo.highlights.map((h, i) => {
          const { bbox, img_w, img_h } = h;
          const left = (bbox.left / img_w) * 100;
          const top = (bbox.top / img_h) * 100;
          const width = ((bbox.right - bbox.left) / img_w) * 100;
          const height = ((bbox.bottom - bbox.top) / img_h) * 100;
          return (
            <div key={i}>
              <div
                style={{ left: `${left}%`, top: `${top}%`, width: `${width}%`, height: `${height}%`, borderColor: c.hex, backgroundColor: tooltip?.ean === h.ean ? `${c.hex}66` : `${c.hex}3F` }}
                className="absolute border-2 rounded cursor-pointer transition-all"
                onMouseEnter={() => setTooltip(h)}
                onMouseLeave={() => setTooltip(null)}
                onClick={(e) => { e.stopPropagation(); setTooltip(tooltip?.ean === h.ean ? null : h); }}
              />
              {tooltip?.ean === h.ean && (
                <div
                  style={{ left: `${Math.min(left + width + 1, 55)}%`, top: `${top}%` }}
                  className="absolute z-20 bg-gray-900 dark:bg-gray-700 text-white text-xs rounded-xl px-3 py-2 shadow-xl max-w-[180px] pointer-events-none"
                >
                  <p className="font-semibold leading-snug mb-1">{h.description}</p>
                  <p className="font-bold" style={{ color: c.hex }}>{h.qty_in_pallet} unid.</p>
                  {h.units_per_bulto != null && <p className="text-gray-400 font-mono text-[10px] mt-0.5">×{h.units_per_bulto} u/bulto</p>}
                </div>
              )}
            </div>
          );
        })}
        <div className="absolute top-2 right-2">
          {hasHighlights
            ? <span className="text-[10px] bg-green-500/90 text-white font-semibold px-2 py-1 rounded-full">{photo.highlight_count} detectado{photo.highlight_count !== 1 ? "s" : ""}</span>
            : <span className="text-[10px] bg-gray-500/70 text-white font-semibold px-2 py-1 rounded-full">Sin coincidencias</span>}
        </div>
      </div>
      {hasHighlights && (
        <div className="space-y-1">
          {photo.highlights.map((h, i) => (
            <div key={i} style={{ borderColor: `${c.hex}66` }} className="flex items-center gap-2 bg-gray-50 dark:bg-gray-800/60 border rounded-xl px-3 py-2">
              <span className="text-sm shrink-0" style={{ color: c.hex }}>✓</span>
              <div className="flex-1 min-w-0">
                <p className="text-xs font-semibold text-gray-800 dark:text-gray-200 line-clamp-1">{h.description}</p>
                <p className="text-[11px] text-gray-500 font-mono">{h.ean}</p>
              </div>
              <span className="shrink-0 text-xs font-bold" style={{ color: c.hex }}>{h.qty_in_pallet} unid.</span>
            </div>
          ))}
        </div>
      )}
      {viewerOpen && (
        <TicketViewer photo={photo} onClose={() => setViewerOpen(false)} />
      )}
    </div>
  );
}

// ── Sección de tickets del pedido ────────────────────────────────────────────
export function TicketSection({ sections }) {
  const [open, setOpen] = useState(true);
  if (!sections?.length) return null;
  const totalPhotos = sections.reduce((s, t) => s + t.photos.length, 0);
  const totalHighlights = sections.reduce((s, t) => s + t.photos.reduce((ps, p) => ps + (p.highlight_count ?? 0), 0), 0);
  if (totalPhotos === 0) return null;

  return (
    <div className="rounded-2xl border border-gray-200 dark:border-gray-700 overflow-hidden">
      <button onClick={() => setOpen((v) => !v)} className="w-full flex items-center gap-2 px-4 py-3 bg-indigo-700 text-left">
        <span className="text-white text-base">🧾</span>
        <span className="text-white font-bold text-sm">Tickets del cliente</span>
        <span className="ml-auto text-indigo-300 text-xs shrink-0">
          {totalPhotos} foto{totalPhotos !== 1 ? "s" : ""}{totalHighlights > 0 && ` · ${totalHighlights} ✓`}
        </span>
        <span className="text-indigo-300 text-sm">{open ? "▲" : "▼"}</span>
      </button>
      {open && (
        <div className="bg-white dark:bg-gray-800 divide-y divide-gray-100 dark:divide-gray-700">
          {sections.map((ticket) => ticket.photos.map((photo) => (
            <div key={photo.id} className="p-4">
              {(ticket.code || ticket.note) && (
                <p className="text-xs text-gray-400 mb-2">
                  {ticket.code && <span className="font-mono font-semibold">#{ticket.code} </span>}
                  {ticket.note}
                </p>
              )}
              <TicketPhotoHighlight photo={photo} />
            </div>
          )))}
        </div>
      )}
    </div>
  );
}

// ── Tabla de ítems del pedido ────────────────────────────────────────────────
export function OrderItemsTable({ items, totalPrice }) {
  const [open, setOpen] = useState(true);
  if (!items?.length) return null;

  const hasPrices = items.some((i) => i.price != null);

  return (
    <div className="rounded-2xl border border-gray-200 dark:border-gray-700 overflow-hidden">
      <button onClick={() => setOpen((v) => !v)} className="w-full flex items-center gap-2 px-4 py-3 bg-gray-800 dark:bg-gray-900/80 text-left">
        <span className="text-white text-base">📋</span>
        <span className="text-white font-bold text-sm">Resumen del pedido</span>
        <span className="ml-auto text-gray-400 text-xs shrink-0">{items.length} prod.</span>
        <span className="text-gray-400 text-sm">{open ? "▲" : "▼"}</span>
      </button>
      {open && (
        <div className="bg-white dark:bg-gray-800 px-4">
          {items.map((item, i) => <ProductRow key={`${item.ean}-${i}`} item={item} />)}
          {hasPrices && totalPrice != null && (
            <div className="flex items-center justify-between py-3 border-t border-gray-200 dark:border-gray-700 mt-1">
              <span className="text-sm font-semibold text-gray-700 dark:text-gray-200">Total del ticket</span>
              <span className="text-sm font-bold text-indigo-600 dark:text-indigo-400">
                ${totalPrice.toLocaleString("es-AR", { minimumFractionDigits: 2 })}
              </span>
            </div>
          )}
        </div>
      )}
    </div>
  );
}

// ── Pallet card ───────────────────────────────────────────────────────────────
export function PalletCard({ pallet, palletNum }) {
  const [open, setOpen] = useState(true);
  const totalItems = pallet.bases?.reduce((s, b) => s + (b.items?.length ?? 0), 0) ?? 0;
  const isDone = pallet.status === "done";

  return (
    <div className="rounded-2xl border border-gray-200 dark:border-gray-700 overflow-hidden shadow-sm">
      <button onClick={() => setOpen((v) => !v)} className="w-full flex items-center gap-2 px-4 py-3 bg-gray-800 dark:bg-gray-900/80 text-left">
        <span className="text-white text-base">📦</span>
        <span className="text-white font-bold text-sm">{pallet.code}</span>
        <span className={`shrink-0 text-[10px] font-semibold px-2 py-0.5 rounded-full ${isDone ? "bg-green-500/20 text-green-300" : "bg-amber-500/20 text-amber-300"}`}>
          {isDone ? "Listo" : "En prep."}
        </span>
        <span className="ml-auto text-gray-400 text-xs shrink-0">{pallet.bases?.length ?? 0} base{pallet.bases?.length !== 1 ? "s" : ""} · {totalItems} prod.</span>
        <span className="text-gray-400 text-sm">{open ? "▲" : "▼"}</span>
      </button>
      {open && (
        <div className="bg-white dark:bg-gray-800 divide-y divide-gray-100 dark:divide-gray-700">
          {pallet.photos?.length > 0 && (
            <div className="px-4 pt-3 pb-2 bg-gray-50/50 dark:bg-gray-700/20">
              <p className="text-[10px] uppercase tracking-wider text-gray-400 dark:text-gray-500 font-semibold mb-2">Fotos del pallet</p>
              <PhotoStrip photos={pallet.photos} />
            </div>
          )}
          {pallet.bases?.map((base, i) => (
            <div key={base.id}>
              <div className="flex items-center gap-2 px-4 py-2 bg-gray-50 dark:bg-gray-700/40">
                <span className="text-gray-500 dark:text-gray-400 text-sm">🧱</span>
                <span className="text-xs font-semibold text-gray-700 dark:text-gray-300">{base.name || `Base ${i + 1}`}</span>
                <span className="ml-auto text-xs text-gray-400">{base.items?.length ?? 0} prod.</span>
              </div>
              {base.photos?.length > 0 && (
                <div className="px-4 pt-3 pb-2 bg-gray-50/50 dark:bg-gray-700/20 border-b border-gray-100 dark:border-gray-700">
                  <PhotoStrip photos={base.photos} />
                </div>
              )}
              {base.items?.length > 0
                ? <div className="px-4">{base.items.map((item, j) => <ProductRow key={`${item.ean}-${j}`} item={item} />)}</div>
                : <p className="px-4 py-4 text-xs text-gray-400 text-center">Sin productos registrados</p>}
            </div>
          ))}
          {(!pallet.bases || pallet.bases.length === 0) && (
            <p className="px-4 py-6 text-xs text-gray-400 text-center">Sin bases registradas</p>
          )}
        </div>
      )}
    </div>
  );
}
