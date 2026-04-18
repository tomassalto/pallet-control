import { useEffect, useState } from "react";
import { Link } from "react-router-dom";
import { apiGet } from "../api/client";
import BackButton from "../ui/BackButton";
import Title from "../ui/Title";

export default function AllLogs() {
  const [loading, setLoading] = useState(true);
  const [logs, setLogs] = useState([]);
  const [error, setError] = useState("");

  async function load() {
    setError("");
    setLoading(true);
    try {
      const data = await apiGet(`/activity-logs?limit=500`);
      setLogs(data.logs || []);
    } catch (e) {
      setError(e.message || "Error cargando logs");
    } finally {
      setLoading(false);
    }
  }

  useEffect(() => {
    load();
  }, []);

  if (loading) {
    return (
      <div className="space-y-3">
        <BackButton to="/" />
        <div className="text-center py-8 text-gray-500">Cargando...</div>
      </div>
    );
  }

  if (error) {
    return (
      <div className="space-y-3">
        <BackButton to="/" />
        <div className="bg-red-50 border border-red-200 text-red-800 rounded-xl p-3">
          {error}
        </div>
      </div>
    );
  }

  return (
    <div className="space-y-4">
      <div className="flex items-center justify-center pt-[40px]">
        <Title size="3xl">Todos los logs</Title>
      </div>

      {logs.length === 0 ? (
        <div className="bg-white border-border rounded-2xl p-8 text-center text-gray-500">
          No hay actividad registrada aún.
        </div>
      ) : (
        <div className="space-y-2">
          {logs.map((log) => (
            <div
              key={log.id}
              className="bg-white border-border rounded-xl p-4 space-y-2"
            >
              <div className="text-sm text-gray-900">{log.description}</div>

              {log.context && (
                <div className="text-xs text-gray-600 font-medium">
                  {log.context}
                </div>
              )}

              <div className="flex items-center justify-between text-xs text-gray-500">
                <span>{log.user_name}</span>
                <span>{log.created_at_formatted}</span>
              </div>

              <div className="flex gap-2 pt-1">
                {log.pallet_id && (
                  <Link
                    to={`/pallet/${log.pallet_id}`}
                    className="text-xs text-blue-600 hover:underline"
                  >
                    Ver pallet
                  </Link>
                )}
                {log.order_id && (
                  <Link
                    to={`/order/${log.order_id}`}
                    className="text-xs text-blue-600 hover:underline"
                  >
                    Ver pedido
                  </Link>
                )}
              </div>
            </div>
          ))}
        </div>
      )}
    </div>
  );
}
