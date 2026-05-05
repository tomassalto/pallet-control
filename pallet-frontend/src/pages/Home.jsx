import { useEffect, useState } from "react";
import { Link } from "react-router-dom";
import { apiGet } from "../api/client";
import { useAuth } from "../auth/AuthContext";
import { PageSpinner } from "../ui/Spinner";
import { ActionItem, Icons } from "../ui/ActionList";

const SEC_LABEL =
  "text-xs font-semibold uppercase tracking-wider text-gray-400 dark:text-gray-500";

/* ── Mini-card para último pedido/pallet ────────────────────────────────────── */
function LastCard({ to, accentColor = "bg-blue-500", label, code, chips = [], cta }) {
  return (
    <Link
      to={to}
      className="relative flex flex-col gap-2.5 bg-white dark:bg-gray-800/60 border border-gray-200 dark:border-gray-700/50 rounded-2xl overflow-hidden shadow-sm hover:shadow-md hover:-translate-y-0.5 active:scale-[0.99] active:shadow-none transition-all duration-150 pl-5 pr-4 py-4"
    >
      {/* Acento izquierdo */}
      <div className={`absolute left-0 top-0 bottom-0 w-1 ${accentColor}`} />

      <div className="flex items-start justify-between gap-2">
        <div className="min-w-0">
          <p className="text-xs font-semibold uppercase tracking-wide text-gray-400 dark:text-gray-500">
            {label}
          </p>
          <p className="font-mono font-bold text-xl text-gray-900 dark:text-white mt-0.5 truncate">
            {code}
          </p>
        </div>
        {/* Pulso activo */}
        <div className={`shrink-0 w-2.5 h-2.5 rounded-full ${accentColor} animate-pulse mt-1`} />
      </div>

      {chips.length > 0 && (
        <div className="flex flex-wrap gap-1.5">
          {chips.map((chip, i) => (
            <span
              key={i}
              className="inline-flex items-center px-2 py-0.5 rounded-lg bg-gray-100 dark:bg-gray-700/60 text-xs font-mono font-semibold text-gray-600 dark:text-gray-300"
            >
              {chip}
            </span>
          ))}
        </div>
      )}

      <div className="flex items-center gap-1 text-xs font-semibold text-blue-600 dark:text-blue-400">
        {cta}
        <svg className="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" strokeWidth={2.5} stroke="currentColor">
          <path strokeLinecap="round" strokeLinejoin="round" d="M9 5l7 7-7 7" />
        </svg>
      </div>
    </Link>
  );
}

/* ── Página ─────────────────────────────────────────────────────────────────── */
export default function Home() {
  const { user } = useAuth();
  const canWrite = user?.role !== null && user?.role !== undefined;
  const [loading, setLoading]           = useState(true);
  const [lastOpenOrder, setLastOpenOrder] = useState(null);
  const [lastOpenPallet, setLastOpenPallet] = useState(null);
  const [pendingCount, setPendingCount] = useState(0);

  async function load() {
    setLoading(true);
    try {
      const [orderData, summaryData, palletData] = await Promise.allSettled([
        apiGet("/orders/last-open"),
        apiGet("/pending-items/summary"),
        apiGet("/pallets/last-open"),
      ]);
      setLastOpenOrder(orderData.value?.order   || null);
      setPendingCount(summaryData.value?.pending_count ?? 0);
      setLastOpenPallet(palletData.value?.pallet || null);
    } catch {
      setLastOpenOrder(null);
    } finally {
      setLoading(false);
    }
  }

  useEffect(() => { load(); }, []);

  if (loading)
    return (
      <div className="flex-1 w-full mx-auto px-4 sm:px-6 lg:px-8 py-6 min-h-screen max-w-xl sm:max-w-2xl md:max-w-3xl lg:max-w-4xl xl:max-w-5xl 2xl:max-w-6xl">
        <PageSpinner />
      </div>
    );

  return (
    <div className="flex flex-col gap-8 justify-center min-h-[calc(100dvh-7rem)] py-8 w-full">

      {/* ── Hero ─────────────────────────────────────────────────────── */}
      <div className="flex flex-col items-center text-center gap-4">
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

      {/* ── Alerta pendientes ────────────────────────────────────────── */}
      {pendingCount > 0 && (
        <Link
          to="/pending-items"
          className="flex items-center gap-3 bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800/50 rounded-2xl px-4 py-3 hover:bg-red-100 dark:hover:bg-red-900/30 transition-colors"
        >
          <span className="text-2xl shrink-0">🚨</span>
          <div className="flex-1 min-w-0">
            <p className="text-sm font-bold text-red-700 dark:text-red-400">
              {pendingCount === 1 ? "1 pendiente sin resolver" : `${pendingCount} pendientes sin resolver`}
            </p>
            <p className="text-xs text-red-500 dark:text-red-500 mt-0.5 truncate">
              Hay productos faltantes que todavía no fueron entregados
            </p>
          </div>
          <svg className="w-4 h-4 text-red-400 shrink-0" fill="none" viewBox="0 0 24 24" strokeWidth={2.5} stroke="currentColor">
            <path strokeLinecap="round" strokeLinejoin="round" d="M9 5l7 7-7 7" />
          </svg>
        </Link>
      )}

      {/* ── Continuar donde lo dejaste ──────────────────────────────── */}
      {(lastOpenOrder || lastOpenPallet) && (
        <div className="space-y-2">
          <p className={SEC_LABEL}>Continuar donde lo dejaste</p>
          <div className="flex flex-col gap-2">
            {lastOpenOrder && (
              <LastCard
                to={`/order/${lastOpenOrder.id}`}
                accentColor="bg-blue-500"
                label="Pedido en proceso"
                code={`#${lastOpenOrder.code}`}
                chips={lastOpenOrder.pallets?.map((p) => p.code) ?? []}
                cta="Abrir pedido"
              />
            )}
            {lastOpenPallet && (
              <LastCard
                to={`/pallet/${lastOpenPallet.id}`}
                accentColor="bg-violet-500"
                label="Pallet en proceso"
                code={lastOpenPallet.code}
                chips={lastOpenPallet.orders?.map((o) => `#${o.code}`) ?? []}
                cta="Abrir pallet"
              />
            )}
          </div>
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
