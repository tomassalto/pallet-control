import { useMemo, useState } from "react";
import { apiPost } from "../api/client";
import { toastError, toastSuccess } from "../ui/toast";
import { useOrderDetail, useAvailablePallets } from "./useOrderDetail";
import { useOrganize } from "./useOrganize";
import { useItemAction } from "./useItemAction";
import { useRefreshData } from "./useRefreshData";

function onlyDigits(v) {
  return (v || "").replace(/\D/g, "");
}

export function useOrderDetailPage(orderId) {
  const {
    order,
    pallets,
    items,
    tickets,
    highlightsReady,
    pendingItemsCount,
    pendingItemIds,
    isLoading,
    error,
    canFinalize,
    finalize,
    finalizing,
    refetch,
  } = useOrderDetail(orderId);

  const load = () => refetch();
  const { refreshOrder } = useRefreshData();

  const [eanOrLast4, setEanOrLast4] = useState("");
  const [qty, setQty] = useState("1");
  const [saving, setSaving] = useState(false);
  const [openAttachPallet, setOpenAttachPallet] = useState(false);
  const [openAddProduct, setOpenAddProduct] = useState(false);
  const [detachingPallet, setDetachingPallet] = useState(null);
  const [confirmDetachPallet, setConfirmDetachPallet] = useState(null);
  const [openAddTicket, setOpenAddTicket] = useState(false);
  const [showQR, setShowQR] = useState(false);

  const {
    organizeModal,
    setOrganizeModal,
    reopenModal,
    setReopenModal,
    reopenAndOrganize,
    openOrganizeModal,
    selectBaseForOrganize,
    createBaseAndOrganize,
    countFromThisOrderInBase,
    modalMaxQty,
    incModalQty,
    decModalQty,
    setModalQty,
    saveOrganize,
  } = useOrganize({ items, setPallets: () => refetch(), load, refreshOrder });

  const {
    actionItem,
    setActionItem,
    actionQty,
    setActionQty,
    qtyConflict,
    setQtyConflict,
    tryApplyAction,
    setConflictKeep,
    resolveConflictAndSave,
  } = useItemAction({ items, load, refreshOrder });

  const availablePalletsQuery = useAvailablePallets({
    enabled: openAttachPallet,
    excludedIds: pallets.map((pallet) => pallet.id),
  });

  const modalItems = useMemo(
    () =>
      items
        .filter((it) => (it.qty ?? 0) > 0)
        .sort((a, b) => a.description.localeCompare(b.description)),
    [items],
  );

  const pendingItemIdsSet = useMemo(
    () => new Set(pendingItemIds),
    [pendingItemIds],
  );

  const itemStatusSummary = useMemo(
    () => ({
      pending: items.filter((item) => item.status === "pending").length,
      done: items.filter((item) => item.status === "done").length,
      removed: items.filter((item) => item.status === "removed").length,
    }),
    [items],
  );

  async function onAttachPallet(palletId) {
    try {
      await apiPost(`/orders/${orderId}/attach-pallet`, {
        pallet_id: palletId,
      });
      toastSuccess("Pedido asociado al pallet");
      setOpenAttachPallet(false);
      load();
    } catch (e) {
      toastError(
        e?.response?.data?.message || e?.message || "Error asociando pallet",
      );
    }
  }

  async function createAndAttachPallet() {
    try {
      const pallet = await apiPost(`/pallets`, { note: null });
      toastSuccess(`Pallet creado: ${pallet.code}`);
      await onAttachPallet(pallet.id);
    } catch (e) {
      toastError(
        e?.data?.message || e?.message || "Error creando pallet",
      );
    }
  }

  async function handleFinalize() {
    if (!canFinalize) {
      toastError(
        "No se puede finalizar. Todos los productos deben tener sus unidades distribuidas en bases de pallets.",
      );
      return;
    }

    if (!window.confirm("¿Estás seguro de finalizar este pedido?")) {
      return;
    }

    try {
      await finalize();
      toastSuccess("Pedido finalizado correctamente");
      refetch();
    } catch (e) {
      toastError(
        e?.response?.data?.message || e?.message || "Error al finalizar pedido",
      );
    }
  }

  async function onAddManual(e) {
    e.preventDefault();
    setSaving(true);

    const raw = onlyDigits(eanOrLast4);
    const parsedQty = parseInt(onlyDigits(qty), 10) || 1;

    try {
      if (!raw) {
        toastError("Ingresá EAN completo o últimos 4.");
        return;
      }

      const body =
        raw.length <= 4
          ? { last4: raw.padStart(4, "0"), qty: parsedQty }
          : { ean: raw, qty: parsedQty };

      await apiPost(`/orders/${orderId}/items`, body);
      refetch();
      setEanOrLast4("");
      setQty("1");
      setOpenAddProduct(false);
      toastSuccess("Producto agregado al pedido");
    } catch (e) {
      const status = e?.response?.status;
      const data = e?.response?.data;

      if (status === 409 && data?.candidates?.length) {
        toastError("Últimos 4 ambiguos. Usá EAN completo.");
      } else {
        toastError(data?.message || e.message || "No se pudo agregar");
      }
    } finally {
      setSaving(false);
    }
  }

  function rowClass(status, hasPending) {
    if (hasPending) return "border-red-500";
    if (status === "done") return "border-green-500";
    if (status === "removed") return "border-red-500 opacity-80";
    return "border-gray-200";
  }

  return {
    order,
    pallets,
    items,
    tickets,
    highlightsReady,
    pendingItemsCount,
    pendingItemIdsSet,
    itemStatusSummary,
    isLoading,
    queryError: error,
    canFinalize,
    finalizing,
    load,
    handleFinalize,
    openAttachPallet,
    setOpenAttachPallet,
    availablePallets: availablePalletsQuery.data || [],
    loadingPallets: availablePalletsQuery.isLoading,
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
  };
}
