import BackButton from "../ui/BackButton";
import CreatePendingModal from "../features/pending-items/CreatePendingModal";
import PendingCard from "../features/pending-items/PendingCard";
import { usePendingItemsPage } from "../hooks/usePendingItemsPage";
import RequestCard from "./RequestCard";

function EmptyState({ tab }) {
  return (
    <div className="text-center py-16">
      <p className="text-5xl mb-4">
        {tab === "pending" ? "✅" : tab === "resolved" ? "📭" : "📋"}
      </p>
      <p className="text-base font-semibold text-gray-700 dark:text-gray-300">
        {tab === "pending"
          ? "¡Todo al día!"
          : tab === "resolved"
            ? "Sin pendientes resueltos"
            : "Sin solicitudes"}
      </p>
      <p className="text-sm text-gray-400 mt-1">
        {tab === "pending"
          ? "No hay productos con faltantes pendientes"
          : tab === "resolved"
            ? "Los pendientes resueltos aparecerán acá"
            : "Los clientes pueden reportar faltantes desde la vista pública del pedido"}
      </p>
    </div>
  );
}

function TabButton({ active, count, countClassName, onClick, children }) {
  return (
    <button
      onClick={onClick}
      className={`flex-1 flex items-center justify-center gap-2 py-2 rounded-xl text-sm font-semibold transition-colors ${
        active
          ? "bg-white dark:bg-gray-700 text-gray-900 dark:text-white shadow-sm"
          : "text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-200"
      }`}
    >
      {children}
      {count > 0 && (
        <span
          className={`${countClassName} text-white text-[10px] font-bold px-1.5 py-0.5 rounded-full min-w-[18px] text-center`}
        >
          {count}
        </span>
      )}
    </button>
  );
}

export default function PendingItems() {
  const {
    tab,
    setTab,
    showCreate,
    setShowCreate,
    loading,
    loadingRequests,
    pendingTotal,
    resolvedTotal,
    pendingRequestsCount,
    current,
    pagination,
    handleRequestUpdated,
    handleResolve,
    handleReopen,
    handleDelete,
    handleCreated,
  } = usePendingItemsPage();

  return (
    <div className="space-y-6 py-2">
      <div className="flex justify-between gap-3">
        <div className="space-y-1">
          <div className="flex items-center gap-2">
            <BackButton to="/" />
          </div>
          <h1 className="font-bold text-2xl md:text-3xl text-gray-900 dark:text-white tracking-tight">
            Pendientes
          </h1>
          <p className="text-sm text-gray-500 dark:text-gray-400">
            Productos que quedaron con faltantes en una entrega
          </p>
        </div>
        <div className="flex items-center justify-end">
          <button
            onClick={() => setShowCreate(true)}
            className="shrink-0 flex items-center gap-2 px-4 py-2.5 rounded-2xl bg-gray-900 dark:bg-white text-white dark:text-gray-900 font-bold text-sm hover:bg-gray-700 dark:hover:bg-gray-100 transition-colors"
          >
            <svg
              className="w-4 h-4"
              fill="none"
              viewBox="0 0 24 24"
              strokeWidth={2.5}
              stroke="currentColor"
            >
              <path
                strokeLinecap="round"
                strokeLinejoin="round"
                d="M12 4.5v15m7.5-7.5h-15"
              />
            </svg>
            <span className="hidden sm:inline">Nuevo</span>
          </button>
        </div>
      </div>

      {!loading && pendingTotal > 0 && (
        <div className="flex items-center gap-3 bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800/50 rounded-2xl px-4 py-3">
          <span className="text-2xl shrink-0">🚨</span>
          <div>
            <p className="text-sm font-semibold text-red-700 dark:text-red-400">
              {pendingTotal === 1
                ? "1 pendiente sin resolver"
                : `${pendingTotal} pendientes sin resolver`}
            </p>
            <p className="text-xs text-red-500 dark:text-red-500 mt-0.5">
              Hay productos que todavía no fueron entregados al cliente
            </p>
          </div>
        </div>
      )}

      <div className="flex gap-1 p-1 bg-gray-100 dark:bg-gray-800 rounded-2xl">
        <TabButton
          active={tab === "pending"}
          count={pendingTotal}
          countClassName="bg-red-500"
          onClick={() => setTab("pending")}
        >
          Pendientes
        </TabButton>
        <TabButton
          active={tab === "resolved"}
          count={resolvedTotal}
          countClassName="bg-gray-400 dark:bg-gray-600"
          onClick={() => setTab("resolved")}
        >
          Resueltos
        </TabButton>
        <TabButton
          active={tab === "requests"}
          count={pendingRequestsCount}
          countClassName="bg-orange-500"
          onClick={() => setTab("requests")}
        >
          Solicitudes
        </TabButton>
      </div>

      {(tab === "requests" ? loadingRequests : loading) ? (
        <div className="space-y-3">
          {[1, 2, 3].map((item) => (
            <div
              key={item}
              className="h-28 rounded-2xl bg-gray-100 dark:bg-gray-800 animate-pulse"
            />
          ))}
        </div>
      ) : current.length === 0 ? (
        <EmptyState tab={tab} />
      ) : tab === "requests" ? (
        <div className="space-y-3">
          {current.map((request) => (
            <RequestCard
              key={request.id}
              item={request}
              onUpdated={handleRequestUpdated}
            />
          ))}
        </div>
      ) : (
        <div className="space-y-3">
          {current.map((item) => (
            <PendingCard
              key={item.id}
              item={item}
              onResolve={handleResolve}
              onReopen={handleReopen}
              onDelete={handleDelete}
            />
          ))}
        </div>
      )}

      {pagination && pagination.last_page > 1 && (
        <nav
          className="flex items-center justify-between gap-3"
          aria-label="Paginación de pendientes"
        >
          <button
            type="button"
            onClick={() => pagination.setPage((page) => Math.max(1, page - 1))}
            disabled={pagination.current_page <= 1 || pagination.fetching}
            className="px-3 py-2 rounded-xl border border-gray-200 dark:border-gray-700 text-sm font-semibold disabled:opacity-40"
          >
            Anterior
          </button>
          <span className="text-xs text-gray-500 dark:text-gray-400" aria-live="polite">
            Página {pagination.current_page} de {pagination.last_page} · {pagination.total} en total
          </span>
          <button
            type="button"
            onClick={() => pagination.setPage((page) => Math.min(pagination.last_page, page + 1))}
            disabled={pagination.current_page >= pagination.last_page || pagination.fetching}
            className="px-3 py-2 rounded-xl border border-gray-200 dark:border-gray-700 text-sm font-semibold disabled:opacity-40"
          >
            Siguiente
          </button>
        </nav>
      )}

      {showCreate && (
        <CreatePendingModal
          onClose={() => setShowCreate(false)}
          onCreated={handleCreated}
        />
      )}
    </div>
  );
}
