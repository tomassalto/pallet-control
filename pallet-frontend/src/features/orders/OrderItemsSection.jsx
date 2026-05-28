import ItemCard from "../order-items/ItemCard";

const SEC_LABEL =
  "text-xs font-semibold uppercase tracking-wider text-gray-400 dark:text-gray-500";
const BTN_SEC =
  "flex items-center justify-center w-full py-3 px-4 rounded-xl bg-gray-100 dark:bg-gray-700/60 text-gray-700 dark:text-gray-200 text-sm font-semibold hover:bg-gray-200 dark:hover:bg-gray-700 disabled:opacity-40 transition-colors";

export default function OrderItemsSection({
  order,
  items,
  pendingItemIdsSet,
  itemStatusSummary,
  rowClass,
  onSelectItem,
  onRefresh,
}) {
  if (order?.status === "done" || items.length === 0) {
    return null;
  }

  return (
    <section className="space-y-3">
      <div className="flex items-center justify-between">
        <p className={SEC_LABEL}>Productos del pedido</p>
        <div className="flex gap-2">
          {itemStatusSummary.pending > 0 && (
            <span className="text-xs font-semibold text-amber-600 dark:text-amber-400">
              {itemStatusSummary.pending} pend.
            </span>
          )}
          {itemStatusSummary.done > 0 && (
            <span className="text-xs font-semibold text-green-600 dark:text-green-400">
              {itemStatusSummary.done} listos
            </span>
          )}
          {itemStatusSummary.removed > 0 && (
            <span className="text-xs font-semibold text-red-500 dark:text-red-400">
              {itemStatusSummary.removed} quit.
            </span>
          )}
        </div>
      </div>

      <div className="flex flex-col gap-3">
        {[...items]
          .sort((a, b) => a.description.localeCompare(b.description))
          .map((item) => {
            const isPending = pendingItemIdsSet.has(item.id);
            return (
              <ItemCard
                key={item.id}
                item={item}
                onSelect={() => onSelectItem(item)}
                borderColor={rowClass(item.status, isPending)}
                bgColor={isPending ? "bg-red-50 dark:bg-red-900/20" : "bg-gray-50"}
              />
            );
          })}
      </div>

      <button onClick={onRefresh} className={BTN_SEC}>
        Refrescar
      </button>
    </section>
  );
}
