import { useEffect, useState } from "react";
import { Link } from "react-router-dom";
import { apiGet } from "../api/client";
import BackButton from "../ui/BackButton";
import { PageSpinner, InlineSpinner } from "../ui/Spinner";

function actionDotColor(action = "") {
  if (action.includes("delete") || action.includes("elimina")) return "bg-red-400";
  if (action.includes("create") || action.includes("crea"))   return "bg-green-400";
  if (action.includes("finalize") || action.includes("done")) return "bg-blue-400";
  if (action.includes("reopen"))                              return "bg-amber-400";
  return "bg-gray-400 dark:bg-gray-500";
}

export default function AllLogs() {
  const [loading, setLoading] = useState(true);
  const [logs, setLogs]       = useState([]);
  const [page, setPage]       = useState(1);
  const [hasMore, setHasMore] = useState(false);
  const [error, setError]     = useState("");

  async function load(nextPage = 1, { append = false } = {}) {
    setError("");
    setLoading(true);
    try {
      const data = await apiGet(`/activity-logs?page=${nextPage}&per_page=50`);
      const rows = data.logs || [];
      setLogs((prev) => (append ? [...prev, ...rows] : rows));
      setPage(data.current_page ?? nextPage);
      setHasMore((data.current_page ?? nextPage) < (data.last_page ?? nextPage));
    } catch (e) {
      setError(e.message || "Error cargando logs");
    } finally {
      setLoading(false);
    }
  }

  useEffect(() => { load(1); }, []);

  if (loading && logs.length === 0) {
    return (
      <div className="space-y-4">
        <BackButton to="/" />
        <PageSpinner />
      </div>
    );
  }

  if (error) {
    return (
      <div className="space-y-3">
        <BackButton to="/" />
        <div className="bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 text-red-800 dark:text-red-300 rounded-xl p-3 text-sm">
          {error}
        </div>
      </div>
    );
  }

  return (
    <div className="space-y-6 pb-8">
      <BackButton to="/" />

      {/* Header */}
      <div className="space-y-1">
        <h1 className="font-bold text-2xl md:text-3xl text-gray-900 dark:text-white leading-tight">
          Actividad global
        </h1>
        <p className="text-xs font-semibold uppercase tracking-wider text-gray-400 dark:text-gray-500">
          {logs.length} evento{logs.length !== 1 ? "s" : ""} cargado{logs.length !== 1 ? "s" : ""}
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

                  {log.context && (
                    <p className="text-xs text-gray-500 dark:text-gray-400 mt-1">{log.context}</p>
                  )}

                  <div className="flex items-center justify-between mt-2 gap-2">
                    <span className="text-xs font-semibold text-gray-500 dark:text-gray-400 truncate">
                      {log.user_name}
                    </span>
                    <span className="text-xs text-gray-400 dark:text-gray-500 flex-shrink-0">
                      {log.created_at_formatted}
                    </span>
                  </div>

                  {/* Links a entidad */}
                  {(log.pallet_id || log.order_id) && (
                    <div className="flex gap-2 mt-2 pt-2 border-t border-gray-100 dark:border-gray-700/50">
                      {log.pallet_id && (
                        <Link
                          to={`/pallet/${log.pallet_id}`}
                          className="inline-flex items-center gap-1 text-[11px] font-semibold text-blue-600 dark:text-blue-400 hover:text-blue-800 dark:hover:text-blue-300 transition-colors"
                        >
                          <svg className="w-3 h-3" fill="none" viewBox="0 0 24 24" strokeWidth={2.5} stroke="currentColor">
                            <path strokeLinecap="round" strokeLinejoin="round" d="M13.5 6H5.25A2.25 2.25 0 003 8.25v10.5A2.25 2.25 0 005.25 21h10.5A2.25 2.25 0 0018 18.75V10.5m-10.5 6L21 3m0 0h-5.25M21 3v5.25" />
                          </svg>
                          Ver pallet
                        </Link>
                      )}
                      {log.order_id && (
                        <Link
                          to={`/order/${log.order_id}`}
                          className="inline-flex items-center gap-1 text-[11px] font-semibold text-blue-600 dark:text-blue-400 hover:text-blue-800 dark:hover:text-blue-300 transition-colors"
                        >
                          <svg className="w-3 h-3" fill="none" viewBox="0 0 24 24" strokeWidth={2.5} stroke="currentColor">
                            <path strokeLinecap="round" strokeLinejoin="round" d="M13.5 6H5.25A2.25 2.25 0 003 8.25v10.5A2.25 2.25 0 005.25 21h10.5A2.25 2.25 0 0018 18.75V10.5m-10.5 6L21 3m0 0h-5.25M21 3v5.25" />
                          </svg>
                          Ver pedido
                        </Link>
                      )}
                    </div>
                  )}
                </div>
              </div>
            ))}
          </div>
        </div>
      )}

      {/* Cargar más */}
      {hasMore && (
        <button
          disabled={loading}
          onClick={() => load(page + 1, { append: true })}
          className="w-full rounded-2xl py-3 border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800/60 text-sm font-semibold text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-700/50 disabled:opacity-60 transition-colors"
        >
          {loading ? <InlineSpinner label="Cargando…" /> : "Cargar más eventos"}
        </button>
      )}
    </div>
  );
}
