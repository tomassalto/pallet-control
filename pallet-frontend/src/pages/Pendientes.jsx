import { useState, useEffect, useRef } from "react";
import { apiGet, apiPost, apiPatch, apiDelete } from "../api/client";
import { toastSuccess, toastError } from "../ui/toast";
import BackButton from "../ui/BackButton";

// ── helpers ──────────────────────────────────────────────────────────────────
function relativeDate(iso) {
  if (!iso) return "";
  const d = new Date(iso);
  const now = new Date();
  const diff = Math.floor((now - d) / 1000);
  if (diff < 60) return "hace un momento";
  if (diff < 3600) return `hace ${Math.floor(diff / 60)} min`;
  if (diff < 86400) return `hace ${Math.floor(diff / 3600)} h`;
  return d.toLocaleDateString("es-AR", { day: "numeric", month: "short" });
}

const INPUT_CLS =
  "w-full border border-gray-300 dark:border-gray-600 rounded-xl px-4 py-3 bg-white dark:bg-gray-700 dark:text-white focus:outline-none focus:ring-2 focus:ring-gray-900 dark:focus:ring-white transition-shadow";
const SEC_LABEL =
  "text-xs font-semibold uppercase tracking-wider text-gray-400 dark:text-gray-500";

// ── OrderSearch ───────────────────────────────────────────────────────────────
function OrderSearch({ value, onChange }) {
  const [query, setQuery] = useState(value ? `#${value.code}` : "");
  const [results, setResults] = useState([]);
  const [loading, setLoading] = useState(false);
  const [open, setOpen] = useState(false);
  const ref = useRef(null);

  useEffect(() => {
    function outside(e) {
      if (ref.current && !ref.current.contains(e.target)) setOpen(false);
    }
    document.addEventListener("mousedown", outside);
    return () => document.removeEventListener("mousedown", outside);
  }, []);

  useEffect(() => {
    if (!query || query.startsWith("#")) return;
    const t = setTimeout(async () => {
      setLoading(true);
      try {
        const res = await apiGet(
          `/orders?search=${encodeURIComponent(query)}&limit=8`
        );
        setResults(Array.isArray(res) ? res : res.data ?? []);
        setOpen(true);
      } catch {
        setResults([]);
      } finally {
        setLoading(false);
      }
    }, 300);
    return () => clearTimeout(t);
  }, [query]);

  function select(order) {
    setQuery(`#${order.code}`);
    setOpen(false);
    onChange(order);
  }

  function clear() {
    setQuery("");
    onChange(null);
  }

  return (
    <div className="relative" ref={ref}>
      <div className="relative">
        <input
          value={query}
          onChange={(e) => {
            setQuery(e.target.value);
            if (value) onChange(null);
          }}
          onFocus={() => results.length && setOpen(true)}
          placeholder="Buscar por número de pedido…"
          className={INPUT_CLS}
        />
        {value && (
          <button
            type="button"
            onClick={clear}
            className="absolute right-3 top-1/2 -translate-y-1/2 text-gray-400 hover:text-gray-700 dark:hover:text-gray-200 text-lg leading-none"
          >
            ✕
          </button>
        )}
      </div>
      {open && (loading || results.length > 0) && (
        <div className="absolute z-50 w-full mt-1 bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-xl shadow-lg max-h-60 overflow-y-auto">
          {loading ? (
            <div className="p-3 text-sm text-gray-400 text-center">
              Buscando…
            </div>
          ) : (
            results.map((o) => (
              <button
                key={o.id}
                type="button"
                onClick={() => select(o)}
                className="w-full text-left px-4 py-2.5 hover:bg-gray-50 dark:hover:bg-gray-700 border-b border-gray-100 dark:border-gray-700/50 last:border-0 transition-colors"
              >
                <span className="font-mono font-semibold text-sm text-gray-900 dark:text-white">
                  #{o.code}
                </span>
                {o.customer && (
                  <span className="text-xs text-gray-400 ml-2">
                    {o.customer.name}
                  </span>
                )}
              </button>
            ))
          )}
        </div>
      )}
    </div>
  );
}

