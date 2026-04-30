import { useState } from "react";

/** Thumbnail inside the modal product list */
function ModalProductImage({ src, alt }) {
  const [err, setErr] = useState(false);
  if (!src || err) {
    return (
      <div className="w-11 h-11 shrink-0 rounded-xl bg-gray-100 flex items-center justify-center border border-gray-200 text-xl select-none">
        📦
      </div>
    );
  }
  return (
    <div className="w-11 h-11 shrink-0 rounded-xl overflow-hidden bg-gray-100 border border-gray-200">
      <img
        src={src}
        alt={alt}
        className="w-full h-full object-contain"
        onError={() => setErr(true)}
      />
    </div>
  );
}

/**
 * Two-step bottom-sheet modal for assigning order items to a pallet base.
 *
 * Props:
 *  - organizeModal            : state object | null  (palletCode, step, bases, selectedBase, quantities, loading, saving)
 *  - setOrganizeModal         : state setter (pass null to close)
 *  - modalItems               : filtered order items available for organizing
 *  - modalMaxQty              : (orderItem) => number
 *  - decModalQty              : (itemId) => void
 *  - incModalQty              : (orderItem) => void
 *  - setModalQty              : (orderItem, rawValue) => void
 *  - selectBaseForOrganize    : (base) => void
 *  - createBaseAndOrganize    : () => Promise<void>
 *  - saveOrganize             : () => Promise<void>
 *  - countFromThisOrderInBase : (base) => number
 */
