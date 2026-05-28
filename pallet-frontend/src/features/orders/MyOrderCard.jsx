import { useMemo, useState } from "react";
import { Link } from "react-router-dom";
import ProductAvatar from "./ProductAvatar";
import {
  formatOrderDate,
  formatPeso,
  getOrderStatusConfig,
  normalizePendingItemIds,
} from "./myOrdersUtils";

function getCardStatusConfig(order, hasPending) {
  if (!hasPending) return getOrderStatusConfig(order.status);

  return {
    label: "Pendiente",
    accent: "bg-red-500",
    badge: "bg-red-100 text-red-700 dark:bg-red-900/40 dark:text-red-300",
    bar: "bg-red-500",
  };
}

export default function MyOrderCard({
  order,
  dim = false,
  canFinalize = false,
  finalizing = false,
  onFinalize,
}) {
  const [expanded, setExpanded] = useState(false);
  const hasPending = order.pending_items_count > 0;
  const pendingItemIds = useMemo(
    () => new Set(normalizePendingItemIds(order.pending_item_ids)),
    [order.pending_item_ids],
  );
  const c = getCardStatusConfig(order, hasPending);
  const totalQty = order.total_qty ?? 0;
  const assignedQty = order.assigned_qty ?? 0;
  const pct = totalQty > 0 ? Math.round((assignedQty / totalQty) * 100) : 0;
  const pallets = order.pallets ?? [];
  const items = order.items ?? [];
  const preview = items.slice(0, 3);
  const extra = items.length - preview.length;
  const formattedTotal = formatPeso(order.total_price);

  return (
    <div
      className={[
        "relative rounded-2xl overflow-hidden",
        "bg-white dark:bg-gray-800/60",
        "border border-gray-200 dark:border-gray-700/50",
        "shadow-sm hover:shadow-md transition-shadow duration-200",
        dim ? "opacity-55" : "",
      ]
        .filter(Boolean)
        .join(" ")}
    >
      <div className={`absolute left-0 top-0 bottom-0 w-1 ${c.accent} z-10`} />

      <Link
        to={`/order/${order.id}`}
        className="block pl-4 pr-4 pt-3 pb-3 space-y-2 hover:bg-gray-50/60 dark:hover:bg-white/[0.02] active:bg-gray-100/80 dark:active:bg-white/[0.05] transition-colors"
      >
        <div className="flex items-start gap-2 justify-between">
          <div className="flex flex-wrap items-center gap-1.5 min-w-0">
            <span className="font-mono font-extrabold text-lg tracking-tight text-gray-900 dark:text-white leading-none">
              #{order.code}
            </span>
            <span
              className={`inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[11px] font-semibold shrink-0 ${c.badge}`}
            >
              <span className={`w-1.5 h-1.5 rounded-full ${c.accent}`} />
              {c.label}
            </span>
            {hasPending && (
              <span className="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[11px] font-semibold bg-red-100 text-red-700 dark:bg-red-900/40 dark:text-red-300 shrink-0 animate-pulse">
                🚨 {order.pending_items_count}
              </span>
            )}
            {canFinalize && (
              <span className="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[11px] font-semibold bg-green-100 text-green-700 dark:bg-green-900/40 dark:text-green-300 shrink-0">
                ✓ Listo
              </span>
            )}
          </div>
          <span className="text-[11px] text-gray-400 dark:text-gray-500 shrink-0 tabular-nums pt-0.5">
            {order.created_at ? formatOrderDate(order.created_at) : ""}
          </span>
        </div>

        {preview.length > 0 && (
          <div className="flex flex-wrap gap-x-2 gap-y-0.5">
            {preview.map((item) => (
              <span
                key={item.id}
                className="text-xs text-gray-700 dark:text-gray-200"
              >
                {item.description}
                <span className="text-gray-400 dark:text-gray-500 ml-0.5">
                  ×{item.qty}
                </span>
              </span>
            ))}
            {extra > 0 && (
              <span className="text-xs text-gray-400 dark:text-gray-500 italic">
                +{extra} más
              </span>
            )}
          </div>
        )}

        {formattedTotal && (
          <p className="text-xs font-semibold text-gray-600 dark:text-gray-300">
            💰 {formattedTotal}
          </p>
        )}

        {totalQty > 0 && (
          <div className="space-y-1">
            <div className="flex items-center justify-between">
              <span className="text-xs text-gray-500 dark:text-gray-400 font-medium">
                {assignedQty}/{totalQty} uds. en bases
              </span>
              <span
                className={`text-xs font-bold ${
                  pct === 100
                    ? "text-green-600 dark:text-green-400"
                    : "text-gray-500 dark:text-gray-400"
                }`}
              >
                {pct}%
              </span>
            </div>
            <div className="h-2 w-full rounded-full bg-gray-100 dark:bg-gray-700 overflow-hidden">
              <div
                className={`h-full rounded-full transition-all duration-500 ${c.bar}`}
                style={{ width: `${pct}%` }}
              />
            </div>
          </div>
        )}

        {pallets.length > 0 && (
          <div className="flex flex-wrap gap-1.5">
            {pallets.map((pallet) => (
              <span
                key={pallet.id}
                className="font-mono text-[11px] bg-gray-100 dark:bg-gray-700/70 text-gray-600 dark:text-gray-300 px-2 py-0.5 rounded-md"
              >
                {pallet.code}
              </span>
            ))}
          </div>
        )}
      </Link>

      {items.length > 0 && (
        <button
          onClick={() => setExpanded((value) => !value)}
          className={[
            "w-full flex items-center justify-center gap-1.5 py-1.5",
            "border-t border-gray-100 dark:border-gray-700/40",
            "text-[11px] font-medium select-none",
            expanded
              ? "bg-blue-50 dark:bg-blue-900/10 text-blue-600 dark:text-blue-400"
              : "text-gray-400 dark:text-gray-500 hover:bg-gray-50 dark:hover:bg-white/[0.03] hover:text-gray-600 dark:hover:text-gray-300",
            "transition-colors",
          ].join(" ")}
        >
          <svg
            className={`w-3.5 h-3.5 transition-transform duration-300 ${
              expanded ? "rotate-180" : ""
            }`}
            fill="none"
            viewBox="0 0 24 24"
            strokeWidth={2.5}
            stroke="currentColor"
          >
            <path
              strokeLinecap="round"
              strokeLinejoin="round"
              d="M19 9l-7 7-7-7"
            />
          </svg>
          {expanded ? "Ocultar" : `Ver los ${items.length} productos`}
        </button>
      )}

      <div
        className={`grid ${expanded ? "grid-rows-[1fr]" : "grid-rows-[0fr]"}`}
        style={{ transition: "grid-template-rows 280ms ease-in-out" }}
      >
        <div className="overflow-hidden">
          <div className="border-t border-gray-100 dark:border-gray-700/40 px-4 py-3 space-y-3">
            <div className="space-y-2">
              {items.map((item) => {
                const isPending = pendingItemIds.has(item.id);
                return (
                  <div
                    key={item.id}
                    className={`flex items-center gap-3 min-w-0 ${
                      isPending
                        ? "bg-red-50 dark:bg-red-900/20 -mx-2 px-2 rounded-lg"
                        : ""
                    }`}
                  >
                    <ProductAvatar
                      description={item.description}
                      imageUrl={item.image_url}
                    />
                    <div className="flex-1 min-w-0">
                      <p
                        className={`text-xs leading-snug truncate ${
                          isPending
                            ? "text-red-700 dark:text-red-300 font-semibold"
                            : "text-gray-800 dark:text-gray-100"
                        }`}
                      >
                        {item.description}
                        {isPending && " 🚨"}
                      </p>
                      <p className="text-[11px] text-gray-400 dark:text-gray-500 mt-0.5">
                        {item.qty} unidades
                      </p>
                    </div>
                    <span
                      className={`w-1.5 h-1.5 rounded-full shrink-0 ${
                        isPending
                          ? "bg-red-500 animate-pulse"
                          : item.status === "done"
                            ? "bg-green-400"
                            : "bg-gray-300 dark:bg-gray-600"
                      }`}
                    />
                  </div>
                );
              })}
            </div>

            {canFinalize && (
              <button
                onClick={onFinalize}
                disabled={finalizing}
                className={[
                  "w-full flex items-center gap-3 px-3.5 py-2.5 rounded-2xl",
                  "border border-green-200 dark:border-green-800/40",
                  "bg-green-50 dark:bg-green-900/20",
                  "hover:bg-green-100 dark:hover:bg-green-900/30",
                  "active:scale-[0.99] transition-all duration-150",
                  "disabled:opacity-50 disabled:pointer-events-none",
                ].join(" ")}
              >
                <div className="w-9 h-9 rounded-xl bg-green-500 shadow-sm flex items-center justify-center shrink-0">
                  <svg
                    className="w-5 h-5 text-white"
                    fill="none"
                    viewBox="0 0 24 24"
                    strokeWidth={2.5}
                    stroke="currentColor"
                  >
                    <path
                      strokeLinecap="round"
                      strokeLinejoin="round"
                      d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z"
                    />
                  </svg>
                </div>
                <span className="text-sm font-semibold text-green-700 dark:text-green-400">
                  {finalizing ? "Finalizando…" : "Finalizar pedido"}
                </span>
              </button>
            )}

            <Link
              to={`/order/${order.id}`}
              className="flex items-center justify-end gap-1 text-xs font-semibold text-blue-600 dark:text-blue-400 hover:underline"
            >
              Abrir pedido completo
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
            </Link>
          </div>
        </div>
      </div>
    </div>
  );
}
