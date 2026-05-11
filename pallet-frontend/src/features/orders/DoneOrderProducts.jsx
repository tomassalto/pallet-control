import { useMemo, useState } from "react";
import { Link } from "react-router-dom";

const SEC_LABEL =
  "text-xs font-semibold uppercase tracking-wider text-gray-400 dark:text-gray-500";

function getPhotoUrl(photo) {
  if (photo.url) {
    if (photo.url.startsWith("http://") || photo.url.startsWith("https://")) {
      return photo.url.replace(/([^:]\/)\/+/g, "$1");
    }
    return photo.url.startsWith("/") ? photo.url : `/${photo.url}`;
  }
  const API_BASE = import.meta.env.VITE_API_BASE_URL || "";
  const baseUrl = API_BASE.replace("/api/v1", "").replace(/\/$/, "") || "";
  const storagePath = photo.path.startsWith("/") ? photo.path : `/${photo.path}`;
  return `${baseUrl}/storage${storagePath}`;
}

// { pallet_id -> { base_id -> [{ ...item, qty_in_base }] } }
function buildLocationMap(items) {
  const map = {};
  for (const item of items) {
    for (const loc of item.locations || []) {
      if (!map[loc.pallet_id]) map[loc.pallet_id] = {};
      if (!map[loc.pallet_id][loc.base_id]) map[loc.pallet_id][loc.base_id] = [];
      map[loc.pallet_id][loc.base_id].push({ ...item, qty_in_base: loc.qty });
    }
  }
  return map;
}

// ── Fila de producto dentro de una base ───────────────────────────────────────

function ProductRow({ item }) {
  const [imgErr, setImgErr] = useState(false);
  const shortEan = (item.ean && String(item.ean).slice(-4).padStart(4, "0")) || "—";

  return (
    <div className="flex items-center gap-3 px-3 py-2.5">
      <div className="w-10 h-10 shrink-0 rounded-lg overflow-hidden bg-gray-100 dark:bg-gray-700 flex items-center justify-center border border-gray-200 dark:border-gray-600">
        {item.image_url && !imgErr ? (
          <img
            src={item.image_url}
            alt={item.description}
            className="w-full h-full object-contain"
            onError={() => setImgErr(true)}
          />
        ) : (
          <span className="text-base select-none">📦</span>
        )}
      </div>

      <div className="flex-1 min-w-0">
        <p className="text-sm text-gray-800 dark:text-gray-100 leading-snug line-clamp-2">
          {item.description}
        </p>
        <p className="text-[10px] font-mono text-gray-400 dark:text-gray-500 mt-0.5">
          {shortEan}
        </p>
      </div>

      <span className="shrink-0 text-sm font-bold text-gray-900 dark:text-white tabular-nums">
        {item.qty_in_base} u.
      </span>
    </div>
  );
}

// ── Sección de base dentro del acordeón de pallet ─────────────────────────────

function BaseSection({ base, items }) {
  const totalQty = items.reduce((s, i) => s + i.qty_in_base, 0);
  const sorted = [...items].sort((a, b) =>
    a.description.localeCompare(b.description),
  );

  return (
    <div className="space-y-2">
      {/* Header base */}
      <div className="flex items-center gap-2 px-1">
        <div className="w-1.5 h-1.5 rounded-full bg-blue-400 dark:bg-blue-500 shrink-0" />
        <p className="text-xs font-semibold text-gray-600 dark:text-gray-400 flex-1 truncate">
          {base.name}
        </p>
        <span className="text-[10px] font-mono text-gray-400 dark:text-gray-500 tabular-nums">
          {totalQty} u.
        </span>
      </div>

      {/* Productos */}
      <div className="rounded-xl overflow-hidden border border-gray-100 dark:border-gray-700/40 divide-y divide-gray-100 dark:divide-gray-700/30 bg-white dark:bg-gray-800/30">
        {sorted.map((item) => (
          <ProductRow key={`${item.id}-${base.id}`} item={item} />
        ))}
      </div>

      {/* Fotos de la base en scroll horizontal */}
      {base.photos?.length > 0 && (
        <div
          className="flex gap-2 overflow-x-auto pb-1"
          style={{ scrollbarWidth: "none" }}
        >
          {base.photos.map((photo) => (
            <img
              key={photo.id}
              src={getPhotoUrl(photo)}
              alt="Foto de base"
              className="h-20 w-20 object-cover rounded-xl shrink-0 border border-gray-200 dark:border-gray-700"
            />
          ))}
        </div>
      )}
    </div>
  );
}

// ── Acordeón de pallet ────────────────────────────────────────────────────────

