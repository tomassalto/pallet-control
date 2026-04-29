import { useEffect, useState } from "react";
import { useParams } from "react-router-dom";
import { apiGet } from "../api/client";
import BackButton from "../ui/BackButton";
import { PageSpinner } from "../ui/Spinner";

// Punto de color para el dot del timeline según tipo de acción
function actionDotColor(action = "") {
  if (action.includes("delete") || action.includes("elimina")) return "bg-red-400";
  if (action.includes("create") || action.includes("crea"))   return "bg-green-400";
  if (action.includes("finalize") || action.includes("done")) return "bg-blue-400";
  if (action.includes("reopen"))                              return "bg-amber-400";
  return "bg-gray-400 dark:bg-gray-500";
}

export default function PalletHistory() {
  const { palletId } = useParams();
  const [loading, setLoading] = useState(true);
  const [pallet, setPallet]   = useState(null);
  const [logs, setLogs]       = useState([]);
  const [error, setError]     = useState("");

  async function load() {
    setError("");
    setLoading(true);
    try {
      const data = await apiGet(`/pallets/${palletId}/activity-logs`);
      setPallet(data.pallet);
      setLogs(data.logs || []);
    } catch (e) {
      setError(e.message || "Error cargando historial");
    } finally {
      setLoading(false);
    }
  }

  useEffect(() => { load(); }, [palletId]); // eslint-disable-line

  if (loading) return (
    <div className="space-y-4">
      <BackButton to={`/pallet/${palletId}`} />
      <PageSpinner />
    </div>
  );

  if (error) return (
    <div className="space-y-3">
      <BackButton to={`/pallet/${palletId}`} />
      <div className="bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 text-red-800 dark:text-red-300 rounded-xl p-3 text-sm">{error}</div>
    </div>
  );

  return (
    <div className="space-y-6 pb-8">
      <BackButton to={`/pallet/${palletId}`} />

      {/* Header */}
      <div className="space-y-1">
        <h1 className="font-mono font-bold text-2xl md:text-3xl text-gray-900 dark:text-white leading-tight">
          {pallet?.code}
        </h1>
        <p className="text-xs font-semibold uppercase tracking-wider text-gray-400 dark:text-gray-500">
          Historial de actividad · {logs.length} evento{logs.length !== 1 ? "s" : ""}
        </p>
      </div>

      {/* Timeline */}
      {logs.length === 0 ? (
        <div className="text-center py-16">
          <p className="text-sm text-gray-500 dark:text-gray-400">No hay actividad registrada aún.</p>
        </div>
      ) : (
        <div className="relative pl-6">
          {/* Línea vertical */}
          <div className="absolute left-[9px] top-2 bottom-2 w-0.5 bg-gray-200 dark:bg-gray-700 rounded-full" />

          <div className="space-y-3">
            {logs.map((log) => (
              <div key={log.id} className="relative">
                {/* Dot */}
                <div className={`absolute left-[-19px] top-3.5 w-2.5 h-2.5 rounded-full border-2 border-white dark:border-gray-900 ${actionDotColor(log.action)}`} />

                <div className="bg-white dark:bg-gray-800/60 border border-gray-200 dark:border-gray-700/50 rounded-2xl px-4 py-3 shadow-sm">
                  <p className="text-sm text-gray-800 dark:text-gray-200 leading-snug">
                    {log.description}
                  </p>
                  <div className="flex items-center justify-between mt-2 gap-2">
                    <span className="text-xs font-semibold text-gray-500 dark:text-gray-400 truncate">
                      {log.user_name}
                    </span>
                    <span className="text-xs text-gray-400 dark:text-gray-500 flex-shrink-0">
                      {log.created_at_formatted}
                    </span>
                  </div>
                </div>
              </div>
            ))}
          </div>
        </div>
      )}
    </div>
  );
}
