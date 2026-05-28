import { ActionItem, Icons } from "../../ui/ActionList";

const SEC_LABEL =
  "text-xs font-semibold uppercase tracking-wider text-gray-400 dark:text-gray-500";

export default function OrderDetailActions({
  order,
  orderId,
  canFinalize,
  finalizing,
  onOpenAttachPallet,
  onOpenAddProduct,
  onFinalize,
}) {
  if (!order) return null;

  return (
    <section className="space-y-2.5">
      <p className={SEC_LABEL}>Acciones</p>
      {order.status !== "done" ? (
        <div className="space-y-2">
          <ActionItem
            icon={Icons.Eye}
            iconBg="bg-blue-600"
            label="Vista pública"
            sublabel="Ver como cliente vía QR"
            href={`/order-view/${order.code}`}
          />
          <ActionItem
            icon={Icons.Import}
            iconBg="bg-blue-500"
            label="Importar pedido"
            sublabel="Cargar productos desde texto copiado"
            to={`/order/${orderId}/import`}
          />
          <ActionItem
            icon={Icons.Pallet}
            iconBg="bg-purple-500"
            label="Asociar pallet"
            sublabel="Vincular este pedido a un pallet existente"
            onClick={onOpenAttachPallet}
          />
          <ActionItem
            icon={Icons.Plus}
            iconBg="bg-green-500"
            label="Agregar producto"
            sublabel="Buscar por EAN o últimos 4 dígitos"
            onClick={onOpenAddProduct}
          />
          <ActionItem
            icon={Icons.History}
            iconBg="bg-gray-500"
            label="Historial"
            sublabel="Registro de cambios del pedido"
            to={`/order/${orderId}/history`}
          />
          {order.status === "open" && canFinalize && (
            <ActionItem
              icon={Icons.Check}
              iconBg="bg-green-500"
              label={finalizing ? "Finalizando..." : "Finalizar pedido"}
              sublabel="Todos los productos están distribuidos en bases"
              onClick={onFinalize}
              disabled={finalizing}
              noChevron
            />
          )}
        </div>
      ) : (
        <>
          <ActionItem
            icon={Icons.Eye}
            iconBg="bg-blue-600"
            label="Vista pública"
            sublabel="Ver como cliente vía QR"
            href={`/order-view/${order.code}`}
          />
          <ActionItem
            icon={Icons.History}
            iconBg="bg-gray-500"
            label="Ver historial"
            sublabel="Registro de actividad del pedido"
            to={`/order/${orderId}/history`}
          />
        </>
      )}
    </section>
  );
}
