import { useState } from "react";
import { apiPatch } from "../api/client";
import { toastSuccess, toastError } from "../ui/toast";

function relativeDate(iso) {
  if (!iso) return "";
  const d = new Date(iso);
  const diff = Math.floor((Date.now() - d) / 1000);
  if (diff < 60) return "hace un momento";
  if (diff < 3600) return `hace ${Math.floor(diff / 60)} min`;
  if (diff < 86400) return `hace ${Math.floor(diff / 3600)} h`;
  return d.toLocaleDateString("es-AR", { day: "numeric", month: "short" });
}

export default function RequestCard({ item, onUpdated }) {
  const [loading, setLoading] = useState(null); // "approved" | "rejected" | null

  const isPending = item.status === "pending_review";
  const isApproved = item.status === "approved";
  const isRejected = item.status === "rejected";

  async function handleAction(status) {
    setLoading(status);
    try {
      const updated = await apiPatch(`/admin/missing-item-requests/${item.id}`, { status });
      toastSuccess(status === "approved" ? "Solicitud aprobada — pendiente creado" : "Solicitud rechazada");
      onUpdated(updated);
    } catch (e) {
      toastError(e?.response?.data?.message || "Error al procesar la solicitud");
    } finally {
      setLoading(null);
    }
  }

  return (
    <div className={`relative rounded-2xl border overflow-hidden ${isPending ? "border-orange-200 dark:border-orange-800/50" : isApproved ? "border-green-200 dark:border-green-800/50" : "border-gray-200 dark:border-gray-700"}`}>
      {/* Acento izquierdo */}
      <div className={`absolute inset-y-0 left-0 w-1 ${isPending ? "bg-orange-500" : isApproved ? "bg-green-500" : "bg-gray-300 dark:bg-gray-600"}`} />

      <div className="pl-5 pr-4 py-4 bg-white dark:bg-gray-800 flex gap-3 items-start">
        {/* Imagen del producto */}
        <div className="w-12 h-12 shrink-0 rounded-xl overflow-hidden bg-gray-100 dark:bg-gray-700 flex items-center justify-center">
          {item.image_url
            ? <img src={item.image_url} alt={item.description} className="w-full h-full object-contain" onError={(e) => { e.target.style.display = "none"; }} />
            : <span className="text-2xl">📦</span>}
        </div>

        {/* Info */}
        <div className="flex-1 min-w-0 space-y-1">
          <div className="flex items-start gap-2 flex-wrap">
            <p className="text-sm font-semibold text-gray-900 dark:text-white leading-snug flex-1 min-w-0">{item.description}</p>
            <span className={`shrink-0 text-[10px] font-bold px-2 py-0.5 rounded-full ${isPending ? "bg-orange-100 dark:bg-orange-900/30 text-orange-700 dark:text-orange-300" : isApproved ? "bg-green-100 dark:bg-green-900/30 text-green-700 dark:text-green-300" : "bg-gray-100 dark:bg-gray-700 text-gray-500 dark:text-gray-400"}`}>
              {isPending ? "Pendiente revisión" : isApproved ? "Aprobada" : "Rechazada"}
            </span>
          </div>

          <p className="text-xs text-gray-400 dark:text-gray-500 font-mono">{item.ean}</p>

          <div className="flex items-center gap-2 flex-wrap">
            <span className="text-xs bg-gray-100 dark:bg-gray-700 text-gray-600 dark:text-gray-300 font-mono px-2 py-0.5 rounded-lg">#{item.order_code}</span>
            <span className={`text-xs font-bold px-2 py-0.5 rounded-lg ${isPending ? "bg-orange-100 dark:bg-orange-900/30 text-orange-700 dark:text-orange-300" : "bg-gray-100 dark:bg-gray-700 text-gray-600 dark:text-gray-300"}`}>
              Faltan {item.qty_missing} unid.
            </span>
            {item.original_qty != null && (
              <span className="text-[10px] text-gray-400 dark:text-gray-500">de {item.original_qty} totales</span>
            )}
          </div>

          {item.requester_name && (
            <p className="text-xs text-gray-500 dark:text-gray-400">👤 {item.requester_name}</p>
          )}

          {item.note && (
            <p className="text-xs text-gray-500 dark:text-gray-400 italic">"{item.note}"</p>
          )}

          <p className="text-[10px] text-gray-400 dark:text-gray-500">{relativeDate(item.created_at)}</p>

          {!isPending && (
            <p className="text-[10px] text-gray-400 dark:text-gray-500">
              {isApproved ? "✅ Aprobado" : "❌ Rechazado"} por {item.reviewed_by_name} · {relativeDate(item.reviewed_at)}
            </p>
          )}

          {/* Botones de acción */}
          {isPending && (
            <div className="flex gap-2 pt-1">
              <button
                onClick={() => handleAction("approved")}
                disabled={loading !== null}
                className="text-xs px-3 py-1.5 rounded-xl bg-green-600 hover:bg-green-700 text-white font-semibold transition-colors disabled:opacity-40"
              >
                {loading === "approved" ? "…" : "Aprobar"}
              </button>
              <button
                onClick={() => handleAction("rejected")}
                disabled={loading !== null}
                className="text-xs px-3 py-1.5 rounded-xl border border-red-200 dark:border-red-800/50 text-red-500 dark:text-red-400 font-semibold hover:bg-red-50 dark:hover:bg-red-900/20 transition-colors disabled:opacity-40"
              >
                {loading === "rejected" ? "…" : "Rechazar"}
              </button>
            </div>
          )}
        </div>
      </div>
    </div>
  );
}
