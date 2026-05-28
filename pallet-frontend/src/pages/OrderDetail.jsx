import { Link, useParams } from "react-router-dom";
import { apiDelete } from "../api/client";
import { toastSuccess, toastError } from "../ui/toast";
import BackButton from "../ui/BackButton";
import OrganizeModal from "../Components/OrganizeModal";
import QtyConflictModal from "../Components/QtyConflictModal";
import { PageSpinner } from "../ui/Spinner";
import QRModal from "../ui/QRModal";
import { TicketCard, AddTicketModal } from "../features/tickets/TicketSection";
import ItemActionModal from "../features/order-items/ItemActionModal";
import { useOrderDetailPage } from "../hooks/useOrderDetailPage";
import { OrderHeader } from "../features/orders/OrderHeader";
import OrderDetailActions from "../features/orders/OrderDetailActions";
import OrderItemsSection from "../features/orders/OrderItemsSection";
import AttachPalletModal from "../features/orders/AttachPalletModal";
import AddProductModal from "../features/orders/AddProductModal";
import DoneOrderProducts from "../features/orders/DoneOrderProducts";

const SEC_LABEL =
  "text-xs font-semibold uppercase tracking-wider text-gray-400 dark:text-gray-500";

export default function OrderDetail() {
  const { orderId } = useParams();
  const {
    order,
    pallets,
    items,
    tickets,
    highlightsReady,
    pendingItemsCount,
    pendingItemIdsSet,
    itemStatusSummary,
    isLoading,
    queryError,
    canFinalize,
    finalizing,
    load,
    handleFinalize,
    openAttachPallet,
    setOpenAttachPallet,
    availablePallets,
    loadingPallets,
    onAttachPallet,
    createAndAttachPallet,
    openAddProduct,
    setOpenAddProduct,
    eanOrLast4,
    setEanOrLast4,
    qty,
    setQty,
    saving,
    onAddManual,
    detachingPallet,
    setDetachingPallet,
    confirmDetachPallet,
    setConfirmDetachPallet,
    openAddTicket,
    setOpenAddTicket,
    showQR,
    setShowQR,
    organizeModal,
    setOrganizeModal,
    reopenModal,
    setReopenModal,
    reopenAndOrganize,
    openOrganizeModal,
    selectBaseForOrganize,
    createBaseAndOrganize,
    countFromThisOrderInBase,
    modalItems,
    modalMaxQty,
    incModalQty,
    decModalQty,
    setModalQty,
    saveOrganize,
    actionItem,
    setActionItem,
    actionQty,
    setActionQty,
    qtyConflict,
    setQtyConflict,
    tryApplyAction,
    setConflictKeep,
    resolveConflictAndSave,
    rowClass,
    refreshOrder,
  } = useOrderDetailPage(orderId);

  if (isLoading) return <PageSpinner />;

  if (queryError) {
    return (
      <div className="space-y-3">
        <div className="bg-red-50 border border-red-200 text-red-800 rounded-xl p-3">
          {queryError.message || "Error cargando pedido"}
        </div>
        <div className="flex justify-start">
          <BackButton to="/" />
        </div>
      </div>
    );
  }

  return (
    <div className="space-y-4">
      <AttachPalletModal
        open={openAttachPallet}
        onClose={() => setOpenAttachPallet(false)}
        onCreatePallet={createAndAttachPallet}
        onAttachPallet={onAttachPallet}
        availablePallets={availablePallets}
        loadingPallets={loadingPallets}
      />

      <OrderHeader
        order={order}
        pallets={pallets}
        modalItems={modalItems}
        onOrganize={openOrganizeModal}
        onDetach={setConfirmDetachPallet}
        onReopen={setReopenModal}
        detachingPallet={detachingPallet}
        canFinalize={canFinalize}
        onShowQR={() => setShowQR(true)}
      />

      <section className="space-y-3">
        <div className="flex items-center justify-between">
          <p className={SEC_LABEL}>Ticket del pedido</p>
          {order?.status !== "done" && (
            <button
              onClick={() => setOpenAddTicket(true)}
              className="text-sm font-semibold text-blue-600 dark:text-blue-400 hover:text-blue-800 dark:hover:text-blue-300 transition-colors"
            >
              + Agregar
            </button>
          )}
        </div>
        {tickets.length === 0 ? (
          <p className="text-sm text-gray-500 dark:text-gray-400 py-1">
            No hay tickets agregados.
          </p>
        ) : (
          <div className="space-y-2">
            {tickets.map((ticket) => (
              <TicketCard
                key={ticket.id}
                ticket={ticket}
                orderId={orderId}
                highlightsReady={highlightsReady}
                onUpdate={load}
              />
            ))}
          </div>
        )}
      </section>

      <OrderDetailActions
        order={order}
        orderId={orderId}
        canFinalize={canFinalize}
        finalizing={finalizing}
        onOpenAttachPallet={() => setOpenAttachPallet(true)}
        onOpenAddProduct={() => setOpenAddProduct(true)}
        onFinalize={handleFinalize}
      />

      <AddProductModal
        open={openAddProduct}
        onClose={() => setOpenAddProduct(false)}
        eanOrLast4={eanOrLast4}
        setEanOrLast4={setEanOrLast4}
        qty={qty}
        setQty={setQty}
        saving={saving}
        onSubmit={onAddManual}
      />

      {pendingItemsCount > 0 && (
        <Link
          to="/pending-items"
          className="flex items-center gap-3 bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800/50 rounded-2xl px-4 py-3 hover:bg-red-100 dark:hover:bg-red-900/30 transition-colors"
        >
          <span className="text-2xl shrink-0">🚨</span>
          <div className="flex-1 min-w-0">
            <p className="text-sm font-bold text-red-700 dark:text-red-400">
              {pendingItemsCount === 1
                ? "1 pendiente sin resolver"
                : `${pendingItemsCount} pendientes sin resolver`}
            </p>
            <p className="text-xs text-red-500 dark:text-red-500 mt-0.5 truncate">
              Hay productos faltantes que todavía no fueron entregados
            </p>
          </div>
          <svg
            className="w-4 h-4 text-red-400 shrink-0"
            fill="none"
            viewBox="0 0 24 24"
            strokeWidth={2.5}
            stroke="currentColor"
          >
            <path strokeLinecap="round" strokeLinejoin="round" d="M9 5l7 7-7 7" />
          </svg>
        </Link>
      )}

      {order?.status === "done" ? (
        <DoneOrderProducts pallets={pallets} items={items} />
      ) : items.length === 0 ? (
        <div className="text-center py-10 space-y-1">
          <p className="font-semibold text-gray-700 dark:text-gray-300">
            No hay productos en este pedido
          </p>
          <p className="text-sm text-gray-500 dark:text-gray-400">
            Importá un pedido para comenzar.
          </p>
        </div>
      ) : (
        <OrderItemsSection
          order={order}
          items={items}
          pendingItemIdsSet={pendingItemIdsSet}
          itemStatusSummary={itemStatusSummary}
          rowClass={rowClass}
          onSelectItem={(item) => {
            setActionItem(item);
            setActionQty(
              item.status === "removed" ? "0" : String(item.qty ?? ""),
            );
          }}
          onRefresh={load}
        />
      )}

      <ItemActionModal
        actionItem={actionItem}
        actionQty={actionQty}
        setActionQty={setActionQty}
        onClose={() => setActionItem(null)}
        onSave={tryApplyAction}
        orderStatus={order?.status}
      />

      {reopenModal && (
        <div className="fixed inset-0 z-50 bg-black/60 flex items-center justify-center p-4">
          <div className="w-full max-w-sm bg-white dark:bg-gray-800 rounded-2xl p-6 space-y-4 shadow-2xl">
            <div className="text-center space-y-2">
              <div className="text-4xl">🔒</div>
              <p className="font-bold text-lg text-gray-900 dark:text-white">Pallet finalizado</p>
              <p className="text-sm text-gray-600 dark:text-gray-300 leading-snug">
                <span className="font-semibold font-mono">
                  {reopenModal.pallet.code}
                </span>{" "}
                está cerrado. Para organizar productos necesitás reabrirlo.
              </p>
              <p className="text-xs text-gray-400 dark:text-gray-500">
                Podés volver a finalizarlo después de hacer los cambios.
              </p>
            </div>
            <div className="flex gap-2">
              <button
                onClick={() => setReopenModal(null)}
                disabled={reopenModal.reopening}
                className="flex-1 rounded-xl py-3 border border-gray-200 dark:border-gray-600 text-sm text-gray-600 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-700 disabled:opacity-50 transition-colors"
              >
                Cancelar
              </button>
              <button
                onClick={reopenAndOrganize}
                disabled={reopenModal.reopening}
                className="flex-1 rounded-xl py-3 bg-amber-500 hover:bg-amber-600 text-white text-sm font-semibold disabled:opacity-60 transition-colors"
              >
                {reopenModal.reopening ? "Reabriendo…" : "Reabrir y organizar"}
              </button>
            </div>
          </div>
        </div>
      )}

      <QtyConflictModal
        qtyConflict={qtyConflict}
        setQtyConflict={setQtyConflict}
        setConflictKeep={setConflictKeep}
        resolveConflictAndSave={resolveConflictAndSave}
      />

      {confirmDetachPallet && (
        <>
          <div
            className="fixed inset-0 bg-black/50 z-40"
            onClick={() => setConfirmDetachPallet(null)}
          />
          <div className="fixed inset-0 z-50 flex items-center justify-center p-4">
            <div
              className="bg-white rounded-2xl p-6 w-full max-w-md space-y-4"
              onClick={(e) => e.stopPropagation()}
            >
              <div className="space-y-2">
                <div className="font-semibold text-lg">
                  ¿Desvincular pallet?
                </div>
                <div className="text-sm text-gray-700">
                  Estás por desvincular el pallet{" "}
                  <span className="font-semibold">
                    {confirmDetachPallet.code}
                  </span>{" "}
                  de este pedido.
                </div>
              </div>

              <div className="bg-red-50 border border-red-200 rounded-lg p-4">
                <div className="flex items-start gap-2">
                  <div className="text-sm text-red-800">
                    <div className="font-semibold mb-1">ADVERTENCIA:</div>
                    <div>
                      Se perderán todos los productos de este pedido que fueron
                      asignados a este pallet. Tendrás que volver a asignarlos
                      manualmente.
                    </div>
                  </div>
                </div>
              </div>

              <div className="flex gap-2">
                <button
                  onClick={async () => {
                    const palletToDetach = confirmDetachPallet;
                    setConfirmDetachPallet(null);
                    setDetachingPallet(palletToDetach.id);
                    try {
                      await apiDelete(
                        `/orders/${orderId}/detach-pallet/${palletToDetach.id}`,
                      );
                      toastSuccess("Pallet desvinculado correctamente");
                      load();
                    } catch (e) {
                      toastError(
                        e?.response?.data?.message ||
                          "Error al desvincular pallet",
                      );
                    } finally {
                      setDetachingPallet(null);
                    }
                  }}
                  disabled={detachingPallet === confirmDetachPallet.id}
                  className="flex-1 rounded-lg py-3 bg-red-600 text-white disabled:opacity-60"
                >
                  {detachingPallet === confirmDetachPallet.id
                    ? "Desvinculando..."
                    : "Desvincular"}
                </button>
                <button
                  onClick={() => setConfirmDetachPallet(null)}
                  className="flex-1 rounded-lg py-3 border bg-white text-gray-700 hover:bg-gray-50"
                >
                  Cancelar
                </button>
              </div>
            </div>
          </div>
        </>
      )}

      {openAddTicket && (
        <AddTicketModal
          orderId={orderId}
          onClose={() => setOpenAddTicket(false)}
          onSuccess={() => {
            setOpenAddTicket(false);
            load();
            refreshOrder(orderId);
          }}
        />
      )}

      <OrganizeModal
        organizeModal={organizeModal}
        setOrganizeModal={setOrganizeModal}
        modalItems={modalItems}
        modalMaxQty={modalMaxQty}
        decModalQty={decModalQty}
        incModalQty={incModalQty}
        setModalQty={setModalQty}
        selectBaseForOrganize={selectBaseForOrganize}
        createBaseAndOrganize={createBaseAndOrganize}
        saveOrganize={saveOrganize}
        countFromThisOrderInBase={countFromThisOrderInBase}
      />

      {showQR && (
        <QRModal
          order={order}
          onClose={() => setShowQR(false)}
        />
      )}
    </div>
  );
}
