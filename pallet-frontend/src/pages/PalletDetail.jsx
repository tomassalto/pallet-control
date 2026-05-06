import { useEffect, useMemo, useState } from "react";
import { Link, useParams } from "react-router-dom";
import { apiDelete, apiGet, apiPost } from "../api/client";
import { toastError, toastSuccess } from "../ui/toast";
import BackButton from "../ui/BackButton";
import ConfirmModal from "../ui/ConfirmModal";
import { PageSpinner } from "../ui/Spinner";
import { StatusBadge } from "../ui/EntityCard";
import { ActionItem, Icons } from "../ui/ActionList";
import { useMigrate } from "../hooks/useMigrate";
import { useBaseMutations } from "../hooks/useBaseMutations";
import MigrateModal from "../features/pallet-bases/MigrateModal";
import BaseCard from "../features/pallet-bases/BaseCard";
import BaseFormModal from "../features/pallet-bases/BaseFormModal";

// ── Utilidades ────────────────────────────────────────────────────────────────
function onlyDigits(v) {
  return (v || "").replace(/\D/g, "");
}
function formatDate(iso) {
  return new Date(iso).toLocaleDateString(undefined, { dateStyle: "medium" });
}
function ExternalLinkIcon() {
  return (
    <svg className="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" strokeWidth={2.5} stroke="currentColor">
      <path strokeLinecap="round" strokeLinejoin="round" d="M13.5 6H5.25A2.25 2.25 0 003 8.25v10.5A2.25 2.25 0 005.25 21h10.5A2.25 2.25 0 0018 18.75V10.5m-10.5 6L21 3m0 0h-5.25M21 3v5.25" />
    </svg>
  );
}

// ── Clases CSS reutilizables ──────────────────────────────────────────────────
const SEC_LABEL = "text-xs font-semibold uppercase tracking-wider text-gray-400 dark:text-gray-500";
const BTN_PRI   = "w-full py-3 px-4 rounded-xl bg-gray-900 dark:bg-white text-white dark:text-gray-900 text-sm font-semibold hover:bg-gray-700 dark:hover:bg-gray-100 disabled:opacity-40 transition-colors";
const BTN_GREEN = "w-full py-3 px-4 rounded-xl bg-green-600 text-white text-sm font-semibold hover:bg-green-700 disabled:opacity-40 transition-colors";
const BTN_BLUE  = "w-full py-3 px-4 rounded-xl bg-blue-600 text-white text-sm font-semibold hover:bg-blue-700 disabled:opacity-40 transition-colors";
const INPUT_CLS = "w-full border border-gray-300 dark:border-gray-600 rounded-xl px-3 py-2.5 text-sm bg-gray-50 dark:bg-gray-700 dark:text-white focus:outline-none focus:ring-2 focus:ring-gray-900 dark:focus:ring-white transition-shadow";

// ── Chip de pedido asociado ───────────────────────────────────────────────────
function OrderChip({ o, activeOrderId, setActiveOrderId }) {
  const active = o.id === activeOrderId;
  return (
    <button
      onClick={() => setActiveOrderId(o.id)}
      className={[
        "inline-flex items-center gap-2 px-3 py-2 rounded-xl text-sm font-semibold transition-colors",
        active
          ? "bg-gray-900 dark:bg-white text-white dark:text-gray-900"
          : "bg-gray-100 dark:bg-gray-700/60 text-gray-700 dark:text-gray-200 hover:bg-gray-200 dark:hover:bg-gray-700",
      ].join(" ")}
    >
      <span>Pedido #{o.code}</span>
      {active && (
        <Link
          to={`/order/${o.id}`}
          onClick={(e) => e.stopPropagation()}
          className="opacity-60 hover:opacity-100 transition-opacity"
          title="Abrir detalle del pedido"
        >
          <ExternalLinkIcon />
        </Link>
      )}
    </button>
  );
}

