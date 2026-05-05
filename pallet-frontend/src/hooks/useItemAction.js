import { useState } from "react";
import { apiPatch } from "../api/client";
import { toastSuccess, toastError } from "../ui/toast";

/**
 * Encapsula el estado y la lógica de "marcar ítem como encontrado/quitado",
 * incluyendo la resolución de conflictos cuando la cantidad nueva es menor
 * a lo ya organizado en bases de pallets.
 *
 * @param {object}   deps
 * @param {Function} deps.setItems — setter del array de ítems del pedido
 * @param {Function} deps.load     — recarga los datos del pedido
 */
export function useItemAction({ setItems, load }) {
  // null | objeto del ítem seleccionado
  const [actionItem, setActionItem] = useState(null);
  const [actionQty, setActionQty] = useState("");

  // null | { item, newStatus, newQty, totalOrganized, deficit, keepQtys, saving }
  const [qtyConflict, setQtyConflict] = useState(null);

  async function updateItem(itemId, patch) {
    try {
      const updated = await apiPatch(`/order-items/${itemId}`, patch);
      // Preservar campos enriquecidos (locations, image_url) que el PATCH no devuelve
      setItems((prev) =>
        prev.map((it) => (it.id === itemId ? { ...it, ...updated } : it)),
      );
    } catch (e) {
      toastError(e.message || "No se pudo actualizar");
    }
  }

  /** Intenta guardar la acción. Si hay conflicto, abre el modal de resolución. */
  function tryApplyAction(item, newQty) {
    const totalOrganized = (item.locations ?? []).reduce(
      (s, l) => s + (l.qty ?? 0),
      0,
    );
    const newStatus = newQty === 0 ? "removed" : "done";

    if (totalOrganized > newQty) {
      const deficit = totalOrganized - newQty;
      // keepQtys: cuántas unidades mantener en cada base (arranca en el valor actual)
      const keepQtys = Object.fromEntries(
        (item.locations ?? []).map((l) => [l.base_id, l.qty]),
      );
      setQtyConflict({
        item,
        newStatus,
        newQty,
        totalOrganized,
        deficit,
        keepQtys,
        saving: false,
      });
      setActionItem(null); // cierra el modal de acción
      return;
    }

    // Sin conflicto → guardar normalmente
    void doApplyAction(item, newStatus, newQty);
  }

  async function doApplyAction(item, newStatus, newQty) {
    if (newStatus === "removed") {
      await updateItem(item.id, { status: "removed" });
      toastSuccess("Producto quitado");
    } else {
      await updateItem(item.id, { status: "done", qty: newQty });
      toastSuccess(`Cantidad actualizada: ${newQty} unidades`);
    }
    setActionItem(null);
  }

  /** Ajusta keepQty de una ubicación en el modal de conflicto */
  function setConflictKeep(baseId, rawVal, maxQty) {
    const v = Math.max(0, Math.min(maxQty, parseInt(rawVal, 10) || 0));
    setQtyConflict((prev) =>
      prev ? { ...prev, keepQtys: { ...prev.keepQtys, [baseId]: v } } : null,
    );
  }

  /** Confirma el conflicto: ajusta cada base afectada y luego guarda el ítem */
  async function resolveConflictAndSave() {
    if (!qtyConflict) return;
    const { item, newStatus, newQty, keepQtys } = qtyConflict;
    setQtyConflict((prev) => prev && { ...prev, saving: true });

    try {
      // Ajustar cantidades en cada base donde el usuario eligió liberar unidades
      for (const loc of item.locations ?? []) {
        const keepQty = keepQtys[loc.base_id] ?? loc.qty;
        if (keepQty !== loc.qty) {
          await apiPatch(
            `/pallets/${loc.pallet_id}/bases/${loc.base_id}/adjust-item`,
            { order_item_id: item.id, qty: keepQty },
          );
        }
      }
      await doApplyAction(item, newStatus, newQty);
      setQtyConflict(null);
      load(); // refrescar locations actualizadas
    } catch (e) {
      toastError(e?.response?.data?.message || e.message || "Error al guardar");
      setQtyConflict((prev) => prev && { ...prev, saving: false });
    }
  }

  return {
    actionItem,
    setActionItem,
    actionQty,
    setActionQty,
    qtyConflict,
    setQtyConflict,
    tryApplyAction,
    setConflictKeep,
    resolveConflictAndSave,
  };
}
