import { Link } from "react-router-dom";
import { StatusBadge } from "../../ui/EntityCard";
import BackButton from "../../ui/BackButton";
import { getPalletStatusConfig } from "../../constants/status";

const SEC_LABEL = "text-xs font-semibold uppercase tracking-wider text-gray-400 dark:text-gray-500";

function ExternalLinkIcon() {
  return (
    <svg className="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" strokeWidth={2.5} stroke="currentColor">
      <path strokeLinecap="round" strokeLinejoin="round" d="M13.5 6H5.25A2.25 2.25 0 003 8.25v10.5A2.25 2.25 0 005.25 21h10.5A2.25 2.25 0 0018 18.75V10.5m-10.5 6L21 3m0 0h-5.25M21 3v5.25" />
    </svg>
  );
}

function OrderChip({ o, activeOrderId, setActiveOrderId }) {
  const active = o.id === activeOrderId;
  return (
    <button
      onClick={() => setActiveOrderId(o.id)}
      className={[
        "inline-flex items-center gap-2 px-3 py-2 rounded-xl text-sm font-semibold transition-colors",
        active
          ? "bg-gray-900 dark:bg-white text-white dark:text-gray-900"
          : "bg-gray-100 dark:bg-gray-700/60 text-gray-700 dark:text-gray-200 hover:bg-gray-200 dark:hover:bg-gray-700",
      ].join(" ")}
    >
      <span>Pedido #{o.code}</span>
      {active && (
        <Link
          to={`/order/${o.id}`}
          onClick={(e) => e.stopPropagation()}
          className="opacity-60 hover:opacity-100 transition-opacity"
          title="Abrir detalle del pedido"
        >
          <ExternalLinkIcon />
        </Link>
      )}
    </button>
  );
}

export function PalletHeader({ pallet, orders, activeOrderId, setActiveOrderId, onShowQR, onAddBase, onDeletePallet }) {
  if (!pallet) return null;

  const status = getPalletStatusConfig(pallet.status);

  return (
    <>
      <BackButton to="/mis-pallets" />

      <div className="space-y-3">
        <div className="flex items-start justify-between">
          <div className="space-y-1">
            <h1 className="font-mono font-bold text-2xl md:text-3xl text-gray-900 dark:text-white">
              Pallet {pallet.code}
            </h1>
            <StatusBadge label={status.label} color={status.color} />
          </div>
          <div className="flex gap-2">
            <button
              onClick={onShowQR}
              className="p-2 rounded-lg bg-gray-100 dark:bg-gray-700/60 hover:bg-gray-200 dark:hover:bg-gray-700 transition-colors"
              title="Ver QR"
            >
              <svg className="w-5 h-5 text-gray-600 dark:text-gray-300" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 4v1m6 11h2m-6 0h-2v4m0-11v3m0 0h.01M12 12h4.01M16 20h4M4 12h4m12 0h.01M5 8h2a1 1 0 001-1V5a1 1 0 00-1-1H5a1 1 0 00-1 1v2a1 1 0 001 1zm12 0h2a1 1 0 001-1V5a1 1 0 00-1-1h-2a1 1 0 00-1 1v2a1 1 0 001 1zM5 20h2a1 1 0 001-1v-2a1 1 0 00-1-1H5a1 1 0 00-1 1v2a1 1 0 001 1z" />
              </svg>
            </button>
          </div>
        </div>

        {pallet.note && (
          <p className="text-sm text-gray-600 dark:text-gray-400 whitespace-pre-wrap">{pallet.note}</p>
        )}

        {orders.length > 0 && (
          <div className="space-y-2 pt-1">
            <p className={SEC_LABEL}>Pedidos asociados ({orders.length})</p>
            <div className="flex flex-wrap gap-2">
              {orders.map((o) => (
                <OrderChip
                  key={o.id}
                  o={o}
                  activeOrderId={activeOrderId}
                  setActiveOrderId={setActiveOrderId}
                />
              ))}
            </div>
          </div>
        )}
      </div>
    </>
  );
}