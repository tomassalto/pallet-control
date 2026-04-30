import { useEffect, useMemo, useState } from "react";
import { Link, useParams } from "react-router-dom";
import { apiGet, apiPost, apiPatch, apiDelete } from "../api/client";
import { toastSuccess, toastError } from "../ui/toast";
import BackButton from "../ui/BackButton";
import ConfirmModal from "../ui/ConfirmModal";
import { PageSpinner } from "../ui/Spinner";
import { StatusBadge } from "../ui/EntityCard";
import { ActionItem, Icons } from "../ui/ActionList";

function onlyDigits(v) {
  return (v || "").replace(/\D/g, "");
}

function formatDate(iso) {
  return new Date(iso).toLocaleDateString(undefined, { dateStyle: "medium" });
}

// ── Iconos ──────────────────────────────────────────────────────────────────
function PencilIcon() {
  return (
    <svg
      className="w-4 h-4"
      fill="none"
      viewBox="0 0 24 24"
      strokeWidth={2}
      stroke="currentColor"
    >
      <path
        strokeLinecap="round"
        strokeLinejoin="round"
        d="M16.862 4.487l1.687-1.688a1.875 1.875 0 112.652 2.652L10.582 16.07a4.5 4.5 0 01-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 011.13-1.897l8.932-8.931z"
      />
    </svg>
  );
}
function TrashIcon() {
  return (
    <svg
      className="w-4 h-4"
      fill="none"
      viewBox="0 0 24 24"
      strokeWidth={2}
      stroke="currentColor"
    >
      <path
        strokeLinecap="round"
        strokeLinejoin="round"
        d="M14.74 9l-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 01-2.244 2.077H8.084a2.25 2.25 0 01-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 00-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 013.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 00-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 00-7.5 0"
      />
    </svg>
  );
}
function ExternalLinkIcon() {
  return (
    <svg
      className="w-3.5 h-3.5"
      fill="none"
      viewBox="0 0 24 24"
      strokeWidth={2.5}
      stroke="currentColor"
    >
      <path
        strokeLinecap="round"
        strokeLinejoin="round"
        d="M13.5 6H5.25A2.25 2.25 0 003 8.25v10.5A2.25 2.25 0 005.25 21h10.5A2.25 2.25 0 0018 18.75V10.5m-10.5 6L21 3m0 0h-5.25M21 3v5.25"
      />
    </svg>
  );
}

// ── Clases de botón reutilizables ──────────────────────────────────────────
const SEC_LABEL =
  "text-xs font-semibold uppercase tracking-wider text-gray-400 dark:text-gray-500";
const BTN_PRI =
  "w-full py-3 px-4 rounded-xl bg-gray-900 dark:bg-white text-white dark:text-gray-900 text-sm font-semibold hover:bg-gray-700 dark:hover:bg-gray-100 disabled:opacity-40 transition-colors";
const BTN_SEC =
  "flex items-center justify-center w-full py-3 px-4 rounded-xl bg-gray-100 dark:bg-gray-700/60 text-gray-700 dark:text-gray-200 text-sm font-semibold hover:bg-gray-200 dark:hover:bg-gray-700 disabled:opacity-40 transition-colors";
const BTN_GREEN =
  "w-full py-3 px-4 rounded-xl bg-green-600 text-white text-sm font-semibold hover:bg-green-700 disabled:opacity-40 transition-colors";
const BTN_BLUE =
  "w-full py-3 px-4 rounded-xl bg-blue-600 text-white text-sm font-semibold hover:bg-blue-700 disabled:opacity-40 transition-colors";
const BTN_RED =
  "w-full py-3 px-4 rounded-xl bg-red-600 text-white text-sm font-semibold hover:bg-red-700 disabled:opacity-40 transition-colors";
const INPUT_CLS =
  "w-full border border-gray-300 dark:border-gray-600 rounded-xl px-3 py-2.5 text-sm bg-gray-50 dark:bg-gray-700 dark:text-white focus:outline-none focus:ring-2 focus:ring-gray-900 dark:focus:ring-white transition-shadow";

