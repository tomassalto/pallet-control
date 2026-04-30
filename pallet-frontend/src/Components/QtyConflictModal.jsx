import { useState } from "react";

/**
 * Bottom-sheet modal shown when the user tries to reduce an order item's qty
 * below the total already organized across pallet bases.
 *
 * Props:
 *  - qtyConflict  : { item, newQty, totalOrganized, deficit, keepQtys, saving } | null
 *  - setQtyConflict   : state setter (pass null to close)
 *  - setConflictKeep  : (baseId, rawVal, maxQty) => void
 *  - resolveConflictAndSave : () => Promise<void>
 */
export default function QtyConflictModal({
  qtyConflict,
  setQtyConflict,
  setConflictKeep,
  resolveConflictAndSave,
}) {
  if (!qtyConflict) return null;

  const { item, newQty, totalOrganized, deficit, keepQtys, saving } =
    qtyConflict;

  const totalFreed = (item.locations ?? []).reduce(
    (s, l) => s + ((l.qty ?? 0) - (keepQtys[l.base_id] ?? l.qty)),
    0,
  );
  const canConfirm = totalFreed >= deficit && !saving;

  return (
    <div className="fixed inset-0 z-50 bg-black/60 flex items-end sm:items-center justify-center">
      <div className="w-full sm:max-w-lg bg-white rounded-t-3xl sm:rounded-2xl flex flex-col max-h-[90vh]">
        {/* Header */}
        <div className="px-4 pt-5 pb-3 border-b shrink-0">
          <div className="flex items-start justify-between gap-2">
            <div>
              <p className="font-bold text-base">⚠️ Hay unidades organizadas</p>
              <p className="text-sm text-gray-500 mt-0.5 leading-snug">
                Querés cambiar{" "}
                <span className="font-medium text-gray-800">
                  {item.description}
                </span>{" "}
                a <span className="font-semibold">{newQty} u.</span>, pero tenés{" "}
                <span className="font-semibold text-orange-600">
                  {totalOrganized} organizadas
                </span>
                .
              </p>
            </div>
            <button
              onClick={() => setQtyConflict(null)}
              className="text-gray-400 hover:text-gray-700 text-xl leading-none shrink-0 mt-0.5"
            >
              ✕
            </button>
          </div>

          {/* Barra de progreso */}
          <div className="mt-3">
            <div className="flex items-center justify-between text-xs mb-1">
              <span className="text-gray-500">
                Liberando{" "}
                <span
                  className={
                    totalFreed >= deficit
                      ? "text-green-600 font-semibold"
                      : "text-orange-600 font-semibold"
                  }
                >
                  {totalFreed}
                </span>{" "}
                de <span className="font-semibold">{deficit}</span> necesarias
              </span>
              {totalFreed >= deficit && (
                <span className="text-green-600 text-xs font-medium">
                  ✓ Listo
                </span>
              )}
            </div>
            <div className="h-2 rounded-full bg-gray-100 overflow-hidden">
              <div
                className={`h-full rounded-full transition-all ${totalFreed >= deficit ? "bg-green-500" : "bg-orange-400"}`}
                style={{
                  width: `${Math.min(100, (totalFreed / deficit) * 100)}%`,
                }}
              />
            </div>
          </div>
        </div>

        {/* Lista de ubicaciones */}
        <div className="flex-1 overflow-y-auto divide-y divide-gray-100">
          {(item.locations ?? []).map((loc) => {
            const keepQty = keepQtys[loc.base_id] ?? loc.qty;
            const freed = loc.qty - keepQty;
            return (
              <div
                key={loc.base_id}
                className="px-4 py-3 flex items-center gap-3"
              >
                {/* Info de ubicación */}
                <div className="flex-1 min-w-0">
                  <p className="text-sm font-semibold text-gray-800 truncate">
                    {loc.pallet_code}
                  </p>
                  <p className="text-xs text-gray-500">
                    {loc.base_name ?? `Base #${loc.base_id}`}
                  </p>
                  <p className="text-xs text-gray-400 mt-0.5">
                    {loc.qty} asignadas actualmente
                  </p>
                </div>

                {/* Badge "liberar" */}
                <div className="text-center w-14 shrink-0">
                  {freed > 0 ? (
                    <span className="inline-block bg-orange-100 text-orange-700 text-xs font-bold px-2 py-0.5 rounded-full">
                      −{freed}
                    </span>
                  ) : (
                    <span className="inline-block bg-gray-100 text-gray-400 text-xs px-2 py-0.5 rounded-full">
                      sin cambio
                    </span>
                  )}
                </div>

                {/* Stepper para "mantener" */}
                <div className="flex items-center gap-1 shrink-0">
                  <button
                    onClick={() =>
                      setConflictKeep(loc.base_id, keepQty - 1, loc.qty)
                    }
                    disabled={keepQty === 0}
                    className="w-8 h-8 rounded-full border border-gray-300 flex items-center justify-center text-gray-600 disabled:opacity-25 hover:bg-gray-50 text-lg leading-none select-none"
                  >
                    −
                  </button>
                  <input
                    type="text"
                    inputMode="numeric"
                    value={keepQty === 0 ? "" : keepQty}
                    placeholder="0"
                    onChange={(e) =>
                      setConflictKeep(loc.base_id, e.target.value, loc.qty)
                    }
                    onFocus={(e) => e.target.select()}
                    className="w-12 text-center text-sm font-bold border rounded-lg py-1 focus:outline-none focus:ring-2 focus:ring-gray-300"
                  />
                  <button
                    onClick={() =>
                      setConflictKeep(loc.base_id, keepQty + 1, loc.qty)
                    }
                    disabled={keepQty >= loc.qty}
                    className="w-8 h-8 rounded-full border border-gray-300 flex items-center justify-center text-gray-600 disabled:opacity-25 hover:bg-gray-50 text-lg leading-none select-none"
                  >
                    +
                  </button>
                </div>
              </div>
            );
          })}
        </div>

        {/* Footer */}
        <div className="px-4 py-4 border-t shrink-0 space-y-2">
          {!canConfirm && (
            <p className="text-xs text-center text-orange-600">
              Todavía necesitás liberar{" "}
              <span className="font-semibold">{deficit - totalFreed}</span>{" "}
              unidad{deficit - totalFreed !== 1 ? "es" : ""} más
            </p>
          )}
          <div className="flex gap-2">
            <button
              onClick={() => setQtyConflict(null)}
              className="flex-1 rounded-2xl py-3 border text-sm text-gray-600"
            >
              Cancelar
            </button>
            <button
              onClick={resolveConflictAndSave}
              disabled={!canConfirm}
              className="flex-1 rounded-2xl py-3 bg-gray-900 text-white text-sm font-bold disabled:opacity-40"
            >
              {saving ? "Guardando…" : "Confirmar y guardar"}
            </button>
          </div>
        </div>
      </div>
    </div>
  );
}
