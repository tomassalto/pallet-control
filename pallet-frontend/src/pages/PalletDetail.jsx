import BackButton from "../ui/BackButton";
import ConfirmModal from "../ui/ConfirmModal";
import { PageSpinner } from "../ui/Spinner";
import { usePalletDetailPage } from "../hooks/usePalletDetailPage";
import MigrateModal from "../features/pallet-bases/MigrateModal";
import BaseFormModal from "../features/pallet-bases/BaseFormModal";
import AssignOrderModal from "../features/pallets/AssignOrderModal";
import ImportOrderModal from "../features/pallets/ImportOrderModal";
import PalletActionsSection from "../features/pallets/PalletActionsSection";
import PalletBasesSection from "../features/pallets/PalletBasesSection";
import PalletDetailHeader from "../features/pallets/PalletDetailHeader";
import PalletOrdersSection from "../features/pallets/PalletOrdersSection";

export default function PalletDetail() {
  const page = usePalletDetailPage();
  const {
    palletId,
    pallet,
    orders,
    bases,
    isLoading,
    error: queryError,
    canFinalize,
    activeOrder,
    activeOrderId,
    setActiveOrderId,
    confirmModal,
    setConfirmModal,
    openAssign,
    setOpenAssign,
    openImport,
    setOpenImport,
    orderCode,
    setOrderCode,
    raw,
    setRaw,
    migrate,
    baseMutations,
    onAssignSubmit,
    onImportSubmit,
    handleFinalize,
    handleReopen,
    handleDeletePallet,
    handleMigrate,
  } = page;

  if (isLoading) return <PageSpinner />;
  if (queryError || !pallet) {
    return (
      <div className="space-y-3">
        <BackButton to="/" />
        <div className="bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 text-red-800 dark:text-red-300 rounded-xl p-3 text-sm">
          {queryError?.message || "No se pudo cargar el pallet."}
        </div>
      </div>
    );
  }

  const palletDone = pallet.status === "done";

  return (
    <div className="space-y-7 pb-8">
      <BackButton to="/" />

      <PalletDetailHeader pallet={pallet} />

      <PalletActionsSection
        palletId={palletId}
        pallet={pallet}
        activeOrder={activeOrder}
        canFinalize={canFinalize}
        onAssign={() => setOpenAssign(true)}
        onImport={() => setOpenImport(true)}
        onFinalize={handleFinalize}
        onReopen={handleReopen}
        onDelete={handleDeletePallet}
      />

      <PalletOrdersSection
        orders={orders}
        activeOrderId={activeOrderId}
        onSelectOrder={setActiveOrderId}
      />

      <PalletBasesSection
        bases={bases}
        palletId={palletId}
        palletDone={palletDone}
        baseMutations={baseMutations}
        onMigrate={handleMigrate}
      />

      {openAssign && (
        <AssignOrderModal
          orderCode={orderCode}
          setOrderCode={setOrderCode}
          onClose={() => setOpenAssign(false)}
          onSubmit={onAssignSubmit}
        />
      )}

      {openImport && (
        <ImportOrderModal
          activeOrder={activeOrder}
          raw={raw}
          setRaw={setRaw}
          onClose={() => setOpenImport(false)}
          onSubmit={onImportSubmit}
        />
      )}

      <BaseFormModal
        showNewBase={baseMutations.showNewBase}
        editingBase={baseMutations.editingBase}
        baseName={baseMutations.baseName}
        setBaseName={baseMutations.setBaseName}
        baseNote={baseMutations.baseNote}
        setBaseNote={baseMutations.setBaseNote}
        onClose={() => {
          baseMutations.setShowNewBase(false);
          baseMutations.setEditingBase(null);
          baseMutations.setBaseName("");
          baseMutations.setBaseNote("");
        }}
        onSave={() =>
          baseMutations.editingBase
            ? baseMutations.onUpdateBase(baseMutations.editingBase)
            : baseMutations.onCreateBase()
        }
      />

      <MigrateModal
        migrateModal={migrate.migrateModal}
        setMigrateModal={migrate.setMigrateModal}
        setMigrateQty={migrate.setMigrateQty}
        selectAllMigrate={migrate.selectAllMigrate}
        goToMigrateDest={migrate.goToMigrateDest}
        selectMigratePallet={migrate.selectMigratePallet}
        saveMigrate={migrate.saveMigrate}
      />

      <ConfirmModal
        isOpen={confirmModal !== null}
        onClose={() => setConfirmModal(null)}
        onConfirm={confirmModal?.onConfirm || (() => {})}
        title={confirmModal?.title || ""}
        message={confirmModal?.message || ""}
        confirmText={confirmModal?.confirmText}
        cancelText={confirmModal?.cancelText}
        confirmColor={confirmModal?.confirmColor}
      />
    </div>
  );
}
