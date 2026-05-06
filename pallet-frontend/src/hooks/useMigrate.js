import { useState } from "react";
import { apiGet, apiPost } from "../api/client";
import { toastError, toastSuccess } from "../ui/toast";

/**
 * Encapsula toda la lógica del modal de migración de productos entre bases.
 *
 * @param {Object} deps
 * @param {string|number} deps.palletId - ID del pallet origen
 * @param {Function} deps.load          - Recarga el pallet tras una migración exitosa
 */
export function useMigrate({ palletId, load }) {
  const [migrateModal, setMigrateModal] = useState(null);

  /** Abre el modal con los ítems de la base seleccionada */
  function openMigrateModal(base) {
    const quantities = {};
    (base.order_items || []).forEach((item) => {
      quantities[item.id] = item.pivot?.qty ?? 0;
    });
    setMigrateModal({
      sourceBase: base,
      step: "items",
      quantities,
      loadingPallets: false,
      pallets: [],
      selectedPalletId: null,
      selectedPalletBases: [],
      selectedBaseId: null,
      saving: false,
    });
  }

  /** Ajusta la cantidad de un ítem a migrar */
  function setMigrateQty(itemId, value, maxQty) {
    const v = Math.min(Math.max(0, parseInt(value, 10) || 0), maxQty);
    setMigrateModal((prev) =>
      prev ? { ...prev, quantities: { ...prev.quantities, [itemId]: v } } : null
    );
  }

  /** Selecciona todas las unidades disponibles de todos los ítems */
  function selectAllMigrate() {
    const quantities = {};
    (migrateModal?.sourceBase?.order_items || []).forEach((item) => {
      quantities[item.id] = item.pivot?.qty ?? 0;
    });
    setMigrateModal((prev) => (prev ? { ...prev, quantities } : null));
  }

  /** Avanza al paso "destino": carga la lista de pallets disponibles */
  async function goToMigrateDest() {
    setMigrateModal((prev) =>
      prev ? { ...prev, step: "dest", loadingPallets: true } : null
    );
    try {
      const data = await apiGet("/pallets?page=1");
      const list = (Array.isArray(data) ? data : data.data || []).filter(
        (p) => p.id !== parseInt(palletId, 10)
      );
      setMigrateModal((prev) =>
        prev ? { ...prev, loadingPallets: false, pallets: list } : null
      );
    } catch {
      toastError("Error cargando pallets");
      setMigrateModal((prev) =>
        prev ? { ...prev, loadingPallets: false } : null
      );
    }
  }

  /** Selecciona el pallet destino y carga sus bases */
  async function selectMigratePallet(pId) {
    if (pId === null) {
      setMigrateModal((prev) =>
        prev
          ? { ...prev, selectedPalletId: null, selectedPalletBases: [], selectedBaseId: null }
          : null
      );
      return;
    }
    setMigrateModal((prev) =>
      prev
        ? { ...prev, selectedPalletId: pId, selectedPalletBases: [], selectedBaseId: null }
        : null
    );
    try {
      const data = await apiGet(`/pallets/${pId}`);
      setMigrateModal((prev) =>
        prev ? { ...prev, selectedPalletBases: data.bases || [] } : null
      );
    } catch {
      toastError("Error cargando bases del pallet");
    }
  }

  /** Ejecuta la migración y recarga el pallet */
  async function saveMigrate() {
    if (!migrateModal) return;
    const items = Object.entries(migrateModal.quantities)
      .filter(([, q]) => q > 0)
      .map(([id, q]) => ({ order_item_id: parseInt(id, 10), qty: q }));

    if (items.length === 0) {
      toastError("Seleccioná al menos 1 unidad para migrar");
      return;
    }
    setMigrateModal((prev) => (prev ? { ...prev, saving: true } : null));
    try {
      const res = await apiPost(
        `/pallets/${palletId}/bases/${migrateModal.sourceBase.id}/migrate`,
        {
          items,
          destination_pallet_id: migrateModal.selectedPalletId ?? null,
          destination_base_id: migrateModal.selectedBaseId ?? null,
        }
      );
      toastSuccess(`Migrado correctamente → ${res.destination_pallet_code}`);
      setMigrateModal(null);
      load();
    } catch (e) {
      toastError(e?.response?.data?.message || "Error al migrar");
      setMigrateModal((prev) => (prev ? { ...prev, saving: false } : null));
    }
  }

  return {
    migrateModal,
    setMigrateModal,
    openMigrateModal,
    setMigrateQty,
    selectAllMigrate,
    goToMigrateDest,
    selectMigratePallet,
    saveMigrate,
  };
}
