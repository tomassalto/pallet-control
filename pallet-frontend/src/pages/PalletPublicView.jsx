import { useEffect, useState } from "react";
import { useParams } from "react-router-dom";
import { apiGet } from "../api/client";

// ── Paleta de colores por pedido ─────────────────────────────────────────────
const COLORS = [
  {
    bg: "bg-blue-50 dark:bg-blue-950/40",
    border: "border-blue-200 dark:border-blue-800",
    badge: "bg-blue-600",
    dot: "bg-blue-500",
    customer: "text-gray-700 dark:text-blue-200",
  },
  {
    bg: "bg-emerald-50 dark:bg-emerald-950/40",
    border: "border-emerald-200 dark:border-emerald-800",
    badge: "bg-emerald-600",
    dot: "bg-emerald-500",
    customer: "text-gray-700 dark:text-emerald-200",
  },
  {
    bg: "bg-violet-50 dark:bg-violet-950/40",
    border: "border-violet-200 dark:border-violet-800",
    badge: "bg-violet-600",
    dot: "bg-violet-500",
    customer: "text-gray-700 dark:text-violet-200",
  },
  {
    bg: "bg-amber-50 dark:bg-amber-950/40",
    border: "border-amber-200 dark:border-amber-800",
    badge: "bg-amber-500",
    dot: "bg-amber-400",
    customer: "text-gray-700 dark:text-amber-200",
  },
  {
    bg: "bg-rose-50 dark:bg-rose-950/40",
    border: "border-rose-200 dark:border-rose-800",
    badge: "bg-rose-600",
    dot: "bg-rose-500",
    customer: "text-gray-700 dark:text-rose-200",
  },
  {
    bg: "bg-cyan-50 dark:bg-cyan-950/40",
    border: "border-cyan-200 dark:border-cyan-800",
    badge: "bg-cyan-600",
    dot: "bg-cyan-500",
    customer: "text-gray-700 dark:text-cyan-200",
  },
];
const color = (idx) => COLORS[idx % COLORS.length];

// ── Fila de producto ─────────────────────────────────────────────────────────
function ProductRow({ item, c }) {
  const [err, setErr] = useState(false);
  return (
    <div className="flex items-center gap-3 py-2.5 border-b border-gray-100 dark:border-gray-700/50 last:border-0">
      {/* Imagen */}
      <div className="w-12 h-12 shrink-0 rounded-xl overflow-hidden bg-gray-100 dark:bg-gray-700 flex items-center justify-center">
        {item.image_url && !err ? (
          <img
            src={item.image_url}
            alt={item.description}
            className="w-full h-full object-contain"
            onError={() => setErr(true)}
          />
        ) : (
          <span className="text-2xl">📦</span>
        )}
      </div>

      {/* Descripción */}
      <div className="flex-1 min-w-0">
        <p className="text-sm font-medium leading-snug line-clamp-2 text-gray-900 dark:text-gray-100">
          {item.description}
        </p>
        <p className="text-xs text-gray-400 dark:text-gray-500 mt-0.5 font-mono">
          {item.ean}
        </p>
      </div>

      {/* Cantidad */}
      <div
        className={`shrink-0 min-w-[48px] text-center py-1.5 px-2 rounded-xl ${c.badge} text-white`}
      >
        <div className="text-xl font-bold leading-none">{item.qty}</div>
        <div className="text-[9px] uppercase tracking-wide opacity-75 mt-0.5">
          unid.
        </div>
      </div>
    </div>
  );
}

