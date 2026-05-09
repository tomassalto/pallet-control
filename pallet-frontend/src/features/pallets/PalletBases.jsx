import BaseCard from "../pallet-bases/BaseCard";

const SEC_LABEL = "text-xs font-semibold uppercase tracking-wider text-gray-400 dark:text-gray-500";

export function PalletBases({ bases, palletStatus, onEditBase, onMigrateBase, onDeleteBase, onAddBase }) {
  return (
    <section className="space-y-3">
      <div className="flex items-center justify-between">
        <p className={SEC_LABEL}>Bases ({bases.length})</p>
        {palletStatus !== "done" && (
          <button
            onClick={onAddBase}
            className="text-sm font-semibold text-blue-600 dark:text-blue-400 hover:text-blue-800 dark:hover:text-blue-300 transition-colors"
          >
            + Nueva base
          </button>
        )}
      </div>

      {bases.length === 0 ? (
        <div className="text-center py-8">
          <p className="text-gray-500 dark:text-gray-400 mb-4">Este pallet no tiene bases todavía.</p>
          {palletStatus !== "done" && (
            <button
              onClick={onAddBase}
              className="px-4 py-2 rounded-lg bg-gray-900 dark:bg-white text-white dark:text-gray-900 font-semibold text-sm hover:bg-gray-700 dark:hover:bg-gray-100 transition-colors"
            >
              Crear primera base
            </button>
          )}
        </div>
      ) : (
        <div className="grid gap-4">
          {bases.map((base) => (
            <BaseCard
              key={base.id}
              base={base}
              palletStatus={palletStatus}
              onEdit={() => onEditBase(base)}
              onMigrate={() => onMigrateBase(base)}
              onDelete={() => onDeleteBase(base)}
            />
          ))}
        </div>
      )}
    </section>
  );
}