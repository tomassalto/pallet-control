import { useEffect, useState } from "react";
import { useParams } from "react-router-dom";
import { apiGet, apiPatch } from "../api/client";
import { toastSuccess, toastError } from "../ui/toast";
import BackButton from "../ui/BackButton";
import Title from "../ui/Title";

// Thumbnail con fallback al emoji 📦
function ProductImage({ src, alt }) {
  const [err, setErr] = useState(false);
  if (!src || err) return <span className="text-2xl select-none">📦</span>;
  return (
    <img
      src={src}
      alt={alt}
      className="w-full h-full object-contain"
      onError={() => setErr(true)}
    />
  );
}

// ── Paleta consistente con la vista pública ──────────────────────────────────
const ORDER_COLORS = [
  { bg: "bg-blue-50",    badge: "bg-blue-600",    border: "border-l-blue-500"    },
  { bg: "bg-emerald-50", badge: "bg-emerald-600",  border: "border-l-emerald-500" },
  { bg: "bg-violet-50",  badge: "bg-violet-600",   border: "border-l-violet-500"  },
  { bg: "bg-amber-50",   badge: "bg-amber-500",    border: "border-l-amber-400"   },
  { bg: "bg-rose-50",    badge: "bg-rose-600",     border: "border-l-rose-500"    },
  { bg: "bg-cyan-50",    badge: "bg-cyan-600",     border: "border-l-cyan-500"    },
];