// ── Modal para crear nuevo pendiente ─────────────────────────────────────────
function CreateModal({ onClose, onCreated }) {
  const [order, setOrder] = useState(null);
  const [orderItems, setOrderItems] = useState([]);
  const [loadingItems, setLoadingItems] = useState(false);
  const [selectedItem, setSelectedItem] = useState(null);
  const [qty, setQty] = useState("1");
  const [note, setNote] = useState("");
  const [saving, setSaving] = useState(false);

  // Cuando se elige un pedido, cargar sus productos
  useEffect(() => {
    if (!order) {
      setOrderItems([]);
      setSelectedItem(null);
      return;
    }
    setLoadingItems(true);
    apiGet(`/orders/${order.id}`)
      .then((data) => {
        setOrderItems(data.items ?? []);
        setSelectedItem(null);
      })
      .catch(() => toastError("No se pudieron cargar los productos"))
      .finally(() => setLoadingItems(false));
  }, [order]);

  async function handleSubmit(e) {
    e.preventDefault();
    if (!order) return toastError("Seleccioná un pedido");
    if (!selectedItem) return toastError("Seleccioná un producto");
    const qtyNum = parseInt(qty, 10);
    if (!qtyNum || qtyNum < 1) return toastError("La cantidad debe ser mayor a 0");

    setSaving(true);
    try {
      const item = await apiPost("/pending-items", {
        order_id: order.id,
        order_item_id: selectedItem.id,
        qty_missing: qtyNum,
        note: note.trim() || null,
      });
      toastSuccess("Pendiente creado");
      onCreated(item);
      onClose();
    } catch (e) {
      toastError(e?.data?.message || "Error al crear el pendiente");
    } finally {
      setSaving(false);
    }
  }

  return (
    <div
      className="fixed inset-0 z-50 flex items-end sm:items-center justify-center p-4 bg-black/50"
      onClick={(e) => e.target === e.currentTarget && onClose()}
    >
      <div className="w-full sm:max-w-lg bg-white dark:bg-gray-800 rounded-2xl shadow-2xl overflow-hidden">
        {/* Header modal */}
        <div className="flex items-center justify-between px-5 py-4 border-b border-gray-100 dark:border-gray-700">
          <h2 className="font-bold text-base text-gray-900 dark:text-white">
            Nuevo pendiente
          </h2>
          <button
            onClick={onClose}
            className="w-8 h-8 rounded-full bg-gray-100 dark:bg-gray-700 flex items-center justify-center text-gray-500 hover:bg-gray-200 dark:hover:bg-gray-600 transition-colors"
          >
            ✕
          </button>
        </div>

        {/* Body */}
        <form onSubmit={handleSubmit} className="p-5 space-y-4">
          {/* Pedido */}
          <div className="space-y-1.5">
            <label className={SEC_LABEL}>Pedido</label>
            <OrderSearch value={order} onChange={setOrder} />
          </div>

          {/* Producto */}
          <div className="space-y-1.5">
            <label className={SEC_LABEL}>Producto</label>
            {!order ? (
              <p className="text-sm text-gray-400 dark:text-gray-500 px-1">
                Primero seleccioná un pedido
              </p>
            ) : loadingItems ? (
              <p className="text-sm text-gray-400 animate-pulse px-1">
                Cargando productos…
              </p>
            ) : orderItems.length === 0 ? (
              <p className="text-sm text-gray-400 px-1">
                Este pedido no tiene productos
              </p>
            ) : (
              <div className="rounded-xl border border-gray-200 dark:border-gray-600 overflow-hidden max-h-52 overflow-y-auto">
                {orderItems.map((item) => {
                  const selected = selectedItem?.id === item.id;
                  return (
                    <button
                      key={item.id}
                      type="button"
                      onClick={() => setSelectedItem(selected ? null : item)}
                      className={`w-full text-left flex items-center gap-3 px-3 py-2.5 border-b border-gray-100 dark:border-gray-700/50 last:border-0 transition-colors ${
                        selected
                          ? "bg-blue-50 dark:bg-blue-900/30"
                          : "bg-white dark:bg-gray-800 hover:bg-gray-50 dark:hover:bg-gray-700/50"
                      }`}
                    >
                      {/* Miniatura */}
                      <div className="w-9 h-9 flex-shrink-0 rounded-lg bg-gray-100 dark:bg-gray-700 flex items-center justify-center overflow-hidden">
                        {item.image_url ? (
                          <img
                            src={item.image_url}
                            alt=""
                            className="w-full h-full object-contain"
                          />
                        ) : (
                          <span className="text-base">📦</span>
                        )}
                      </div>
                      <div className="flex-1 min-w-0">
                        <p
                          className={`text-sm font-medium leading-snug line-clamp-1 ${
                            selected
                              ? "text-blue-700 dark:text-blue-300"
                              : "text-gray-900 dark:text-gray-100"
                          }`}
                        >
                          {item.description}
                        </p>
                        <p className="text-xs text-gray-400 font-mono mt-0.5">
                          {item.ean} · {item.qty} unid.
                        </p>
                      </div>
                      {selected && (
                        <svg
                          className="w-4 h-4 text-blue-600 dark:text-blue-400 flex-shrink-0"
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
                      )}
                    </button>
                  );
                })}
              </div>
            )}
          </div>

          {/* Cantidad */}
          <div className="space-y-1.5">
            <label className={SEC_LABEL}>Unidades faltantes</label>
            <input
              type="number"
              min="1"
              value={qty}
              onChange={(e) => setQty(e.target.value)}
              className={`${INPUT_CLS} font-mono text-lg text-center`}
              inputMode="numeric"
            />
            {selectedItem && (
              <p className="text-xs text-gray-400 dark:text-gray-500 px-1">
                Cantidad original del pedido:{" "}
                <span className="font-semibold">{selectedItem.qty} unid.</span>
              </p>
            )}
          </div>

          {/* Nota */}
          <div className="space-y-1.5">
            <label className={SEC_LABEL}>
              Nota{" "}
              <span className="normal-case font-normal tracking-normal text-gray-300 dark:text-gray-600">
                (opcional)
              </span>
            </label>
            <textarea
              value={note}
              onChange={(e) => setNote(e.target.value)}
              placeholder="Ej: el cliente reportó que recibió 3 en vez de 5…"
              rows={2}
              className={`${INPUT_CLS} resize-none`}
            />
          </div>

          {/* Actions */}
          <div className="flex gap-2 pt-1">
            <button
              type="button"
              onClick={onClose}
              className="flex-1 py-3 rounded-2xl border border-gray-200 dark:border-gray-600 text-gray-600 dark:text-gray-300 font-semibold text-sm hover:bg-gray-50 dark:hover:bg-gray-700 transition-colors"
            >
              Cancelar
            </button>
            <button
              type="submit"
              disabled={saving || !order || !selectedItem || !qty}
              className="flex-1 py-3 rounded-2xl bg-gray-900 dark:bg-white text-white dark:text-gray-900 font-bold text-sm hover:bg-gray-700 dark:hover:bg-gray-100 disabled:opacity-40 transition-colors"
            >
              {saving ? "Guardando…" : "Crear pendiente"}
            </button>
          </div>
        </form>
      </div>
    </div>
  );
}

