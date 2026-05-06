import { useState } from "react";
import { apiDelete, apiPatch, apiPost } from "../api/client";
import { toastError, toastSuccess } from "../ui/toast";

/**
 * Encapsula la lógica CRUD de bases de pallet:
 * crear, renombrar, eliminar y el estado del formulario de edición.
 *
 * @param {Object} deps
 * @param {string|number} deps.palletId       - ID del pallet
 * @param {Function}      deps.load           - Recarga el pallet tras cada mutación
 * @param {Function}      deps.setConfirmModal - Abre el modal de confirmación global
 */
export function useBaseMutations({ palletId, load, setConfirmModal }) {
  const [showNewBase, setShowNewBase] = useState(false);
  const [editingBase, setEditingBase] = useState(null);
  const [baseName, setBaseName] = useState("");
  const [baseNote, setBaseNote] = useState("");

  /** Crea una nueva base en el pallet */
  async function onCreateBase() {
    if (!baseName.trim() && !baseNote.trim()) {
      toastError("Agregá un nombre o una nota");
      return;
    }
    try {
      await apiPost(`/pallets/${palletId}/bases`, {
        name: baseName.trim() || null,
        note: baseNote.trim() || null,
      });
      toastSuccess("Base creada");
      setShowNewBase(false);
      setBaseName("");
      setBaseNote("");
      await load();
    } catch (e) {
      toastError(e.response?.data?.message || e.message || "No se pudo crear la base");
    }
  }

  /** Guarda cambios de nombre/nota en una base existente */
  async function onUpdateBase(base) {
    try {
      await apiPatch(`/pallets/${palletId}/bases/${base.id}`, {
        name: baseName.trim() || null,
        note: baseNote.trim() || null,
      });
      toastSuccess("Base actualizada");
      setEditingBase(null);
      setBaseName("");
      setBaseNote("");
      await load();
    } catch (e) {
      toastError(e.response?.data?.message || e.message || "No se pudo actualizar la base");
    }
  }

  /** Muestra confirmación y elimina la base */
  function onDeleteBase(base) {
    setConfirmModal({
      title: "Eliminar base",
      message: `¿Eliminar la base "${base.name || `Base #${base.id}`}" y todas sus fotos y productos?`,
      confirmText: "Eliminar",
      cancelText: "Cancelar",
      confirmColor: "red",
      onConfirm: async () => {
        try {
          await apiDelete(`/pallets/${palletId}/bases/${base.id}`);
          toastSuccess("Base eliminada");
          await load();
        } catch (e) {
          toastError(e.response?.data?.message || e.message || "No se pudo eliminar la base");
        }
      },
    });
  }

  /** Precarga el formulario para editar una base existente */
  function startEditBase(base) {
    setEditingBase(base);
    setBaseName(base.name || "");
    setBaseNote(base.note || "");
  }

  return {
    showNewBase, setShowNewBase,
    editingBase, setEditingBase,
    baseName,    setBaseName,
    baseNote,    setBaseNote,
    onCreateBase,
    onUpdateBase,
    onDeleteBase,
    startEditBase,
  };
}
