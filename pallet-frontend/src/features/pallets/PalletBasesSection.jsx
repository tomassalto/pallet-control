import BaseCard from "../pallet-bases/BaseCard";
import { SEC_LABEL } from "./palletDetailUi";

export default function PalletBasesSection({
  bases,
  palletId,
  palletDone,
  baseMutations,
  onMigrate,
}) {
  function openNewBaseForm() {
    baseMutations.setShowNewBase(true);
    baseMutations.setBaseName("");
    baseMutations.setBaseNote("");
  }

  return (
    <section className="space-y-3">
      <div className="flex items-center justify-between">
        <p className={SEC_LABEL}>Bases ({bases.length})</p>
        {!palletDone && (
          <button
            onClick={openNewBaseForm}
            className="text-sm font-semibold text-blue-600 dark:text-blue-400 hover:text-blue-800 dark:hover:text-blue-300 transition-colors"
          >
            + Agregar
          </button>
        )}
      </div>

      {bases.length === 0 ? (
        <div className="text-center py-10 space-y-4">
          <p className="text-sm text-gray-500 dark:text-gray-400">
            No hay bases. Creá una para organizar los productos.
          </p>
          {!palletDone && (
            <button
              onClick={openNewBaseForm}
              className="px-5 py-2.5 rounded-xl bg-gray-900 dark:bg-white text-white dark:text-gray-900 text-sm font-semibold hover:bg-gray-700 dark:hover:bg-gray-100 transition-colors"
            >
              Agregar primera base
            </button>
          )}
        </div>
      ) : (
        <div className="space-y-3">
          {bases.map((base) => (
            <BaseCard
              key={base.id}
              base={base}
              palletId={palletId}
              palletDone={palletDone}
              onEdit={baseMutations.startEditBase}
              onDelete={baseMutations.onDeleteBase}
              onMigrate={onMigrate}
            />
          ))}
        </div>
      )}
    </section>
  );
}