// ── Tarjeta de pendiente ──────────────────────────────────────────────────────
function PendingCard({ item, onResolve, onReopen, onDelete }) {
  const isPending = item.status === "pending";
  const [resolving, setResolving] = useState(false);
  const [deleting, setDeleting] = useState(false);

  async function handleResolve() {
    setResolving(true);
    try {
      await onResolve(item.id);
    } finally {
      setResolving(false);
    }
  }

  async function handleDelete() {
    if (!confirm("¿Eliminar este pendiente?")) return;
    setDeleting(true);
    try {
      await onDelete(item.id);
    } finally {
      setDeleting(false);
    }
  }

  return (
    <div
      className={`relative bg-white dark:bg-gray-800/60 border rounded-2xl overflow-hidden shadow-sm ${
        isPending
          ? "border-red-200 dark:border-red-800/50"
          : "border-gray-100 dark:border-gray-700"
      }`}
    >
      {/* Accent bar */}
      <div
        className={`absolute left-0 top-0 bottom-0 w-1 ${
          isPending ? "bg-red-500" : "bg-green-500"
        }`}
      />

      <div className="pl-4 pr-4 pt-3 pb-3">
        {/* Top row */}
        <div className="flex items-start gap-3">
          {/* Imagen */}
          <div className="w-12 h-12 flex-shrink-0 rounded-xl bg-gray-100 dark:bg-gray-700 flex items-center justify-center overflow-hidden">
            {item.image_url ? (
              <img
                src={item.image_url}
                alt=""
                className="w-full h-full object-contain"
              />
            ) : (
              <span className="text-xl">📦</span>
            )}
          </div>

          {/* Info */}
          <div className="flex-1 min-w-0">
            <p className="text-sm font-semibold text-gray-900 dark:text-gray-100 leading-snug line-clamp-2">
              {item.description}
            </p>
            <div className="flex flex-wrap items-center gap-1.5 mt-1">
              {/* Pedido */}
              <span className="text-[11px] font-bold bg-gray-900 dark:bg-gray-700 text-white px-2 py-0.5 rounded-full">
                #{item.order_code}
              </span>
              {/* EAN */}
              <span className="text-[11px] text-gray-400 font-mono">
                {item.ean}
              </span>
            </div>
          </div>

          {/* Cantidad faltante */}
          <div
            className={`flex-shrink-0 text-center px-2.5 py-1.5 rounded-xl ${
              isPending
                ? "bg-red-100 dark:bg-red-900/30"
                : "bg-green-100 dark:bg-green-900/30"
            }`}
          >
            <div
              className={`text-xl font-bold leading-none ${
                isPending
                  ? "text-red-600 dark:text-red-400"
                  : "text-green-600 dark:text-green-400"
              }`}
            >
              {item.qty_missing}
            </div>
            <div className="text-[9px] uppercase tracking-wide text-gray-400 mt-0.5">
              unid.
            </div>
          </div>
        </div>

        {/* Nota */}
        {item.note && (
          <p className="mt-2 text-xs text-gray-500 dark:text-gray-400 bg-gray-50 dark:bg-gray-700/40 rounded-lg px-3 py-2 italic">
            "{item.note}"
          </p>
        )}

        {/* Footer */}
        <div className="flex items-center justify-between mt-3 pt-2.5 border-t border-gray-100 dark:border-gray-700/50">
          <div className="text-[11px] text-gray-400 dark:text-gray-500 space-y-0.5">
            {isPending ? (
              <span>Creado por {item.created_by_name} · {relativeDate(item.created_at)}</span>
            ) : (
              <span className="text-green-600 dark:text-green-400">
                ✓ Resuelto por {item.resolved_by_name} · {relativeDate(item.resolved_at)}
              </span>
            )}
          </div>

          <div className="flex items-center gap-2">
            {/* Resolver / Reabrir */}
            {isPending ? (
              <button
                onClick={handleResolve}
                disabled={resolving}
                className="text-xs px-3 py-1.5 rounded-xl bg-green-600 hover:bg-green-700 text-white font-semibold transition-colors disabled:opacity-40"
              >
                {resolving ? "…" : "✓ Entregar"}
              </button>
            ) : (
              <button
                onClick={() => onReopen(item.id)}
                className="text-xs px-3 py-1.5 rounded-xl border border-gray-200 dark:border-gray-600 text-gray-600 dark:text-gray-300 font-semibold hover:bg-gray-50 dark:hover:bg-gray-700 transition-colors"
              >
                Reabrir
              </button>
            )}
            {/* Eliminar */}
            <button
              onClick={handleDelete}
              disabled={deleting}
              className="text-xs px-3 py-1.5 rounded-xl border border-red-200 dark:border-red-800/50 text-red-500 dark:text-red-400 font-semibold hover:bg-red-50 dark:hover:bg-red-900/20 transition-colors disabled:opacity-40"
            >
              {deleting ? "…" : "Eliminar"}
            </button>
          </div>
        </div>
      </div>
    </div>
  );
}

