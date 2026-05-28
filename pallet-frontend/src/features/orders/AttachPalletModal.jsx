import { PageSpinner } from "../../ui/Spinner";

export default function AttachPalletModal({
  open,
  onClose,
  onCreatePallet,
  onAttachPallet,
  availablePallets,
  loadingPallets,
}) {
  if (!open) return null;

  return (
    <div className="fixed inset-0 z-50 bg-black/60 p-4 flex items-center justify-center">
      <div className="w-full max-w-md bg-white dark:bg-gray-800 rounded-2xl p-4 max-h-[80vh] flex flex-col shadow-2xl">
        <div className="flex items-center justify-between mb-4">
          <h2 className="font-bold text-lg text-gray-900 dark:text-white">
            Asociar a pallet
          </h2>
          <button
            onClick={onClose}
            className="w-8 h-8 flex items-center justify-center rounded-full bg-gray-100 dark:bg-gray-700 text-gray-500 dark:text-gray-400 hover:bg-gray-200 dark:hover:bg-gray-600 transition-colors"
          >
            <svg
              className="w-4 h-4"
              fill="none"
              viewBox="0 0 24 24"
              strokeWidth={2.5}
              stroke="currentColor"
            >
              <path
                strokeLinecap="round"
                strokeLinejoin="round"
                d="M6 18 18 6M6 6l12 12"
              />
            </svg>
          </button>
        </div>

        <div className="mb-4">
          <button
            onClick={onCreatePallet}
            className="w-full flex items-center justify-center gap-2 bg-gray-900 dark:bg-white text-white dark:text-gray-900 rounded-xl py-3 text-sm font-bold hover:bg-gray-700 dark:hover:bg-gray-100 active:scale-[0.99] transition-all"
          >
            <svg
              className="w-4 h-4"
              fill="none"
              viewBox="0 0 24 24"
              strokeWidth={2.5}
              stroke="currentColor"
            >
              <path
                strokeLinecap="round"
                strokeLinejoin="round"
                d="M12 4.5v15m7.5-7.5h-15"
              />
            </svg>
            Crear pallet nuevo
          </button>
        </div>

        <div className="flex-1 overflow-y-auto space-y-2">
          {loadingPallets ? (
            <PageSpinner />
          ) : availablePallets.length === 0 ? (
            <div className="text-sm text-gray-500 dark:text-gray-400 text-center py-8">
              No hay pallets disponibles para asociar
            </div>
          ) : (
            <>
              <p className="text-xs font-semibold uppercase tracking-wider text-gray-400 dark:text-gray-500 mb-1">
                Pallets existentes
              </p>
              {availablePallets.map((pallet) => (
                <button
                  key={pallet.id}
                  onClick={() => onAttachPallet(pallet.id)}
                  className="relative w-full text-left bg-white dark:bg-gray-700/60 border border-gray-200 dark:border-gray-600/50 rounded-xl overflow-hidden hover:shadow-md hover:-translate-y-0.5 active:scale-[0.99] active:shadow-none transition-all duration-150"
                >
                  <div
                    className={`absolute left-0 top-0 bottom-0 w-1 ${pallet.status === "done" ? "bg-green-500" : "bg-blue-500"}`}
                  />
                  <div className="pl-4 pr-3 py-3 flex items-center justify-between gap-3">
                    <div>
                      <p className="font-mono font-bold text-gray-900 dark:text-white">
                        {pallet.code}
                      </p>
                      <p className="text-xs text-gray-400 dark:text-gray-500 mt-0.5 capitalize">
                        {pallet.status === "done" ? "✓ Completo" : "En proceso"}
                      </p>
                    </div>
                    <svg
                      className="w-4 h-4 text-gray-300 dark:text-gray-600 shrink-0"
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
                  </div>
                </button>
              ))}
            </>
          )}
        </div>
      </div>
    </div>
  );
}
