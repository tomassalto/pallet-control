import PalletOrderChip from "./PalletOrderChip";
import { SEC_LABEL } from "./palletDetailUi";

export default function PalletOrdersSection({
  orders,
  activeOrderId,
  onSelectOrder,
}) {
  return (
    <section className="space-y-3">
      <p className={SEC_LABEL}>Pedidos asociados</p>
      {orders.length === 0 ? (
        <p className="text-sm text-gray-500 dark:text-gray-400 py-2">
          No hay pedidos asignados.
        </p>
      ) : (
        <div className="flex flex-wrap gap-2">
          {orders.map((order) => (
            <PalletOrderChip
              key={order.id}
              order={order}
              activeOrderId={activeOrderId}
              onSelect={onSelectOrder}
            />
          ))}
        </div>
      )}
    </section>
  );
}
