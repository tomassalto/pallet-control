import { useState } from "react";
import { Link } from "react-router-dom";
import { StatusBadge } from "../../ui/EntityCard";
import BackButton from "../../ui/BackButton";
import { getStatusConfig } from "../../constants/status";

const SEC_LABEL =
  "text-xs font-semibold uppercase tracking-wider text-gray-400 dark:text-gray-500";

async function copyOrderViewUrl(code, setCopied) {
  const url = `${window.location.origin}/order-view/${code}`;
  try {
    if (navigator.share) {
      await navigator.share({ title: `Pedido ${code}`, url });
      return;
    }
    await navigator.clipboard.writeText(url);
  } catch {
    const el = document.createElement("textarea");
    el.value = url;
    document.body.appendChild(el);
    el.select();
    document.execCommand("copy");
    document.body.removeChild(el);
  }
  setCopied(true);
  setTimeout(() => setCopied(false), 2000);
}

export function OrderHeader({ order, pallets, modalItems, onOrganize, onDetach, onReopen, detachingPallet, onShowQR }) {
  const [copied, setCopied] = useState(false);

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
        <div className="flex items-start justify-between gap-3">
          <h1 className="font-mono font-bold text-2xl md:text-3xl text-gray-900 dark:text-white leading-tight">
            Pedido #{order.code}
          </h1>
          <button
            onClick={onShowQR}
            title="Ver QR del pedido"
            className="shrink-0 p-2 rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 hover:bg-gray-50 dark:hover:bg-gray-700 active:scale-95 transition-all"
          >
            <svg
              xmlns="http://www.w3.org/2000/svg"
              className="w-5 h-5 text-gray-600 dark:text-gray-300"
              fill="none"
              viewBox="0 0 24 24"
              stroke="currentColor"
              strokeWidth={1.8}
            >
              <rect x="3" y="3" width="7" height="7" rx="1" />
              <rect x="14" y="3" width="7" height="7" rx="1" />
              <rect x="3" y="14" width="7" height="7" rx="1" />
              <path d="M14 14h2v2h-2zM18 14h3v2h-3zM14 18h3v3h-3zM19 18h2v3h-2z" />
            </svg>
          </button>
        </div>

        <div className="flex items-center gap-2 flex-wrap">
          <StatusBadge label={status.label} color={status.color} />
          <button
            onClick={() => copyOrderViewUrl(order.code, setCopied)}
            className="flex items-center gap-1.5 text-xs px-3 py-1 rounded-xl bg-indigo-100 dark:bg-indigo-900/30 text-indigo-700 dark:text-indigo-300 font-semibold hover:bg-indigo-200 dark:hover:bg-indigo-900/50 transition-colors"
            title="Compartir vista pública del pedido"
          >
            {copied ? "✓ Copiado" : "🔗 Compartir vista"}
          </button>
        </div>

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