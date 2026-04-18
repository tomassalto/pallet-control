import { useEffect, useState } from "react";
import { useParams } from "react-router-dom";
import { apiGet } from "../api/client";
import BackButton from "../ui/BackButton";
import Title from "../ui/Title";

export default function OrderHistory() {
  const { orderId } = useParams();
  const [loading, setLoading] = useState(true);
  const [order, setOrder] = useState(null);
  const [logs, setLogs] = useState([]);
  const [error, setError] = useState("");

  async function load() {
    setError("");
    setLoading(true);
    try {
      const data = await apiGet(`/orders/${orderId}/activity-logs`);
      setOrder(data.order);
      setLogs(data.logs || []);
    } catch (e) {
      setError(e.message || "Error cargando historial");
    } finally {
      setLoading(false);
    }
  }

  useEffect(() => {
    load();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [orderId]);

  if (loading) {
    return (
      <div className="space-y-3">
        <BackButton to={`/order/${orderId}`} />
        <div className="text-center py-8 text-gray-500">Cargando...</div>
      </div>
    );
  }

  if (error) {
    return (
      <div className="space-y-3">
        <BackButton to={`/order/${orderId}`} />
        <div className="bg-red-50 border border-red-200 text-red-800 rounded-xl p-3">
          {error}
        </div>
      </div>
    );
  }

  return (
    <div className="space-y-4">
      <BackButton to={`/order/${orderId}`} />

      <div className="bg-white border rounded-2xl p-4">
        <Title size="2xl">Historial de actividad</Title>
        <div className="text-sm text-gray-600 mt-1">Pedido #{order?.code}</div>
      </div>

      {logs.length === 0 ? (
        <div className="bg-white border rounded-2xl p-8 text-center text-gray-500">
          No hay actividad registrada aún.
        </div>
      ) : (
        <div className="space-y-2">
          {logs.map((log) => (
            <div
              key={log.id}
              className="bg-white border rounded-xl p-4 space-y-1"
            >
              <div className="text-sm text-gray-900">{log.description}</div>
              <div className="flex items-center justify-between text-xs text-gray-500">
                <span>{log.user_name}</span>
                <span>{log.created_at_formatted}</span>
              </div>
            </div>
          ))}
        </div>
      )}
    </div>
  );
}
