import { useEffect, useMemo, useState } from "react";
import { Link, useParams } from "react-router-dom";
import { apiGet, apiPost, apiPatch, apiDelete } from "../api/client";
import { toastSuccess, toastError } from "../ui/toast";
import BackButton from "../ui/BackButton";
import Title from "../ui/Title";
import ConfirmModal from "../ui/ConfirmModal";

function onlyDigits(v) {
  return (v || "").replace(/\D/g, "");
}

export default function PalletDetail() {
  const { palletId } = useParams();

  const [loading, setLoading] = useState(true);
  const [pallet, setPallet] = useState(null);
  const [orders, setOrders] = useState([]);
  const [bases, setBases] = useState([]);
  const [error, setError] = useState("");
  const [canFinalize, setCanFinalize] = useState(false);
  const [finalizeInfo, setFinalizeInfo] = useState(null);
  const [confirmModal, setConfirmModal] = useState(null);

  // pedido seleccionado (para importar)
  const [activeOrderId, setActiveOrderId] = useState(null);

  // modales
  const [openAssign, setOpenAssign] = useState(false);
  const [openImport, setOpenImport] = useState(false);
  const [showNewBase, setShowNewBase] = useState(false);
  const [editingBase, setEditingBase] = useState(null);
  const [baseName, setBaseName] = useState("");
  const [baseNote, setBaseNote] = useState("");

  // modal migración
  // null | { sourceBase, step:'items'|'dest', quantities:{}, loadingPallets, pallets:[],
  //          selectedPalletId, selectedPalletBases:[], selectedBaseId, saving }
  const [migrateModal, setMigrateModal] = useState(null);

  // assign
  const [orderCode, setOrderCode] = useState("");

  // import
  const [raw, setRaw] = useState("");
  const activeOrder = useMemo(
    () => orders.find((o) => o.id === activeOrderId) || null,
    [orders, activeOrderId]
  );

  async function load() {
    setError("");
    setLoading(true);
    try {
      const data = await apiGet(`/pallets/${palletId}`);
      setPallet(data.pallet || null);
      setOrders(data.orders || []);
      setBases(data.bases || []);

      if ((data.orders || []).length > 0 && !activeOrderId) {
        setActiveOrderId(data.orders[data.orders.length - 1].id);
      }

      // Verificar si puede finalizar (solo si está abierto)
      if (data.pallet?.status === "open") {
        try {
          const finalizeData = await apiGet(
            `/pallets/${palletId}/can-finalize`
          );
          setCanFinalize(finalizeData.can_finalize || false);
          setFinalizeInfo(finalizeData.requirements || null);
        } catch {
          // Si falla, asumir que no puede finalizar
          setCanFinalize(false);
          setFinalizeInfo(null);
        }
      } else {
        setCanFinalize(false);
        setFinalizeInfo(null);
      }
    } catch (e) {
      setError(e?.data?.message || e.message || "Error cargando pallet");
      toastError(e?.data?.message || e.message || "Error cargando pallet");
    } finally {
      setLoading(false);
    }
  }

  useEffect(() => {
    load();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [palletId]);

  async function onAssignSubmit(e) {
    e.preventDefault();
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
      const newOrders = res.orders || [];
      setOrders(newOrders);

      // seleccionar el recién creado/asignado
      if (res.order?.id) setActiveOrderId(res.order.id);

      setOrderCode("");
      setOpenAssign(false);
    } catch (e) {
      toastError(
        e.response?.data?.message || e.message || "No se pudo asignar"
      );
    }
  }

  async function onImportSubmit(e) {
    e.preventDefault();

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
      toastError(
        e.response?.data?.message || e.message || "No se pudo importar"
      );
    }
  }

  async function onCreateBase() {
    if (!baseName.trim() && !baseNote.trim()) {
      toastError("Agregá un nombre o una nota para la base");
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
      toastError(
        e.response?.data?.message || e.message || "No se pudo crear la base"
      );
    }
  }

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
      toastError(
        e.response?.data?.message ||
          e.message ||
          "No se pudo actualizar la base"
      );
    }
  }

  async function onDeleteBase(base) {
    setConfirmModal({
      title: "Eliminar base",
      message: `¿Eliminar la base "${
        base.name || `Base #${base.id}`
      }" y todas sus fotos y productos?`,
      confirmText: "Eliminar",
      cancelText: "Cancelar",
      confirmColor: "red",
      onConfirm: async () => {
        try {
          await apiDelete(`/pallets/${palletId}/bases/${base.id}`);
          toastSuccess("Base eliminada");
          await load();
        } catch (e) {
          toastError(
            e.response?.data?.message ||
              e.message ||
              "No se pudo eliminar la base"
          );
        }
      },
    });
  }

  // ── Migración ─────────────────────────────────────────────────────────────
  async function openMigrateModal(base) {
    // Inicializar con qty = 0 para cada ítem (el usuario elige qué mover)
    const quantities = {};
    (base.order_items || []).forEach((item) => { quantities[item.id] = 0; });
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

  function setMigrateQty(itemId, value, maxQty) {
    const v = Math.min(Math.max(0, parseInt(value, 10) || 0), maxQty);
    setMigrateModal((prev) => prev ? { ...prev, quantities: { ...prev.quantities, [itemId]: v } } : null);
  }

  function selectAllMigrate() {
    const quantities = {};
    (migrateModal?.sourceBase?.order_items || []).forEach((item) => {
      quantities[item.id] = item.pivot?.qty ?? 0;
    });
    setMigrateModal((prev) => prev ? { ...prev, quantities } : null);
  }

  async function goToMigrateDest() {
    setMigrateModal((prev) => prev ? { ...prev, step: "dest", loadingPallets: true } : null);
    try {
      const data = await apiGet("/pallets?page=1");
      const list = (Array.isArray(data) ? data : (data.data || []))
        .filter((p) => p.id !== parseInt(palletId, 10));
      setMigrateModal((prev) => prev ? { ...prev, loadingPallets: false, pallets: list } : null);
    } catch {
      toastError("Error cargando pallets");
      setMigrateModal((prev) => prev ? { ...prev, loadingPallets: false } : null);
    }
  }

  async function selectMigratePallet(pId) {
    if (pId === null) {
      // Pallet nuevo → no tiene bases aún
      setMigrateModal((prev) => prev ? { ...prev, selectedPalletId: null, selectedPalletBases: [], selectedBaseId: null } : null);
      return;
    }
    setMigrateModal((prev) => prev ? { ...prev, selectedPalletId: pId, selectedPalletBases: [], selectedBaseId: null } : null);
    try {
      const data = await apiGet(`/pallets/${pId}`);
      setMigrateModal((prev) => prev ? { ...prev, selectedPalletBases: data.bases || [] } : null);
    } catch {
      toastError("Error cargando bases del pallet");
    }
  }

  async function saveMigrate() {
    if (!migrateModal) return;
    const items = Object.entries(migrateModal.quantities)
      .filter(([, q]) => q > 0)
      .map(([id, q]) => ({ order_item_id: parseInt(id, 10), qty: q }));
    if (items.length === 0) { toastError("Seleccioná al menos 1 unidad para migrar"); return; }

    setMigrateModal((prev) => prev ? { ...prev, saving: true } : null);
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
      setMigrateModal((prev) => prev ? { ...prev, saving: false } : null);
    }
  }

  function startEditBase(base) {
    setEditingBase(base);
    setBaseName(base.name || "");
    setBaseNote(base.note || "");
  }

  async function handleFinalize() {
    setConfirmModal({
      title: "Finalizar pallet",
      message:
        "¿Estás seguro de que querés finalizar este pallet? Una vez finalizado, no podrás agregar más contenido hasta reabrirlo.",
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
            e.response?.data?.message || e.message || "Error finalizando pallet"
          );
        }
      },
    });
  }

  async function handleReopen() {
    setConfirmModal({
      title: "Reabrir pallet",
      message:
        "¿Estás seguro de que querés reabrir este pallet? Podrás agregar más contenido.",
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
            e.response?.data?.message || e.message || "Error reabriendo pallet"
          );
        }
      },
    });
  }

  async function handleDeletePallet() {
    setConfirmModal({
      title: "Eliminar pallet",
      message:
        "¿Estás seguro de que querés eliminar este pallet? Esta acción no se puede deshacer y eliminará todas las bases, fotos y productos asociados.",
      confirmText: "Eliminar",
      cancelText: "Cancelar",
      confirmColor: "red",
      onConfirm: async () => {
        try {
          await apiDelete(`/pallets/${palletId}`);
          toastSuccess("Pallet eliminado correctamente");
          // Redirigir a la página principal
          window.location.href = "/";
        } catch (e) {
          toastError(
            e.response?.data?.message || e.message || "Error eliminando pallet"
          );
        }
      },
    });
  }

  if (loading) return <div className="text-sm text-gray-600">Cargando…</div>;

  if (error) {
    return (
      <div className="space-y-3">
        <BackButton to="/" />
        <div className="bg-red-50 border border-red-200 text-red-800 rounded-xl p-3">
          {error}
        </div>
      </div>
    );
  }

  if (!pallet) {
    return (
      <div className="space-y-3">
        <BackButton to="/" />
        <div className="bg-red-50 border border-red-200 text-red-800 rounded-xl p-3">
          {error || "No se pudo cargar el pallet."}
        </div>
      </div>
    );
  }
  return (
    <div className="space-y-2">
      <div className="flex justify-start">
        <BackButton to="/" />
      </div>

      {/* Header pallet */}
      <div className="bg-white border border-border rounded-2xl p-4 flex flex-col gap-2">
        <Title size="3xl">{pallet.code}</Title>

        <div className="text-sm text-gray-500">
          Estado:{" "}
          <span className="capitalize font-semibold text-gray-900">
            {pallet.status}
          </span>
        </div>

        {/* Acciones */}
        <div className="mt-3 grid grid-cols-2 gap-2">
          {pallet.status === "open" && (
            <>
              <button
                onClick={() => setOpenAssign(true)}
                className="rounded-lg py-3 bg-black text-white text-sm"
              >
                Asignar pedido
              </button>

              <button
                onClick={() => setOpenImport(true)}
                disabled={!activeOrder}
                className="rounded-lg py-3 border disabled:opacity-50 text-sm"
                title={!activeOrder ? "Primero seleccioná un pedido" : ""}
              >
                Importar pedido
              </button>
            </>
          )}

          {pallet.status === "done" && (
            <button
              onClick={handleReopen}
              className="rounded-lg py-3 bg-green-600 text-white text-sm col-span-2"
            >
              Reabrir pallet
            </button>
          )}

          {canFinalize && pallet.status === "open" && (
            <button
              onClick={handleFinalize}
              className="rounded-lg py-3 bg-blue-600 text-white text-sm col-span-2"
            >
              Finalizar pallet
            </button>
          )}

          <Link
            to={`/pallet/${palletId}/gallery`}
            className="rounded-lg flex py-3 border text-center items-center justify-center hover:bg-gray-50 active:scale-[0.99] text-sm"
          >
            Galería
          </Link>

          <Link
            to={`/pallet/${palletId}/history`}
            className="rounded-lg flex py-3 border text-center items-center justify-center hover:bg-gray-50 active:scale-[0.99] text-sm"
          >
            Historial
          </Link>

          <button
            onClick={handleDeletePallet}
            className="rounded-lg py-3 bg-red-600 text-white text-sm col-span-2"
          >
            Eliminar pallet
          </button>
        </div>
      </div>

      {/* Pedidos asociados */}
      <div className="bg-white border rounded-2xl border-[#D1D5DB] overflow-hidden">
        <div className="px-4 py-3">
          <div className="font-semibold">Pedidos en este pallet</div>
        </div>

        {orders.length === 0 ? (
          <div className="p-4 text-sm text-gray-600">
            Todavía no hay pedidos asignados.
          </div>
        ) : (
          <div className="p-3 space-y-2">
            {orders.map((o) => (
              <button
                key={o.id}
                onClick={() => setActiveOrderId(o.id)}
                className={`w-full text-left rounded-xl p-3 border flex flex-col gap-2 items-center justify-center ${
                  o.id === activeOrderId ? "bg-black text-white" : "bg-white"
                }`}
              >
                <Title size="xs" className="opacity-80">
                  Pedido #{o.code}
                </Title>

                <div>
                  <Link
                    to={`/order/${o.id}`}
                    onClick={(ev) => ev.stopPropagation()}
                    className={`text-sm underline ${
                      o.id === activeOrderId ? "text-white" : ""
                    }`}
                  >
                    Abrir detalle del pedido
                  </Link>
                </div>
              </button>
            ))}
          </div>
        )}
      </div>

      {/* Bases */}
      <div className="bg-white border border-border rounded-2xl overflow-hidden">
        <div className="px-4 pt-4 flex items-center justify-center w-full gap-2">
          <div className="flex flex-col gap-2 ">
            <div className="font-semibold text-center">
              <Title size="2xl">Bases ({bases.length})</Title>
            </div>
            <div className="text-xs text-gray-500 text-start">
              Organizá los productos del pallet por base
            </div>
          </div>
        </div>

        {bases.length === 0 ? (
          <div className="p-8 text-center space-y-4">
            <div className="text-sm text-gray-600">
              No hay bases todavía. Creá una base para organizar productos.
            </div>
            {pallet.status === "open" && (
              <button
                onClick={() => {
                  setShowNewBase(true);
                  setBaseName("");
                  setBaseNote("");
                }}
                className="text-sm p-2 border rounded-lg hover:bg-gray-50 bg-black text-white"
              >
                Agregar base
              </button>
            )}
          </div>
        ) : (
          <div className="p-3 space-y-4">
            {bases.map((base) => (
              <div
                key={base.id}
                className="border rounded-xl p-4 space-y-3 bg-white"
              >
                {/* Header de la base */}
                <div className="flex items-start justify-between gap-2">
                  <div className="flex-1">
                    <div className="flex items-start font-semibold text-base">
                      {base.name || `Base #${base.id}`}
                    </div>
                    {base.note && (
                      <div className="text-xs text-gray-600 mt-1 text-start">
                        {base.note}
                      </div>
                    )}
                  </div>
                  {pallet.status === "open" && (
                    <div className="flex gap-2">
                      <button
                        onClick={() => startEditBase(base)}
                        className="text-xs px-2 py-1 border rounded bg-white hover:bg-gray-50"
                      >
                        Editar
                      </button>
                      <button
                        onClick={() => onDeleteBase(base)}
                        className="text-xs px-2 py-1 border rounded bg-red-50 text-red-700 hover:bg-red-100"
                      >
                        Eliminar
                      </button>
                    </div>
                  )}
                </div>

                {/* Botones de acción */}
                <div className="grid grid-cols-2 gap-2">
                  <Link
                    to={`/pallet/${palletId}/base/${base.id}/products`}
                    className="rounded-lg py-3 border text-center hover:bg-gray-50 active:scale-[0.99] text-sm font-medium"
                  >
                    Productos ({base.order_items?.length || 0})
                  </Link>
                  <Link
                    to={`/pallet/${palletId}/base/${base.id}/gallery`}
                    className="rounded-lg py-3 border text-center hover:bg-gray-50 active:scale-[0.99] text-sm font-medium"
                  >
                    Galería ({base.photos?.length || 0})
                  </Link>
                  {(base.order_items?.length || 0) > 0 && (
                    <button
                      onClick={() => openMigrateModal(base)}
                      className="col-span-2 rounded-lg py-2.5 border border-amber-300 bg-amber-50 text-amber-800 hover:bg-amber-100 active:scale-[0.99] text-sm font-medium"
                    >
                      🔀 Migrar productos
                    </button>
                  )}
                </div>
              </div>
            ))}
            {pallet.status === "open" && (
              <button
                onClick={() => {
                  setShowNewBase(true);
                  setBaseName("");
                  setBaseNote("");
                }}
                className="text-sm p-2 border rounded-lg hover:bg-gray-50 bg-black text-white w-3/4"
              >
                Agregar base
              </button>
            )}
          </div>
        )}
      </div>

      {/* Modal asignar */}
      {openAssign && (
        <div className="fixed inset-0 z-50 bg-black/60 p-4 flex items-center justify-center">
          <div className="w-full max-w-md bg-white rounded-2xl p-4">
            <div className="flex items-center justify-between">
              <div className="font-semibold">Asignar pedido</div>
              <button
                onClick={() => setOpenAssign(false)}
                className="px-3 py-2 border rounded-lg"
              >
                Cerrar
              </button>
            </div>

            <form onSubmit={onAssignSubmit} className="mt-4 space-y-3">
              <label className="text-sm font-medium block">
                Número de pedido
              </label>
              <input
                value={orderCode}
                onChange={(e) => setOrderCode(onlyDigits(e.target.value))}
                inputMode="numeric"
                placeholder="Ej: 123456"
                className="w-full border rounded-lg px-3 py-3"
              />

              <button className="w-full rounded-lg py-3 bg-black text-white">
                Asignar
              </button>

              <p className="text-xs text-gray-500">
                Si el pedido no existe, se crea. Luego queda asociado a este
                pallet.
              </p>
            </form>
          </div>
        </div>
      )}

      {/* Modal importar */}
      {openImport && (
        <div className="fixed inset-0 z-50 bg-black/60 p-4 flex items-center justify-center">
          <div className="w-full max-w-md bg-white rounded-2xl p-4">
            <div className="flex items-center justify-between">
              <div className="font-semibold">Importar pedido</div>
              <button
                onClick={() => setOpenImport(false)}
                className="px-3 py-2 border rounded-lg"
              >
                Cerrar
              </button>
            </div>

            <div className="mt-2 text-xs text-gray-600">
              Pedido activo:{" "}
              <span className="font-mono">{activeOrder?.code || "—"}</span>
            </div>

            <form onSubmit={onImportSubmit} className="mt-4 space-y-3">
              <textarea
                value={raw}
                onChange={(e) => setRaw(e.target.value)}
                rows={10}
                placeholder="Pegá acá el texto copiado de la tabla (TABs)."
                className="w-full border rounded-lg p-3 font-mono text-xs"
              />

              <button className="w-full rounded-lg py-3 bg-black text-white">
                Importar (reemplaza)
              </button>

              <p className="text-xs text-gray-500">
                Esto reemplaza los ítems del pedido importado.
              </p>
            </form>
          </div>
        </div>
      )}

      {/* Modal crear/editar base */}
      {(showNewBase || editingBase) && (
        <div className="fixed inset-0 z-50 bg-black/60 p-4 flex items-center justify-center">
          <div className="w-full max-w-md bg-white rounded-2xl p-4 space-y-3">
            <div className="flex items-center justify-between">
              <div className="font-semibold">
                {editingBase ? "Editar base" : "Nueva base"}
              </div>
              <button
                onClick={() => {
                  setShowNewBase(false);
                  setEditingBase(null);
                  setBaseName("");
                  setBaseNote("");
                }}
                className="px-3 py-1 text-xs border rounded-lg"
              >
                Cerrar
              </button>
            </div>

            <div>
              <label className="text-sm font-medium block mb-1">
                Nombre (opcional)
              </label>
              <input
                value={baseName}
                onChange={(e) => setBaseName(e.target.value)}
                placeholder="Ej: Base 1, Base A"
                className="w-full border rounded-lg px-3 py-2 text-sm"
              />
            </div>

            <div>
              <label className="text-sm font-medium block mb-1">
                Nota / Descripción (opcional)
              </label>
              <textarea
                value={baseNote}
                onChange={(e) => setBaseNote(e.target.value)}
                placeholder="Notas adicionales sobre la base"
                rows={2}
                className="w-full border rounded-lg px-3 py-2 text-sm"
              />
            </div>

            <button
              onClick={() =>
                editingBase ? onUpdateBase(editingBase) : onCreateBase()
              }
              className="w-full rounded-lg py-2 bg-black text-white text-sm"
            >
              {editingBase ? "Actualizar" : "Crear base"}
            </button>
          </div>
        </div>
      )}

      {/* ── Modal migración ──────────────────────────────────────────────── */}
      {migrateModal && (
        <div className="fixed inset-0 z-50 bg-black/60 flex items-end sm:items-center justify-center">
          <div className="w-full sm:max-w-lg bg-white rounded-t-3xl sm:rounded-2xl flex flex-col max-h-[90vh]">

            {/* Header */}
            <div className="flex items-center justify-between px-4 pt-5 pb-3 border-b flex-shrink-0">
              <div>
                <p className="font-bold text-base">
                  🔀 Migrar desde {migrateModal.sourceBase.name || `Base #${migrateModal.sourceBase.id}`}
                </p>
                <p className="text-xs text-gray-500 mt-0.5">
                  {migrateModal.step === "items" ? "¿Qué productos y cuántas unidades?" : "¿A dónde los llevás?"}
                </p>
              </div>
              <button onClick={() => setMigrateModal(null)} className="text-gray-400 hover:text-gray-700 text-xl leading-none">✕</button>
            </div>

            {/* ── Paso 1: seleccionar cantidades ── */}
            {migrateModal.step === "items" && (
              <>
                <div className="flex-1 overflow-y-auto divide-y divide-gray-100">
                  {/* Botón "Mover todo" */}
                  <div className="px-4 py-2 bg-gray-50 flex items-center justify-between">
                    <span className="text-xs text-gray-500">
                      {Object.values(migrateModal.quantities).filter((q) => q > 0).length} producto{Object.values(migrateModal.quantities).filter((q) => q > 0).length !== 1 ? "s" : ""} seleccionado{Object.values(migrateModal.quantities).filter((q) => q > 0).length !== 1 ? "s" : ""}
                    </span>
                    <button
                      onClick={selectAllMigrate}
                      className="text-xs text-blue-600 font-medium hover:underline"
                    >
                      Seleccionar todo
                    </button>
                  </div>

                  {(migrateModal.sourceBase.order_items || []).map((item) => {
                    const maxQty = item.pivot?.qty ?? 0;
                    const cur = migrateModal.quantities[item.id] ?? 0;
                    const active = cur > 0;
                    return (
                      <div
                        key={item.id}
                        className={["flex items-center gap-3 px-4 py-3 transition-colors", active ? "border-l-4 border-l-amber-500" : ""].join(" ")}
                      >
                        <div className="flex-1 min-w-0">
                          <p className="text-sm font-medium leading-snug">{item.description}</p>
                          <p className="text-xs font-mono text-gray-400 mt-0.5">{item.ean}</p>
                          <p className="text-xs text-gray-500 mt-0.5">En base: <span className="font-semibold">{maxQty}</span> u.</p>
                        </div>
                        <div className="flex items-center gap-1.5 flex-shrink-0">
                          <button
                            onClick={() => setMigrateQty(item.id, cur - 1, maxQty)}
                            disabled={cur === 0}
                            className="w-8 h-8 rounded-full border border-gray-300 flex items-center justify-center text-gray-600 disabled:opacity-25 text-lg leading-none select-none"
                          >−</button>
                          <input
                            type="text"
                            inputMode="numeric"
                            value={cur === 0 ? "" : cur}
                            placeholder="0"
                            onChange={(e) => setMigrateQty(item.id, e.target.value, maxQty)}
                            onFocus={(e) => e.target.select()}
                            className="w-12 text-center text-sm font-bold border rounded-lg py-1 focus:outline-none focus:ring-2 focus:ring-amber-300"
                          />
                          <button
                            onClick={() => setMigrateQty(item.id, cur + 1, maxQty)}
                            disabled={cur >= maxQty}
                            className="w-8 h-8 rounded-full bg-amber-500 text-white flex items-center justify-center disabled:opacity-25 text-lg leading-none select-none"
                          >+</button>
                        </div>
                      </div>
                    );
                  })}
                </div>

                <div className="p-4 border-t flex-shrink-0">
                  <button
                    onClick={goToMigrateDest}
                    disabled={Object.values(migrateModal.quantities).every((q) => q === 0)}
                    className="w-full py-3.5 rounded-2xl bg-gray-900 text-white font-bold text-sm disabled:opacity-40"
                  >
                    Seleccionar destino →
                  </button>
                </div>
              </>
            )}

            {/* ── Paso 2: seleccionar destino ── */}
            {migrateModal.step === "dest" && (
              <>
                <div className="px-4 py-2 border-b flex-shrink-0">
                  <button
                    onClick={() => setMigrateModal((prev) => prev ? { ...prev, step: "items", selectedPalletId: null, selectedBaseId: null } : null)}
                    className="text-sm text-blue-600 hover:underline"
                  >
                    ← Volver
                  </button>
                </div>

                <div className="flex-1 overflow-y-auto p-4 space-y-4">
                  {migrateModal.loadingPallets ? (
                    <p className="text-center text-gray-400 py-8 text-sm">Cargando pallets…</p>
                  ) : (
                    <>
                      {/* Pallet destino */}
                      <div>
                        <p className="text-xs font-bold uppercase tracking-wide text-gray-500 mb-2">Pallet destino</p>
                        <div className="space-y-2">
                          {/* Opción: pallet nuevo */}
                          <button
                            onClick={() => selectMigratePallet(null)}
                            className={[
                              "w-full text-left rounded-xl border px-4 py-3 text-sm transition-colors",
                              migrateModal.selectedPalletId === null
                                ? "border-amber-400 bg-amber-50 font-semibold"
                                : "border-gray-200 hover:bg-gray-50",
                            ].join(" ")}
                          >
                            <span className="font-medium">🆕 Crear pallet nuevo</span>
                            <span className="block text-xs text-gray-400 mt-0.5">Se generará con código automático</span>
                          </button>

                          {migrateModal.pallets.map((p) => (
                            <button
                              key={p.id}
                              onClick={() => selectMigratePallet(p.id)}
                              className={[
                                "w-full text-left rounded-xl border px-4 py-3 text-sm transition-colors",
                                migrateModal.selectedPalletId === p.id
                                  ? "border-amber-400 bg-amber-50 font-semibold"
                                  : "border-gray-200 hover:bg-gray-50",
                              ].join(" ")}
                            >
                              <span className="font-mono font-semibold">{p.code}</span>
                              <span className={`ml-2 text-xs px-1.5 py-0.5 rounded-full ${p.status === "done" ? "bg-green-100 text-green-700" : "bg-amber-100 text-amber-700"}`}>
                                {p.status === "done" ? "Finalizado" : "Abierto"}
                              </span>
                            </button>
                          ))}
                        </div>
                      </div>

                      {/* Base destino — solo si se eligió un pallet existente */}
                      {migrateModal.selectedPalletId !== null && (
                        <div>
                          <p className="text-xs font-bold uppercase tracking-wide text-gray-500 mb-2">Base destino</p>
                          <div className="space-y-2">
                            {/* Base nueva */}
                            <button
                              onClick={() => setMigrateModal((prev) => prev ? { ...prev, selectedBaseId: null } : null)}
                              className={[
                                "w-full text-left rounded-xl border px-4 py-3 text-sm transition-colors",
                                migrateModal.selectedBaseId === null
                                  ? "border-amber-400 bg-amber-50 font-semibold"
                                  : "border-gray-200 hover:bg-gray-50",
                              ].join(" ")}
                            >
                              🆕 Crear base nueva
                            </button>

                            {migrateModal.selectedPalletBases.map((b) => (
                              <button
                                key={b.id}
                                onClick={() => setMigrateModal((prev) => prev ? { ...prev, selectedBaseId: b.id } : null)}
                                className={[
                                  "w-full text-left rounded-xl border px-4 py-3 text-sm transition-colors",
                                  migrateModal.selectedBaseId === b.id
                                    ? "border-amber-400 bg-amber-50 font-semibold"
                                    : "border-gray-200 hover:bg-gray-50",
                                ].join(" ")}
                              >
                                <span className="font-medium">{b.name || `Base #${b.id}`}</span>
                                <span className="ml-2 text-xs text-gray-400">
                                  {b.order_items?.length || 0} prod.
                                </span>
                              </button>
                            ))}
                          </div>
                        </div>
                      )}
                    </>
                  )}
                </div>

                {/* Botón confirmar */}
                <div className="p-4 border-t flex-shrink-0">
                  {(() => {
                    const count = Object.values(migrateModal.quantities).filter((q) => q > 0).length;
                    const totalUnits = Object.values(migrateModal.quantities).reduce((s, q) => s + q, 0);
                    const destReady = migrateModal.selectedPalletId === null || migrateModal.selectedPalletId !== null;
                    return (
                      <button
                        onClick={saveMigrate}
                        disabled={migrateModal.saving || !destReady}
                        className="w-full py-3.5 rounded-2xl bg-amber-500 text-white font-bold text-sm disabled:opacity-40"
                      >
                        {migrateModal.saving
                          ? "Migrando…"
                          : `Migrar ${totalUnits} u. (${count} producto${count !== 1 ? "s" : ""})`}
                      </button>
                    );
                  })()}
                </div>
              </>
            )}
          </div>
        </div>
      )}

      {/* Modal de confirmación */}
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
