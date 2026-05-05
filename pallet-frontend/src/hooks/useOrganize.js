import { useState } from "react";
import { apiGet, apiPost, apiPatch } from "../api/client";
import { toastSuccess, toastError } from "../ui/toast";

/**
 * Encapsula todo el estado y la lógica del modal "Organizar en pallet".
 *
 * @param {object}   deps
 * @param {Array}    deps.items      — ítems del pedido (del estado del padre)
 * @param {Function} deps.setPallets — setter para actualización optimista al reabrir
 * @param {Function} deps.load       — recarga los datos del pedido
 */
export function useOrganize({ items, setPallets, load }) {
  // null | { palletId, palletCode, step:'base'|'products', bases:[], selectedBase:null, quantities:{}, loading, saving }
  const [organizeModal, setOrganizeModal] = useState(null);

  // null | { pallet, reopening }
  const [reopenModal, setReopenModal] = useState(null);

  /** Reabre el pallet y luego abre el modal de organización */
  async function reopenAndOrganize() {
    if (!reopenModal) return;
    const p = reopenModal.pallet;
    setReopenModal((prev) => prev && { ...prev, reopening: true });
    try {
      await apiPost(`/pallets/${p.id}/reopen`);
      setPallets((prev) =>
        prev.map((pl) => (pl.id === p.id ? { ...pl, status: "open" } : pl)),
      );
      toastSuccess("Pallet reabierto");
      setReopenModal(null);
      openOrganizeModal({ ...p, status: "open" });
      load();
    } catch (e) {
      toastError(e?.response?.data?.message || e.message || "Error reabriendo pallet");
      setReopenModal((prev) => prev && { ...prev, reopening: false });
    }
  }

  async function openOrganizeModal(pallet) {
    setOrganizeModal({
      palletId: pallet.id,
      palletCode: pallet.code,
      step: "base",
      bases: [],
      selectedBase: null,
      quantities: {},
      loading: true,
      saving: false,
    });
    try {
      const data = await apiGet(`/pallets/${pallet.id}`);
      setOrganizeModal((prev) =>
        prev ? { ...prev, bases: data.bases || [], loading: false } : null,
      );
    } catch {
      toastError("Error cargando bases del pallet");
      setOrganizeModal(null);
    }
  }

  function selectBaseForOrganize(base) {
    const orderItemIds = new Set(items.map((i) => i.id));
    const init = {};
    base.order_items?.forEach((item) => {
      if (orderItemIds.has(item.id)) init[item.id] = item.pivot?.qty ?? 0;
    });
    setOrganizeModal((prev) =>
      prev
        ? { ...prev, step: "products", selectedBase: base, quantities: init }
        : null,
    );
  }

  async function createBaseAndOrganize() {
    setOrganizeModal((prev) => (prev ? { ...prev, loading: true } : null));
    try {
      const nextNum = (organizeModal.bases?.length ?? 0) + 1;
      await apiPost(`/pallets/${organizeModal.palletId}/bases`, {
        name: `Base ${nextNum}`,
      });
      const data = await apiGet(`/pallets/${organizeModal.palletId}`);
      const bases = data.bases || [];
      const newBase = bases[bases.length - 1];
      setOrganizeModal((prev) =>
        prev
          ? { ...prev, bases, loading: false, step: "products", selectedBase: newBase, quantities: {} }
          : null,
      );
    } catch (e) {
      toastError(e?.response?.data?.message || "Error creando base");
      setOrganizeModal((prev) => (prev ? { ...prev, loading: false } : null));
    }
  }

  /** Cuántos ítems de este pedido hay ya asignados en una base */
  function countFromThisOrderInBase(base) {
    const ids = new Set(items.map((i) => i.id));
    return base.order_items?.filter((i) => ids.has(i.id)).length ?? 0;
  }

  /** Máximo que se puede asignar de un ítem en la base actualmente seleccionada */
  function modalMaxQty(orderItem) {
    if (!organizeModal?.selectedBase) return 0;
    const total = orderItem.qty || 0;
    const fullItem = items.find((i) => i.id === orderItem.id);
    const allLocations = fullItem?.locations ?? [];
    const assignedElsewhere = allLocations
      .filter((l) => l.base_id !== organizeModal.selectedBase.id)
      .reduce((sum, l) => sum + (l.qty ?? 0), 0);
    return Math.max(0, total - assignedElsewhere);
  }

  function incModalQty(orderItem) {
    const cur = organizeModal?.quantities[orderItem.id] ?? 0;
    const max = modalMaxQty(orderItem);
    if (cur < max)
      setOrganizeModal((prev) =>
        prev
          ? { ...prev, quantities: { ...prev.quantities, [orderItem.id]: cur + 1 } }
          : null,
      );
  }

  function decModalQty(itemId) {
    const cur = organizeModal?.quantities[itemId] ?? 0;
    if (cur > 0)
      setOrganizeModal((prev) =>
        prev
          ? { ...prev, quantities: { ...prev.quantities, [itemId]: cur - 1 } }
          : null,
      );
  }

  function setModalQty(orderItem, rawValue) {
    const max = modalMaxQty(orderItem);
    const v = Math.min(Math.max(0, parseInt(rawValue, 10) || 0), max);
    setOrganizeModal((prev) =>
      prev
        ? { ...prev, quantities: { ...prev.quantities, [orderItem.id]: v } }
        : null,
    );
  }

  async function saveOrganize() {
    if (!organizeModal) return;
    const payload = Object.entries(organizeModal.quantities)
      .filter(([, q]) => q > 0)
      .map(([id, q]) => ({ order_item_id: parseInt(id, 10), qty: q }));
    setOrganizeModal((prev) => (prev ? { ...prev, saving: true } : null));
    try {
      await apiPatch(
        `/pallets/${organizeModal.palletId}/bases/${organizeModal.selectedBase.id}`,
        { items: payload },
      );
      toastSuccess("Productos asignados al pallet");
      setOrganizeModal(null);
      load();
    } catch (e) {
      toastError(e?.response?.data?.message || "Error al guardar");
      setOrganizeModal((prev) => (prev ? { ...prev, saving: false } : null));
    }
  }

  return {
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
  };
}