// ── Vista principal ───────────────────────────────────────────────────────────
export default function Pendientes() {
  const [items, setItems] = useState([]);
  const [loading, setLoading] = useState(true);
  const [tab, setTab] = useState("pending"); // "pending" | "resolved"
  const [showCreate, setShowCreate] = useState(false);

  useEffect(() => {
    load();
  }, []);

  async function load() {
    setLoading(true);
    try {
      const data = await apiGet("/pending-items");
      setItems(data);
    } catch {
      toastError("Error al cargar los pendientes");
    } finally {
      setLoading(false);
    }
  }

  async function handleResolve(id) {
    const updated = await apiPatch(`/pending-items/${id}`, {
      status: "resolved",
    });
    setItems((prev) => prev.map((i) => (i.id === id ? updated : i)));
    toastSuccess("Marcado como entregado");
  }

  async function handleReopen(id) {
    const updated = await apiPatch(`/pending-items/${id}`, {
      status: "pending",
    });
    setItems((prev) => prev.map((i) => (i.id === id ? updated : i)));
    toastSuccess("Pendiente reabierto");
  }

  async function handleDelete(id) {
    await apiDelete(`/pending-items/${id}`);
    setItems((prev) => prev.filter((i) => i.id !== id));
    toastSuccess("Pendiente eliminado");
  }

  function handleCreated(item) {
    setItems((prev) => [item, ...prev]);
    setTab("pending");
  }

  const pendingList = items.filter((i) => i.status === "pending");
  const resolvedList = items.filter((i) => i.status === "resolved");
  const current = tab === "pending" ? pendingList : resolvedList;

  return (
    <div className="space-y-6 py-2">
      {/* Header */}
      <div className="flex items-start justify-between gap-3">
        <div className="space-y-1">
          <div className="flex items-center gap-2">
            <BackButton to="/" />
          </div>
          <h1 className="font-bold text-2xl md:text-3xl text-gray-900 dark:text-white tracking-tight">
            Pendientes
          </h1>
          <p className="text-sm text-gray-500 dark:text-gray-400">
            Productos que quedaron con faltantes en una entrega
          </p>
        </div>
        <button
          onClick={() => setShowCreate(true)}
          className="flex-shrink-0 flex items-center gap-2 px-4 py-2.5 rounded-2xl bg-gray-900 dark:bg-white text-white dark:text-gray-900 font-bold text-sm hover:bg-gray-700 dark:hover:bg-gray-100 transition-colors"
        >
          <svg
            className="w-4 h-4"
            fill="none"
            viewBox="0 0 24 24"
            strokeWidth={2.5}
            stroke="currentColor"
          >
            <path
              strokeLinecap="round"
              strokeLinejoin="round"
              d="M12 4.5v15m7.5-7.5h-15"
            />
          </svg>
          <span className="hidden sm:inline">Nuevo</span>
        </button>
      </div>

      {/* Alerta si hay pendientes */}
      {!loading && pendingList.length > 0 && (
        <div className="flex items-center gap-3 bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800/50 rounded-2xl px-4 py-3">
          <span className="text-2xl flex-shrink-0">🚨</span>
          <div>
            <p className="text-sm font-semibold text-red-700 dark:text-red-400">
              {pendingList.length === 1
                ? "1 pendiente sin resolver"
                : `${pendingList.length} pendientes sin resolver`}
            </p>
            <p className="text-xs text-red-500 dark:text-red-500 mt-0.5">
              Hay productos que todavía no fueron entregados al cliente
            </p>
          </div>
        </div>
      )}

      {/* Tabs */}
      <div className="flex gap-1 p-1 bg-gray-100 dark:bg-gray-800 rounded-2xl">
        <button
          onClick={() => setTab("pending")}
          className={`flex-1 flex items-center justify-center gap-2 py-2 rounded-xl text-sm font-semibold transition-colors ${
            tab === "pending"
              ? "bg-white dark:bg-gray-700 text-gray-900 dark:text-white shadow-sm"
              : "text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-200"
          }`}
        >
          Pendientes
          {pendingList.length > 0 && (
            <span className="bg-red-500 text-white text-[10px] font-bold px-1.5 py-0.5 rounded-full min-w-[18px] text-center">
              {pendingList.length}
            </span>
          )}
        </button>
        <button
          onClick={() => setTab("resolved")}
          className={`flex-1 flex items-center justify-center gap-2 py-2 rounded-xl text-sm font-semibold transition-colors ${
            tab === "resolved"
              ? "bg-white dark:bg-gray-700 text-gray-900 dark:text-white shadow-sm"
              : "text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-200"
          }`}
        >
          Resueltos
          {resolvedList.length > 0 && (
            <span className="bg-gray-400 dark:bg-gray-600 text-white text-[10px] font-bold px-1.5 py-0.5 rounded-full min-w-[18px] text-center">
              {resolvedList.length}
            </span>
          )}
        </button>
      </div>

      {/* Lista */}
      {loading ? (
        <div className="space-y-3">
          {[1, 2, 3].map((i) => (
            <div
              key={i}
              className="h-28 rounded-2xl bg-gray-100 dark:bg-gray-800 animate-pulse"
            />
          ))}
        </div>
      ) : current.length === 0 ? (
        <div className="text-center py-16">
          <p className="text-5xl mb-4">
            {tab === "pending" ? "✅" : "📭"}
          </p>
          <p className="text-base font-semibold text-gray-700 dark:text-gray-300">
            {tab === "pending"
              ? "¡Todo al día!"
              : "Sin pendientes resueltos"}
          </p>
          <p className="text-sm text-gray-400 mt-1">
            {tab === "pending"
              ? "No hay productos con faltantes pendientes"
              : "Los pendientes resueltos aparecerán acá"}
          </p>
        </div>
      ) : (
        <div className="space-y-3">
          {current.map((item) => (
            <PendingCard
              key={item.id}
              item={item}
              onResolve={handleResolve}
              onReopen={handleReopen}
              onDelete={handleDelete}
            />
          ))}
        </div>
      )}

      {/* Modal crear */}
      {showCreate && (
        <CreateModal
          onClose={() => setShowCreate(false)}
          onCreated={handleCreated}
        />
      )}
    </div>
  );
}
