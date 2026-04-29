import { useEffect, useState } from "react";
import { Link } from "react-router-dom";
import { apiGet } from "../api/client";
import { useAuth } from "../auth/AuthContext";
import { PageSpinner } from "../ui/Spinner";
import { ActionItem, Icons } from "../ui/ActionList";

const SEC_LABEL =
  "text-xs font-semibold uppercase tracking-wider text-gray-400 dark:text-gray-500";

export default function Home() {
  const { user } = useAuth();
  const canWrite = user?.role !== null && user?.role !== undefined;
  const [loading, setLoading] = useState(true);
  const [lastOpenOrder, setLastOpenOrder] = useState(null);

  async function load() {
    setLoading(true);
    try {
      const orderData = await apiGet("/orders/last-open");
      setLastOpenOrder(orderData?.order || null);
    } catch {
      setLastOpenOrder(null);
    } finally {
      setLoading(false);
    }
  }

  useEffect(() => {
    load();
  }, []);

  if (loading) return <PageSpinner />;

  return (
    <div className="flex flex-col gap-8 justify-center min-h-[calc(100dvh-7rem)] py-8 w-full">
      {/* ── Hero ─────────────────────────────────────────────────────── */}
      <div className="flex flex-col items-center text-center gap-4">
        {/* App icon */}
        <div className="w-16 h-16 rounded-2xl bg-gray-900 dark:bg-gray-700 flex items-center justify-center shadow-lg select-none">
          <span className="text-3xl">📦</span>
        </div>
        <div className="space-y-1.5">
          <h1 className="text-3xl font-bold text-gray-900 dark:text-white tracking-tight">
            Pallet Control
          </h1>
          <p className="text-sm text-gray-500 dark:text-gray-400 max-w-[260px] leading-relaxed">
            Control rápido de pallets, pedidos, importación y movimientos.
          </p>
        </div>
      </div>

      {/* ── Continuar último pedido ──────────────────────────────────── */}
      {lastOpenOrder && (
        <div className="space-y-2">
          <p className={SEC_LABEL}>Continuar donde lo dejaste</p>
          <Link
            to={`/order/${lastOpenOrder.id}`}
            className="relative flex flex-col gap-2.5 bg-white dark:bg-gray-800/60 border border-gray-200 dark:border-gray-700/50 rounded-2xl overflow-hidden shadow-sm hover:shadow-md hover:-translate-y-0.5 active:scale-[0.99] active:shadow-none transition-all duration-150 px-5 py-4"
          >
            {/* Acento izquierdo */}
            <div className="absolute left-0 top-0 bottom-0 w-1 bg-blue-500" />

            <div className="flex items-center justify-between gap-2">
              <div>
                <p className="text-xs font-semibold uppercase tracking-wide text-gray-400 dark:text-gray-500">
                  Pedido en proceso
                </p>
                <p className="font-mono font-bold text-xl text-gray-900 dark:text-white mt-0.5">
                  #{lastOpenOrder.code}
                </p>
              </div>
              {/* Pulso activo */}
              <div className="flex-shrink-0 w-2.5 h-2.5 rounded-full bg-blue-500 animate-pulse" />
            </div>

            {lastOpenOrder.pallets?.length > 0 && (
              <div className="flex flex-wrap gap-1.5">
                {lastOpenOrder.pallets.map((p) => (
                  <span
                    key={p.id}
                    className="inline-flex items-center px-2 py-0.5 rounded-lg bg-gray-100 dark:bg-gray-700/60 text-xs font-mono font-semibold text-gray-600 dark:text-gray-300"
                  >
                    {p.code}
                  </span>
                ))}
              </div>
            )}

            <div className="flex items-center gap-1 text-xs font-semibold text-blue-600 dark:text-blue-400">
              Abrir pedido
              <svg
                className="w-3.5 h-3.5"
                fill="none"
                viewBox="0 0 24 24"
                strokeWidth={2.5}
                stroke="currentColor"
              >
                <path
                  strokeLinecap="round"
                  strokeLinejoin="round"
                  d="M9 5l7 7-7 7"
                />
              </svg>
            </div>
          </Link>
        </div>
      )}

      {/* ── Acciones rápidas ─────────────────────────────────────────── */}
      {canWrite && (
        <div className="space-y-2">
          <p className={SEC_LABEL}>Acciones rápidas</p>
          <ActionItem
            icon={Icons.Plus}
            iconBg="bg-emerald-500"
            label="Empezar pedido"
            sublabel="Crear un nuevo pedido y asociarlo a un pallet"
            to="/orders/new"
          />
        </div>
      )}
    </div>
  );
}