export default function OrganizeModal({
  organizeModal,
  setOrganizeModal,
  modalItems,
  modalMaxQty,
  decModalQty,
  incModalQty,
  setModalQty,
  selectBaseForOrganize,
  createBaseAndOrganize,
  saveOrganize,
  countFromThisOrderInBase,
}) {
  if (!organizeModal) return null;

  return (
    <div className="fixed inset-0 z-50 bg-black/60 flex items-end sm:items-center justify-center">
      <div className="w-full sm:max-w-lg bg-white rounded-t-3xl sm:rounded-2xl flex flex-col max-h-[90vh]">
        {/* Header */}
        <div className="flex items-center justify-between px-4 pt-5 pb-3 border-b shrink-0">
          <div>
            <p className="font-bold text-base">📦 {organizeModal.palletCode}</p>
            {organizeModal.step === "products" && (
              <p className="text-sm text-gray-500">
                {organizeModal.selectedBase?.name ||
                  `Base ${(organizeModal.bases.findIndex((b) => b.id === organizeModal.selectedBase?.id) ?? 0) + 1}`}
              </p>
            )}
          </div>
          <button
            onClick={() => setOrganizeModal(null)}
            className="text-gray-400 hover:text-gray-700 text-xl leading-none"
          >
            ✕
          </button>
        </div>

        {/* ── Paso 1: selección de base ─────────────────────────── */}
        {organizeModal.step === "base" && (
          <div className="flex-1 overflow-y-auto p-4 space-y-3">
            <p className="text-sm text-gray-500">
              ¿En qué base querés poner los productos de este pedido?
            </p>

            {organizeModal.loading ? (
              <p className="text-center text-gray-400 py-8 text-sm">
                Cargando…
              </p>
            ) : (
              <>
                {organizeModal.bases.length === 0 && (
                  <p className="text-sm text-gray-400 text-center py-4">
                    Este pallet no tiene bases todavía.
                  </p>
                )}

                {organizeModal.bases.map((base, i) => {
                  const count = countFromThisOrderInBase(base);
                  return (
                    <button
                      key={base.id}
                      onClick={() => selectBaseForOrganize(base)}
                      className="w-full text-left rounded-2xl border border-gray-200 p-4 hover:bg-gray-50 active:scale-[0.99] transition-colors"
                    >
                      <p className="font-semibold text-sm">
                        {base.name || `Base ${i + 1}`}
                      </p>
                      <p className="text-xs text-gray-500 mt-0.5">
                        {count > 0
                          ? `${count} producto${count !== 1 ? "s" : ""} de este pedido ya asignados`
                          : "Sin productos de este pedido aún"}
                      </p>
                    </button>
                  );
                })}

                <button
                  onClick={createBaseAndOrganize}
                  disabled={organizeModal.loading}
                  className="w-full border-2 border-dashed border-gray-300 rounded-2xl p-4 text-sm text-gray-500 hover:bg-gray-50 disabled:opacity-50 active:scale-[0.99]"
                >
                  + Crear nueva base
                </button>
              </>
            )}
          </div>
        )}

        {/* ── Paso 2: asignar cantidades ────────────────────────── */}
        {organizeModal.step === "products" && (
          <>
            <div className="px-4 py-2 border-b shrink-0">
              <button
                onClick={() =>
                  setOrganizeModal((prev) =>
                    prev
                      ? {
                          ...prev,
                          step: "base",
                          selectedBase: null,
                          quantities: {},
                        }
                      : null,
                  )
                }
                className="text-sm text-blue-600 hover:underline"
              >
                ← Cambiar base
              </button>
            </div>

            <div className="flex-1 overflow-y-auto divide-y divide-gray-100">
              {modalItems.length === 0 ? (
                <p className="text-center text-sm text-gray-400 py-10">
                  No hay productos importados en este pedido.
                </p>
              ) : (
                modalItems.map((item) => {
                  const cur = organizeModal.quantities[item.id] ?? 0;
                  const max = modalMaxQty(item);
                  const active = cur > 0;
                  return (
                    <div
                      key={item.id}
                      className={[
                        "flex items-center gap-3 px-4 py-3",
                        active ? "border-l-4 border-l-gray-800" : "opacity-70",
                      ].join(" ")}
                    >
                      <ModalProductImage
                        src={item.image_url}
                        alt={item.description}
                      />

                      <div className="flex-1 min-w-0">
                        <p className="text-sm font-medium leading-snug">
                          {item.description}
                        </p>
                        <p className="text-xs font-mono text-gray-400 mt-0.5">
                          {item.ean}
                        </p>
                        <p className="text-xs text-gray-500 mt-0.5">
                          Disp:{" "}
                          <span
                            className={
                              max === 0 && !active
                                ? "text-red-500 font-medium"
                                : ""
                            }
                          >
                            {max}
                          </span>
                          {" / "}
                          {item.qty} unid.
                        </p>
                      </div>

                      {/* Stepper */}
                      <div className="flex items-center gap-1.5 shrink-0">
                        <button
                          onClick={() => decModalQty(item.id)}
                          disabled={cur === 0}
                          className="w-8 h-8 rounded-full border border-gray-300 flex items-center justify-center text-gray-600 disabled:opacity-25 hover:bg-gray-50 text-lg leading-none select-none"
                        >
                          −
                        </button>
                        <input
                          type="text"
                          inputMode="numeric"
                          value={cur === 0 ? "" : cur}
                          placeholder="0"
                          onChange={(e) => setModalQty(item, e.target.value)}
                          onFocus={(e) => e.target.select()}
                          className="w-12 text-center text-sm font-bold border rounded-lg py-1 focus:outline-none focus:ring-2 focus:ring-gray-300"
                        />
                        <button
                          onClick={() => incModalQty(item)}
                          disabled={cur >= max || max === 0}
                          className="w-8 h-8 rounded-full bg-gray-900 text-white flex items-center justify-center disabled:opacity-25 text-lg leading-none select-none"
                        >
                          +
                        </button>
                      </div>
                    </div>
                  );
                })
              )}
            </div>

            {/* Botón guardar */}
            <div className="p-4 border-t shrink-0">
              {(() => {
                const count = Object.values(organizeModal.quantities).filter(
                  (q) => q > 0,
                ).length;
                return (
                  <button
                    onClick={saveOrganize}
                    disabled={organizeModal.saving}
                    className="w-full py-3.5 rounded-2xl bg-gray-900 text-white font-bold text-sm disabled:opacity-60"
                  >
                    {organizeModal.saving
                      ? "Guardando…"
                      : `Guardar — ${count} producto${count !== 1 ? "s" : ""} en esta base`}
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
