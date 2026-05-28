import { useEffect, useState } from "react";
import { apiGet, apiPost } from "../../api/client";
import { toastSuccess, toastError } from "../../ui/toast";
import OrderSearch from "./OrderSearch";
import { INPUT_CLS, SEC_LABEL, onlyDigits } from "./PendingItemsShared";

export default function CreatePendingModal({ onClose, onCreated }) {
  const [order, setOrder] = useState(null);
  const [orderItems, setOrderItems] = useState([]);
  const [loadingItems, setLoadingItems] = useState(false);
  const [selectedItem, setSelectedItem] = useState(null);
  const [qty, setQty] = useState("1");
  const [note, setNote] = useState("");
  const [saving, setSaving] = useState(false);
  const selectedQtyMax = selectedItem ? Number(selectedItem.qty) || 0 : 0;

  function handleQtyChange(rawValue) {
    const digits = onlyDigits(rawValue);
    if (!digits) {
      setQty("");
      return;
    }

    let next = parseInt(digits, 10);
    if (!Number.isFinite(next) || next < 1) next = 1;
    if (selectedQtyMax > 0) next = Math.min(next, selectedQtyMax);
    setQty(String(next));
  }

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
    if (!qtyNum || qtyNum < 1) {
      return toastError("La cantidad debe ser mayor a 0");
    }
    if (selectedQtyMax > 0 && qtyNum > selectedQtyMax) {
      return toastError(
        `La cantidad no puede ser mayor a ${selectedQtyMax} (cantidad del pedido)`,
      );
    }

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
        <div className="flex items-center justify-between px-5 py-4 border-b border-gray-100 dark:border-gray-700">
          <h2 className="font-bold text-base text-gray-900 dark:text-white">
            Nuevo pendiente
          </h2>
          <button
            onClick={onClose}
            className="w-8 h-8 rounded-full bg-gray-100 dark:bg-gray-700 flex items-center justify-center text-gray-500 hover:bg-gray-200 dark:hover:bg-gray-600 transition-colors"
          >
            ×
          </button>
        </div>

        <form onSubmit={handleSubmit} className="p-5 space-y-4">
          <div className="space-y-1.5">
            <label className={SEC_LABEL}>Pedido</label>
            <OrderSearch value={order} onChange={setOrder} />
          </div>

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
                      <div className="w-9 h-9 shrink-0 rounded-lg bg-gray-100 dark:bg-gray-700 flex items-center justify-center overflow-hidden">
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
                          {item.units_per_bulto != null &&
                            ` · ×${item.units_per_bulto} u/bulto`}
                        </p>
                      </div>
                      {selected && (
                        <svg
                          className="w-4 h-4 text-blue-600 dark:text-blue-400 shrink-0"
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

          <div className="space-y-1.5">
            <label className={SEC_LABEL}>Unidades faltantes</label>
            <input
              type="number"
              min="1"
              max={selectedQtyMax > 0 ? String(selectedQtyMax) : undefined}
              value={qty}
              onChange={(e) => handleQtyChange(e.target.value)}
              onBlur={(e) => handleQtyChange(e.target.value)}
              onKeyDown={(e) => {
                if (["e", "E", "+", "-", ".", ","].includes(e.key)) {
                  e.preventDefault();
                }
              }}
              onPaste={(e) => {
                const text = e.clipboardData.getData("text");
                if (/\D/.test(text)) {
                  e.preventDefault();
                }
              }}
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
              disabled={
                saving ||
                !order ||
                !selectedItem ||
                !qty ||
                (selectedQtyMax > 0 &&
                  (Number(qty) < 1 || Number(qty) > selectedQtyMax))
              }
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
