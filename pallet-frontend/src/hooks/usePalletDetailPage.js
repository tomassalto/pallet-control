import { useMemo, useState } from "react";
import { useParams } from "react-router-dom";
import { apiDelete, apiPost } from "../api/client";
import { toastError, toastSuccess } from "../ui/toast";
import { useBaseMutations } from "./useBaseMutations";
import { useMigrate } from "./useMigrate";
import { usePalletDetail } from "./usePalletDetail";
import { onlyDigits } from "../features/pallets/palletDetailUi";

export function usePalletDetailPage() {
  const { palletId } = useParams();
  const detail = usePalletDetail(palletId);
  const { pallet, orders, refetch } = detail;

  const load = () => refetch();
  const [confirmModal, setConfirmModal] = useState(null);
  const [selectedOrderId, setSelectedOrderId] = useState(null);
  const [openAssign, setOpenAssign] = useState(false);
  const [openImport, setOpenImport] = useState(false);
  const [orderCode, setOrderCode] = useState("");
  const [raw, setRaw] = useState("");

  const activeOrderId = selectedOrderId ?? orders.at(-1)?.id ?? null;
  const activeOrder = useMemo(
    () => orders.find((order) => order.id === activeOrderId) || null,
    [orders, activeOrderId],
  );
  const migrate = useMigrate({ palletId, load });
  const baseMutations = useBaseMutations({
    palletId,
    load,
    setConfirmModal,
  });

  async function onAssignSubmit(event) {
    event.preventDefault();
    const clean = onlyDigits(orderCode);
    if (!clean) {
      toastError("El número de pedido debe ser numérico.");
      return;
    }

    try {
      const res = await apiPost(`/pallets/${palletId}/attach-order`, {
        order_code: clean,
      });
      toastSuccess("Pedido asignado");
      if (res.order?.id) setSelectedOrderId(res.order.id);
      setOrderCode("");
      setOpenAssign(false);
      refetch();
    } catch (e) {
      toastError(e.response?.data?.message || e.message || "No se pudo asignar");
    }
  }

  async function onImportSubmit(event) {
    event.preventDefault();
    if (!activeOrder) {
      toastError("Seleccioná un pedido para importar.");
      return;
    }
    if (!raw.trim()) {
      toastError("Pegá el texto del pedido.");
      return;
    }

    try {
      await apiPost(`/orders/${activeOrder.id}/import`, { raw });
      toastSuccess("Pedido importado");
      setRaw("");
      setOpenImport(false);
    } catch (e) {
      toastError(e.response?.data?.message || e.message || "No se pudo importar");
    }
  }

  function handleFinalize() {
    setConfirmModal({
      title: "Finalizar pallet",
      message:
        "¿Estás seguro de finalizar este pallet? Una vez finalizado, no podrás agregar más contenido hasta reabrirlo.",
      confirmText: "Finalizar",
      cancelText: "Cancelar",
      confirmColor: "blue",
      onConfirm: async () => {
        try {
          await apiPost(`/pallets/${palletId}/finalize`);
          toastSuccess("Pallet finalizado correctamente");
          await load();
        } catch (e) {
          toastError(
            e.response?.data?.message || e.message || "Error finalizando pallet",
          );
        }
      },
    });
  }

  function handleReopen() {
    setConfirmModal({
      title: "Reabrir pallet",
      message: "¿Estás seguro de que querés reabrir este pallet?",
      confirmText: "Reabrir",
      cancelText: "Cancelar",
      confirmColor: "green",
      onConfirm: async () => {
        try {
          await apiPost(`/pallets/${palletId}/reopen`);
          toastSuccess("Pallet reabierto correctamente");
          await load();
        } catch (e) {
          toastError(
            e.response?.data?.message || e.message || "Error reabriendo pallet",
          );
        }
      },
    });
  }

  function confirmReopenThen(onConfirmed) {
    setConfirmModal({
      title: "Pallet finalizado",
      message:
        "Este pallet está cerrado. ¿Querés reabrirlo para poder hacer cambios?",
      confirmText: "Reabrir y continuar",
      cancelText: "Cancelar",
      confirmColor: "green",
      onConfirm: async () => {
        try {
          await apiPost(`/pallets/${palletId}/reopen`);
          toastSuccess("Pallet reabierto");
          await load();
          onConfirmed();
        } catch (e) {
          toastError(
            e.response?.data?.message || e.message || "Error reabriendo pallet",
          );
        }
      },
    });
  }

  function handleDeletePallet() {
    setConfirmModal({
      title: "Eliminar pallet",
      message:
        "¿Estás seguro de que querés eliminar este pallet? Esta acción no se puede deshacer.",
      confirmText: "Eliminar",
      cancelText: "Cancelar",
      confirmColor: "red",
      onConfirm: async () => {
        try {
          await apiDelete(`/pallets/${palletId}`);
          toastSuccess("Pallet eliminado correctamente");
          window.location.href = "/";
        } catch (e) {
          toastError(
            e.response?.data?.message || e.message || "Error eliminando pallet",
          );
        }
      },
    });
  }

  function handleMigrate(base) {
    if (pallet?.status === "done") {
      confirmReopenThen(() => migrate.openMigrateModal(base));
    } else {
      migrate.openMigrateModal(base);
    }
  }

  return {
    ...detail,
    palletId,
    activeOrder,
    activeOrderId,
    setActiveOrderId: setSelectedOrderId,
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
  };
}