// ── Componente principal ──────────────────────────────────────────────────────
export default function PalletDetail() {
  const { palletId } = useParams();

  // Estado del pallet
  const [loading, setLoading]       = useState(true);
  const [pallet, setPallet]         = useState(null);
  const [orders, setOrders]         = useState([]);
  const [bases, setBases]           = useState([]);
  const [error, setError]           = useState("");
  const [canFinalize, setCanFinalize] = useState(false);
  const [confirmModal, setConfirmModal] = useState(null);

  // Estado UI de pedidos
  const [activeOrderId, setActiveOrderId] = useState(null);
  const [openAssign, setOpenAssign] = useState(false);
  const [openImport, setOpenImport] = useState(false);
  const [orderCode, setOrderCode]   = useState("");
  const [raw, setRaw]               = useState("");

  const activeOrder = useMemo(
    () => orders.find((o) => o.id === activeOrderId) || null,
    [orders, activeOrderId]
  );

  // Hooks extraídos
  const migrate = useMigrate({ palletId, load });
  const baseMutations = useBaseMutations({ palletId, load, setConfirmModal });

  // ── Carga de datos ───────────────────────────────────────────────────────
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
      if (data.pallet?.status === "open") {
        try {
          const fd = await apiGet(`/pallets/${palletId}/can-finalize`);
          setCanFinalize(fd.can_finalize || false);
        } catch {
          setCanFinalize(false);
        }
      } else {
        setCanFinalize(false);
      }
    } catch (e) {
      setError(e?.data?.message || e.message || "Error cargando pallet");
      toastError(e?.data?.message || e.message || "Error cargando pallet");
    } finally {
      setLoading(false);
    }
  }

  useEffect(() => { load(); }, [palletId]); // eslint-disable-line

  // ── Handlers: asignar pedido / importar ─────────────────────────────────
  async function onAssignSubmit(e) {
    e.preventDefault();
    const clean = onlyDigits(orderCode);
    if (!clean) { toastError("El número de pedido debe ser numérico."); return; }
    try {
      const res = await apiPost(`/pallets/${palletId}/attach-order`, { order_code: clean });
      toastSuccess("Pedido asignado");
      setOrders(res.orders || []);
      if (res.order?.id) setActiveOrderId(res.order.id);
      setOrderCode("");
      setOpenAssign(false);
    } catch (e) {
      toastError(e.response?.data?.message || e.message || "No se pudo asignar");
    }
  }

  async function onImportSubmit(e) {
    e.preventDefault();
    if (!activeOrder) { toastError("Seleccioná un pedido para importar."); return; }
    if (!raw.trim())  { toastError("Pegá el texto del pedido."); return; }
    try {
      await apiPost(`/orders/${activeOrder.id}/import`, { raw });
      toastSuccess("Pedido importado");
      setRaw("");
      setOpenImport(false);
    } catch (e) {
      toastError(e.response?.data?.message || e.message || "No se pudo importar");
    }
  }

  // ── Handlers: finalizar / reabrir / eliminar pallet ─────────────────────
  function handleFinalize() {
    setConfirmModal({
      title: "Finalizar pallet",
      message: "¿Estás seguro de finalizar este pallet? Una vez finalizado, no podrás agregar más contenido hasta reabrirlo.",
      confirmText: "Finalizar",
      cancelText: "Cancelar",
      confirmColor: "blue",
      onConfirm: async () => {
        try {
          await apiPost(`/pallets/${palletId}/finalize`);
          toastSuccess("Pallet finalizado correctamente");
          await load();
        } catch (e) {
          toastError(e.response?.data?.message || e.message || "Error finalizando pallet");
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
          toastError(e.response?.data?.message || e.message || "Error reabriendo pallet");
        }
      },
    });
  }

  /** Prompt para reabrir el pallet antes de ejecutar una acción que lo requiere */
  function confirmReopenThen(onConfirmed) {
    setConfirmModal({
      title: "Pallet finalizado",
      message: "Este pallet está cerrado. ¿Querés reabrirlo para poder hacer cambios?",
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
          toastError(e.response?.data?.message || e.message || "Error reabriendo pallet");
        }
      },
    });
  }

  function handleDeletePallet() {
    setConfirmModal({
      title: "Eliminar pallet",
      message: "¿Estás seguro de que querés eliminar este pallet? Esta acción no se puede deshacer.",
      confirmText: "Eliminar",
      cancelText: "Cancelar",
      confirmColor: "red",
      onConfirm: async () => {
        try {
          await apiDelete(`/pallets/${palletId}`);
          toastSuccess("Pallet eliminado correctamente");
          window.location.href = "/";
        } catch (e) {
          toastError(e.response?.data?.message || e.message || "Error eliminando pallet");
        }
      },
    });
  }

  // ── Early returns ────────────────────────────────────────────────────────
  if (loading) return <PageSpinner />;
  if (error || !pallet) {
    return (
      <div className="space-y-3">
        <BackButton to="/" />
        <div className="bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 text-red-800 dark:text-red-300 rounded-xl p-3 text-sm">
          {error || "No se pudo cargar el pallet."}
        </div>
      </div>
    );
  }

  const palletDone = pallet.status === "done";

  // onMigrate envuelto: si el pallet está cerrado, pide reabrirlo primero
  function handleMigrate(base) {
    if (palletDone) {
      confirmReopenThen(() => migrate.openMigrateModal(base));
    } else {
      migrate.openMigrateModal(base);
    }
  }

  // ── Render ───────────────────────────────────────────────────────────────
  return (
    <div className="space-y-7 pb-8">
      <BackButton to="/" />

      {/* Header */}
      <div className="space-y-2.5">
        <h1 className="font-mono font-bold text-2xl md:text-3xl text-gray-900 dark:text-white leading-tight">
          {pallet.code}
        </h1>
        <div className="flex items-center gap-3 flex-wrap">
          <StatusBadge
            label={palletDone ? "Completo" : "En proceso"}
            color={palletDone ? "green" : "blue"}
          />
          {pallet.created_at && (
            <span className="text-xs text-gray-400 dark:text-gray-500">
              Creado el {formatDate(pallet.created_at)}
            </span>
          )}
          <a
            href={`/pallet-view/${pallet.code}`}
            target="_blank"
            rel="noopener noreferrer"
            className="flex items-center gap-1.5 text-xs font-semibold px-2.5 py-1 rounded-full bg-gray-100 dark:bg-gray-700 text-gray-600 dark:text-gray-300 hover:bg-blue-50 dark:hover:bg-blue-900/30 hover:text-blue-600 dark:hover:text-blue-400 transition-colors"
          >
            <ExternalLinkIcon />
            Vista pública
          </a>
        </div>
      </div>

      {/* Acciones */}
      <section className="space-y-2.5">
        <p className={SEC_LABEL}>Acciones</p>
        <div className="space-y-2">
          {pallet.status === "open" && (
            <>
              <ActionItem
                icon={Icons.AssignOrder}
                iconBg="bg-blue-500"
                label="Asignar pedido"
                sublabel="Vincular un número de pedido a este pallet"
                onClick={() => setOpenAssign(true)}
              />
              <ActionItem
                icon={Icons.Import}
                iconBg="bg-indigo-500"
                label="Importar pedido"
                sublabel={activeOrder ? `Pedido activo: #${activeOrder.code}` : "Primero asigná un pedido"}
                onClick={() => setOpenImport(true)}
                disabled={!activeOrder}
              />
            </>
          )}

          <ActionItem icon={Icons.Gallery} iconBg="bg-amber-500" label="Galería" sublabel="Fotos del pallet completo" to={`/pallet/${palletId}/gallery`} />
          <ActionItem icon={Icons.History} iconBg="bg-gray-500" label="Historial" sublabel="Registro de actividad" to={`/pallet/${palletId}/history`} />

          <a
            href={`/pallet-view/${pallet.code}`}
            target="_blank"
            rel="noopener noreferrer"
            className="flex items-center gap-3 px-3 py-2.5 rounded-xl bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 hover:bg-gray-50 dark:hover:bg-gray-700/60 transition-colors"
          >
            <div className="w-8 h-8 rounded-lg flex items-center justify-center shrink-0 shadow-sm bg-blue-600">
              <svg className="w-4 h-4 text-white" fill="none" viewBox="0 0 24 24" strokeWidth={2} stroke="currentColor">
                <path strokeLinecap="round" strokeLinejoin="round" d="M2.036 12.322a1.012 1.012 0 0 1 0-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178Z" />
                <path strokeLinecap="round" strokeLinejoin="round" d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z" />
              </svg>
            </div>
            <div className="flex-1 text-left">
              <p className="text-sm font-semibold text-gray-800 dark:text-gray-200">Vista pública</p>
              <p className="text-xs text-gray-400 dark:text-gray-500">Ver como cliente vía QR</p>
            </div>
            <ExternalLinkIcon />
          </a>

          {palletDone && (
            <button onClick={handleReopen} className={BTN_GREEN}>Reabrir pallet</button>
          )}
          {canFinalize && !palletDone && (
            <button onClick={handleFinalize} className={BTN_BLUE}>✓ Finalizar pallet</button>
          )}

          <div className="pt-1 border-t border-gray-100 dark:border-gray-800 mt-1">
            <ActionItem
              icon={Icons.Trash}
              iconBg="bg-red-600"
              label="Eliminar pallet"
              sublabel="Elimina todas las bases, fotos y datos. Irreversible."
              onClick={handleDeletePallet}
              variant="danger"
            />
          </div>
        </div>
      </section>

      {/* Pedidos asociados */}
      <section className="space-y-3">
        <p className={SEC_LABEL}>Pedidos asociados</p>
        {orders.length === 0 ? (
          <p className="text-sm text-gray-500 dark:text-gray-400 py-2">No hay pedidos asignados.</p>
        ) : (
          <div className="flex flex-wrap gap-2">
            {orders.map((o) => (
              <OrderChip key={o.id} o={o} activeOrderId={activeOrderId} setActiveOrderId={setActiveOrderId} />
            ))}
          </div>
        )}
      </section>

      {/* Bases */}
      <section className="space-y-3">
        <div className="flex items-center justify-between">
          <p className={SEC_LABEL}>Bases ({bases.length})</p>
          {!palletDone && (
            <button
              onClick={() => { baseMutations.setShowNewBase(true); baseMutations.setBaseName(""); baseMutations.setBaseNote(""); }}
              className="text-sm font-semibold text-blue-600 dark:text-blue-400 hover:text-blue-800 dark:hover:text-blue-300 transition-colors"
            >
              + Agregar
            </button>
          )}
        </div>

        {bases.length === 0 ? (
          <div className="text-center py-10 space-y-4">
            <p className="text-sm text-gray-500 dark:text-gray-400">
              No hay bases. Creá una para organizar los productos.
            </p>
            {!palletDone && (
              <button
                onClick={() => { baseMutations.setShowNewBase(true); baseMutations.setBaseName(""); baseMutations.setBaseNote(""); }}
                className="px-5 py-2.5 rounded-xl bg-gray-900 dark:bg-white text-white dark:text-gray-900 text-sm font-semibold hover:bg-gray-700 dark:hover:bg-gray-100 transition-colors"
              >
                Agregar primera base
              </button>
            )}
          </div>
        ) : (
          <div className="space-y-3">
            {bases.map((base) => (
              <BaseCard
                key={base.id}
                base={base}
                palletId={palletId}
                palletDone={palletDone}
                onEdit={baseMutations.startEditBase}
                onDelete={baseMutations.onDeleteBase}
                onMigrate={handleMigrate}
              />
            ))}
          </div>
        )}
      </section>

      {/* Modal: Asignar pedido */}
      {openAssign && (
        <div className="fixed inset-0 z-50 bg-black/60 p-4 flex items-center justify-center">
          <div className="w-full max-w-md bg-white dark:bg-gray-800 rounded-2xl p-5 shadow-2xl">
            <div className="flex items-center justify-between mb-4">
              <p className="font-semibold text-gray-900 dark:text-white">Asignar pedido</p>
              <button onClick={() => setOpenAssign(false)} className="p-1.5 rounded-lg text-gray-400 hover:text-gray-700 hover:bg-gray-100 dark:hover:bg-gray-700 transition-colors">✕</button>
            </div>
            <form onSubmit={onAssignSubmit} className="space-y-3">
              <div>
                <label className="text-sm font-medium text-gray-700 dark:text-gray-300 block mb-1">Número de pedido</label>
                <input value={orderCode} onChange={(e) => setOrderCode(onlyDigits(e.target.value))} inputMode="numeric" placeholder="Ej: 123456" className={INPUT_CLS} />
              </div>
              <button className={BTN_PRI}>Asignar</button>
              <p className="text-xs text-gray-500 dark:text-gray-400">Si el pedido no existe, se crea automáticamente.</p>
            </form>
          </div>
        </div>
      )}

      {/* Modal: Importar pedido */}
      {openImport && (
        <div className="fixed inset-0 z-50 bg-black/60 p-4 flex items-center justify-center">
          <div className="w-full max-w-md bg-white dark:bg-gray-800 rounded-2xl p-5 shadow-2xl">
            <div className="flex items-center justify-between mb-2">
              <p className="font-semibold text-gray-900 dark:text-white">Importar pedido</p>
              <button onClick={() => setOpenImport(false)} className="p-1.5 rounded-lg text-gray-400 hover:text-gray-700 hover:bg-gray-100 dark:hover:bg-gray-700 transition-colors">✕</button>
            </div>
            <p className="text-xs text-gray-500 dark:text-gray-400 mb-4">
              Pedido activo: <span className="font-mono font-semibold">{activeOrder?.code || "—"}</span>
            </p>
            <form onSubmit={onImportSubmit} className="space-y-3">
              <textarea value={raw} onChange={(e) => setRaw(e.target.value)} rows={10} placeholder="Pegá acá el texto copiado de la tabla (TABs)." className="w-full border border-gray-300 dark:border-gray-600 rounded-xl p-3 font-mono text-xs bg-gray-50 dark:bg-gray-700 dark:text-white focus:outline-none focus:ring-2 focus:ring-gray-900 dark:focus:ring-white" />
              <button className={BTN_PRI}>Importar (reemplaza)</button>
              <p className="text-xs text-gray-500 dark:text-gray-400">Esto reemplaza los ítems del pedido importado.</p>
            </form>
          </div>
        </div>
      )}

      {/* Modal: Crear / Editar base */}
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

      {/* Modal: Migración */}
      <MigrateModal
        migrateModal={migrate.migrateModal}
        setMigrateModal={migrate.setMigrateModal}
        setMigrateQty={migrate.setMigrateQty}
        selectAllMigrate={migrate.selectAllMigrate}
        goToMigrateDest={migrate.goToMigrateDest}
        selectMigratePallet={migrate.selectMigratePallet}
        saveMigrate={migrate.saveMigrate}
      />

      {/* Modal: Confirmaciones */}
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
