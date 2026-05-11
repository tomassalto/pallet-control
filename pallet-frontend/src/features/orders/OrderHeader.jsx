import { Link } from "react-router-dom";
import { StatusBadge } from "../../ui/EntityCard";
import BackButton from "../../ui/BackButton";
import { getStatusConfig } from "../../constants/status";

const SEC_LABEL =
  "text-xs font-semibold uppercase tracking-wider text-gray-400 dark:text-gray-500";

export function OrderHeader({ order, pallets, modalItems, onOrganize, onDetach, onReopen, detachingPallet, canFinalize }) {
  if (!order) return null;

  const status = getStatusConfig(order.status);
  const hasItems = modalItems?.length > 0;

  return (
    <>
      {pallets.length > 0 ? (
        <BackButton to={`/pallet/${pallets[0].id}`} />
      ) : (
        <BackButton to="/" />
      )}

      <div className="space-y-2.5">
        <h1 className="font-mono font-bold text-2xl md:text-3xl text-gray-900 dark:text-white leading-tight">
          Pedido #{order.code}
        </h1>

        <StatusBadge label={status.label} color={status.color} />

        {pallets.length > 0 && (
          <div className="space-y-2 pt-1">
            <p className={SEC_LABEL}>Pallets asociados</p>
            <div className="flex flex-wrap gap-2">
              {pallets.map((p) => {
                const isPalletDone = p.status === "done";
                return (
                  <div
                    key={p.id}
                    className="inline-flex items-center gap-1.5 bg-gray-100 dark:bg-gray-700/60 rounded-xl px-3 py-1.5"
                  >
                    <Link
                      to={`/pallet/${p.id}`}
                      className="text-sm font-semibold text-gray-800 dark:text-gray-200 hover:underline font-mono"
                    >
                      {p.code}
                    </Link>
                    {isPalletDone && (
                      <span className="text-[10px] bg-gray-200 dark:bg-gray-600 text-gray-500 dark:text-gray-400 px-1.5 py-0.5 rounded-full font-medium">
                        cerrado
                      </span>
                    )}
                    {order.status !== "done" && hasItems && (
                      <button
                        onClick={() =>
                          isPalletDone
                            ? onReopen({ pallet: p, reopening: false })
                            : onOrganize(p)
                        }
                        className={`text-[11px] px-2 py-0.5 rounded-lg font-semibold transition-colors ${
                          isPalletDone
                            ? "bg-amber-100 dark:bg-amber-900/30 text-amber-700 dark:text-amber-400 hover:bg-amber-200 dark:hover:bg-amber-900/50"
                            : "bg-gray-900 dark:bg-white text-white dark:text-gray-900 hover:bg-gray-700 dark:hover:bg-gray-100"
                        }`}
                      >
                        {isPalletDone ? "🔒" : "📦"} Organizar
                      </button>
                    )}
                    {order.status !== "done" && (
                      <button
                        onClick={() => onDetach(p)}
                        disabled={detachingPallet === p.id}
                        className="text-[11px] px-2 py-0.5 rounded-lg font-semibold bg-red-100 dark:bg-red-900/30 text-red-600 dark:text-red-400 hover:bg-red-200 dark:hover:bg-red-900/50 disabled:opacity-50 transition-colors"
                      >
                        ✕
                      </button>
                    )}
                  </div>
                );
              })}
            </div>
          </div>
        )}
      </div>
    </>
  );
}