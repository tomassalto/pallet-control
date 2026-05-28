import { ActionItem, Icons } from "../../ui/ActionList";
import { BTN_BLUE, BTN_GREEN, SEC_LABEL } from "./palletDetailUi";

export default function PalletActionsSection({
  palletId,
  pallet,
  activeOrder,
  canFinalize,
  onAssign,
  onImport,
  onFinalize,
  onReopen,
  onDelete,
}) {
  const palletDone = pallet.status === "done";

  return (
    <section className="space-y-2.5">
      <p className={SEC_LABEL}>Acciones</p>
      <div className="space-y-2">
        {pallet.status === "open" && (
          <>
            <ActionItem
              icon={Icons.AssignOrder}
              iconBg="bg-blue-500"
              label="Asignar pedido"
              sublabel="Vincular un número de pedido a este pallet"
              onClick={onAssign}
            />
            <ActionItem
              icon={Icons.Import}
              iconBg="bg-indigo-500"
              label="Importar pedido"
              sublabel={
                activeOrder
                  ? `Pedido activo: #${activeOrder.code}`
                  : "Primero asigná un pedido"
              }
              onClick={onImport}
              disabled={!activeOrder}
            />
          </>
        )}

        <ActionItem
          icon={Icons.Gallery}
          iconBg="bg-amber-500"
          label="Galería"
          sublabel="Fotos del pallet completo"
          to={`/pallet/${palletId}/gallery`}
        />
        <ActionItem
          icon={Icons.History}
          iconBg="bg-gray-500"
          label="Historial"
          sublabel="Registro de actividad"
          to={`/pallet/${palletId}/history`}
        />

        {palletDone && (
          <button onClick={onReopen} className={BTN_GREEN}>
            Reabrir pallet
          </button>
        )}
        {canFinalize && !palletDone && (
          <button onClick={onFinalize} className={BTN_BLUE}>
            ✓ Finalizar pallet
          </button>
        )}

        <div className="pt-1 border-t border-gray-100 dark:border-gray-800 mt-1">
          <ActionItem
            icon={Icons.Trash}
            iconBg="bg-red-600"
            label="Eliminar pallet"
            sublabel="Elimina todas las bases, fotos y datos. Irreversible."
            onClick={onDelete}
            variant="danger"
          />
        </div>
      </div>
    </section>
  );
}
