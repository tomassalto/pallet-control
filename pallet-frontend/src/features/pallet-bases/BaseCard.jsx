import { Link } from "react-router-dom";

function PencilIcon() {
  return (
    <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" strokeWidth={2} stroke="currentColor">
      <path strokeLinecap="round" strokeLinejoin="round" d="M16.862 4.487l1.687-1.688a1.875 1.875 0 112.652 2.652L10.582 16.07a4.5 4.5 0 01-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 011.13-1.897l8.932-8.931z" />
    </svg>
  );
}
function TrashIcon() {
  return (
    <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" strokeWidth={2} stroke="currentColor">
      <path strokeLinecap="round" strokeLinejoin="round" d="M14.74 9l-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 01-2.244 2.077H8.084a2.25 2.25 0 01-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 00-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 013.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 00-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 00-7.5 0" />
    </svg>
  );
}
function ChevronRight() {
  return (
    <svg className="w-4 h-4 text-gray-300 dark:text-gray-600" fill="none" viewBox="0 0 24 24" strokeWidth={2.5} stroke="currentColor">
      <path strokeLinecap="round" strokeLinejoin="round" d="M9 5l7 7-7 7" />
    </svg>
  );
}

/**
 * Tarjeta de base dentro de PalletDetail.
 *
 * Props:
 *   base       — objeto base con order_items y photos
 *   palletId   — para construir los links internos
 *   palletDone — pallet.status === "done"
 *   onEdit     — startEditBase(base)
 *   onDelete   — onDeleteBase(base)
 *   onMigrate  — función ya envuelta con lógica de confirmReopenThen si palletDone
 */
