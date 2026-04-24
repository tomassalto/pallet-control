import { useEffect, useState } from "react";
import { useParams } from "react-router-dom";
import { apiGet } from "../api/client";

// ── Paleta de colores por pedido ─────────────────────────────────────────────
const COLORS = [
  { bg: "bg-blue-50",   border: "border-blue-200",   badge: "bg-blue-600",   dot: "bg-blue-500"   },
  { bg: "bg-emerald-50",border: "border-emerald-200", badge: "bg-emerald-600",dot: "bg-emerald-500" },
  { bg: "bg-violet-50", border: "border-violet-200",  badge: "bg-violet-600", dot: "bg-violet-500"  },
  { bg: "bg-amber-50",  border: "border-amber-200",   badge: "bg-amber-500",  dot: "bg-amber-400"   },
  { bg: "bg-rose-50",   border: "border-rose-200",    badge: "bg-rose-600",   dot: "bg-rose-500"    },
  { bg: "bg-cyan-50",   border: "border-cyan-200",    badge: "bg-cyan-600",   dot: "bg-cyan-500"    },
];
const color = (idx) => COLORS[idx % COLORS.length];

// ── Fila de producto ─────────────────────────────────────────────────────────
function ProductRow({ item, c }) {
  const [err, setErr] = useState(false);
  return (
    <div className="flex items-center gap-3 py-2.5 border-b border-gray-100 last:border-0">
      {/* Imagen */}
      <div className="w-12 h-12 flex-shrink-0 rounded-xl overflow-hidden bg-gray-100 flex items-center justify-center">
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
        <p className="text-sm font-medium leading-snug line-clamp-2">{item.description}</p>
        <p className="text-xs text-gray-400 mt-0.5 font-mono">{item.ean}</p>
      </div>

      {/* Cantidad */}
      <div className={`flex-shrink-0 min-w-[48px] text-center py-1.5 px-2 rounded-xl ${c.badge} text-white`}>
        <div className="text-xl font-bold leading-none">{item.qty}</div>
        <div className="text-[9px] uppercase tracking-wide opacity-75 mt-0.5">unid.</div>
      </div>
    </div>
  );
}