// ── Lightbox modal ───────────────────────────────────────────────────────────
function Lightbox({ url, onClose }) {
  useEffect(() => {
    function onKey(e) {
      if (e.key === "Escape") onClose();
    }
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
        className="absolute top-4 right-4 w-10 h-10 rounded-full bg-white/10 hover:bg-white/20 flex items-center justify-center text-white text-xl leading-none transition-colors"
        aria-label="Cerrar"
      >
        ✕
      </button>
      <img
        src={url}
        alt=""
        className="max-w-full max-h-full object-contain rounded-xl shadow-2xl"
        onClick={(e) => e.stopPropagation()}
      />
    </div>
  );
}

// ── Galería de fotos horizontal ──────────────────────────────────────────────
function PhotoStrip({ photos }) {
  const [selected, setSelected] = useState(null);

  if (!photos?.length) return null;
  return (
    <>
      <div
        className="flex gap-2 overflow-x-auto pb-1"
        style={{ scrollbarWidth: "none" }}
      >
        {photos.map((p) => (
          <button
            key={p.id}
            onClick={() => setSelected(p.url)}
            className="shrink-0 block active:scale-95 transition-transform"
          >
            <img
              src={p.url}
              alt=""
              className="h-28 w-28 object-cover rounded-2xl border border-gray-200 dark:border-gray-600 shadow-sm"
            />
          </button>
        ))}
      </div>
      {selected && (
        <Lightbox url={selected} onClose={() => setSelected(null)} />
      )}
    </>
  );
}

// ── Tarjeta de pedido (resumen global) ───────────────────────────────────────
function OrderCard({ order, idx }) {
  const c = color(idx);
  const [open, setOpen] = useState(true);

  return (
    <div className={`rounded-2xl border ${c.border} overflow-hidden`}>
      {/* Header */}
      <button
        onClick={() => setOpen((v) => !v)}
        className={`w-full flex items-center gap-2 px-4 py-3 ${c.bg} text-left`}
      >
        <span
          className={`text-xs font-bold px-2.5 py-0.5 rounded-full text-white ${c.badge}`}
        >
          #{order.code}
        </span>
        {order.customer && (
          <span className={`text-sm font-medium truncate ${c.customer}`}>
            {order.customer}
          </span>
        )}
        <span className="ml-auto text-xs text-gray-400 dark:text-gray-500 shrink-0">
          {order.items.length} prod.
        </span>
        <span className="text-gray-400 dark:text-gray-500 text-sm">
          {open ? "▲" : "▼"}
        </span>
      </button>

      {/* Productos */}
      {open && (
        <div className="bg-white dark:bg-gray-800 px-4">
          {order.items.length === 0 ? (
            <p className="py-6 text-sm text-gray-400 text-center">
              Sin productos registrados
            </p>
          ) : (
            order.items.map((item, i) => (
              <ProductRow key={`${item.ean}-${i}`} item={item} c={c} />
            ))
          )}
        </div>
      )}
    </div>
  );
}

// ── Grupo de productos dentro de una base (agrupado por pedido) ──────────────
function BaseOrderGroup({ group, idx }) {
  const c = color(idx);
  return (
    <div>
      {/* Sub-header del pedido dentro de la base */}
      <div className={`flex items-center gap-2 px-4 py-2 ${c.bg}`}>
        <span className={`w-2 h-2 rounded-full shrink-0 ${c.dot}`} />
        <span
          className={`text-xs font-bold text-white px-2 py-0.5 rounded-full ${c.badge}`}
        >
          #{group.order_code}
        </span>
        {group.customer && (
          <span className={`text-xs truncate ${c.customer}`}>
            {group.customer}
          </span>
        )}
        <span className="ml-auto text-xs text-gray-400 dark:text-gray-500 shrink-0">
          {group.items.length} prod.
        </span>
      </div>
      <div className="px-4 bg-white dark:bg-gray-800">
        {group.items.map((item, i) => (
          <ProductRow key={`${item.ean}-${i}`} item={item} c={c} />
        ))}
      </div>
    </div>
  );
}

// ── Tarjeta de base ──────────────────────────────────────────────────────────
function BaseCard({ base, orderColorMap, baseNum }) {
  const [open, setOpen] = useState(true);
  const hasPhotos = base.photos?.length > 0;
  const totalItems = base.orders?.reduce((s, g) => s + g.items.length, 0) ?? 0;
  const hasItems = totalItems > 0;

  return (
    <div className="rounded-2xl border border-gray-200 dark:border-gray-700 overflow-hidden shadow-sm bg-white dark:bg-gray-800">
      {/* Header oscuro de la base */}
      <button
        onClick={() => setOpen((v) => !v)}
        className="w-full flex items-center gap-2 px-4 py-3 bg-gray-800 dark:bg-gray-900/80 text-left"
      >
        <span className="text-white text-base">🧱</span>
        <span className="text-white font-bold text-sm">
          {base.name || `Base ${baseNum}`}
        </span>
        <span className="ml-auto text-gray-400 text-xs shrink-0">
          {totalItems} prod. · {base.photos?.length ?? 0} foto
          {base.photos?.length !== 1 ? "s" : ""}
        </span>
        <span className="text-gray-400 text-sm">{open ? "▲" : "▼"}</span>
      </button>

      {open && (
        <>
          {/* Fotos de la base */}
          {hasPhotos && (
            <div className="px-4 pt-3 pb-2 bg-gray-50 dark:bg-gray-700/30 border-b border-gray-100 dark:border-gray-700">
              <PhotoStrip photos={base.photos} />
            </div>
          )}

          {/* Productos agrupados por pedido */}
          {hasItems ? (
            <div className="divide-y divide-gray-100 dark:divide-gray-700">
              {base.orders
                ?.filter((g) => g.items.length > 0)
                .map((group) => (
                  <BaseOrderGroup
                    key={group.order_id}
                    group={group}
                    idx={orderColorMap[group.order_id] ?? 0}
                  />
                ))}
            </div>
          ) : (
            !hasPhotos && (
              <p className="py-6 text-sm text-gray-400 dark:text-gray-500 text-center">
                Base sin contenido registrado
              </p>
            )
          )}
        </>
      )}
    </div>
  );
}

// ── Foto de ticket con overlays de EAN ───────────────────────────────────────
function TicketPhotoHighlight({ photo }) {
  const [tooltip, setTooltip] = useState(null); // highlight activo para tooltip
  const [imgLoaded, setImgLoaded] = useState(false);

  const hasHighlights = photo.highlights?.length > 0;
  const isProcessed = photo.ocr_processed;

  return (
    <div className="space-y-2">
      {/* Imagen con overlays */}
      <div className="relative w-full rounded-2xl overflow-hidden border border-gray-200 dark:border-gray-700 bg-gray-100 dark:bg-gray-800">
        <img
          src={photo.url}
          alt="Ticket"
          className="w-full h-auto block"
          onLoad={() => setImgLoaded(true)}
        />

        {/* Overlays de EAN — solo cuando la imagen ya cargó */}
        {imgLoaded &&
          hasHighlights &&
          photo.highlights.map((h, i) => {
            const { bbox, img_w, img_h } = h;
            const left = (bbox.left / img_w) * 100;
            const top = (bbox.top / img_h) * 100;
            const width = ((bbox.right - bbox.left) / img_w) * 100;
            const height = ((bbox.bottom - bbox.top) / img_h) * 100;

            return (
              <div key={i}>
                {/* Highlight box */}
                <div
                  style={{
                    left: `${left}%`,
                    top: `${top}%`,
                    width: `${width}%`,
                    height: `${height}%`,
                  }}
                  className="absolute border-2 border-green-400 bg-green-400/25 rounded cursor-pointer transition-opacity hover:bg-green-400/40"
                  onMouseEnter={() => setTooltip(h)}
                  onMouseLeave={() => setTooltip(null)}
                  onClick={() => setTooltip(tooltip?.ean === h.ean ? null : h)}
                />

                {/* Tooltip al hover */}
                {tooltip?.ean === h.ean && (
                  <div
                    style={{
                      left: `${Math.min(left + width + 1, 55)}%`,
                      top: `${top}%`,
                    }}
                    className="absolute z-20 bg-gray-900 dark:bg-gray-700 text-white text-xs rounded-xl px-3 py-2 shadow-xl max-w-[180px] pointer-events-none"
                  >
                    <p className="font-semibold leading-snug mb-1">
                      {h.description}
                    </p>
                    <p className="text-green-400 font-bold">
                      {h.qty_in_pallet} unid. en pallet
                    </p>
                    {h.orders?.length > 1 && (
                      <div className="mt-1 space-y-0.5 text-gray-300">
                        {h.orders.map((o, oi) => (
                          <p key={oi}>
                            #{o.code}: {o.qty} unid.
                          </p>
                        ))}
                      </div>
                    )}
                    {h.orders?.length === 1 && (
                      <p className="text-gray-300 mt-0.5">
                        Pedido #{h.orders[0].code}
                      </p>
                    )}
                  </div>
                )}
              </div>
            );
          })}

        {/* Badge de estado OCR */}
        <div className="absolute top-2 right-2">
          {!isProcessed ? (
            <span className="text-[10px] bg-amber-500/90 text-white font-semibold px-2 py-1 rounded-full">
              Procesando…
            </span>
          ) : hasHighlights ? (
            <span className="text-[10px] bg-green-500/90 text-white font-semibold px-2 py-1 rounded-full">
              {photo.highlight_count} producto
              {photo.highlight_count !== 1 ? "s" : ""} detectado
              {photo.highlight_count !== 1 ? "s" : ""}
            </span>
          ) : (
            <span className="text-[10px] bg-gray-500/70 text-white font-semibold px-2 py-1 rounded-full">
              Sin coincidencias
            </span>
          )}
        </div>
      </div>

      {/* Lista de productos detectados debajo de la imagen */}
      {hasHighlights && (
        <div className="space-y-1">
          {photo.highlights.map((h, i) => (
            <div
              key={i}
              className="flex items-center gap-2 bg-green-50 dark:bg-green-900/20 border border-green-200 dark:border-green-800/40 rounded-xl px-3 py-2"
            >
              <span className="text-green-500 text-sm shrink-0">✓</span>
              <div className="flex-1 min-w-0">
                <p className="text-xs font-semibold text-gray-800 dark:text-gray-200 line-clamp-1">
                  {h.description}
                </p>
                <p className="text-[11px] text-gray-500 dark:text-gray-400 font-mono">
                  {h.ean}
                </p>
              </div>
              <span className="shrink-0 text-xs font-bold text-green-600 dark:text-green-400">
                {h.qty_in_pallet} unid.
              </span>
            </div>
          ))}
        </div>
      )}
    </div>
  );
}

// ── Sección de tickets de un pedido ──────────────────────────────────────────
function TicketSection({ section }) {
  const [open, setOpen] = useState(true);
  const totalPhotos = section.tickets.reduce((s, t) => s + t.photos.length, 0);
  const totalHighlights = section.tickets.reduce(
    (s, t) => s + t.photos.reduce((ps, p) => ps + (p.highlight_count ?? 0), 0),
    0,
  );

  if (totalPhotos === 0) return null;

  return (
    <div className="rounded-2xl border border-gray-200 dark:border-gray-700 overflow-hidden">
      {/* Header */}
      <button
        onClick={() => setOpen((v) => !v)}
        className="w-full flex items-center gap-2 px-4 py-3 bg-indigo-700 text-left"
      >
        <span className="text-white text-base">🧾</span>
        <span className="text-white font-bold text-sm truncate">
          Pedido #{section.order_code}
          {section.customer && (
            <span className="font-normal opacity-80">
              {" "}
              · {section.customer}
            </span>
          )}
        </span>
        <span className="ml-auto text-indigo-300 text-xs shrink-0">
          {totalPhotos} foto{totalPhotos !== 1 ? "s" : ""}
          {totalHighlights > 0 && ` · ${totalHighlights} ✓`}
        </span>
        <span className="text-indigo-300 text-sm">{open ? "▲" : "▼"}</span>
      </button>

      {open && (
        <div className="bg-white dark:bg-gray-800 divide-y divide-gray-100 dark:divide-gray-700">
          {section.tickets.map((ticket) =>
            ticket.photos.map((photo) => (
              <div key={photo.id} className="p-4">
                {(ticket.code || ticket.note) && (
                  <p className="text-xs text-gray-400 dark:text-gray-500 mb-2">
                    {ticket.code && (
                      <span className="font-mono font-semibold">
                        #{ticket.code}{" "}
                      </span>
                    )}
                    {ticket.note}
                  </p>
                )}
                <TicketPhotoHighlight photo={photo} />
              </div>
            )),
          )}
        </div>
      )}
    </div>
  );
}

// ── Vista principal ──────────────────────────────────────────────────────────
export default function PalletPublicView() {
  const { code } = useParams();
  const [pallet, setPallet] = useState(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);
  const [copied, setCopied] = useState(false);

  // Dark mode: localStorage tiene prioridad; si no hay nada guardado, usa preferencia del sistema
  const [isDark, setIsDark] = useState(() => {
    try {
      const saved = localStorage.getItem("pallet-theme");
      if (saved !== null) return saved === "dark";
    } catch {
      /* ignore */
    }
    return window.matchMedia("(prefers-color-scheme: dark)").matches;
  });

  useEffect(() => {
    document.documentElement.classList.toggle("dark", isDark);
    try {
      localStorage.setItem("pallet-theme", isDark ? "dark" : "light");
    } catch {
      /* ignore */
    }
  }, [isDark]);

  useEffect(() => {
    apiGet(`/public/pallets/${code}`)
      .then(setPallet)
      .catch((e) =>
        setError(e?.response?.data?.message || "Pallet no encontrado"),
      )
      .finally(() => setLoading(false));
  }, [code]);

  async function handleShare() {
    const url = window.location.href;
    const title = `Pallet ${code} — Pallet Control`;
    const text = `Ver el contenido del pallet ${code}`;

    if (navigator.share) {
      try {
        await navigator.share({ title, text, url });
      } catch {
        // usuario canceló el share sheet — no hacer nada
      }
    } else {
      try {
        await navigator.clipboard.writeText(url);
        setCopied(true);
        setTimeout(() => setCopied(false), 2000);
      } catch {
        const el = document.createElement("textarea");
        el.value = url;
        document.body.appendChild(el);
        el.select();
        document.execCommand("copy");
        document.body.removeChild(el);
        setCopied(true);
        setTimeout(() => setCopied(false), 2000);
      }
    }
  }

  if (loading) {
    return (
      <div className="min-h-screen bg-gray-50 dark:bg-gray-900 flex items-center justify-center">
        <p className="text-gray-400 text-sm animate-pulse">Cargando…</p>
      </div>
    );
  }

  if (error) {
    return (
      <div className="min-h-screen bg-gray-50 dark:bg-gray-900 flex items-center justify-center p-6">
        <div className="text-center">
          <p className="text-5xl mb-4">📭</p>
          <p className="text-lg font-semibold text-gray-700 dark:text-gray-200">
            Pallet no encontrado
          </p>
          <p className="text-sm text-gray-400 mt-1">Código: {code}</p>
        </div>
      </div>
    );
  }

  const totalProds = pallet.orders.reduce((s, o) => s + o.items.length, 0);
  const isDone = pallet.status === "done";

  // Mapa order_id → índice de color (consistente en toda la página)
  const orderColorMap = {};
  pallet.orders.forEach((o, i) => {
    orderColorMap[o.id] = i;
  });

  return (
    <div className="min-h-screen bg-gray-50 dark:bg-gray-900">
      {/* ── Header sticky ──────────────────────────────────────── */}
      <div className="bg-white dark:bg-gray-800 border-b border-gray-200 dark:border-gray-700 sticky top-0 z-10 shadow-sm">
        <div className="max-w-lg mx-auto px-4 py-3 flex items-center gap-2">
          {/* Ícono */}
          <div className="w-10 h-10 rounded-xl bg-gray-900 dark:bg-gray-700 flex items-center justify-center shrink-0 text-xl">
            📦
          </div>

          {/* Info */}
          <div className="flex-1 min-w-0">
            <p className="font-bold text-base leading-tight text-gray-900 dark:text-white">
              {pallet.code}
            </p>
            <p className="text-xs text-gray-500 dark:text-gray-400">
              {pallet.orders.length} pedido
              {pallet.orders.length !== 1 ? "s" : ""} · {totalProds} prod. ·{" "}
              {pallet.bases.length} base{pallet.bases.length !== 1 ? "s" : ""}
            </p>
          </div>

          {/* Estado */}
          <span
            className={`shrink-0 text-xs font-semibold px-2.5 py-1 rounded-full ${
              isDone
                ? "bg-green-100 dark:bg-green-900/30 text-green-700 dark:text-green-300"
                : "bg-amber-100 dark:bg-amber-900/30 text-amber-700 dark:text-amber-300"
            }`}
          >
            {isDone ? "✅ Listo" : "🔄 En prep."}
          </span>

          {/* Toggle dark / light */}
          <button
            onClick={() => setIsDark((v) => !v)}
            title={isDark ? "Cambiar a modo claro" : "Cambiar a modo oscuro"}
            className="shrink-0 w-8 h-8 rounded-xl bg-gray-100 dark:bg-gray-700 hover:bg-gray-200 dark:hover:bg-gray-600 flex items-center justify-center transition-colors"
          >
            {isDark ? (
              /* Sol → ir a light */
              <svg
                className="w-4 h-4 text-amber-400"
                fill="currentColor"
                viewBox="0 0 24 24"
              >
                <path d="M12 2.25a.75.75 0 0 1 .75.75v2.25a.75.75 0 0 1-1.5 0V3a.75.75 0 0 1 .75-.75ZM7.5 12a4.5 4.5 0 1 1 9 0 4.5 4.5 0 0 1-9 0ZM18.894 6.166a.75.75 0 0 0-1.06-1.06l-1.591 1.59a.75.75 0 1 0 1.06 1.061l1.591-1.59ZM21.75 12a.75.75 0 0 1-.75.75h-2.25a.75.75 0 0 1 0-1.5H21a.75.75 0 0 1 .75.75ZM17.834 18.894a.75.75 0 0 0 1.06-1.06l-1.59-1.591a.75.75 0 1 0-1.061 1.06l1.59 1.591ZM12 18a.75.75 0 0 1 .75.75V21a.75.75 0 0 1-1.5 0v-2.25A.75.75 0 0 1 12 18ZM7.166 17.834a.75.75 0 0 0-1.06 1.06l1.59 1.591a.75.75 0 1 0 1.061-1.06l-1.59-1.591ZM6 12a.75.75 0 0 1-.75.75H3a.75.75 0 0 1 0-1.5h2.25A.75.75 0 0 1 6 12ZM6.166 6.166a.75.75 0 0 0 1.06-1.06L5.636 3.515a.75.75 0 0 0-1.061 1.06l1.591 1.591Z" />
              </svg>
            ) : (
              /* Luna → ir a dark */
              <svg
                className="w-4 h-4 text-gray-500"
                fill="currentColor"
                viewBox="0 0 24 24"
              >
                <path
                  fillRule="evenodd"
                  d="M9.528 1.718a.75.75 0 0 1 .162.819A8.97 8.97 0 0 0 9 6a9 9 0 0 0 9 9 8.97 8.97 0 0 0 3.463-.69.75.75 0 0 1 .981.98 10.503 10.503 0 0 1-9.694 6.46c-5.799 0-10.5-4.7-10.5-10.5 0-4.368 2.667-8.112 6.46-9.694a.75.75 0 0 1 .818.162Z"
                  clipRule="evenodd"
                />
              </svg>
            )}
          </button>

          {/* Botón compartir */}
          <button
            onClick={handleShare}
            title="Compartir"
            className="shrink-0 flex items-center gap-1.5 px-3 py-1.5 rounded-xl bg-blue-600 hover:bg-blue-700 text-white text-xs font-semibold transition-colors active:scale-95"
          >
            {copied ? (
              <>
                <svg
                  className="w-3.5 h-3.5"
                  fill="none"
                  viewBox="0 0 24 24"
                  strokeWidth={2.5}
                  stroke="currentColor"
                >
                  <path
                    strokeLinecap="round"
                    strokeLinejoin="round"
                    d="M4.5 12.75l6 6 9-13.5"
                  />
                </svg>
                <span>Copiado</span>
              </>
            ) : (
              <>
                <svg
                  className="w-3.5 h-3.5"
                  fill="none"
                  viewBox="0 0 24 24"
                  strokeWidth={2}
                  stroke="currentColor"
                >
                  <path
                    strokeLinecap="round"
                    strokeLinejoin="round"
                    d="M7.217 10.907a2.25 2.25 0 1 0 0 2.186m0-2.186c.18.324.283.696.283 1.093s-.103.77-.283 1.093m0-2.186 9.566-5.314m-9.566 7.5 9.566 5.314m0 0a2.25 2.25 0 1 0 3.935 2.186 2.25 2.25 0 0 0-3.935-2.186zm0-12.814a2.25 2.25 0 1 0 3.933-2.185 2.25 2.25 0 0 0-3.933 2.185z"
                  />
                </svg>
                <span className="hidden sm:inline">Compartir</span>
              </>
            )}
          </button>
        </div>
      </div>

      {/* ── Contenido ─────────────────────────────────────────── */}
      <div className="max-w-lg mx-auto px-4 py-5 space-y-8">
        {/* ─── Fotos del pallet ─────────────────────────────── */}
        {pallet.photos?.length > 0 && (
          <section>
            <h2 className="text-xs font-bold uppercase tracking-widest text-gray-400 dark:text-gray-500 mb-3">
              📸 Fotos del pallet
            </h2>
            <PhotoStrip photos={pallet.photos} />
          </section>
        )}

        {/* ─── Pedidos ──────────────────────────────────────── */}
        <section>
          <h2 className="text-xs font-bold uppercase tracking-widest text-gray-400 dark:text-gray-500 mb-3">
            🧾 Pedidos en este pallet
          </h2>
          {pallet.orders.length === 0 ? (
            <div className="bg-white dark:bg-gray-800 rounded-2xl border border-gray-100 dark:border-gray-700 py-12 text-center text-gray-400">
              <p className="text-4xl mb-3">🗂️</p>
              <p className="text-sm">Sin pedidos asignados</p>
            </div>
          ) : (
            <div className="space-y-3">
              {pallet.orders.map((order, idx) => (
                <OrderCard key={order.id} order={order} idx={idx} />
              ))}
            </div>
          )}
        </section>

        {/* ─── Bases ────────────────────────────────────────── */}
        {pallet.bases?.length > 0 && (
          <section>
            <h2 className="text-xs font-bold uppercase tracking-widest text-gray-400 dark:text-gray-500 mb-3">
              🧱 Bases del pallet
            </h2>
            <div className="space-y-3">
              {pallet.bases.map((base, i) => (
                <BaseCard
                  key={base.id}
                  base={base}
                  baseNum={i + 1}
                  orderColorMap={orderColorMap}
                />
              ))}
            </div>
          </section>
        )}

        {/* ─── Tickets del cliente ──────────────────────────── */}
        {pallet.ticket_sections?.some((s) =>
          s.tickets.some((t) => t.photos.length > 0),
        ) && (
          <section>
            <h2 className="text-xs font-bold uppercase tracking-widest text-gray-400 dark:text-gray-500 mb-3">
              🧾 Tickets del cliente
            </h2>
            <p className="text-xs text-gray-400 dark:text-gray-500 mb-3 -mt-1">
              Los productos resaltados en verde coinciden con lo que está en
              este pallet.
            </p>
            <div className="space-y-3">
              {pallet.ticket_sections.map((section) => (
                <TicketSection key={section.order_id} section={section} />
              ))}
            </div>
          </section>
        )}

        {/* Footer */}
        <p className="text-center text-xs text-gray-300 dark:text-gray-600 pb-4 pt-2">
          Pallet Control · Solo lectura
        </p>
      </div>
    </div>
  );
}
