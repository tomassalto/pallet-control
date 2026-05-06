const INPUT_CLS =
  "w-full border border-gray-300 dark:border-gray-600 rounded-xl px-3 py-2.5 text-sm bg-gray-50 dark:bg-gray-700 dark:text-white focus:outline-none focus:ring-2 focus:ring-gray-900 dark:focus:ring-white transition-shadow";
const BTN_PRI =
  "w-full py-3 px-4 rounded-xl bg-gray-900 dark:bg-white text-white dark:text-gray-900 text-sm font-semibold hover:bg-gray-700 dark:hover:bg-gray-100 disabled:opacity-40 transition-colors";

/**
 * Modal para crear o editar una base de pallet.
 *
 * Props:
 *   showNewBase  — true cuando se está creando
 *   editingBase  — objeto base cuando se está editando, null si es creación
 *   baseName     — valor del input nombre
 *   setBaseName
 *   baseNote     — valor del textarea nota
 *   setBaseNote
 *   onClose      — cierra el modal (limpia estado)
 *   onSave       — llama a onCreateBase() o onUpdateBase(editingBase) según corresponda
 */
export default function BaseFormModal({
  showNewBase,
  editingBase,
  baseName,
  setBaseName,
  baseNote,
  setBaseNote,
  onClose,
  onSave,
}) {
  if (!showNewBase && !editingBase) return null;

  return (
    <div className="fixed inset-0 z-50 bg-black/60 p-4 flex items-center justify-center">
      <div className="w-full max-w-md bg-white dark:bg-gray-800 rounded-2xl p-5 shadow-2xl space-y-4">
        <div className="flex items-center justify-between">
          <p className="font-semibold text-gray-900 dark:text-white">
            {editingBase ? "Editar base" : "Nueva base"}
          </p>
          <button
            onClick={onClose}
            className="p-1.5 rounded-lg text-gray-400 hover:text-gray-700 hover:bg-gray-100 dark:hover:bg-gray-700 transition-colors"
          >
            ✕
          </button>
        </div>

        <div>
          <label className="text-sm font-medium text-gray-700 dark:text-gray-300 block mb-1">
            Nombre (opcional)
          </label>
          <input
            value={baseName}
            onChange={(e) => setBaseName(e.target.value)}
            placeholder="Ej: Base 1, Base A"
            className={INPUT_CLS}
          />
        </div>

        <div>
          <label className="text-sm font-medium text-gray-700 dark:text-gray-300 block mb-1">
            Nota (opcional)
          </label>
          <textarea
            value={baseNote}
            onChange={(e) => setBaseNote(e.target.value)}
            placeholder="Notas adicionales"
            rows={2}
            className={INPUT_CLS}
          />
        </div>

        <button onClick={onSave} className={BTN_PRI}>
          {editingBase ? "Actualizar" : "Crear base"}
        </button>
      </div>
    </div>
  );
}
