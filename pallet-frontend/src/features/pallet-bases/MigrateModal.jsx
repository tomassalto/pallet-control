import { PageSpinner } from "../../ui/Spinner";

/**
 * Modal de migración de productos entre bases.
 * Flujo en 2 pasos: selección de ítems/cantidades → selección de destino.
 *
 * Props vienen de useMigrate().
 */
export default function MigrateModal({
  migrateModal,
  setMigrateModal,
  setMigrateQty,
  selectAllMigrate,
  goToMigrateDest,
  selectMigratePallet,
  saveMigrate,
}) {
  if (!migrateModal) return null;

  return (
    <div className="fixed inset-0 z-50 bg-black/60 flex items-end sm:items-center justify-center">
      <div className="w-full sm:max-w-lg bg-white dark:bg-gray-800 rounded-t-3xl sm:rounded-2xl flex flex-col max-h-[90vh]">

        {/* Header */}
        <div className="flex items-center justify-between px-4 pt-5 pb-3 border-b border-gray-200 dark:border-gray-700 shrink-0">
          <div>
            <p className="font-bold text-base text-gray-900 dark:text-white">
              🔀 Migrar desde{" "}
              {migrateModal.sourceBase.name || `Base #${migrateModal.sourceBase.id}`}
            </p>
            <p className="text-xs text-gray-500 dark:text-gray-400 mt-0.5">
              {migrateModal.step === "items"
                ? "¿Qué productos y cuántas unidades?"
                : "¿A dónde los llevás?"}
            </p>
          </div>
          <button
            onClick={() => setMigrateModal(null)}
            className="p-1.5 rounded-lg text-gray-400 hover:text-gray-700 hover:bg-gray-100 dark:hover:bg-gray-700 transition-colors text-lg"
          >
            ✕
          </button>
        </div>

        {/* Paso 1: selección de ítems */}
        {migrateModal.step === "items" && (
          <>
            <div className="flex-1 overflow-y-auto divide-y divide-gray-100 dark:divide-gray-700">
              <div className="px-4 py-2 bg-gray-50 dark:bg-gray-700/50 flex items-center justify-between">
                <span className="text-xs text-gray-500 dark:text-gray-400">
                  {Object.values(migrateModal.quantities).filter((q) => q > 0).length}{" "}
                  producto(s) seleccionado(s)
                </span>
                <button
                  onClick={selectAllMigrate}
                  className="text-xs text-blue-600 dark:text-blue-400 font-semibold hover:underline"
                >
                  Seleccionar todo
                </button>
              </div>

              {(migrateModal.sourceBase.order_items || []).map((item) => {
                const maxQty = item.pivot?.qty ?? 0;
                const cur = migrateModal.quantities[item.id] ?? 0;
                return (
                  <div
                    key={item.id}
                    className={[
                      "flex items-center gap-3 px-4 py-3 transition-colors",
                      cur > 0 ? "border-l-4 border-l-amber-500" : "",
                    ].join(" ")}
                  >
                    <div className="flex-1 min-w-0">
                      <p className="text-sm font-medium leading-snug text-gray-900 dark:text-white">
                        {item.description}
                      </p>
                      <p className="text-xs font-mono text-gray-400 mt-0.5">{item.ean}</p>
                      <p className="text-xs text-gray-500 mt-0.5">
                        En base: <span className="font-semibold">{maxQty}</span> u.
                      </p>
                    </div>
                    <div className="flex items-center gap-1.5 shrink-0">
                      <button
                        onClick={() => setMigrateQty(item.id, cur - 1, maxQty)}
                        disabled={cur === 0}
                        className="w-8 h-8 rounded-full border border-gray-300 dark:border-gray-600 flex items-center justify-center text-gray-600 dark:text-gray-300 disabled:opacity-25 text-lg leading-none select-none"
                      >
                        −
                      </button>
                      <input
                        type="text"
                        inputMode="numeric"
                        value={cur === 0 ? "" : cur}
                        placeholder="0"
                        onChange={(e) => setMigrateQty(item.id, e.target.value, maxQty)}
                        onFocus={(e) => e.target.select()}
                        className="w-12 text-center text-sm font-bold border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white rounded-lg py-1 focus:outline-none focus:ring-2 focus:ring-amber-300"
                      />
                      <button
                        onClick={() => setMigrateQty(item.id, cur + 1, maxQty)}
                        disabled={cur >= maxQty}
                        className="w-8 h-8 rounded-full bg-amber-500 text-white flex items-center justify-center disabled:opacity-25 text-lg leading-none select-none"
                      >
                        +
                      </button>
                    </div>
                  </div>
                );
              })}
            </div>

            <div className="p-4 border-t border-gray-200 dark:border-gray-700 shrink-0">
              <button
                onClick={goToMigrateDest}
                disabled={Object.values(migrateModal.quantities).every((q) => q === 0)}
                className="w-full py-3.5 rounded-2xl bg-gray-900 dark:bg-white text-white dark:text-gray-900 font-bold text-sm disabled:opacity-40"
              >
                Seleccionar destino →
              </button>
            </div>
          </>
        )}

        {/* Paso 2: selección de destino */}
        {migrateModal.step === "dest" && (
          <>
            <div className="px-4 py-2 border-b border-gray-200 dark:border-gray-700 shrink-0">
              <button
                onClick={() =>
                  setMigrateModal((prev) =>
                    prev
                      ? { ...prev, step: "items", selectedPalletId: null, selectedBaseId: null }
                      : null
                  )
                }
                className="text-sm text-blue-600 dark:text-blue-400 font-semibold hover:underline"
              >
                ← Volver
              </button>
            </div>

            <div className="flex-1 overflow-y-auto p-4 space-y-4">
              {migrateModal.loadingPallets ? (
                <PageSpinner />
              ) : (
                <>
                  {/* Pallet destino */}
                  <div>
                    <p className="text-xs font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-2">
                      Pallet destino
                    </p>
                    <div className="space-y-2">
                      <button
                        onClick={() => selectMigratePallet(null)}
                        className={[
                          "w-full text-left rounded-xl border px-4 py-3 text-sm transition-colors",
                          migrateModal.selectedPalletId === null
                            ? "border-amber-400 bg-amber-50 dark:bg-amber-900/20 font-semibold"
                            : "border-gray-200 dark:border-gray-700 hover:bg-gray-50 dark:hover:bg-gray-700",
                        ].join(" ")}
                      >
                        <span className="font-medium text-gray-900 dark:text-white">
                          🆕 Crear pallet nuevo
                        </span>
                        <span className="block text-xs text-gray-400 mt-0.5">
                          Se generará con código automático
                        </span>
                      </button>

                      {migrateModal.pallets.map((p) => (
                        <button
                          key={p.id}
                          onClick={() => selectMigratePallet(p.id)}
                          className={[
                            "w-full text-left rounded-xl border px-4 py-3 text-sm transition-colors",
                            migrateModal.selectedPalletId === p.id
                              ? "border-amber-400 bg-amber-50 dark:bg-amber-900/20 font-semibold"
                              : "border-gray-200 dark:border-gray-700 hover:bg-gray-50 dark:hover:bg-gray-700",
                          ].join(" ")}
                        >
                          <span className="font-mono font-semibold text-gray-900 dark:text-white">
                            {p.code}
                          </span>
                          <span
                            className={`ml-2 text-xs px-1.5 py-0.5 rounded-full ${
                              p.status === "done"
                                ? "bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-300"
                                : "bg-amber-100 text-amber-700 dark:bg-amber-900/30 dark:text-amber-300"
                            }`}
                          >
                            {p.status === "done" ? "Finalizado" : "Abierto"}
                          </span>
                        </button>
                      ))}
                    </div>
                  </div>

                  {/* Base destino (solo si se eligió un pallet existente) */}
                  {migrateModal.selectedPalletId !== null && (
                    <div>
                      <p className="text-xs font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-2">
                        Base destino
                      </p>
                      <div className="space-y-2">
                        <button
                          onClick={() =>
                            setMigrateModal((prev) =>
                              prev ? { ...prev, selectedBaseId: null } : null
                            )
                          }
                          className={[
                            "w-full text-left rounded-xl border px-4 py-3 text-sm transition-colors",
                            migrateModal.selectedBaseId === null
                              ? "border-amber-400 bg-amber-50 dark:bg-amber-900/20 font-semibold"
                              : "border-gray-200 dark:border-gray-700 hover:bg-gray-50 dark:hover:bg-gray-700",
                          ].join(" ")}
                        >
                          <span className="text-gray-900 dark:text-white">🆕 Crear base nueva</span>
                        </button>

                        {migrateModal.selectedPalletBases.map((b) => (
                          <button
                            key={b.id}
                            onClick={() =>
                              setMigrateModal((prev) =>
                                prev ? { ...prev, selectedBaseId: b.id } : null
                              )
                            }
                            className={[
                              "w-full text-left rounded-xl border px-4 py-3 text-sm transition-colors",
                              migrateModal.selectedBaseId === b.id
                                ? "border-amber-400 bg-amber-50 dark:bg-amber-900/20 font-semibold"
                                : "border-gray-200 dark:border-gray-700 hover:bg-gray-50 dark:hover:bg-gray-700",
                            ].join(" ")}
                          >
                            <span className="font-medium text-gray-900 dark:text-white">
                              {b.name || `Base #${b.id}`}
                            </span>
                            <span className="ml-2 text-xs text-gray-400">
                              {b.order_items?.length || 0} prod.
                            </span>
                          </button>
                        ))}
                      </div>
                    </div>
                  )}
                </>
              )}
            </div>

            <div className="p-4 border-t border-gray-200 dark:border-gray-700 shrink-0">
              {(() => {
                const count = Object.values(migrateModal.quantities).filter((q) => q > 0).length;
                const totalUnits = Object.values(migrateModal.quantities).reduce((s, q) => s + q, 0);
                return (
                  <button
                    onClick={saveMigrate}
                    disabled={migrateModal.saving}
                    className="w-full py-3.5 rounded-2xl bg-amber-500 hover:bg-amber-600 text-white font-bold text-sm disabled:opacity-40 transition-colors"
                  >
                    {migrateModal.saving
                      ? "Migrando…"
                      : `Migrar ${totalUnits} u. (${count} producto${count !== 1 ? "s" : ""})`}
                  </button>
                );
              })()}
            </div>
          </>
        )}
      </div>
    </div>
  );
}
