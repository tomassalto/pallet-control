import { ActionItem, Icons } from "../../ui/ActionList";

const SEC_LABEL =
  "text-xs font-semibold uppercase tracking-wider text-gray-400 dark:text-gray-500";

export function OrderActions({ order, onAttachPallet, onImport, onFinalize, onAddProduct, finalizing, canFinalize }) {
  if (!order) return null;

  if (order.status === "done") {
    return (
      <section className="space-y-2.5">
        <p className={SEC_LABEL}>Acciones</p>
        <p className="text-sm text-gray-500 dark:text-gray-400">
          Este pedido está finalizado y no permite más acciones.
        </p>
      </section>
    );
  }

  return (
    <section className="space-y-2.5">
      <p className={SEC_LABEL}>Acciones</p>
      <div className="space-y-2">
        <ActionItem
          icon={Icons.Import}
          iconBg="bg-blue-500"
          label="Importar pedido"
          sublabel="Cargar productos desde texto copiado"
          to={`/order/${order.id}/import`}
        />
        <ActionItem
          icon={Icons.Pallet}
          iconBg="bg-purple-500"
          label="Asociar pallet"
          sublabel="Vincular este pedido a un pallet existente"
          onClick={onAttachPallet}
        />
        <ActionItem
          icon={Icons.Add}
          iconBg="bg-green-500"
          label="Agregar producto"
          sublabel="Añadir un producto manualmente al pedido"
          onClick={onAddProduct}
        />
        <ActionItem
          icon={Icons.Check}
          iconBg={canFinalize ? "bg-green-500" : "bg-gray-400"}
          label="Finalizar pedido"
          sublabel={canFinalize ? "Marcar como completado" : "Organizá todos los productos primero"}
          onClick={canFinalize ? onFinalize : null}
          disabled={!canFinalize}
        />
      </div>
    </section>
  );
}