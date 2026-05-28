import { BTN_PRI, INPUT_CLS, onlyDigits } from "./palletDetailUi";

export default function AssignOrderModal({
  orderCode,
  setOrderCode,
  onClose,
  onSubmit,
}) {
  return (
    <div className="fixed inset-0 z-50 bg-black/60 p-4 flex items-center justify-center">
      <div className="w-full max-w-md bg-white dark:bg-gray-800 rounded-2xl p-5 shadow-2xl">
        <div className="flex items-center justify-between mb-4">
          <p className="font-semibold text-gray-900 dark:text-white">
            Asignar pedido
          </p>
          <button
            onClick={onClose}
            className="p-1.5 rounded-lg text-gray-400 hover:text-gray-700 hover:bg-gray-100 dark:hover:bg-gray-700 transition-colors"
          >
            ×
          </button>
        </div>
        <form onSubmit={onSubmit} className="space-y-3">
          <div>
            <label className="text-sm font-medium text-gray-700 dark:text-gray-300 block mb-1">
              Número de pedido
            </label>
            <input
              value={orderCode}
              onChange={(event) => setOrderCode(onlyDigits(event.target.value))}
              inputMode="numeric"
              placeholder="Ej: 123456"
              className={INPUT_CLS}
            />
          </div>
          <button className={BTN_PRI}>Asignar</button>
          <p className="text-xs text-gray-500 dark:text-gray-400">
            Si el pedido no existe, se crea automáticamente.
          </p>
        </form>
      </div>
    </div>
  );
}