function PalletAccordion({ pallet, locationMap }) {
  const [open, setOpen] = useState(true);

  const palletBaseMap = locationMap[pallet.id] || {};
  const allBases = pallet.bases || [];
  const activeBases = allBases.filter((b) => palletBaseMap[b.id]?.length > 0);
  const totalQty = Object.values(palletBaseMap)
    .flat()
    .reduce((s, i) => s + i.qty_in_base, 0);
  const totalProducts = new Set(
    Object.values(palletBaseMap).flat().map((i) => i.id),
  ).size;
  const isDone = pallet.status === "done";

  return (
    <div className="relative bg-white dark:bg-gray-800/60 border border-gray-200 dark:border-gray-700/50 rounded-2xl overflow-hidden shadow-sm">
      {/* Acento lateral */}
      <div
        className={`absolute left-0 top-0 bottom-0 w-1 ${isDone ? "bg-green-500" : "bg-blue-500"}`}
      />

      {/* Header toggle */}
      <button
        onClick={() => setOpen((o) => !o)}
        className="w-full pl-5 pr-4 py-4 flex items-start justify-between gap-3 text-left hover:bg-gray-50/50 dark:hover:bg-gray-700/20 transition-colors"
      >
        <div className="flex-1 min-w-0">
          <div className="flex items-center gap-2 flex-wrap">
            <Link
              to={`/pallet/${pallet.id}`}
              onClick={(e) => e.stopPropagation()}
              className="font-mono font-bold text-gray-900 dark:text-white hover:underline"
            >
              {pallet.code}
            </Link>
            <span
              className={`text-[10px] px-1.5 py-0.5 rounded-full font-semibold ${
                isDone
                  ? "bg-green-100 dark:bg-green-900/30 text-green-700 dark:text-green-400"
                  : "bg-blue-100 dark:bg-blue-900/30 text-blue-700 dark:text-blue-400"
              }`}
            >
              {isDone ? "Completo" : "En proceso"}
            </span>
          </div>
          <p className="text-xs text-gray-400 dark:text-gray-500 mt-0.5 tabular-nums">
            {activeBases.length} base{activeBases.length !== 1 ? "s" : ""} ·{" "}
            {totalProducts} producto{totalProducts !== 1 ? "s" : ""} · {totalQty}{" "}
            u.
          </p>
        </div>
        <svg
          className={`w-4 h-4 text-gray-400 dark:text-gray-500 shrink-0 mt-0.5 transition-transform duration-200 ${open ? "rotate-180" : ""}`}
          fill="none"
          viewBox="0 0 24 24"
          strokeWidth={2.5}
          stroke="currentColor"
        >
          <path
            strokeLinecap="round"
            strokeLinejoin="round"
            d="M19.5 8.25l-7.5 7.5-7.5-7.5"
          />
        </svg>
      </button>

      {/* Contenido */}
      {open && activeBases.length > 0 && (
        <div className="pl-5 pr-4 pb-4 space-y-5 border-t border-gray-100 dark:border-gray-700/30 pt-4">
          {activeBases.map((base) => (
            <BaseSection
              key={base.id}
              base={base}
              items={palletBaseMap[base.id] || []}
            />
          ))}
        </div>
      )}
    </div>
  );
}

// ── Export principal ──────────────────────────────────────────────────────────

export default function DoneOrderProducts({ pallets, items, pendingItemsCount }) {
  const locationMap = useMemo(() => buildLocationMap(items), [items]);

  const removedItems = items.filter(
    (it) => it.status === "removed" && !it.locations?.length,
  );

  return (
    <section className="space-y-3">
      {/* Alerta faltantes */}
      {pendingItemsCount > 0 && (
        <Link
          to="/pending-items"
          className="flex items-center gap-3 bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800/50 rounded-2xl px-4 py-3 hover:bg-red-100 dark:hover:bg-red-900/30 transition-colors"
        >
          <span className="text-2xl shrink-0">🚨</span>
          <div className="flex-1 min-w-0">
            <p className="text-sm font-bold text-red-700 dark:text-red-400">
              {pendingItemsCount === 1
                ? "1 faltante sin resolver"
                : `${pendingItemsCount} faltantes sin resolver`}
            </p>
            <p className="text-xs text-red-500 dark:text-red-500 mt-0.5">
              Hay productos de este pedido que no fueron entregados
            </p>
          </div>
          <svg
            className="w-4 h-4 text-red-400 shrink-0"
            fill="none"
            viewBox="0 0 24 24"
            strokeWidth={2.5}
            stroke="currentColor"
          >
            <path strokeLinecap="round" strokeLinejoin="round" d="M9 5l7 7-7 7" />
          </svg>
        </Link>
      )}

      <p className={SEC_LABEL}>Distribución por pallet</p>

      {pallets.map((pallet) => (
        <PalletAccordion
          key={pallet.id}
          pallet={pallet}
          locationMap={locationMap}
        />
      ))}

      {/* Productos removidos sin ubicación */}
      {removedItems.length > 0 && (
        <div className="rounded-2xl border border-red-200 dark:border-red-800/40 bg-red-50 dark:bg-red-900/10 px-4 py-3 space-y-2">
          <p className="text-xs font-semibold text-red-600 dark:text-red-400 uppercase tracking-wider">
            Removidos ({removedItems.length})
          </p>
          {removedItems.map((it) => (
            <p
              key={it.id}
              className="text-sm text-red-700 dark:text-red-400 line-through"
            >
              {it.description}
            </p>
          ))}
        </div>
      )}
    </section>
  );
}