export default function PalletDetail() {
  const { palletId } = useParams();

  const [loading, setLoading] = useState(true);
  const [pallet, setPallet] = useState(null);
  const [orders, setOrders] = useState([]);
  const [bases, setBases] = useState([]);
  const [error, setError] = useState("");
  const [canFinalize, setCanFinalize] = useState(false);
  const [confirmModal, setConfirmModal] = useState(null);

  const [activeOrderId, setActiveOrderId] = useState(null);
  const [openAssign, setOpenAssign] = useState(false);
  const [openImport, setOpenImport] = useState(false);
  const [showNewBase, setShowNewBase] = useState(false);
  const [editingBase, setEditingBase] = useState(null);
  const [baseName, setBaseName] = useState("");
  const [baseNote, setBaseNote] = useState("");

  const [migrateModal, setMigrateModal] = useState(null);
  const [orderCode, setOrderCode] = useState("");
  const [raw, setRaw] = useState("");

  const activeOrder = useMemo(
    () => orders.find((o) => o.id === activeOrderId) || null,
    [orders, activeOrderId],
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

  useEffect(() => {
    load();
  }, [palletId]); // eslint-disable-line

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
      setOrders(res.orders || []);
      if (res.order?.id) setActiveOrderId(res.order.id);
      setOrderCode("");
      setOpenAssign(false);
    } catch (e) {
      toastError(
        e.response?.data?.message || e.message || "No se pudo asignar",
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
        e.response?.data?.message || e.message || "No se pudo importar",
      );
    }
  }

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
      toastError(
        e.response?.data?.message || e.message || "No se pudo crear la base",
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
          "No se pudo actualizar la base",
      );
    }
  }

  async function onDeleteBase(base) {
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
          toastError(
            e.response?.data?.message ||
              e.message ||
              "No se pudo eliminar la base",
          );
        }
      },
    });
  }

  async function openMigrateModal(base) {
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

  function setMigrateQty(itemId, value, maxQty) {
    const v = Math.min(Math.max(0, parseInt(value, 10) || 0), maxQty);
    setMigrateModal((prev) =>
      prev
        ? { ...prev, quantities: { ...prev.quantities, [itemId]: v } }
        : null,
    );
  }

  function selectAllMigrate() {
    const quantities = {};
    (migrateModal?.sourceBase?.order_items || []).forEach((item) => {
      quantities[item.id] = item.pivot?.qty ?? 0;
    });
    setMigrateModal((prev) => (prev ? { ...prev, quantities } : null));
  }

  async function goToMigrateDest() {
    setMigrateModal((prev) =>
      prev ? { ...prev, step: "dest", loadingPallets: true } : null,
    );
    try {
      const data = await apiGet("/pallets?page=1");
      const list = (Array.isArray(data) ? data : data.data || []).filter(
        (p) => p.id !== parseInt(palletId, 10),
      );
      setMigrateModal((prev) =>
        prev ? { ...prev, loadingPallets: false, pallets: list } : null,
      );
    } catch {
      toastError("Error cargando pallets");
      setMigrateModal((prev) =>
        prev ? { ...prev, loadingPallets: false } : null,
      );
    }
  }

  async function selectMigratePallet(pId) {
    if (pId === null) {
      setMigrateModal((prev) =>
        prev
          ? {
              ...prev,
              selectedPalletId: null,
              selectedPalletBases: [],
              selectedBaseId: null,
            }
          : null,
      );
      return;
    }
    setMigrateModal((prev) =>
      prev
        ? {
            ...prev,
            selectedPalletId: pId,
            selectedPalletBases: [],
            selectedBaseId: null,
          }
        : null,
    );
    try {
      const data = await apiGet(`/pallets/${pId}`);
      setMigrateModal((prev) =>
        prev ? { ...prev, selectedPalletBases: data.bases || [] } : null,
      );
    } catch {
      toastError("Error cargando bases del pallet");
    }
  }

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
        },
      );
      toastSuccess(`Migrado correctamente → ${res.destination_pallet_code}`);
      setMigrateModal(null);
      load();
    } catch (e) {
      toastError(e?.response?.data?.message || "Error al migrar");
      setMigrateModal((prev) => (prev ? { ...prev, saving: false } : null));
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
            e.response?.data?.message ||
              e.message ||
              "Error finalizando pallet",
          );
        }
      },
    });
  }

  async function handleReopen() {
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

  async function handleDeletePallet() {
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

  // ── Loading / error ──────────────────────────────────────────────────────
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

  // ── Sub-componentes (usan closure sobre pallet, palletId, handlers) ────
  function OrderChip({ o }) {
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

  function BaseCard({ base }) {
    return (
      <div className="relative bg-white dark:bg-gray-800/60 border border-gray-200 dark:border-gray-700/50 rounded-2xl overflow-hidden shadow-sm hover:shadow-md transition-shadow">
        {/* Acento izquierdo */}
        <div
          className={`absolute left-0 top-0 bottom-0 w-1 ${palletDone ? "bg-green-500" : "bg-blue-500"}`}
        />

        <div className="pl-5 pr-4 py-4 space-y-3">
          {/* Header */}
          <div className="flex items-start justify-between gap-2">
            <div className="flex-1 min-w-0">
              <p className="font-semibold text-gray-900 dark:text-white leading-tight">
                {base.name || `Base #${base.id}`}
              </p>
              {base.note && (
                <p className="text-xs text-gray-500 dark:text-gray-400 mt-0.5 text-left">
                  {base.note}
                </p>
              )}
              {/* Badges de conteo */}
              <div className="flex gap-2 mt-2 flex-wrap">
                <span className="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-semibold bg-blue-50 text-blue-700 dark:bg-blue-900/20 dark:text-blue-300">
                  <span className="w-1.5 h-1.5 rounded-full bg-blue-500" />
                  {base.order_items?.length || 0} prod.
                </span>
                <span className="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-semibold bg-purple-50 text-purple-700 dark:bg-purple-900/20 dark:text-purple-300">
                  <span className="w-1.5 h-1.5 rounded-full bg-purple-500" />
                  {base.photos?.length || 0} fotos
                </span>
              </div>
            </div>

            {/* Editar / Eliminar — icon buttons */}
            {pallet.status === "open" && (
              <div className="flex gap-1 shrink-0">
                <button
                  onClick={() => startEditBase(base)}
                  className="p-2 rounded-lg text-gray-400 hover:text-gray-700 hover:bg-gray-100 dark:hover:text-gray-200 dark:hover:bg-gray-700 transition-colors"
                  title="Editar base"
                >
                  <PencilIcon />
                </button>
                <button
                  onClick={() => onDeleteBase(base)}
                  className="p-2 rounded-lg text-gray-400 hover:text-red-600 hover:bg-red-50 dark:hover:bg-red-900/20 transition-colors"
                  title="Eliminar base"
                >
                  <TrashIcon />
                </button>
              </div>
            )}
          </div>

          {/* ── Links de acción — lista estilo iOS ─────────────────── */}
          <div className="rounded-xl overflow-hidden border border-gray-100 dark:border-gray-700/40 divide-y divide-gray-100 dark:divide-gray-700/40">
            {/* Productos */}
            <Link
              to={`/pallet/${palletId}/base/${base.id}/products`}
              className="flex items-center gap-3 px-3 py-2.5 bg-white dark:bg-transparent hover:bg-gray-50 dark:hover:bg-gray-700/30 transition-colors"
            >
              <div className="w-8 h-8 rounded-lg bg-blue-500 flex items-center justify-center shrink-0 shadow-sm">
                <svg
                  className="w-4 h-4 text-white"
                  fill="none"
                  viewBox="0 0 24 24"
                  strokeWidth={2}
                  stroke="currentColor"
                >
                  <path
                    strokeLinecap="round"
                    strokeLinejoin="round"
                    d="M3.75 12h16.5m-16.5 3.75h16.5M3.75 19.5h16.5M5.625 4.5h12.75a1.875 1.875 0 0 1 0 3.75H5.625a1.875 1.875 0 0 1 0-3.75z"
                  />
                </svg>
              </div>
              <span className="text-sm font-semibold text-gray-800 dark:text-gray-200 flex-1">
                Productos
              </span>
              <svg
                className="w-4 h-4 text-gray-300 dark:text-gray-600"
                fill="none"
                viewBox="0 0 24 24"
                strokeWidth={2.5}
                stroke="currentColor"
              >
                <path
                  strokeLinecap="round"
                  strokeLinejoin="round"
                  d="M9 5l7 7-7 7"
                />
              </svg>
            </Link>

            {/* Galería */}
            <Link
              to={`/pallet/${palletId}/base/${base.id}/gallery`}
              className="flex items-center gap-3 px-3 py-2.5 bg-white dark:bg-transparent hover:bg-gray-50 dark:hover:bg-gray-700/30 transition-colors"
            >
              <div className="w-8 h-8 rounded-lg bg-purple-500 flex items-center justify-center shrink-0 shadow-sm">
                <svg
                  className="w-4 h-4 text-white"
                  fill="none"
                  viewBox="0 0 24 24"
                  strokeWidth={2}
                  stroke="currentColor"
                >
                  <path
                    strokeLinecap="round"
                    strokeLinejoin="round"
                    d="M2.25 15.75l5.159-5.159a2.25 2.25 0 0 1 3.182 0l5.159 5.159m-1.5-1.5 1.409-1.409a2.25 2.25 0 0 1 3.182 0l2.909 2.909m-18 3.75h16.5a1.5 1.5 0 0 0 1.5-1.5V6a1.5 1.5 0 0 0-1.5-1.5H3.75A1.5 1.5 0 0 0 2.25 6v12a1.5 1.5 0 0 0 1.5 1.5zm10.5-11.25h.008v.008h-.008V8.25zm.375 0a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0z"
                  />
                </svg>
              </div>
              <span className="text-sm font-semibold text-gray-800 dark:text-gray-200 flex-1">
                Galería
              </span>
              <svg
                className="w-4 h-4 text-gray-300 dark:text-gray-600"
                fill="none"
                viewBox="0 0 24 24"
                strokeWidth={2.5}
                stroke="currentColor"
              >
                <path
                  strokeLinecap="round"
                  strokeLinejoin="round"
                  d="M9 5l7 7-7 7"
                />
              </svg>
            </Link>

            {/* Migrar productos */}
            {(base.order_items?.length || 0) > 0 && (
              <button
                onClick={() =>
                  palletDone
                    ? confirmReopenThen(() => openMigrateModal(base))
                    : openMigrateModal(base)
                }
                className="w-full flex items-center gap-3 px-3 py-2.5 bg-white dark:bg-transparent hover:bg-amber-50 dark:hover:bg-amber-900/20 transition-colors"
              >
                <div
                  className={`w-8 h-8 rounded-lg flex items-center justify-center shrink-0 shadow-sm ${palletDone ? "bg-gray-400 dark:bg-gray-500" : "bg-amber-500"}`}
                >
                  <svg
                    className="w-4 h-4 text-white"
                    fill="none"
                    viewBox="0 0 24 24"
                    strokeWidth={2}
                    stroke="currentColor"
                  >
                    <path
                      strokeLinecap="round"
                      strokeLinejoin="round"
                      d="M7.5 21 3 16.5m0 0L7.5 12M3 16.5h13.5m0-13.5L21 7.5m0 0L16.5 12M21 7.5H7.5"
                    />
                  </svg>
                </div>
                <span
                  className={`text-sm font-semibold flex-1 text-left ${palletDone ? "text-gray-500 dark:text-gray-400" : "text-amber-800 dark:text-amber-300"}`}
                >
                  {palletDone ? "🔒 Migrar productos" : "Migrar productos"}
                </span>
                <svg
                  className="w-4 h-4 text-gray-300 dark:text-gray-600"
                  fill="none"
                  viewBox="0 0 24 24"
                  strokeWidth={2.5}
                  stroke="currentColor"
                >
                  <path
                    strokeLinecap="round"
                    strokeLinejoin="round"
                    d="M9 5l7 7-7 7"
                  />
                </svg>
              </button>
            )}
          </div>
        </div>
      </div>
    );
  }

  // ── RENDER PRINCIPAL ─────────────────────────────────────────────────────
  return (
    <div className="space-y-7 pb-8">
      <BackButton to="/" />

      {/* ── Header ─────────────────────────────────────────────────────── */}
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

      {/* ── Acciones ───────────────────────────────────────────────────── */}
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
                sublabel={
                  activeOrder
                    ? `Pedido activo: #${activeOrder.code}`
                    : "Primero asigná un pedido"
                }
                onClick={() => setOpenImport(true)}
                disabled={!activeOrder}
              />
            </>
          )}

          <ActionItem
            icon={Icons.Gallery}
            iconBg="bg-amber-500"
            label="Galería"
            sublabel="Fotos del pallet completo"
            to={`/pallet/${palletId}/gallery`}
          />
          <ActionItem
            icon={Icons.History}
            iconBg="bg-gray-500"
            label="Historial"
            sublabel="Registro de actividad"
            to={`/pallet/${palletId}/history`}
          />
          <a
            href={`/pallet-view/${pallet.code}`}
            target="_blank"
            rel="noopener noreferrer"
            className="flex items-center gap-3 px-3 py-2.5 rounded-xl bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 hover:bg-gray-50 dark:hover:bg-gray-700/60 transition-colors"
          >
            <div className="w-8 h-8 rounded-lg flex items-center justify-center shrink-0 shadow-sm bg-blue-600">
              <svg
                className="w-4 h-4 text-white"
                fill="none"
                viewBox="0 0 24 24"
                strokeWidth={2}
                stroke="currentColor"
              >
                <path
                  strokeLinecap="round"
                  strokeLinejoin="round"
                  d="M2.036 12.322a1.012 1.012 0 0 1 0-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178Z"
                />
                <path
                  strokeLinecap="round"
                  strokeLinejoin="round"
                  d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z"
                />
              </svg>
            </div>
            <div className="flex-1 text-left">
              <p className="text-sm font-semibold text-gray-800 dark:text-gray-200">
                Vista pública
              </p>
              <p className="text-xs text-gray-400 dark:text-gray-500">
                Ver como cliente vía QR
              </p>
            </div>
            <ExternalLinkIcon />
          </a>

          {pallet.status === "done" && (
            <button onClick={handleReopen} className={BTN_GREEN}>
              Reabrir pallet
            </button>
          )}

          {canFinalize && pallet.status === "open" && (
            <button onClick={handleFinalize} className={BTN_BLUE}>
              ✓ Finalizar pallet
            </button>
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

      {/* ── Pedidos asociados ───────────────────────────────────────────── */}
      <section className="space-y-3">
        <p className={SEC_LABEL}>Pedidos asociados</p>
        {orders.length === 0 ? (
          <p className="text-sm text-gray-500 dark:text-gray-400 py-2">
            No hay pedidos asignados.
          </p>
        ) : (
          <div className="flex flex-wrap gap-2">
            {orders.map((o) => (
              <OrderChip key={o.id} o={o} />
            ))}
          </div>
        )}
      </section>

      {/* ── Bases ──────────────────────────────────────────────────────── */}
      <section className="space-y-3">
        <div className="flex items-center justify-between">
          <p className={SEC_LABEL}>Bases ({bases.length})</p>
          {pallet.status === "open" && (
            <button
              onClick={() => {
                setShowNewBase(true);
                setBaseName("");
                setBaseNote("");
              }}
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
            {pallet.status === "open" && (
              <button
                onClick={() => {
                  setShowNewBase(true);
                  setBaseName("");
                  setBaseNote("");
                }}
                className="px-5 py-2.5 rounded-xl bg-gray-900 dark:bg-white text-white dark:text-gray-900 text-sm font-semibold hover:bg-gray-700 dark:hover:bg-gray-100 transition-colors"
              >
                Agregar primera base
              </button>
            )}
          </div>
        ) : (
          <div className="space-y-3">
            {bases.map((base) => (
              <BaseCard key={base.id} base={base} />
            ))}
          </div>
        )}
      </section>

      {/* ── Modal: Asignar pedido ───────────────────────────────────────── */}
      {openAssign && (
        <div className="fixed inset-0 z-50 bg-black/60 p-4 flex items-center justify-center">
          <div className="w-full max-w-md bg-white dark:bg-gray-800 rounded-2xl p-5 shadow-2xl">
            <div className="flex items-center justify-between mb-4">
              <p className="font-semibold text-gray-900 dark:text-white">
                Asignar pedido
              </p>
              <button
                onClick={() => setOpenAssign(false)}
                className="p-1.5 rounded-lg text-gray-400 hover:text-gray-700 hover:bg-gray-100 dark:hover:bg-gray-700 transition-colors"
              >
                ✕
              </button>
            </div>
            <form onSubmit={onAssignSubmit} className="space-y-3">
              <div>
                <label className="text-sm font-medium text-gray-700 dark:text-gray-300 block mb-1">
                  Número de pedido
                </label>
                <input
                  value={orderCode}
                  onChange={(e) => setOrderCode(onlyDigits(e.target.value))}
                  inputMode="numeric"
                  placeholder="Ej: 123456"
                  className={INPUT_CLS}
                />
              </div>
              <button className={BTN_PRI}>Asignar</button>
              <p className="text-xs text-gray-500 dark:text-gray-400">
                Si el pedido no existe, se crea automáticamente.
              </p>
            </form>
          </div>
        </div>
      )}

      {/* ── Modal: Importar pedido ─────────────────────────────────────── */}
      {openImport && (
        <div className="fixed inset-0 z-50 bg-black/60 p-4 flex items-center justify-center">
          <div className="w-full max-w-md bg-white dark:bg-gray-800 rounded-2xl p-5 shadow-2xl">
            <div className="flex items-center justify-between mb-2">
              <p className="font-semibold text-gray-900 dark:text-white">
                Importar pedido
              </p>
              <button
                onClick={() => setOpenImport(false)}
                className="p-1.5 rounded-lg text-gray-400 hover:text-gray-700 hover:bg-gray-100 dark:hover:bg-gray-700 transition-colors"
              >
                ✕
              </button>
            </div>
            <p className="text-xs text-gray-500 dark:text-gray-400 mb-4">
              Pedido activo:{" "}
              <span className="font-mono font-semibold">
                {activeOrder?.code || "—"}
              </span>
            </p>
            <form onSubmit={onImportSubmit} className="space-y-3">
              <textarea
                value={raw}
                onChange={(e) => setRaw(e.target.value)}
                rows={10}
                placeholder="Pegá acá el texto copiado de la tabla (TABs)."
                className="w-full border border-gray-300 dark:border-gray-600 rounded-xl p-3 font-mono text-xs bg-gray-50 dark:bg-gray-700 dark:text-white focus:outline-none focus:ring-2 focus:ring-gray-900 dark:focus:ring-white"
              />
              <button className={BTN_PRI}>Importar (reemplaza)</button>
              <p className="text-xs text-gray-500 dark:text-gray-400">
                Esto reemplaza los ítems del pedido importado.
              </p>
            </form>
          </div>
        </div>
      )}

      {/* ── Modal: Crear / Editar base ─────────────────────────────────── */}
      {(showNewBase || editingBase) && (
        <div className="fixed inset-0 z-50 bg-black/60 p-4 flex items-center justify-center">
          <div className="w-full max-w-md bg-white dark:bg-gray-800 rounded-2xl p-5 shadow-2xl space-y-4">
            <div className="flex items-center justify-between">
              <p className="font-semibold text-gray-900 dark:text-white">
                {editingBase ? "Editar base" : "Nueva base"}
              </p>
              <button
                onClick={() => {
                  setShowNewBase(false);
                  setEditingBase(null);
                  setBaseName("");
                  setBaseNote("");
                }}
                className="p-1.5 rounded-lg text-gray-400 hover:text-gray-700 hover:bg-gray-100 dark:hover:bg-gray-700 transition-colors"
              >
                ✕
              </button>
            </div>
            <div>
              <label className="text-sm font-medium text-gray-700 dark:text-gray-300 block mb-1">
                Nombre (opcional)
              </label>
              <input
                value={baseName}
                onChange={(e) => setBaseName(e.target.value)}
                placeholder="Ej: Base 1, Base A"
                className={INPUT_CLS}
              />
            </div>
            <div>
              <label className="text-sm font-medium text-gray-700 dark:text-gray-300 block mb-1">
                Nota (opcional)
              </label>
              <textarea
                value={baseNote}
                onChange={(e) => setBaseNote(e.target.value)}
                placeholder="Notas adicionales"
                rows={2}
                className={INPUT_CLS}
              />
            </div>
            <button
              onClick={() =>
                editingBase ? onUpdateBase(editingBase) : onCreateBase()
              }
              className={BTN_PRI}
            >
              {editingBase ? "Actualizar" : "Crear base"}
            </button>
          </div>
        </div>
      )}

      {/* ── Modal: Migración ───────────────────────────────────────────── */}
      {migrateModal && (
        <div className="fixed inset-0 z-50 bg-black/60 flex items-end sm:items-center justify-center">
          <div className="w-full sm:max-w-lg bg-white dark:bg-gray-800 rounded-t-3xl sm:rounded-2xl flex flex-col max-h-[90vh]">
            <div className="flex items-center justify-between px-4 pt-5 pb-3 border-b border-gray-200 dark:border-gray-700 shrink-0">
              <div>
                <p className="font-bold text-base text-gray-900 dark:text-white">
                  🔀 Migrar desde{" "}
                  {migrateModal.sourceBase.name ||
                    `Base #${migrateModal.sourceBase.id}`}
                </p>
                <p className="text-xs text-gray-500 dark:text-gray-400 mt-0.5">
                  {migrateModal.step === "items"
                    ? "¿Qué productos y cuántas unidades?"
                    : "¿A dónde los llevás?"}
                </p>
              </div>
              <button
                onClick={() => setMigrateModal(null)}
                className="p-1.5 rounded-lg text-gray-400 hover:text-gray-700 hover:bg-gray-100 dark:hover:bg-gray-700 transition-colors text-lg"
              >
                ✕
              </button>
            </div>

            {migrateModal.step === "items" && (
              <>
                <div className="flex-1 overflow-y-auto divide-y divide-gray-100 dark:divide-gray-700">
                  <div className="px-4 py-2 bg-gray-50 dark:bg-gray-700/50 flex items-center justify-between">
                    <span className="text-xs text-gray-500 dark:text-gray-400">
                      {
                        Object.values(migrateModal.quantities).filter(
                          (q) => q > 0,
                        ).length
                      }{" "}
                      producto(s) seleccionado(s)
                    </span>
                    <button
                      onClick={selectAllMigrate}
                      className="text-xs text-blue-600 dark:text-blue-400 font-semibold hover:underline"
                    >
                      Seleccionar todo
                    </button>
                  </div>
                  {(migrateModal.sourceBase.order_items || []).map((item) => {
                    const maxQty = item.pivot?.qty ?? 0;
                    const cur = migrateModal.quantities[item.id] ?? 0;
                    return (
                      <div
                        key={item.id}
                        className={[
                          "flex items-center gap-3 px-4 py-3 transition-colors",
                          cur > 0 ? "border-l-4 border-l-amber-500" : "",
                        ].join(" ")}
                      >
                        <div className="flex-1 min-w-0">
                          <p className="text-sm font-medium leading-snug text-gray-900 dark:text-white">
                            {item.description}
                          </p>
                          <p className="text-xs font-mono text-gray-400 mt-0.5">
                            {item.ean}
                          </p>
                          <p className="text-xs text-gray-500 mt-0.5">
                            En base:{" "}
                            <span className="font-semibold">{maxQty}</span> u.
                          </p>
                        </div>
                        <div className="flex items-center gap-1.5 shrink-0">
                          <button
                            onClick={() =>
                              setMigrateQty(item.id, cur - 1, maxQty)
                            }
                            disabled={cur === 0}
                            className="w-8 h-8 rounded-full border border-gray-300 dark:border-gray-600 flex items-center justify-center text-gray-600 dark:text-gray-300 disabled:opacity-25 text-lg leading-none select-none"
                          >
                            −
                          </button>
                          <input
                            type="text"
                            inputMode="numeric"
                            value={cur === 0 ? "" : cur}
                            placeholder="0"
                            onChange={(e) =>
                              setMigrateQty(item.id, e.target.value, maxQty)
                            }
                            onFocus={(e) => e.target.select()}
                            className="w-12 text-center text-sm font-bold border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white rounded-lg py-1 focus:outline-none focus:ring-2 focus:ring-amber-300"
                          />
                          <button
                            onClick={() =>
                              setMigrateQty(item.id, cur + 1, maxQty)
                            }
                            disabled={cur >= maxQty}
                            className="w-8 h-8 rounded-full bg-amber-500 text-white flex items-center justify-center disabled:opacity-25 text-lg leading-none select-none"
                          >
                            +
                          </button>
                        </div>
                      </div>
                    );
                  })}
                </div>
                <div className="p-4 border-t border-gray-200 dark:border-gray-700 shrink-0">
                  <button
                    onClick={goToMigrateDest}
                    disabled={Object.values(migrateModal.quantities).every(
                      (q) => q === 0,
                    )}
                    className="w-full py-3.5 rounded-2xl bg-gray-900 dark:bg-white text-white dark:text-gray-900 font-bold text-sm disabled:opacity-40"
                  >
                    Seleccionar destino →
                  </button>
                </div>
              </>
            )}

            {migrateModal.step === "dest" && (
              <>
                <div className="px-4 py-2 border-b border-gray-200 dark:border-gray-700 shrink-0">
                  <button
                    onClick={() =>
                      setMigrateModal((prev) =>
                        prev
                          ? {
                              ...prev,
                              step: "items",
                              selectedPalletId: null,
                              selectedBaseId: null,
                            }
                          : null,
                      )
                    }
                    className="text-sm text-blue-600 dark:text-blue-400 font-semibold hover:underline"
                  >
                    ← Volver
                  </button>
                </div>
                <div className="flex-1 overflow-y-auto p-4 space-y-4">
                  {migrateModal.loadingPallets ? (
                    <PageSpinner />
                  ) : (
                    <>
                      <div>
                        <p className="text-xs font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-2">
                          Pallet destino
                        </p>
                        <div className="space-y-2">
                          <button
                            onClick={() => selectMigratePallet(null)}
                            className={[
                              "w-full text-left rounded-xl border px-4 py-3 text-sm transition-colors",
                              migrateModal.selectedPalletId === null
                                ? "border-amber-400 bg-amber-50 dark:bg-amber-900/20 font-semibold"
                                : "border-gray-200 dark:border-gray-700 hover:bg-gray-50 dark:hover:bg-gray-700",
                            ].join(" ")}
                          >
                            <span className="font-medium text-gray-900 dark:text-white">
                              🆕 Crear pallet nuevo
                            </span>
                            <span className="block text-xs text-gray-400 mt-0.5">
                              Se generará con código automático
                            </span>
                          </button>
                          {migrateModal.pallets.map((p) => (
                            <button
                              key={p.id}
                              onClick={() => selectMigratePallet(p.id)}
                              className={[
                                "w-full text-left rounded-xl border px-4 py-3 text-sm transition-colors",
                                migrateModal.selectedPalletId === p.id
                                  ? "border-amber-400 bg-amber-50 dark:bg-amber-900/20 font-semibold"
                                  : "border-gray-200 dark:border-gray-700 hover:bg-gray-50 dark:hover:bg-gray-700",
                              ].join(" ")}
                            >
                              <span className="font-mono font-semibold text-gray-900 dark:text-white">
                                {p.code}
                              </span>
                              <span
                                className={`ml-2 text-xs px-1.5 py-0.5 rounded-full ${p.status === "done" ? "bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-300" : "bg-amber-100 text-amber-700 dark:bg-amber-900/30 dark:text-amber-300"}`}
                              >
                                {p.status === "done" ? "Finalizado" : "Abierto"}
                              </span>
                            </button>
                          ))}
                        </div>
                      </div>
                      {migrateModal.selectedPalletId !== null && (
                        <div>
                          <p className="text-xs font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-2">
                            Base destino
                          </p>
                          <div className="space-y-2">
                            <button
                              onClick={() =>
                                setMigrateModal((prev) =>
                                  prev
                                    ? { ...prev, selectedBaseId: null }
                                    : null,
                                )
                              }
                              className={[
                                "w-full text-left rounded-xl border px-4 py-3 text-sm transition-colors",
                                migrateModal.selectedBaseId === null
                                  ? "border-amber-400 bg-amber-50 dark:bg-amber-900/20 font-semibold"
                                  : "border-gray-200 dark:border-gray-700 hover:bg-gray-50 dark:hover:bg-gray-700",
                              ].join(" ")}
                            >
                              <span className="text-gray-900 dark:text-white">
                                🆕 Crear base nueva
                              </span>
                            </button>
                            {migrateModal.selectedPalletBases.map((b) => (
                              <button
                                key={b.id}
                                onClick={() =>
                                  setMigrateModal((prev) =>
                                    prev
                                      ? { ...prev, selectedBaseId: b.id }
                                      : null,
                                  )
                                }
                                className={[
                                  "w-full text-left rounded-xl border px-4 py-3 text-sm transition-colors",
                                  migrateModal.selectedBaseId === b.id
                                    ? "border-amber-400 bg-amber-50 dark:bg-amber-900/20 font-semibold"
                                    : "border-gray-200 dark:border-gray-700 hover:bg-gray-50 dark:hover:bg-gray-700",
                                ].join(" ")}
                              >
                                <span className="font-medium text-gray-900 dark:text-white">
                                  {b.name || `Base #${b.id}`}
                                </span>
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
                <div className="p-4 border-t border-gray-200 dark:border-gray-700 shrink-0">
                  {(() => {
                    const count = Object.values(migrateModal.quantities).filter(
                      (q) => q > 0,
                    ).length;
                    const totalUnits = Object.values(
                      migrateModal.quantities,
                    ).reduce((s, q) => s + q, 0);
                    return (
                      <button
                        onClick={saveMigrate}
                        disabled={migrateModal.saving}
                        className="w-full py-3.5 rounded-2xl bg-amber-500 hover:bg-amber-600 text-white font-bold text-sm disabled:opacity-40 transition-colors"
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