// ── Galería de fotos horizontal ──────────────────────────────────────────────
function PhotoStrip({ photos }) {
  if (!photos?.length) return null;
  return (
    <div className="flex gap-2 overflow-x-auto pb-1" style={{ scrollbarWidth: "none" }}>
      {photos.map((p) => (
        <a
          key={p.id}
          href={p.url}
          target="_blank"
          rel="noopener noreferrer"
          className="flex-shrink-0 block"
        >
          <img
            src={p.url}
            alt=""
            className="h-28 w-28 object-cover rounded-2xl border border-gray-200 shadow-sm"
          />
        </a>
      ))}
    </div>
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
        <span className={`text-xs font-bold px-2.5 py-0.5 rounded-full text-white ${c.badge}`}>
          #{order.code}
        </span>
        {order.customer && (
          <span className="text-sm text-gray-700 font-medium truncate">{order.customer}</span>
        )}
        <span className="ml-auto text-xs text-gray-400 flex-shrink-0">
          {order.items.length} prod.
        </span>
        <span className="text-gray-400 text-sm">{open ? "▲" : "▼"}</span>
      </button>

      {/* Productos */}
      {open && (
        <div className="bg-white px-4">
          {order.items.length === 0 ? (
            <p className="py-6 text-sm text-gray-400 text-center">Sin productos registrados</p>
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
        <span className={`w-2 h-2 rounded-full flex-shrink-0 ${c.dot}`} />
        <span className={`text-xs font-bold text-white px-2 py-0.5 rounded-full ${c.badge}`}>
          #{group.order_code}
        </span>
        {group.customer && (
          <span className="text-xs text-gray-500 truncate">{group.customer}</span>
        )}
        <span className="ml-auto text-xs text-gray-400 flex-shrink-0">
          {group.items.length} prod.
        </span>
      </div>
      <div className="px-4 bg-white">
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
    <div className="rounded-2xl border border-gray-200 overflow-hidden shadow-sm bg-white">
      {/* Header oscuro de la base */}
      <button
        onClick={() => setOpen((v) => !v)}
        className="w-full flex items-center gap-2 px-4 py-3 bg-gray-800 text-left"
      >
        <span className="text-white text-base">🧱</span>
        <span className="text-white font-bold text-sm">
          {base.name || `Base ${baseNum}`}
        </span>
        <span className="ml-auto text-gray-400 text-xs flex-shrink-0">
          {totalItems} prod. · {base.photos?.length ?? 0} foto{base.photos?.length !== 1 ? "s" : ""}
        </span>
        <span className="text-gray-400 text-sm">{open ? "▲" : "▼"}</span>
      </button>

      {open && (
        <>
          {/* Fotos de la base */}
          {hasPhotos && (
            <div className="px-4 pt-3 pb-2 bg-gray-50 border-b border-gray-100">
              <PhotoStrip photos={base.photos} />
            </div>
          )}

          {/* Productos agrupados por pedido */}
          {hasItems ? (
            <div className="divide-y divide-gray-100">
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
              <p className="py-6 text-sm text-gray-400 text-center">
                Base sin contenido registrado
              </p>
            )
          )}
        </>
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

  useEffect(() => {
    apiGet(`/public/pallets/${code}`)
      .then(setPallet)
      .catch((e) => setError(e?.response?.data?.message || "Pallet no encontrado"))
      .finally(() => setLoading(false));
  }, [code]);

  if (loading) {
    return (
      <div className="min-h-screen bg-gray-50 flex items-center justify-center">
        <p className="text-gray-400 text-sm animate-pulse">Cargando…</p>
      </div>
    );
  }

  if (error) {
    return (
      <div className="min-h-screen bg-gray-50 flex items-center justify-center p-6">
        <div className="text-center">
          <p className="text-5xl mb-4">📭</p>
          <p className="text-lg font-semibold text-gray-700">Pallet no encontrado</p>
          <p className="text-sm text-gray-400 mt-1">Código: {code}</p>
        </div>
      </div>
    );
  }

  const totalProds = pallet.orders.reduce((s, o) => s + o.items.length, 0);
  const isDone = pallet.status === "done";

  // Mapa order_id → índice de color (consistente en toda la página)
  const orderColorMap = {};
  pallet.orders.forEach((o, i) => { orderColorMap[o.id] = i; });

  return (
    <div className="min-h-screen bg-gray-50">
      {/* ── Header sticky ──────────────────────────────────────── */}
      <div className="bg-white border-b sticky top-0 z-10 shadow-sm">
        <div className="max-w-lg mx-auto px-4 py-4 flex items-center gap-3">
          <div className="w-11 h-11 rounded-2xl bg-gray-900 flex items-center justify-center flex-shrink-0 text-2xl">
            📦
          </div>
          <div className="flex-1 min-w-0">
            <p className="font-bold text-lg leading-tight">Pallet {pallet.code}</p>
            <p className="text-xs text-gray-500">
              {pallet.orders.length} pedido{pallet.orders.length !== 1 ? "s" : ""} ·{" "}
              {totalProds} producto{totalProds !== 1 ? "s" : ""} ·{" "}
              {pallet.bases.length} base{pallet.bases.length !== 1 ? "s" : ""}
            </p>
          </div>
          <span
            className={`flex-shrink-0 text-xs font-semibold px-3 py-1 rounded-full ${
              isDone
                ? "bg-green-100 text-green-700"
                : "bg-amber-100 text-amber-700"
            }`}
          >
            {isDone ? "✅ Finalizado" : "🔄 En preparación"}
          </span>
        </div>
      </div>

      {/* ── Contenido ─────────────────────────────────────────── */}
      <div className="max-w-lg mx-auto px-4 py-5 space-y-8">

        {/* ─── Fotos del pallet ─────────────────────────────── */}
        {pallet.photos?.length > 0 && (
          <section>
            <h2 className="text-xs font-bold uppercase tracking-widest text-gray-400 mb-3">
              📸 Fotos del pallet
            </h2>
            <PhotoStrip photos={pallet.photos} />
          </section>
        )}

        {/* ─── Pedidos ──────────────────────────────────────── */}
        <section>
          <h2 className="text-xs font-bold uppercase tracking-widest text-gray-400 mb-3">
            🧾 Pedidos en este pallet
          </h2>
          {pallet.orders.length === 0 ? (
            <div className="bg-white rounded-2xl border border-gray-100 py-12 text-center text-gray-400">
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
            <h2 className="text-xs font-bold uppercase tracking-widest text-gray-400 mb-3">
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

        {/* Footer */}
        <p className="text-center text-xs text-gray-300 pb-4 pt-2">
          Pallet Control · Solo lectura
        </p>
      </div>
    </div>
  );
}
