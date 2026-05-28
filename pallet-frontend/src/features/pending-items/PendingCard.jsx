import { useState } from "react";
import { relativeDate } from "./PendingItemsShared";

export default function PendingCard({ item, onResolve, onReopen, onDelete }) {
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
      <div
        className={`absolute left-0 top-0 bottom-0 w-1 ${
          isPending ? "bg-red-500" : "bg-green-500"
        }`}
      />

      <div className="pl-4 pr-4 pt-3 pb-3">
        <div className="flex items-start gap-3">
          <div className="w-12 h-12 shrink-0 rounded-xl bg-gray-100 dark:bg-gray-700 flex items-center justify-center overflow-hidden">
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

          <div className="flex-1 min-w-0">
            <p className="text-sm font-semibold text-gray-900 dark:text-gray-100 leading-snug line-clamp-2">
              {item.description}
            </p>
            <div className="flex flex-wrap items-center gap-1.5 mt-1">
              <span className="text-[11px] font-bold bg-gray-900 dark:bg-gray-700 text-white px-2 py-0.5 rounded-full">
                #{item.order_code}
              </span>
              <span className="text-[11px] text-gray-400 font-mono">
                {item.ean}
              </span>
              {item.units_per_bulto != null && (
                <span className="text-[11px] text-gray-400 font-mono">
                  ×{item.units_per_bulto} u/bulto
                </span>
              )}
            </div>
          </div>

          <div
            className={`shrink-0 text-center px-2.5 py-1.5 rounded-xl ${
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

        {item.note && (
          <p className="mt-2 text-xs text-gray-500 dark:text-gray-400 bg-gray-50 dark:bg-gray-700/40 rounded-lg px-3 py-2 italic">
            "{item.note}"
          </p>
        )}

        <div className="flex items-center justify-between mt-3 pt-2.5 border-t border-gray-100 dark:border-gray-700/50">
          <div className="text-[11px] text-gray-400 dark:text-gray-500 space-y-0.5">
            {isPending ? (
              <span>
                Creado por {item.created_by_name} · {relativeDate(item.created_at)}
              </span>
            ) : (
              <span className="text-green-600 dark:text-green-400">
                ✓ Resuelto por {item.resolved_by_name} ·{" "}
                {relativeDate(item.resolved_at)}
              </span>
            )}
          </div>

          <div className="flex items-center gap-2">
            {isPending ? (
              <button
                onClick={handleResolve}
                disabled={resolving}
                className="text-xs px-3 py-1.5 rounded-xl bg-green-600 hover:bg-green-700 text-white font-semibold transition-colors disabled:opacity-40"
              >
                {resolving ? "…" : "Entregar"}
              </button>
            ) : (
              <button
                onClick={() => onReopen(item.id)}
                className="text-xs px-3 py-1.5 rounded-xl border border-gray-200 dark:border-gray-600 text-gray-600 dark:text-gray-300 font-semibold hover:bg-gray-50 dark:hover:bg-gray-700 transition-colors"
              >
                Reabrir
              </button>
            )}
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
