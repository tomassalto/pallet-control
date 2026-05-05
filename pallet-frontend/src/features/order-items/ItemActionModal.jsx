import { toastError } from "../../ui/toast";

function onlyDigits(v) {
  return (v || "").replace(/\D/g, "");
}

function textClass(status) {
  if (status === "removed") return "line-through text-red-900";
  return "";
}

/**
 * Modal de acción sobre un ítem del pedido: muestra descripción/EAN y
 * permite al operario ingresar la cantidad encontrada (o quitar el producto).
 *
 * @param {object}   props
 * @param {object}   props.actionItem   — ítem seleccionado (null = cerrado)
 * @param {string}   props.actionQty    — valor actual del input
 * @param {Function} props.setActionQty
 * @param {Function} props.onClose      — cierra el modal (setActionItem(null))
 * @param {Function} props.onSave       — (item, qty) → tryApplyAction
 * @param {string}   props.orderStatus  — status del pedido ("open"|"done"|…)
 */
export default function ItemActionModal({
  actionItem,
  actionQty,
  setActionQty,
  onClose,
  onSave,
  orderStatus,
}) {
  if (!actionItem) return null;

  return (
    <div className="fixed inset-0 z-50 bg-black/60 p-4 flex items-center justify-center">
      <div className="flex flex-col w-full max-w-md bg-white rounded-2xl p-4 gap-2">
        {/* Header */}
        <div className="flex items-center justify-center relative">
          <div className="font-semibold text-center text-lg">
            Acciones para el producto
          </div>
          <button
            onClick={onClose}
            className="absolute right-[-14px] top-[-10px] px-3 py-1 text-xs bg-white"
          >
            x
          </button>
        </div>

        {/* Descripción y EAN */}
        <div className={`text-sm ${textClass(actionItem.status)}`}>
          {actionItem.description}
        </div>
        <div className="font-mono text-sm break-all text-gray-500">
          {actionItem.ean || "—"}
        </div>

        {/* Input de cantidad — solo si el pedido no está finalizado */}
        {orderStatus !== "done" && (
          <div className="flex flex-col gap-2 items-center justify-center">
            <div className="text-md font-semibold text-[#1b1b1b]">
              Cantidad encontrada
            </div>

            <div className="flex items-center justify-center gap-2 w-3/4">
              <input
                value={actionQty}
                onChange={(e) => setActionQty(onlyDigits(e.target.value))}
                inputMode="numeric"
                className="flex-1 border rounded-lg py-2 text-center text-lg font-semibold"
                placeholder="0"
                autoFocus
              />
            </div>

            <button
              onClick={() => {
                const q = parseInt(onlyDigits(actionQty), 10);
                if (isNaN(q) || q < 0) {
                  toastError("La cantidad debe ser 0 o mayor.");
                  return;
                }
                onSave(actionItem, q);
              }}
              className="w-3/4 rounded-lg py-3 bg-black text-white text-sm font-semibold"
            >
              Guardar
            </button>
          </div>
        )}
      </div>
    </div>
  );
}
