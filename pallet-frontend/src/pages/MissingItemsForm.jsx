import { useState } from "react";
import { apiPost } from "../api/client";

const STORAGE_KEY = (code) => `mir_${code}`;

export default function MissingItemsForm({ items, orderCode }) {
  const [selected, setSelected] = useState({}); // { order_item_id: qty }
  const [requesterName, setRequesterName] = useState("");
  const [note, setNote] = useState("");
  const [submitting, setSubmitting] = useState(false);
  const [submitted, setSubmitted] = useState(() => {
    try { return !!localStorage.getItem(STORAGE_KEY(orderCode)); } catch { return false; }
  });
  const [error, setError] = useState(null);

  if (!items?.length) return null;

  function toggleItem(item) {
    setSelected((prev) => {
      const next = { ...prev };
      if (next[item.id] !== undefined) {
        delete next[item.id];
      } else {
        next[item.id] = "";
      }
      return next;
    });
  }

  function setQty(itemId, raw, max) {
    const digits = raw.replace(/\D/g, "");
    const num = Number(digits);
    const clamped = num > max ? String(max) : digits;
    setSelected((prev) => ({ ...prev, [itemId]: clamped }));
  }

  const selectedIds = Object.keys(selected).map(Number);
  const allValid = selectedIds.every((id) => {
    const v = Number(selected[id]);
    return v >= 1;
  });
  const hasSelection = selectedIds.length > 0 && allValid;

  async function handleSubmit(e) {
    e.preventDefault();
    if (!hasSelection) return;
    setSubmitting(true);
    setError(null);
    try {
      for (const itemId of selectedIds) {
        await apiPost(`/public/orders/${orderCode}/missing-items`, {
          order_item_id: itemId,
          qty_missing: Number(selected[itemId]),
          requester_name: requesterName.trim() || null,
          note: note.trim() || null,
        });
      }
      try { localStorage.setItem(STORAGE_KEY(orderCode), "1"); } catch {}
      setSubmitted(true);
    } catch (err) {
      const msg = err?.response?.data?.message || "Ocurrió un error al enviar la solicitud";
      setError(msg);
    } finally {
      setSubmitting(false);
    }
  }

  if (submitted) {
    return (
      <div className="rounded-2xl border border-green-200 dark:border-green-800 bg-green-50 dark:bg-green-900/20 p-6 text-center">
        <p className="text-3xl mb-2">✅</p>
        <p className="font-semibold text-green-700 dark:text-green-300 text-sm">Solicitud enviada</p>
        <p className="text-xs text-green-600 dark:text-green-400 mt-1">El proveedor fue notificado y revisará los faltantes.</p>
      </div>
    );
  }

  return (
    <form onSubmit={handleSubmit} className="rounded-2xl border border-gray-200 dark:border-gray-700 overflow-hidden">
      {/* Header */}
      <div className="flex items-center gap-2 px-4 py-3 bg-rose-700 text-left">
        <span className="text-white text-base">⚠️</span>
        <span className="text-white font-bold text-sm">Reportar faltantes</span>
        <span className="ml-auto text-rose-300 text-xs">Opcional</span>
      </div>

      <div className="bg-white dark:bg-gray-800 px-4 pt-3 pb-4 space-y-4">
        <p className="text-xs text-gray-500 dark:text-gray-400">
          Seleccioná los productos que no recibiste y especificá la cantidad faltante.
        </p>

        {/* Lista de items */}
        <div className="space-y-2">
          {items.map((item) => {
            const isChecked = selected[item.id] !== undefined;
            return (
              <div
                key={item.id}
                className={`rounded-xl border transition-colors ${isChecked ? "border-rose-300 dark:border-rose-700 bg-rose-50 dark:bg-rose-900/20" : "border-gray-200 dark:border-gray-700"}`}
              >
                <div className="flex items-center gap-3 p-3">
                  <input
                    type="checkbox"
                    checked={isChecked}
                    onChange={() => toggleItem(item)}
                    className="w-4 h-4 rounded accent-rose-600 shrink-0 cursor-pointer"
                  />
                  <div className="w-10 h-10 shrink-0 rounded-lg overflow-hidden bg-gray-100 dark:bg-gray-700 flex items-center justify-center">
                    {item.image_url
                      ? <img src={item.image_url} alt="" className="w-full h-full object-contain" onError={(e) => { e.target.style.display = "none"; e.target.nextSibling && (e.target.nextSibling.style.display = ""); }} />
                      : null}
                    <span className="text-lg" style={item.image_url ? { display: "none" } : {}}>📦</span>
                  </div>
                  <div className="flex-1 min-w-0">
                    <p className="text-xs font-medium text-gray-900 dark:text-gray-100 line-clamp-2 leading-snug">{item.description}</p>
                    <p className="text-[10px] text-gray-400 font-mono mt-0.5">{item.ean} · {item.qty} unid. totales</p>
                  </div>
                  {isChecked && (
                    <input
                      type="text"
                      inputMode="numeric"
                      placeholder="Cant."
                      value={selected[item.id]}
                      onChange={(e) => setQty(item.id, e.target.value, item.qty)}
                      className="w-16 shrink-0 text-center text-sm font-bold text-rose-600 dark:text-rose-400 border border-rose-300 dark:border-rose-700 rounded-lg px-2 py-1.5 bg-white dark:bg-gray-700 focus:outline-none focus:ring-2 focus:ring-rose-400"
                    />
                  )}
                </div>
              </div>
            );
          })}
        </div>

        {/* Nombre y nota */}
        <div className="space-y-2">
          <input
            type="text"
            placeholder="Tu nombre (opcional)"
            value={requesterName}
            onChange={(e) => setRequesterName(e.target.value)}
            maxLength={100}
            className="w-full border border-gray-200 dark:border-gray-600 rounded-xl px-3 py-2 text-sm bg-white dark:bg-gray-700 dark:text-white focus:outline-none focus:ring-2 focus:ring-rose-400 dark:focus:ring-rose-600"
          />
          <textarea
            placeholder="Nota adicional (opcional)"
            value={note}
            onChange={(e) => setNote(e.target.value)}
            maxLength={500}
            rows={2}
            className="w-full border border-gray-200 dark:border-gray-600 rounded-xl px-3 py-2 text-sm bg-white dark:bg-gray-700 dark:text-white focus:outline-none focus:ring-2 focus:ring-rose-400 dark:focus:ring-rose-600 resize-none"
          />
        </div>

        {error && (
          <p className="text-xs text-red-600 dark:text-red-400 bg-red-50 dark:bg-red-900/20 rounded-xl px-3 py-2">{error}</p>
        )}

        <button
          type="submit"
          disabled={!hasSelection || submitting}
          className="w-full py-2.5 rounded-xl bg-rose-600 hover:bg-rose-700 disabled:bg-gray-200 dark:disabled:bg-gray-700 disabled:text-gray-400 text-white font-semibold text-sm transition-colors"
        >
          {submitting ? "Enviando…" : `Reportar ${selectedIds.length > 0 ? `${selectedIds.length} producto${selectedIds.length !== 1 ? "s" : ""}` : "faltantes"}`}
        </button>
      </div>
    </form>
  );
}