export default function BaseProducts() {
  const { palletId, baseId } = useParams();

  const [loading, setLoading]   = useState(true);
  const [saving, setSaving]     = useState(false);
  const [pallet, setPallet]     = useState(null);
  const [base, setBase]         = useState(null);
  const [orders, setOrders]     = useState([]);
  const [error, setError]       = useState("");

  // Mapa: order_item_id → qty en esta base (0 = no está, >0 = está)
  const [quantities, setQuantities] = useState({});

  // ── Carga ──────────────────────────────────────────────────────────────────
  async function load() {
    setError("");
    setLoading(true);
    try {
      const data = await apiGet(`/pallets/${palletId}`);
      const palletData = data.pallet ?? null;
      const ordersData = data.orders ?? [];

      setPallet(palletData);
      setOrders(ordersData);

      const foundBase = data.bases?.find((b) => b.id === parseInt(baseId, 10));
      setBase(foundBase ?? null);

      // Inicializar quantidades desde los ítems ya asignados a esta base
      const init = {};
      foundBase?.order_items?.forEach((item) => {
        init[item.id] = item.pivot?.qty ?? 0;
      });
      setQuantities(init);
    } catch (e) {
      setError(e.message || "Error cargando datos");
    } finally {
      setLoading(false);
    }
  }

  useEffect(() => {
    load();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [palletId, baseId]);

  // ── Helpers de disponibilidad ──────────────────────────────────────────────

  /** Cuántas unidades de este ítem están asignadas en OTRAS bases del pallet */
  function assignedElsewhere(orderItemId) {
    if (!pallet?.bases) return 0;
    return pallet.bases.reduce((total, b) => {
      if (b.id === parseInt(baseId, 10)) return total;
      const found = b.order_items?.find((i) => i.id === orderItemId);
      return total + (found?.pivot?.qty ?? 0);
    }, 0);
  }

  /** Qty máxima que se puede asignar a ESTA base */
  function maxQty(orderItem) {
    return Math.max(0, (orderItem.qty ?? 0) - assignedElsewhere(orderItem.id));
  }

  // ── Stepper ────────────────────────────────────────────────────────────────
  function setQty(itemId, value) {
    setQuantities((prev) => ({ ...prev, [itemId]: value }));
  }

  function inc(orderItem) {
    const cur = quantities[orderItem.id] ?? 0;
    const max = maxQty(orderItem);
    if (cur < max) setQty(orderItem.id, cur + 1);
  }

  function dec(itemId) {
    const cur = quantities[itemId] ?? 0;
    if (cur > 0) setQty(itemId, cur - 1);
  }

  function handleInput(orderItem, raw) {
    const v = parseInt(raw, 10);
    if (isNaN(v) || v < 0) { setQty(orderItem.id, 0); return; }
    setQty(orderItem.id, Math.min(v, maxQty(orderItem)));
  }

  // ── Guardar ────────────────────────────────────────────────────────────────
  async function onSave() {
    const items = Object.entries(quantities)
      .filter(([, qty]) => qty > 0)
      .map(([id, qty]) => ({ order_item_id: parseInt(id, 10), qty }));

    setSaving(true);
    try {
      await apiPatch(`/pallets/${palletId}/bases/${baseId}`, { items });
      toastSuccess("Base actualizada");
      await load();
    } catch (e) {
      toastError(e.response?.data?.message ?? e.message ?? "Error al guardar");
    } finally {
      setSaving(false);
    }
  }

  // ── Estados de carga ───────────────────────────────────────────────────────
  if (loading) return <p className="text-sm text-gray-500 p-4">Cargando…</p>;

  if (error || !base) {
    return (
      <div className="space-y-3">
        <BackButton to={`/pallet/${palletId}`} />
        <div className="bg-red-50 border border-red-200 text-red-800 rounded-xl p-3">
          {error || "Base no encontrada"}
        </div>
      </div>
    );
  }

  const palletDone    = pallet?.status === "done";
  const selectedCount = Object.values(quantities).filter((q) => q > 0).length;

  // ── Render ─────────────────────────────────────────────────────────────────
  return (
    <div className="space-y-4 pb-28">
      <BackButton to={`/pallet/${palletId}`} />

      {/* Header */}
      <div className="bg-white border rounded-2xl p-4 space-y-0.5">
        <Title size="2xl">{pallet?.code}</Title>
        <p className="text-gray-600 font-semibold text-lg">
          {base.name || `Base #${base.id}`}
        </p>
      </div>

      {palletDone && (
        <div className="bg-yellow-50 border border-yellow-200 text-yellow-800 rounded-xl p-3 text-sm">
          Este pallet está finalizado. Solo lectura.
        </div>
      )}

      {/* ── Una tarjeta por pedido ─────────────────────────────────────── */}
      {orders.length === 0 ? (
        <div className="text-center text-sm text-gray-400 py-12">
          No hay pedidos asociados a este pallet.
        </div>
      ) : (
        <div className="space-y-3">
          {orders.map((order, orderIdx) => {
            const c         = ORDER_COLORS[orderIdx % ORDER_COLORS.length];
            const orderDone = order.status === "done";

            // Solo mostrar los ítems que ya están asignados a esta base
            const baseItemIds = new Set((base?.order_items ?? []).map((i) => i.id));
            const visibleItems = (order.items ?? []).filter((item) => baseItemIds.has(item.id));

            if (visibleItems.length === 0) return null;

            return (
              <div
                key={order.id}
                className="bg-white rounded-2xl border border-gray-200 overflow-hidden"
              >
                {/* Cabecera del pedido */}
                <div className={`px-4 py-3 flex items-center gap-2 ${c.bg}`}>
                  <span
                    className={`text-xs font-bold text-white px-2.5 py-0.5 rounded-full ${c.badge}`}
                  >
                    #{order.code}
                  </span>
                  {orderDone && (
                    <span className="text-xs text-gray-500 italic ml-1">
                      pedido finalizado · solo lectura
                    </span>
                  )}
                  <span className="ml-auto text-xs text-gray-400">
                    {visibleItems.length} producto{visibleItems.length !== 1 ? "s" : ""}
                  </span>
                </div>

                {/* Filas de ítems */}
                <div className="divide-y divide-gray-100">
                  {visibleItems.map((item) => {
                    const cur      = quantities[item.id] ?? 0;
                    const max      = maxQty(item);
                    const active   = cur > 0;
                    const readOnly = palletDone || orderDone;

                    return (
                      <div
                        key={item.id}
                        className={[
                          "flex items-center gap-3 px-4 py-3 transition-colors",
                          active ? `border-l-4 ${c.border}` : "",
                        ].join(" ")}
                      >
                        {/* Imagen del producto */}
                        <div className="w-12 h-12 flex-shrink-0 rounded-xl overflow-hidden bg-gray-100 flex items-center justify-center border border-gray-200">
                          <ProductImage src={item.image_url} alt={item.description} />
                        </div>

                        {/* Descripción */}
                        <div className="flex-1 min-w-0">
                          <p className="text-sm font-medium leading-snug">
                            {item.description}
                          </p>
                          <p className="text-xs font-mono text-gray-400 mt-0.5">
                            {item.ean}
                          </p>
                        </div>

                        {/* Stepper o valor fijo */}
                        {readOnly ? (
                          <div className="flex-shrink-0 text-center min-w-[52px]">
                            <p className="text-xl font-bold text-gray-800 leading-none">
                              {cur}
                            </p>
                            <p className="text-[10px] text-gray-400">unid.</p>
                          </div>
                        ) : (
                          <div className="flex items-center gap-1.5 flex-shrink-0">
                            {/* − */}
                            <button
                              onClick={() => dec(item.id)}
                              disabled={cur === 0}
                              className="w-8 h-8 rounded-full border border-gray-300 flex items-center justify-center text-gray-600 disabled:opacity-25 hover:bg-gray-50 text-lg leading-none select-none"
                            >
                              −
                            </button>

                            {/* Input numérico */}
                            <input
                              type="text"
                              inputMode="numeric"
                              value={cur === 0 ? "" : cur}
                              placeholder="0"
                              onChange={(e) => handleInput(item, e.target.value)}
                              onFocus={(e) => e.target.select()}
                              className="w-12 text-center text-sm font-bold border rounded-lg py-1 focus:outline-none focus:ring-2 focus:ring-gray-300"
                            />

                            {/* + */}
                            <button
                              onClick={() => inc(item)}
                              disabled={cur >= max}
                              className={`w-8 h-8 rounded-full flex items-center justify-center text-white disabled:opacity-25 text-lg leading-none select-none ${c.badge}`}
                            >
                              +
                            </button>
                          </div>
                        )}
                      </div>
                    );
                  })}
                </div>
              </div>
            );
          })}
        </div>
      )}

      {/* ── Botón guardar sticky ─────────────────────────────────────────── */}
      {!palletDone && (
        <div className="fixed bottom-0 left-0 right-0 bg-white border-t shadow-xl z-20 px-4 py-3">
          <div className="max-w-lg mx-auto">
            <button
              onClick={onSave}
              disabled={saving}
              className="w-full py-3.5 rounded-2xl bg-gray-900 text-white font-bold text-sm disabled:opacity-60 transition-opacity"
            >
              {saving
                ? "Guardando…"
                : `Guardar — ${selectedCount} producto${selectedCount !== 1 ? "s" : ""} en esta base`}
            </button>
          </div>
        </div>
      )}
    </div>
  );
}
