import { useState } from "react";
import { Link } from "react-router-dom";

function textClass(status) {
  if (status === "removed") return "line-through text-red-900";
  return "";
}

export default function ItemCard({
  item: it,
  onSelect,
  borderColor,
  bgColor,
  showDoneQty = false,
}) {
  const [imgErr, setImgErr] = useState(false);
  const shortEan = (it.ean && String(it.ean).slice(-4).padStart(4, "0")) || "—";

  return (
    <button
      onClick={onSelect}
      className={`w-full text-left rounded-xl border ${borderColor} ${bgColor} dark:bg-gray-800/70 dark:border-gray-700 text-gray-900 dark:text-gray-100 px-3 py-3 text-sm active:scale-[0.99]`}
    >
      <div className="flex items-center justify-between gap-3">
        {/* Imagen del producto */}
        <div className="w-14 h-14 shrink-0 rounded-xl overflow-hidden bg-gray-100 dark:bg-gray-700 flex items-center justify-center border border-gray-200 dark:border-gray-600">
          {it.image_url && !imgErr ? (
            <img
              src={it.image_url}
              alt={it.description}
              className="w-full h-full object-contain"
              onError={() => setImgErr(true)}
            />
          ) : (
            <span className="text-2xl select-none">📦</span>
          )}
        </div>

        <div className="flex flex-col gap-1 flex-1 min-w-0">
          <div className="text-[10px] text-gray-500 dark:text-gray-400">EAN</div>
          <div className="font-mono font-semibold text-lg">{shortEan}</div>

          <div className={`text-sm ${textClass(it.status)} wrap-break-word`}>
            {it.description}
          </div>

          {/* Badges: precio, descuento MP, controlado */}
          <div className="flex flex-wrap gap-1 mt-0.5">
            {it.price != null && (
              <span className="text-[10px] text-gray-500 dark:text-gray-400 font-mono">
                ${Number(it.price).toLocaleString("es-AR", { minimumFractionDigits: 2 })}
              </span>
            )}
            {it.desc_medio_pago != null && (
              <span className="text-[10px] bg-blue-100 dark:bg-blue-900/30 text-blue-700 dark:text-blue-300 px-1.5 py-0.5 rounded font-medium">
                💳 -{Number(it.desc_medio_pago).toLocaleString("es-AR", { minimumFractionDigits: 2 })}
              </span>
            )}
            {it.is_controlled && (
              <span className="text-[10px] bg-amber-100 dark:bg-amber-900/30 text-amber-700 dark:text-amber-300 px-1.5 py-0.5 rounded font-medium">
                Controlado
              </span>
            )}
          </div>

          {/* Ubicaciones — solo si el ítem está marcado como listo */}
          {it.status === "done" && it.locations && it.locations.length > 0 && (
            <div className="mt-2 space-y-1">
              <div className="text-[10px] text-gray-500 dark:text-gray-400">Ubicación:</div>
              {it.locations.map((loc, idx) => (
                <div
                  key={idx}
                  className="text-xs text-gray-600 dark:text-gray-300 bg-gray-100 dark:bg-gray-700/60 rounded px-2 py-1"
                >
                  <Link
                    to={`/pallet/${loc.pallet_id}`}
                    onClick={(e) => e.stopPropagation()}
                    className="font-semibold underline"
                  >
                    {loc.pallet_code}
                  </Link>
                  {loc.base_name && (
                    <>{" / "}<span className="font-medium">{loc.base_name}</span></>
                  )}
                  {" - "}
                  <span className="font-mono">{loc.qty} u.</span>
                </div>
              ))}
            </div>
          )}
        </div>

        <div className="flex flex-col items-center shrink-0 gap-2">
          {showDoneQty ? (
            <>
              <div className="text-xs text-gray-500">Esperado</div>
              <div className="text-base font-semibold">{it.qty || 0}</div>
              {it.done_qty !== undefined && (
                <>
                  <div className="text-xs text-gray-500">Encontrado</div>
                  <div className="text-base font-semibold">{it.done_qty || 0}</div>
                </>
              )}
            </>
          ) : (
            <>
              <div className="text-xs text-gray-500">Cant.</div>
              <div className="text-base font-semibold">{it.qty || 0}</div>
            </>
          )}
        </div>
      </div>
    </button>
  );
}
