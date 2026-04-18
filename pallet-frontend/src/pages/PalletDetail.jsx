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
