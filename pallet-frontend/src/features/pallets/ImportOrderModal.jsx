import { BTN_PRI } from "./palletDetailUi";

export default function ImportOrderModal({
  activeOrder,
  raw,
  setRaw,
  onClose,
  onSubmit,
}) {
  return (
    <div className="fixed inset-0 z-50 bg-black/60 p-4 flex items-center justify-center">
      <div className="w-full max-w-md bg-white dark:bg-gray-800 rounded-2xl p-5 shadow-2xl">
        <div className="flex items-center justify-between mb-2">
          <p className="font-semibold text-gray-900 dark:text-white">
            Importar pedido
          </p>
          <button
            onClick={onClose}
            className="p-1.5 rounded-lg text-gray-400 hover:text-gray-700 hover:bg-gray-100 dark:hover:bg-gray-700 transition-colors"
          >
            ×
          </button>
        </div>
        <p className="text-xs text-gray-500 dark:text-gray-400 mb-4">
          Pedido activo:{" "}
          <span className="font-mono font-semibold">
            {activeOrder?.code || "—"}
          </span>
        </p>
        <form onSubmit={onSubmit} className="space-y-3">
          <textarea
            value={raw}
            onChange={(event) => setRaw(event.target.value)}
            rows={10}
            placeholder="Pegá acá el texto copiado de la tabla (TABs)."
            className="w-full border border-gray-300 dark:border-gray-600 rounded-xl p-3 font-mono text-xs bg-gray-50 dark:bg-gray-700 dark:text-white focus:outline-none focus:ring-2 focus:ring-gray-900 dark:focus:ring-white"
          />
          <button className={BTN_PRI}>Importar (reemplaza)</button>
          <p className="text-xs text-gray-500 dark:text-gray-400">
            Esto reemplaza los ítems del pedido importado.
          </p>
        </form>
      </div>
    </div>
  );
}