export default function BaseCard({ base, palletId, palletDone, onEdit, onDelete, onMigrate }) {
  return (
    <div className="relative bg-white dark:bg-gray-800/60 border border-gray-200 dark:border-gray-700/50 rounded-2xl overflow-hidden shadow-sm hover:shadow-md transition-shadow">
      {/* Acento lateral */}
      <div className={`absolute left-0 top-0 bottom-0 w-1 ${palletDone ? "bg-green-500" : "bg-blue-500"}`} />

      <div className="pl-5 pr-4 py-4 space-y-3">
        {/* Header: nombre + badges + botones editar/eliminar */}
        <div className="flex items-start justify-between gap-2">
          <div className="flex-1 min-w-0">
            <p className="font-semibold text-gray-900 dark:text-white leading-tight">
              {base.name || `Base #${base.id}`}
            </p>
            {base.note && (
              <p className="text-xs text-gray-500 dark:text-gray-400 mt-0.5 text-left">
                {base.note}
              </p>
            )}
            <div className="flex gap-2 mt-2 flex-wrap">
              <span className="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-semibold bg-blue-50 text-blue-700 dark:bg-blue-900/20 dark:text-blue-300">
                <span className="w-1.5 h-1.5 rounded-full bg-blue-500" />
                {base.order_items?.length || 0} prod.
              </span>
              <span className="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-semibold bg-purple-50 text-purple-700 dark:bg-purple-900/20 dark:text-purple-300">
                <span className="w-1.5 h-1.5 rounded-full bg-purple-500" />
                {base.photos?.length || 0} fotos
              </span>
            </div>
          </div>

          {!palletDone && (
            <div className="flex gap-1 shrink-0">
              <button
                onClick={() => onEdit(base)}
                className="p-2 rounded-lg text-gray-400 hover:text-gray-700 hover:bg-gray-100 dark:hover:text-gray-200 dark:hover:bg-gray-700 transition-colors"
                title="Editar base"
              >
                <PencilIcon />
              </button>
              <button
                onClick={() => onDelete(base)}
                className="p-2 rounded-lg text-gray-400 hover:text-red-600 hover:bg-red-50 dark:hover:bg-red-900/20 transition-colors"
                title="Eliminar base"
              >
                <TrashIcon />
              </button>
            </div>
          )}
        </div>

        {/* Links de acción estilo iOS */}
        <div className="rounded-xl overflow-hidden border border-gray-100 dark:border-gray-700/40 divide-y divide-gray-100 dark:divide-gray-700/40">
          {/* Productos */}
          <Link
            to={`/pallet/${palletId}/base/${base.id}/products`}
            className="flex items-center gap-3 px-3 py-2.5 bg-white dark:bg-transparent hover:bg-gray-50 dark:hover:bg-gray-700/30 transition-colors"
          >
            <div className="w-8 h-8 rounded-lg bg-blue-500 flex items-center justify-center shrink-0 shadow-sm">
              <svg className="w-4 h-4 text-white" fill="none" viewBox="0 0 24 24" strokeWidth={2} stroke="currentColor">
                <path strokeLinecap="round" strokeLinejoin="round" d="M3.75 12h16.5m-16.5 3.75h16.5M3.75 19.5h16.5M5.625 4.5h12.75a1.875 1.875 0 0 1 0 3.75H5.625a1.875 1.875 0 0 1 0-3.75z" />
              </svg>
            </div>
            <span className="text-sm font-semibold text-gray-800 dark:text-gray-200 flex-1">Productos</span>
            <ChevronRight />
          </Link>

          {/* Galería */}
          <Link
            to={`/pallet/${palletId}/base/${base.id}/gallery`}
            className="flex items-center gap-3 px-3 py-2.5 bg-white dark:bg-transparent hover:bg-gray-50 dark:hover:bg-gray-700/30 transition-colors"
          >
            <div className="w-8 h-8 rounded-lg bg-purple-500 flex items-center justify-center shrink-0 shadow-sm">
              <svg className="w-4 h-4 text-white" fill="none" viewBox="0 0 24 24" strokeWidth={2} stroke="currentColor">
                <path strokeLinecap="round" strokeLinejoin="round" d="M2.25 15.75l5.159-5.159a2.25 2.25 0 0 1 3.182 0l5.159 5.159m-1.5-1.5 1.409-1.409a2.25 2.25 0 0 1 3.182 0l2.909 2.909m-18 3.75h16.5a1.5 1.5 0 0 0 1.5-1.5V6a1.5 1.5 0 0 0-1.5-1.5H3.75A1.5 1.5 0 0 0 2.25 6v12a1.5 1.5 0 0 0 1.5 1.5zm10.5-11.25h.008v.008h-.008V8.25zm.375 0a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0z" />
              </svg>
            </div>
            <span className="text-sm font-semibold text-gray-800 dark:text-gray-200 flex-1">Galería</span>
            <ChevronRight />
          </Link>

          {/* Migrar productos (solo si la base tiene ítems) */}
          {(base.order_items?.length || 0) > 0 && (
            <button
              onClick={() => onMigrate(base)}
              className="w-full flex items-center gap-3 px-3 py-2.5 bg-white dark:bg-transparent hover:bg-amber-50 dark:hover:bg-amber-900/20 transition-colors"
            >
              <div className={`w-8 h-8 rounded-lg flex items-center justify-center shrink-0 shadow-sm ${palletDone ? "bg-gray-400 dark:bg-gray-500" : "bg-amber-500"}`}>
                <svg className="w-4 h-4 text-white" fill="none" viewBox="0 0 24 24" strokeWidth={2} stroke="currentColor">
                  <path strokeLinecap="round" strokeLinejoin="round" d="M7.5 21 3 16.5m0 0L7.5 12M3 16.5h13.5m0-13.5L21 7.5m0 0L16.5 12M21 7.5H7.5" />
                </svg>
              </div>
              <span className={`text-sm font-semibold flex-1 text-left ${palletDone ? "text-gray-500 dark:text-gray-400" : "text-amber-800 dark:text-amber-300"}`}>
                {palletDone ? "🔒 Migrar productos" : "Migrar productos"}
              </span>
              <ChevronRight />
            </button>
          )}
        </div>
      </div>
    </div>
  );
}
