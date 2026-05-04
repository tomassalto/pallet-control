import { useEffect, useMemo, useRef, useState } from "react";
import { Link, useParams } from "react-router-dom";
import { apiGet, apiPost, apiPatch, apiDelete } from "../api/client";
import { toastSuccess, toastError } from "../ui/toast";
import BackButton from "../ui/BackButton";
import Title from "../ui/Title";
import Accordion from "../ui/Accordion";
import PhotoViewer from "../ui/PhotoViewer";
import QRModal from "../ui/QRModal";
import OrganizeModal from "../Components/OrganizeModal";
import QtyConflictModal from "../Components/QtyConflictModal";
import { PageSpinner } from "../ui/Spinner";
import { StatusBadge } from "../ui/EntityCard";
import { ActionItem, Icons } from "../ui/ActionList";

function onlyDigits(v) {
  return (v || "").replace(/\D/g, "");
}

function textClass(status) {
  if (status === "removed") return "line-through text-red-900";
  return "";
}

// Componente para mostrar un item
function ItemCard({
  item: it,
  onSelect,
  borderColor,
  bgColor,
  showDoneQty = false,
}) {
  const [imgErr, setImgErr] = useState(false);
  const shortEan = (it.ean && String(it.ean).slice(-4).padStart(4, "0")) || "—";

  return (
    <button
      onClick={onSelect}
      className={`w-full text-left rounded-xl border ${borderColor} ${bgColor} dark:bg-gray-800/70 dark:border-gray-700 text-gray-900 dark:text-gray-100 px-3 py-3 text-sm active:scale-[0.99]`}
    >
      <div className="flex items-center justify-between gap-3">
        {/* Imagen del producto */}
        <div className="w-14 h-14 shrink-0 rounded-xl overflow-hidden bg-gray-100 dark:bg-gray-700 flex items-center justify-center border border-gray-200 dark:border-gray-600">
          {it.image_url && !imgErr ? (
            <img
              src={it.image_url}
              alt={it.description}
              className="w-full h-full object-contain"
              onError={() => setImgErr(true)}
            />
          ) : (
            <span className="text-2xl select-none">📦</span>
          )}
        </div>

        <div className="flex flex-col gap-1 flex-1 min-w-0">
          <div className="text-[10px] text-gray-500 dark:text-gray-400">
            EAN
          </div>
          <div className="font-mono font-semibold text-lg">{shortEan}</div>

          <div className={`text-sm ${textClass(it.status)} wrap-break-word`}>
            {it.description}
          </div>

          {/* Badges: precio, descuento MP, controlado */}
          <div className="flex flex-wrap gap-1 mt-0.5">
            {it.price != null && (
              <span className="text-[10px] text-gray-500 dark:text-gray-400 font-mono">
                $
                {Number(it.price).toLocaleString("es-AR", {
                  minimumFractionDigits: 2,
                })}
              </span>
            )}
            {it.desc_medio_pago != null && (
              <span className="text-[10px] bg-blue-100 dark:bg-blue-900/30 text-blue-700 dark:text-blue-300 px-1.5 py-0.5 rounded font-medium">
                💳 -
                {Number(it.desc_medio_pago).toLocaleString("es-AR", {
                  minimumFractionDigits: 2,
                })}
              </span>
            )}
            {it.is_controlled && (
              <span className="text-[10px] bg-amber-100 dark:bg-amber-900/30 text-amber-700 dark:text-amber-300 px-1.5 py-0.5 rounded font-medium">
                Controlado
              </span>
            )}
          </div>

          {/* Ubicaciones del producto - solo mostrar si está marcado como "listo" */}
          {it.status === "done" && it.locations && it.locations.length > 0 && (
            <div className="mt-2 space-y-1">
              <div className="text-[10px] text-gray-500 dark:text-gray-400">
                Ubicación:
              </div>
              {it.locations.map((loc, idx) => (
                <div
                  key={idx}
                  className="text-xs text-gray-600 dark:text-gray-300 bg-gray-100 dark:bg-gray-700/60 rounded px-2 py-1"
                >
                  <Link
                    to={`/pallet/${loc.pallet_id}`}
                    onClick={(e) => e.stopPropagation()}
                    className="font-semibold underline"
                  >
                    {loc.pallet_code}
                  </Link>
                  {loc.base_name && (
                    <>
                      {" / "}
                      <span className="font-medium">{loc.base_name}</span>
                    </>
                  )}
                  {" - "}
                  <span className="font-mono">{loc.qty} u.</span>
                </div>
              ))}
            </div>
          )}
        </div>

        <div className="flex flex-col items-center shrink-0 gap-2">
          {showDoneQty ? (
            <>
              <div className="text-xs text-gray-500">Esperado</div>
              <div className="text-base font-semibold">{it.qty || 0}</div>
              {it.done_qty !== undefined && (
                <>
                  <div className="text-xs text-gray-500">Encontrado</div>
                  <div className="text-base font-semibold">
                    {it.done_qty || 0}
                  </div>
                </>
              )}
            </>
          ) : (
            <>
              <div className="text-xs text-gray-500">Cant.</div>
              <div className="text-base font-semibold">{it.qty || 0}</div>
            </>
          )}
        </div>
      </div>
    </button>
  );
}

// ── Estilos de botón compartidos ─────────────────────────────────────────
const SEC_LABEL =
  "text-xs font-semibold uppercase tracking-wider text-gray-400 dark:text-gray-500";
const BTN_SEC =
  "flex items-center justify-center w-full py-3 px-4 rounded-xl bg-gray-100 dark:bg-gray-700/60 text-gray-700 dark:text-gray-200 text-sm font-semibold hover:bg-gray-200 dark:hover:bg-gray-700 disabled:opacity-40 transition-colors";
const BTN_PRI =
  "w-full py-3 px-4 rounded-xl bg-gray-900 dark:bg-white text-white dark:text-gray-900 text-sm font-semibold hover:bg-gray-700 dark:hover:bg-gray-100 disabled:opacity-40 transition-colors";
const BTN_GREEN =
  "w-full py-3 px-4 rounded-xl bg-green-600 text-white text-sm font-semibold hover:bg-green-700 disabled:opacity-40 transition-colors";

function orderStatusBadge(status) {
  if (status === "done") return { label: "Completo", color: "green" };
  if (status === "paused") return { label: "Pausado", color: "amber" };
  return { label: "En proceso", color: "blue" };
}

export default function OrderDetail() {
  const { orderId } = useParams();

  const [loading, setLoading] = useState(true);
  const [order, setOrder] = useState(null);
  const [pallets, setPallets] = useState([]);
  const [items, setItems] = useState([]);
  const [tickets, setTickets] = useState([]);
  const [error, setError] = useState("");

  const [tab, setTab] = useState("pending"); // pending | done | removed

  // add manual
  const [eanOrLast4, setEanOrLast4] = useState("");
  const [qty, setQty] = useState("1");
  const [saving, setSaving] = useState(false);
  const [actionItem, setActionItem] = useState(null);
  const [actionQty, setActionQty] = useState("");

  // asociar pallet
  const [openAttachPallet, setOpenAttachPallet] = useState(false);
  const [availablePallets, setAvailablePallets] = useState([]);
  const [loadingPallets, setLoadingPallets] = useState(false);

  // modal agregar producto
  const [openAddProduct, setOpenAddProduct] = useState(false);

  // modal conflicto de cantidad vs. unidades organizadas
  // null | { item, newStatus, newQty, totalOrganized, deficit, keepQtys:{[base_id]:qty}, saving }
  const [qtyConflict, setQtyConflict] = useState(null);

  // modal reabrir pallet para organizar (cuando el pallet está done)
  // null | { pallet, reopening }
  const [reopenModal, setReopenModal] = useState(null);

  // desvincular pallet
  const [detachingPallet, setDetachingPallet] = useState(null);
  const [confirmDetachPallet, setConfirmDetachPallet] = useState(null);

  // QR modal
  const [showQR, setShowQR] = useState(false);

  // Modal "Organizar en pallet"
  const [organizeModal, setOrganizeModal] = useState(null);
  // null | { palletId, palletCode, step:'base'|'products', bases:[], selectedBase:null, quantities:{}, loading:bool, saving:bool }

  // finalizar pedido
  const [canFinalize, setCanFinalize] = useState(false);
  const [finalizing, setFinalizing] = useState(false);

  // tickets
  const [openAddTicket, setOpenAddTicket] = useState(false);

  async function load() {
    setError("");
    setLoading(true);
    try {
      const data = await apiGet(`/orders/${orderId}`);
      setOrder(data.order);
      setPallets(data.pallets || []);
      setItems(data.items || []);
      setTickets(data.order?.tickets || []);

      // Verificar si se puede finalizar el pedido
      if (data.order?.status === "open") {
        try {
          const canFinalizeData = await apiGet(
            `/orders/${orderId}/can-finalize`,
          );
          setCanFinalize(canFinalizeData.can_finalize || false);
        } catch {
          setCanFinalize(false);
        }
      } else {
        setCanFinalize(false);
      }
    } catch (e) {
      setError(e.message || "Error cargando pedido");
    } finally {
      setLoading(false);
    }
  }

  async function loadAvailablePallets() {
    setLoadingPallets(true);
    try {
      const data = await apiGet(`/pallets?page=1`);
      const allPallets = Array.isArray(data) ? data : data.data || [];
      // Filtrar pallets que ya están asociados
      const palletIds = new Set(pallets.map((p) => p.id));
      const filtered = allPallets.filter((p) => !palletIds.has(p.id));
      setAvailablePallets(filtered);
    } catch (e) {
      toastError(e?.message || "Error cargando pallets");
    } finally {
      setLoadingPallets(false);
    }
  }

  async function onAttachPallet(palletId) {
    try {
      await apiPost(`/orders/${orderId}/attach-pallet`, {
        pallet_id: palletId,
      });
      toastSuccess("Pedido asociado al pallet");
      setOpenAttachPallet(false);
      load(); // Recargar para actualizar la lista de pallets
    } catch (e) {
      toastError(
        e?.response?.data?.message || e?.message || "Error asociando pallet",
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

    setFinalizing(true);
    try {
      await apiPost(`/orders/${orderId}/finalize`);
      toastSuccess("Pedido finalizado correctamente");
      load(); // Recargar para actualizar el estado del pedido
    } catch (e) {
      toastError(
        e?.response?.data?.message || e?.message || "Error al finalizar pedido",
      );
    } finally {
      setFinalizing(false);
    }
  }

  useEffect(() => {
    load();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [orderId]);

  const filtered = useMemo(
    () => items.filter((it) => it.status === tab),
    [items, tab],
  );

  // Items a mostrar en el modal "Organizar en pallet" — todos los que tienen qty > 0
  const modalItems = useMemo(
    () =>
      items
        .filter((it) => (it.qty ?? 0) > 0)
        .sort((a, b) => a.description.localeCompare(b.description)),
    [items],
  );

  // Categorizar productos para pedidos finalizados
  const categorizedItems = useMemo(() => {
    if (order?.status !== "done") return null;

    const doneQty = (it) => it.done_qty || 0;
    const qty = (it) => it.qty || 0;

    // Productos completados/de más: marcados como "done" y alcanzaron o superaron la cantidad
    const completed = items.filter(
      (it) => it.status === "done" && doneQty(it) >= qty(it) && doneQty(it) > 0,
    );

    // Productos incompletos: marcados como "done" pero no alcanzaron la cantidad (y tienen alguna cantidad encontrada)
    const incomplete = items.filter(
      (it) => it.status === "done" && doneQty(it) < qty(it) && doneQty(it) > 0,
    );

    // Productos no encontrados:
    // - Removidos explícitamente (status === 'removed')
    // - Marcados como "done" pero con 0 unidades encontradas (no se encontró nada)
    // - Pendientes con 0 unidades encontradas
    const notFound = items.filter(
      (it) =>
        it.status === "removed" ||
        (it.status === "done" && doneQty(it) === 0) ||
        (it.status === "pending" && doneQty(it) === 0),
    );

    return { completed, incomplete, notFound };
  }, [items, order?.status]);

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

  // ── Lógica de conflicto qty vs. organizadas ────────────────────────────────

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

  /** Confirma el conflicto: ajusta cada base afectada y luego guarda el item */
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
      // Actualizar el item
      await doApplyAction(item, newStatus, newQty);
      setQtyConflict(null);
      load(); // refrescar locations actualizadas
    } catch (e) {
      toastError(e?.response?.data?.message || e.message || "Error al guardar");
      setQtyConflict((prev) => prev && { ...prev, saving: false });
    }
  }

  async function onAddManual(e) {
    e.preventDefault();
    setSaving(true);

    const raw = onlyDigits(eanOrLast4);
    const q = parseInt(onlyDigits(qty), 10) || 1;

    try {
      if (!raw) {
        toastError("Ingresá EAN completo o últimos 4.");
        return;
      }

      const body =
        raw.length <= 4
          ? { last4: raw.padStart(4, "0"), qty: q }
          : { ean: raw, qty: q };

      const created = await apiPost(`/orders/${orderId}/items`, body);
      setItems((prev) =>
        [created, ...prev].sort((a, b) =>
          a.description.localeCompare(b.description),
        ),
      );
      setEanOrLast4("");
      setQty("1");
      setOpenAddProduct(false);
      toastSuccess("Producto agregado al pedido");
    } catch (e) {
      // si last4 ambiguo, el backend te devuelve candidates
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

  // ── Organizar en pallet ───────────────────────────────────────────────────

  /** Reabre el pallet y luego abre el modal de organización */
  async function reopenAndOrganize() {
    if (!reopenModal) return;
    const p = reopenModal.pallet;
    setReopenModal((prev) => prev && { ...prev, reopening: true });
    try {
      await apiPost(`/pallets/${p.id}/reopen`);
      // Actualización optimista: marcar el pallet como open en el estado local
      setPallets((prev) =>
        prev.map((pl) => (pl.id === p.id ? { ...pl, status: "open" } : pl)),
      );
      toastSuccess("Pallet reabierto");
      setReopenModal(null);
      openOrganizeModal({ ...p, status: "open" });
      load(); // refrescar en segundo plano
    } catch (e) {
      toastError(
        e?.response?.data?.message || e.message || "Error reabriendo pallet",
      );
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
          ? {
              ...prev,
              bases,
              loading: false,
              step: "products",
              selectedBase: newBase,
              quantities: {},
            }
          : null,
      );
    } catch (e) {
      toastError(e?.response?.data?.message || "Error creando base");
      setOrganizeModal((prev) => (prev ? { ...prev, loading: false } : null));
    }
  }

  function countFromThisOrderInBase(base) {
    const ids = new Set(items.map((i) => i.id));
    return base.order_items?.filter((i) => ids.has(i.id)).length ?? 0;
  }

  function modalMaxQty(orderItem) {
    if (!organizeModal?.selectedBase) return 0;
    const total = orderItem.qty || 0;

    // Usar locations del estado de items: cubre TODOS los pallets y bases
    const fullItem = items.find((i) => i.id === orderItem.id);
    const allLocations = fullItem?.locations ?? [];

    // Sumar todo lo asignado EXCEPTO lo que ya hay en la base actual
    // (así el usuario puede reasignar esa cantidad sin que cuente doble)
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
          ? {
              ...prev,
              quantities: { ...prev.quantities, [orderItem.id]: cur + 1 },
            }
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

  function rowClass(status) {
    if (status === "done") return "border-green-500";
    if (status === "removed") return "border-red-500 opacity-80";
    // pendiente / default: tarjeta neutra
    return "border-gray-200";
  }

  if (loading) return <PageSpinner />;

  if (error) {
    return (
      <div className="space-y-3">
        <div className="bg-red-50 border border-red-200 text-red-800 rounded-xl p-3">
          {error}
        </div>
        <div className="flex justify-start">
          <BackButton to="/" />
        </div>
      </div>
    );
  }

  return (
    <div className="space-y-4">
      {/* Modal asociar pallet */}
      {openAttachPallet && (
        <div className="fixed inset-0 z-50 bg-black/60 p-4 flex items-center justify-center">
          <div className="w-full max-w-md bg-white dark:bg-gray-800 rounded-2xl p-4 max-h-[80vh] flex flex-col shadow-2xl">
            {/* Header */}
            <div className="flex items-center justify-between mb-4">
              <h2 className="font-bold text-lg text-gray-900 dark:text-white">
                Asociar a pallet
              </h2>
              <button
                onClick={() => setOpenAttachPallet(false)}
                className="w-8 h-8 flex items-center justify-center rounded-full bg-gray-100 dark:bg-gray-700 text-gray-500 dark:text-gray-400 hover:bg-gray-200 dark:hover:bg-gray-600 transition-colors"
              >
                <svg
                  className="w-4 h-4"
                  fill="none"
                  viewBox="0 0 24 24"
                  strokeWidth={2.5}
                  stroke="currentColor"
                >
                  <path
                    strokeLinecap="round"
                    strokeLinejoin="round"
                    d="M6 18 18 6M6 6l12 12"
                  />
                </svg>
              </button>
            </div>

            {/* Crear pallet nuevo */}
            <div className="mb-4">
              <button
                onClick={async () => {
                  try {
                    const pallet = await apiPost(`/pallets`, { note: null });
                    toastSuccess(`Pallet creado: ${pallet.code}`);
                    await onAttachPallet(pallet.id);
                  } catch (e) {
                    toastError(
                      e?.data?.message || e?.message || "Error creando pallet",
                    );
                  }
                }}
                className="w-full flex items-center justify-center gap-2 bg-gray-900 dark:bg-white text-white dark:text-gray-900 rounded-xl py-3 text-sm font-bold hover:bg-gray-700 dark:hover:bg-gray-100 active:scale-[0.99] transition-all"
              >
                <svg
                  className="w-4 h-4"
                  fill="none"
                  viewBox="0 0 24 24"
                  strokeWidth={2.5}
                  stroke="currentColor"
                >
                  <path
                    strokeLinecap="round"
                    strokeLinejoin="round"
                    d="M12 4.5v15m7.5-7.5h-15"
                  />
                </svg>
                Crear pallet nuevo
              </button>
            </div>

            {/* Lista de pallets */}
            <div className="flex-1 overflow-y-auto space-y-2">
              {loadingPallets ? (
                <PageSpinner />
              ) : availablePallets.length === 0 ? (
                <div className="text-sm text-gray-500 dark:text-gray-400 text-center py-8">
                  No hay pallets disponibles para asociar
                </div>
              ) : (
                <>
                  <p className="text-xs font-semibold uppercase tracking-wider text-gray-400 dark:text-gray-500 mb-1">
                    Pallets existentes
                  </p>
                  {availablePallets.map((pallet) => (
                    <button
                      key={pallet.id}
                      onClick={() => onAttachPallet(pallet.id)}
                      className="relative w-full text-left bg-white dark:bg-gray-700/60 border border-gray-200 dark:border-gray-600/50 rounded-xl overflow-hidden hover:shadow-md hover:-translate-y-0.5 active:scale-[0.99] active:shadow-none transition-all duration-150"
                    >
                      {/* Acento izquierdo */}
                      <div
                        className={`absolute left-0 top-0 bottom-0 w-1 ${pallet.status === "done" ? "bg-green-500" : "bg-blue-500"}`}
                      />
                      <div className="pl-4 pr-3 py-3 flex items-center justify-between gap-3">
                        <div>
                          <p className="font-mono font-bold text-gray-900 dark:text-white">
                            {pallet.code}
                          </p>
                          <p className="text-xs text-gray-400 dark:text-gray-500 mt-0.5 capitalize">
                            {pallet.status === "done"
                              ? "✓ Completo"
                              : "En proceso"}
                          </p>
                        </div>
                        <svg
                          className="w-4 h-4 text-gray-300 dark:text-gray-600 shrink-0"
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
                      </div>
                    </button>
                  ))}
                </>
              )}
            </div>
          </div>
        </div>
      )}

      {pallets.length > 0 ? (
        <BackButton to={`/pallet/${pallets[0].id}`} />
      ) : (
        <BackButton to="/" />
      )}

      {/* ── Header ─────────────────────────────────────────────────────── */}
      <div className="space-y-2.5">
        <div className="flex items-start justify-between gap-3">
          <h1 className="font-mono font-bold text-2xl md:text-3xl text-gray-900 dark:text-white leading-tight">
            Pedido #{order?.code}
          </h1>
          <button
            onClick={() => setShowQR(true)}
            title="Ver QR del pedido"
            className="shrink-0 p-2 rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 hover:bg-gray-50 dark:hover:bg-gray-700 active:scale-95 transition-all"
          >
            <svg
              xmlns="http://www.w3.org/2000/svg"
              className="w-5 h-5 text-gray-600 dark:text-gray-300"
              fill="none"
              viewBox="0 0 24 24"
              stroke="currentColor"
              strokeWidth={1.8}
            >
              <rect x="3" y="3" width="7" height="7" rx="1" />
              <rect x="14" y="3" width="7" height="7" rx="1" />
              <rect x="3" y="14" width="7" height="7" rx="1" />
              <path d="M14 14h2v2h-2zM18 14h3v2h-3zM14 18h3v3h-3zM19 18h2v3h-2z" />
            </svg>
          </button>
        </div>

        {order &&
          (() => {
            const b = orderStatusBadge(order.status);
            return <StatusBadge label={b.label} color={b.color} />;
          })()}

        {/* Pallets asociados como chips */}
        {pallets.length > 0 && (
          <div className="space-y-2 pt-1">
            <p className={SEC_LABEL}>Pallets asociados</p>
            <div className="flex flex-wrap gap-2">
              {pallets.map((p) => {
                const isPalletDone = p.status === "done";
                return (
                  <div
                    key={p.id}
                    className="inline-flex items-center gap-1.5 bg-gray-100 dark:bg-gray-700/60 rounded-xl px-3 py-1.5"
                  >
                    <Link
                      to={`/pallet/${p.id}`}
                      className="text-sm font-semibold text-gray-800 dark:text-gray-200 hover:underline font-mono"
                    >
                      {p.code}
                    </Link>
                    {isPalletDone && (
                      <span className="text-[10px] bg-gray-200 dark:bg-gray-600 text-gray-500 dark:text-gray-400 px-1.5 py-0.5 rounded-full font-medium">
                        cerrado
                      </span>
                    )}
                    {order?.status !== "done" && modalItems.length > 0 && (
                      <button
                        onClick={() =>
                          isPalletDone
                            ? setReopenModal({ pallet: p, reopening: false })
                            : openOrganizeModal(p)
                        }
                        className={`text-[11px] px-2 py-0.5 rounded-lg font-semibold transition-colors ${
                          isPalletDone
                            ? "bg-gray-200 dark:bg-gray-600 text-gray-600 dark:text-gray-300"
                            : "bg-gray-900 dark:bg-white text-white dark:text-gray-900 hover:bg-gray-700"
                        }`}
                      >
                        {isPalletDone ? "🔒" : "📦"} Organizar
                      </button>
                    )}
                    {order?.status !== "done" && (
                      <button
                        onClick={() => setConfirmDetachPallet(p)}
                        disabled={detachingPallet === p.id}
                        className="text-[11px] px-2 py-0.5 rounded-lg font-semibold bg-red-100 dark:bg-red-900/30 text-red-600 dark:text-red-400 hover:bg-red-200 dark:hover:bg-red-900/50 disabled:opacity-50 transition-colors"
                      >
                        ✕
                      </button>
                    )}
                  </div>
                );
              })}
            </div>
          </div>
        )}
      </div>

      {/* ── Tickets ────────────────────────────────────────────────────── */}
      <section className="space-y-3">
        <div className="flex items-center justify-between">
          <p className={SEC_LABEL}>Ticket del pedido</p>
          {order?.status !== "done" && (
            <button
              onClick={() => setOpenAddTicket(true)}
              className="text-sm font-semibold text-blue-600 dark:text-blue-400 hover:text-blue-800 dark:hover:text-blue-300 transition-colors"
            >
              + Agregar
            </button>
          )}
        </div>
        {tickets.length === 0 ? (
          <p className="text-sm text-gray-500 dark:text-gray-400 py-1">
            No hay tickets agregados.
          </p>
        ) : (
          <div className="space-y-2">
            {tickets.map((ticket) => (
              <TicketCard
                key={ticket.id}
                ticket={ticket}
                orderId={orderId}
                onUpdate={load}
              />
            ))}
          </div>
        )}
      </section>

      {/* ── Acciones ───────────────────────────────────────────────────── */}
      <section className="space-y-2.5">
        <p className={SEC_LABEL}>Acciones</p>
        {order?.status !== "done" ? (
          <div className="space-y-2">
            <ActionItem
              icon={Icons.Import}
              iconBg="bg-blue-500"
              label="Importar pedido"
              sublabel="Cargar productos desde texto copiado"
              to={`/order/${orderId}/import`}
            />
            <ActionItem
              icon={Icons.Pallet}
              iconBg="bg-purple-500"
              label="Asociar pallet"
              sublabel="Vincular este pedido a un pallet existente"
              onClick={() => {
                setOpenAttachPallet(true);
                loadAvailablePallets();
              }}
            />
            <ActionItem
              icon={Icons.Plus}
              iconBg="bg-green-500"
              label="Agregar producto"
              sublabel="Buscar por EAN o últimos 4 dígitos"
              onClick={() => setOpenAddProduct(true)}
            />
            <ActionItem
              icon={Icons.History}
              iconBg="bg-gray-500"
              label="Historial"
              sublabel="Registro de cambios del pedido"
              to={`/order/${orderId}/history`}
            />
            {order?.status === "open" && canFinalize && (
              <button
                onClick={handleFinalize}
                disabled={finalizing}
                className="w-full py-3.5 rounded-2xl bg-green-600 hover:bg-green-700 text-white text-sm font-bold disabled:opacity-60 transition-colors mt-1"
              >
                {finalizing ? "Finalizando…" : "✓ Finalizar pedido"}
              </button>
            )}
          </div>
        ) : (
          <ActionItem
            icon={Icons.History}
            iconBg="bg-gray-500"
            label="Ver historial"
            sublabel="Registro de actividad del pedido"
            to={`/order/${orderId}/history`}
          />
        )}
      </section>

      {/* Modal agregar producto */}
      {openAddProduct && (
        <>
          <div
            className="fixed inset-0 bg-black/50 z-40"
            onClick={() => setOpenAddProduct(false)}
          />
          <div className="fixed inset-0 z-50 flex items-center justify-center p-4">
            <div
              className="bg-white rounded-2xl p-6 w-full max-w-md space-y-4"
              onClick={(e) => e.stopPropagation()}
            >
              <div className="flex items-center justify-between">
                <div className="font-semibold text-lg">Agregar producto</div>
                <button
                  onClick={() => setOpenAddProduct(false)}
                  className="text-gray-500 hover:text-gray-700"
                >
                  ✕
                </button>
              </div>
              <form
                onSubmit={(e) => {
                  onAddManual(e);
                  setOpenAddProduct(false);
                }}
                className="space-y-3"
              >
                <input
                  value={eanOrLast4}
                  onChange={(e) => setEanOrLast4(onlyDigits(e.target.value))}
                  placeholder="EAN completo o últimos 4"
                  inputMode="numeric"
                  className="w-full border rounded-lg px-3 py-3"
                  autoFocus
                />
                <div className="flex gap-2">
                  <input
                    value={qty}
                    onChange={(e) => setQty(onlyDigits(e.target.value))}
                    inputMode="numeric"
                    placeholder="Qty"
                    className="w-28 border rounded-lg px-3 py-3"
                  />
                  <button
                    type="submit"
                    disabled={saving}
                    className="flex-1 rounded-lg py-3 bg-black text-white disabled:opacity-60"
                  >
                    {saving ? "Agregando..." : "Agregar"}
                  </button>
                </div>
                <p className="text-xs text-gray-500">
                  Tip: un escáner tipo pistola escribe el EAN en el input como
                  teclado.
                </p>
              </form>
            </div>
          </div>
        </>
      )}

      {/* items */}
      {order?.status === "done" && categorizedItems ? (
        // Vista de acordeón para pedidos finalizados
        <div className="space-y-3">
          <Accordion
            title={`Productos completados/de más (${categorizedItems.completed.length})`}
            defaultOpen={true}
          >
            {categorizedItems.completed.length === 0 ? (
              <div className="p-4 text-sm text-gray-600 text-center">
                No hay productos completados
              </div>
            ) : (
              <div className="p-2 flex flex-col gap-3">
                {categorizedItems.completed.map((it) => (
                  <ItemCard
                    key={it.id}
                    item={it}
                    onSelect={() => {
                      setActionItem(it);
                      // Para pedidos finalizados, mostrar la cantidad encontrada (done_qty)
                      setActionQty(String(it.done_qty ?? it.qty ?? ""));
                    }}
                    borderColor="border-green-500"
                    bgColor="bg-green-50"
                    showDoneQty={true}
                  />
                ))}
              </div>
            )}
          </Accordion>

          <Accordion
            title={`Productos incompletos (${categorizedItems.incomplete.length})`}
            defaultOpen={false}
          >
            {categorizedItems.incomplete.length === 0 ? (
              <div className="p-4 text-sm text-gray-600 text-center">
                No hay productos incompletos
              </div>
            ) : (
              <div className="p-2 flex flex-col gap-3">
                {categorizedItems.incomplete.map((it) => (
                  <ItemCard
                    key={it.id}
                    item={it}
                    onSelect={() => {
                      setActionItem(it);
                      // Para pedidos finalizados, mostrar la cantidad encontrada (done_qty)
                      setActionQty(String(it.done_qty ?? it.qty ?? ""));
                    }}
                    borderColor="border-yellow-500"
                    bgColor="bg-yellow-50"
                    showDoneQty={true}
                  />
                ))}
              </div>
            )}
          </Accordion>

          <Accordion
            title={`Productos no encontrados (${categorizedItems.notFound.length})`}
            defaultOpen={false}
          >
            {categorizedItems.notFound.length === 0 ? (
              <div className="p-4 text-sm text-gray-600 text-center">
                No hay productos no encontrados
              </div>
            ) : (
              <div className="p-2 flex flex-col gap-3">
                {categorizedItems.notFound.map((it) => (
                  <ItemCard
                    key={it.id}
                    item={it}
                    onSelect={() => {
                      setActionItem(it);
                      // Si está removido, inicializar con 0, sino con la cantidad encontrada o actual
                      setActionQty(
                        it.status === "removed"
                          ? "0"
                          : String(it.done_qty ?? it.qty ?? ""),
                      );
                    }}
                    borderColor="border-red-500"
                    bgColor="bg-red-50"
                    showDoneQty={true}
                  />
                ))}
              </div>
            )}
          </Accordion>
        </div>
      ) : items.length === 0 ? (
        // Sin productos
        <div className="text-center py-10 space-y-1">
          <p className="font-semibold text-gray-700 dark:text-gray-300">
            No hay productos en este pedido
          </p>
          <p className="text-sm text-gray-500 dark:text-gray-400">
            Importá un pedido para comenzar.
          </p>
        </div>
      ) : (
        // Ítems para pedidos no finalizados
        <section className="space-y-3">
          <div className="flex items-center justify-between">
            <p className={SEC_LABEL}>Productos del pedido</p>
            <div className="flex gap-2">
              {items.filter((i) => i.status === "pending").length > 0 && (
                <span className="text-xs font-semibold text-amber-600 dark:text-amber-400">
                  {items.filter((i) => i.status === "pending").length} pend.
                </span>
              )}
              {items.filter((i) => i.status === "done").length > 0 && (
                <span className="text-xs font-semibold text-green-600 dark:text-green-400">
                  {items.filter((i) => i.status === "done").length} listos
                </span>
              )}
              {items.filter((i) => i.status === "removed").length > 0 && (
                <span className="text-xs font-semibold text-red-500 dark:text-red-400">
                  {items.filter((i) => i.status === "removed").length} quit.
                </span>
              )}
            </div>
          </div>

          <div className="flex flex-col gap-3">
            {[...items]
              .sort((a, b) => a.description.localeCompare(b.description))
              .map((it) => (
                <ItemCard
                  key={it.id}
                  item={it}
                  onSelect={() => {
                    setActionItem(it);
                    setActionQty(
                      it.status === "removed" ? "0" : String(it.qty ?? ""),
                    );
                  }}
                  borderColor={rowClass(it.status)}
                  bgColor="bg-gray-50"
                />
              ))}
          </div>

          <button onClick={load} className={BTN_SEC}>
            Refrescar
          </button>
        </section>
      )}

      {/* Modal acciones ítem */}
      {actionItem && (
        <div className="fixed inset-0 z-50 bg-black/60 p-4 flex items-center justify-center">
          <div className="flex flex-col w-full max-w-md bg-white rounded-2xl p-4 gap-2">
            <div className="flex items-center justify-center relative">
              <div className="font-semibold text-center text-lg">
                Acciones para el producto
              </div>
              <button
                onClick={() => setActionItem(null)}
                className="absolute right-[-14px] top-[-10px] px-3 py-1 text-xs bg-white"
              >
                x
              </button>
            </div>

            <div className={`text-sm ${textClass(actionItem.status)} `}>
              {actionItem.description}
            </div>
            <div className="font-mono text-sm break-all text-gray-500">
              {actionItem.ean || "—"}
            </div>

            {/* Input de cantidad - solo si el pedido no está finalizado */}
            {order?.status !== "done" && (
              <div className="flex flex-col gap-2 items-center justify-center">
                <div className="text-md font-semibold text-[#1b1b1b]">
                  Cantidad encontrada
                </div>

                <div className="flex items-center justify-center gap-2 w-3/4">
                  <input
                    value={actionQty}
                    onChange={(e) => setActionQty(onlyDigits(e.target.value))}
                    inputMode="numeric"
                    className="flex-1 border rounded-lg py-2 text-center text-lg font-semibold "
                    placeholder="0"
                    autoFocus
                  />
                </div>
                <button
                  onClick={() => {
                    const q = parseInt(onlyDigits(actionQty), 10);
                    if (isNaN(q) || q < 0) {
                      toastError("La cantidad debe ser 0 o mayor.");
                      return;
                    }
                    tryApplyAction(actionItem, q);
                  }}
                  className="w-3/4 rounded-lg py-3 bg-black text-white text-sm font-semibold"
                >
                  Guardar
                </button>
              </div>
            )}
          </div>
        </div>
      )}

      {/* ── Modal reabrir pallet para organizar ──────────────────────────────── */}
      {reopenModal && (
        <div className="fixed inset-0 z-50 bg-black/60 flex items-center justify-center p-4">
          <div className="w-full max-w-sm bg-white rounded-2xl p-6 space-y-4">
            <div className="text-center space-y-2">
              <div className="text-4xl">🔒</div>
              <p className="font-bold text-lg">Pallet finalizado</p>
              <p className="text-sm text-gray-600 leading-snug">
                <span className="font-semibold font-mono">
                  {reopenModal.pallet.code}
                </span>{" "}
                está cerrado. Para organizar productos necesitás reabrirlo.
              </p>
              <p className="text-xs text-gray-400">
                Podés volver a finalizarlo después de hacer los cambios.
              </p>
            </div>
            <div className="flex gap-2">
              <button
                onClick={() => setReopenModal(null)}
                disabled={reopenModal.reopening}
                className="flex-1 rounded-xl py-3 border text-sm text-gray-600 disabled:opacity-50"
              >
                Cancelar
              </button>
              <button
                onClick={reopenAndOrganize}
                disabled={reopenModal.reopening}
                className="flex-1 rounded-xl py-3 bg-green-600 text-white text-sm font-semibold disabled:opacity-60"
              >
                {reopenModal.reopening ? "Reabriendo…" : "Reabrir y organizar"}
              </button>
            </div>
          </div>
        </div>
      )}

      {/* ── Modal conflicto cantidad vs. organizadas ──────────────────────────── */}
      <QtyConflictModal
        qtyConflict={qtyConflict}
        setQtyConflict={setQtyConflict}
        setConflictKeep={setConflictKeep}
        resolveConflictAndSave={resolveConflictAndSave}
      />

      {/* Modal confirmar desvincular pallet */}
      {confirmDetachPallet && (
        <>
          <div
            className="fixed inset-0 bg-black/50 z-40"
            onClick={() => setConfirmDetachPallet(null)}
          />
          <div className="fixed inset-0 z-50 flex items-center justify-center p-4">
            <div
              className="bg-white rounded-2xl p-6 w-full max-w-md space-y-4"
              onClick={(e) => e.stopPropagation()}
            >
              <div className="space-y-2">
                <div className="font-semibold text-lg">
                  ¿Desvincular pallet?
                </div>
                <div className="text-sm text-gray-700">
                  Estás por desvincular el pallet{" "}
                  <span className="font-semibold">
                    {confirmDetachPallet.code}
                  </span>{" "}
                  de este pedido.
                </div>
              </div>

              <div className="bg-red-50 border border-red-200 rounded-lg p-4">
                <div className="flex items-start gap-2">
                  <div className="text-sm text-red-800">
                    <div className="font-semibold mb-1">ADVERTENCIA:</div>
                    <div>
                      Se perderán todos los productos de este pedido que fueron
                      asignados a este pallet. Tendrás que volver a asignarlos
                      manualmente.
                    </div>
                  </div>
                </div>
              </div>

              <div className="flex gap-2">
                <button
                  onClick={async () => {
                    const palletToDetach = confirmDetachPallet;
                    setConfirmDetachPallet(null);
                    setDetachingPallet(palletToDetach.id);
                    try {
                      await apiDelete(
                        `/orders/${orderId}/detach-pallet/${palletToDetach.id}`,
                      );
                      toastSuccess("Pallet desvinculado correctamente");
                      load(); // Recargar para actualizar la lista
                    } catch (e) {
                      toastError(
                        e?.response?.data?.message ||
                          "Error al desvincular pallet",
                      );
                    } finally {
                      setDetachingPallet(null);
                    }
                  }}
                  disabled={detachingPallet === confirmDetachPallet.id}
                  className="flex-1 rounded-lg py-3 bg-red-600 text-white disabled:opacity-60"
                >
                  {detachingPallet === confirmDetachPallet.id
                    ? "Desvinculando..."
                    : "Desvincular"}
                </button>
                <button
                  onClick={() => setConfirmDetachPallet(null)}
                  className="flex-1 rounded-lg py-3 border bg-white text-gray-700 hover:bg-gray-50"
                >
                  Cancelar
                </button>
              </div>
            </div>
          </div>
        </>
      )}

      {/* Modal agregar ticket */}
      {openAddTicket && (
        <AddTicketModal
          orderId={orderId}
          onClose={() => setOpenAddTicket(false)}
          onSuccess={() => {
            setOpenAddTicket(false);
            load();
          }}
        />
      )}

      {/* ── Modal Organizar en pallet ──────────────────────────────────── */}
      <OrganizeModal
        organizeModal={organizeModal}
        setOrganizeModal={setOrganizeModal}
        modalItems={modalItems}
        modalMaxQty={modalMaxQty}
        decModalQty={decModalQty}
        incModalQty={incModalQty}
        setModalQty={setModalQty}
        selectBaseForOrganize={selectBaseForOrganize}
        createBaseAndOrganize={createBaseAndOrganize}
        saveOrganize={saveOrganize}
        countFromThisOrderInBase={countFromThisOrderInBase}
      />

      {/* QR Modal */}
      {showQR && order && (
        <QRModal
          order={order}
          pallet={pallets[0] ?? null}
          onClose={() => setShowQR(false)}
        />
      )}
    </div>
  );
}

// ── Helper OCR components ─────────────────────────────────────────────────

/** Badge de estado OCR por foto */
function OcrBadge({ photo }) {
  if (photo.ocr_processed_at) {
    return (
      <span className="inline-flex items-center bg-green-100 dark:bg-green-900/30 text-green-700 dark:text-green-400 text-[10px] font-semibold px-1.5 py-0.5 rounded-full">
        ✓ Escaneado
      </span>
    );
  }
  if (photo.ocr_log) {
    return (
      <span className="inline-flex items-center gap-1 bg-amber-100 dark:bg-amber-900/30 text-amber-700 dark:text-amber-400 text-[10px] font-semibold px-1.5 py-0.5 rounded-full">
        <span className="animate-pulse">●</span> Procesando…
      </span>
    );
  }
  return (
    <span className="inline-flex items-center bg-gray-100 dark:bg-gray-700 text-gray-400 dark:text-gray-500 text-[10px] font-semibold px-1.5 py-0.5 rounded-full">
      Sin escanear
    </span>
  );
}

/** Terminal de logs OCR reutilizable */
function OcrTerminal({ log, done, eansCount, photoId }) {
  const logEndRef = useRef(null);
  useEffect(() => {
    logEndRef.current?.scrollIntoView({ behavior: "smooth" });
  }, [log]);

  if (!log) return null;
  return (
    <div>
      <div className="flex items-center justify-between mb-1">
        <span className="text-xs font-mono font-semibold text-gray-500 uppercase tracking-wider">
          OCR Log{photoId ? ` (foto #${photoId})` : ""}
        </span>
        {!done ? (
          <span className="flex items-center gap-1 text-xs text-amber-600">
            <span className="animate-pulse">●</span> procesando…
          </span>
        ) : eansCount !== null ? (
          <span
            className={`text-xs font-semibold ${eansCount > 0 ? "text-green-600" : "text-red-500"}`}
          >
            {eansCount > 0
              ? `✓ ${eansCount} EAN(s) encontrado(s)`
              : "✗ Sin coincidencias"}
          </span>
        ) : null}
      </div>
      <div className="bg-gray-950 rounded-lg p-3 h-48 overflow-y-auto font-mono text-xs leading-relaxed">
        {log.split("\n").map((line, i) => {
          const isError =
            line.includes("[ERROR]") || line.includes("ERROR:");
          const isOk =
            line.includes("→ EAN:") ||
            line.includes("OK") ||
            line.includes("completado");
          const isWarn =
            line.includes("WARN") ||
            line.includes("fallback") ||
            line.includes("falló");
          return (
            <div
              key={i}
              className={
                isError
                  ? "text-red-400"
                  : isOk
                    ? "text-green-400"
                    : isWarn
                      ? "text-yellow-400"
                      : "text-gray-300"
              }
            >
              {line || " "}
            </div>
          );
        })}
        <div ref={logEndRef} />
      </div>
    </div>
  );
}

// Componente para mostrar un ticket
function TicketCard({ ticket, orderId, onUpdate }) {
  const [expanded, setExpanded] = useState(false);
  const [uploading, setUploading] = useState(false);
  const [deleting, setDeleting] = useState(false);
  const [selectedPhoto, setSelectedPhoto] = useState(null);
  const [confirmDeleteTicket, setConfirmDeleteTicket] = useState(false);

  // Modal de escaneo: null | { photo, step:'confirm'|'scanning', log, done, eansCount }
  const [scanModal, setScanModal] = useState(null);
  const pollRef = useRef(null);

  // Cleanup polling al desmontar
  useEffect(() => () => clearInterval(pollRef.current), []);

  function getPhotoUrl(photo) {
    if (photo.url) {
      if (photo.url.startsWith("http://") || photo.url.startsWith("https://")) {
        return photo.url.replace(/([^:]\/)\/+/g, "$1");
      }
      return photo.url.startsWith("/") ? photo.url : `/${photo.url}`;
    }
    const API_BASE = import.meta.env.VITE_API_BASE_URL || "";
    const baseUrl = API_BASE.replace("/api/v1", "").replace(/\/$/, "") || "";
    const storagePath = photo.path.startsWith("/")
      ? photo.path
      : `/${photo.path}`;
    return `${baseUrl}/storage${storagePath}`;
  }

  async function handleUploadPhoto(e) {
    const file = e.target.files?.[0];
    if (!file) return;

    setUploading(true);
    try {
      const form = new FormData();
      form.append("photo", file);

      await apiPost(`/orders/${orderId}/tickets/${ticket.id}/photos`, form);

      toastSuccess("Foto agregada");
      onUpdate();
    } catch (e) {
      toastError(
        e.response?.data?.message || e.message || "Error subiendo foto",
      );
    } finally {
      setUploading(false);
      e.target.value = "";
    }
  }

  async function handleDeletePhoto(photoId) {
    if (!window.confirm("¿Eliminar esta foto?")) return;
    try {
      await apiDelete(`/orders/${orderId}/tickets/${ticket.id}/photos/${photoId}`);
      toastSuccess("Foto eliminada");
      onUpdate();
    } catch (e) {
      toastError(e.response?.data?.message || e.message || "Error eliminando foto");
    }
  }

  async function handleDeleteTicket() {
    setDeleting(true);
    try {
      await apiDelete(`/orders/${orderId}/tickets/${ticket.id}`);
      toastSuccess("Ticket eliminado");
      setConfirmDeleteTicket(false);
      onUpdate();
    } catch (e) {
      toastError(e.response?.data?.message || e.message || "Error eliminando ticket");
    } finally {
      setDeleting(false);
    }
  }

  function openScanModal(photo) {
    setScanModal({ photo, step: "confirm", log: "", done: false, eansCount: null });
  }

  async function confirmScan() {
    if (!scanModal) return;
    const photo = scanModal.photo;
    setScanModal((prev) => prev && { ...prev, step: "scanning", log: "Iniciando escaneo OCR…" });
    try {
      await apiPost(
        `/orders/${orderId}/tickets/${ticket.id}/photos/${photo.id}/trigger-ocr`,
      );
    } catch (e) {
      const msg = e?.response?.data?.message || e.message || "Error al iniciar OCR";
      setScanModal((prev) => prev && { ...prev, log: `[ERROR] ${msg}`, done: true });
      return;
    }
    clearInterval(pollRef.current);
    pollRef.current = setInterval(async () => {
      try {
        const data = await apiGet(
          `/orders/${orderId}/tickets/${ticket.id}/photos/${photo.id}/ocr-status`,
        );
        if (data.ocr_log) setScanModal((prev) => prev && { ...prev, log: data.ocr_log });
        if (data.ocr_processed_at !== null) {
          clearInterval(pollRef.current);
          setScanModal((prev) => prev && { ...prev, done: true, eansCount: data.ocr_eans_count });
          onUpdate();
        }
      } catch {
        // ignorar errores de polling
      }
    }, 2000);
  }

  function closeScanModal() {
    clearInterval(pollRef.current);
    setScanModal(null);
  }

  const totalPhotos = ticket.photos?.length ?? 0;
  const scannedCount = ticket.photos?.filter((p) => p.ocr_processed_at).length ?? 0;

  return (
    <div className="relative bg-white dark:bg-gray-800/60 border border-gray-200 dark:border-gray-700/50 rounded-2xl overflow-hidden shadow-sm">
      {/* Acento izquierdo */}
      <div className="absolute left-0 top-0 bottom-0 w-1 bg-indigo-500" />

      <div className="pl-5 pr-4 py-3.5 space-y-0">
        {/* ── Header ─────────────────────────────────────────────── */}
        <div className="flex items-start gap-2">
          <div className="flex-1 min-w-0">
            <p className="font-mono font-bold text-gray-900 dark:text-white leading-tight">
              {ticket.code || "Sin código"}
            </p>
            {ticket.note && (
              <p className="text-xs text-gray-500 dark:text-gray-400 mt-0.5">
                {ticket.note}
              </p>
            )}
            {totalPhotos > 0 && (
              <p className="text-[11px] text-gray-400 dark:text-gray-500 mt-0.5">
                {scannedCount}/{totalPhotos} foto{totalPhotos !== 1 ? "s" : ""}{" "}
                escaneada{totalPhotos !== 1 ? "s" : ""}
              </p>
            )}
          </div>

          <div className="flex items-center gap-1.5 shrink-0">
            {/* Contador de fotos */}
            {totalPhotos > 0 && (
              <span className="text-[11px] bg-indigo-50 dark:bg-indigo-900/20 text-indigo-700 dark:text-indigo-300 px-2 py-0.5 rounded-full font-semibold">
                {totalPhotos} foto{totalPhotos !== 1 ? "s" : ""}
              </span>
            )}
            {/* Toggle ver fotos */}
            <button
              onClick={() => setExpanded(!expanded)}
              className="text-xs px-3 py-1.5 rounded-lg bg-gray-100 dark:bg-gray-700/60 text-gray-700 dark:text-gray-300 font-semibold hover:bg-gray-200 dark:hover:bg-gray-700 transition-colors"
            >
              {expanded ? "Ocultar" : "Ver fotos"}
            </button>
            {/* Eliminar */}
            <button
              onClick={() => setConfirmDeleteTicket(true)}
              className="text-xs px-3 py-1.5 rounded-lg border border-red-200 dark:border-red-800/50 text-red-600 dark:text-red-400 font-semibold hover:bg-red-50 dark:hover:bg-red-900/20 transition-colors"
            >
              Eliminar
            </button>
          </div>
        </div>

        {/* ── Grilla de fotos ─────────────────────────────────────── */}
        {expanded && (
          <div className="mt-3 grid grid-cols-2 sm:grid-cols-3 gap-2">
            {ticket.photos?.map((photo) => {
              const photoUrl = getPhotoUrl(photo);
              const isUnscanned = !photo.ocr_processed_at && !photo.ocr_log;
              return (
                <div
                  key={photo.id}
                  className="flex flex-col rounded-xl overflow-hidden border border-gray-200 dark:border-gray-600 bg-gray-100 dark:bg-gray-700 shadow-sm hover:shadow-md hover:-translate-y-0.5 transition-all duration-150"
                >
                  <div className="relative aspect-square">
                    <button
                      onClick={() => setSelectedPhoto(photo)}
                      className="w-full h-full"
                    >
                      <img
                        src={photoUrl}
                        alt={`Foto ${photo.id}`}
                        className="w-full h-full object-cover"
                        onError={(e) => {
                          e.target.style.display = "none";
                          e.target.nextSibling.style.display = "flex";
                        }}
                      />
                      <div className="hidden w-full h-full items-center justify-center text-xs text-gray-500 dark:text-gray-400">
                        Error cargando
                      </div>
                    </button>
                    <button
                      onClick={(e) => {
                        e.stopPropagation();
                        handleDeletePhoto(photo.id);
                      }}
                      className="absolute top-1.5 right-1.5 bg-black/50 hover:bg-red-600 text-white rounded-full w-6 h-6 flex items-center justify-center text-xs z-10 transition-colors"
                    >
                      ✕
                    </button>
                    <div className="absolute bottom-0 left-0 right-0 bg-gradient-to-t from-black/60 to-transparent text-white text-[10px] px-2 py-1.5">
                      {new Date(photo.created_at).toLocaleDateString(undefined, {
                        dateStyle: "short",
                      })}
                    </div>
                  </div>
                  {/* Franja OCR */}
                  <div className="px-2 py-1.5 bg-white dark:bg-gray-800 flex items-center justify-between gap-1 border-t border-gray-100 dark:border-gray-700">
                    <OcrBadge photo={photo} />
                    {isUnscanned && (
                      <button
                        onClick={() => openScanModal(photo)}
                        className="shrink-0 text-[10px] px-2 py-0.5 rounded-md bg-indigo-600 text-white font-semibold hover:bg-indigo-700 transition-colors"
                      >
                        Escanear
                      </button>
                    )}
                  </div>
                </div>
              );
            })}

            {/* Celda para subir foto */}
            <label className="rounded-xl border-2 border-dashed border-gray-300 dark:border-gray-600 bg-gray-50 dark:bg-gray-800/40 flex flex-col items-center justify-center gap-1.5 cursor-pointer hover:bg-gray-100 dark:hover:bg-gray-700/40 hover:border-gray-400 active:scale-[0.98] transition-colors min-h-[110px]">
              <input
                type="file"
                accept="image/*"
                className="hidden"
                onChange={handleUploadPhoto}
                disabled={uploading}
              />
              {uploading ? (
                <>
                  <div className="w-6 h-6 rounded-full border-2 border-gray-400 border-r-transparent animate-spin" />
                  <span className="text-[10px] text-gray-400 dark:text-gray-500">
                    Subiendo…
                  </span>
                </>
              ) : (
                <>
                  <svg
                    className="w-7 h-7 text-gray-400 dark:text-gray-500"
                    fill="none"
                    viewBox="0 0 24 24"
                    strokeWidth={1.5}
                    stroke="currentColor"
                  >
                    <path
                      strokeLinecap="round"
                      strokeLinejoin="round"
                      d="M6.827 6.175A2.31 2.31 0 0 1 5.186 7.23c-.38.054-.757.112-1.134.175C2.999 7.58 2.25 8.507 2.25 9.574V18a2.25 2.25 0 0 0 2.25 2.25h15A2.25 2.25 0 0 0 21.75 18V9.574c0-1.067-.75-1.994-1.802-2.169a47.865 47.865 0 0 0-1.134-.175 2.31 2.31 0 0 1-1.64-1.055l-.822-1.316a2.192 2.192 0 0 0-1.736-1.039 48.774 48.774 0 0 0-5.232 0 2.192 2.192 0 0 0-1.736 1.039l-.821 1.316z"
                    />
                    <path
                      strokeLinecap="round"
                      strokeLinejoin="round"
                      d="M16.5 12.75a4.5 4.5 0 1 1-9 0 4.5 4.5 0 0 1 9 0zM18.75 10.5h.008v.008h-.008V10.5z"
                    />
                  </svg>
                  <span className="text-[10px] font-semibold text-gray-400 dark:text-gray-500">
                    Agregar foto
                  </span>
                </>
              )}
            </label>
          </div>
        )}
      </div>

      {/* Modal foto ampliada */}
      {selectedPhoto && (
        <PhotoViewer
          photoUrl={getPhotoUrl(selectedPhoto)}
          photo={selectedPhoto}
          onClose={() => setSelectedPhoto(null)}
          onDelete={() => {
            handleDeletePhoto(selectedPhoto.id);
            setSelectedPhoto(null);
          }}
        />
      )}

      {/* Modal confirmar eliminar ticket */}
      {confirmDeleteTicket && (
        <>
          <div
            className="fixed inset-0 bg-black/50 z-40"
            onClick={() => setConfirmDeleteTicket(false)}
          />
          <div className="fixed inset-0 z-50 flex items-center justify-center p-4">
            <div
              className="bg-white dark:bg-gray-800 rounded-2xl p-6 w-full max-w-md space-y-4 shadow-2xl"
              onClick={(e) => e.stopPropagation()}
            >
              <div className="space-y-1.5">
                <p className="font-bold text-lg text-gray-900 dark:text-white">
                  ¿Eliminar ticket?
                </p>
                <p className="text-sm text-gray-600 dark:text-gray-400">
                  Ticket{" "}
                  <span className="font-semibold text-gray-900 dark:text-white">
                    {ticket.code || "Sin código"}
                  </span>{" "}
                  y todas sus fotos serán eliminados permanentemente.
                </p>
              </div>

              <div className="bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800/50 rounded-xl p-3.5">
                <p className="text-sm text-red-800 dark:text-red-300">
                  <span className="font-semibold">Atención:</span> esta acción
                  no se puede deshacer.
                </p>
              </div>

              <div className="flex gap-2">
                <button
                  onClick={() => setConfirmDeleteTicket(false)}
                  className="flex-1 rounded-xl py-3 border border-gray-200 dark:border-gray-600 bg-white dark:bg-transparent text-gray-700 dark:text-gray-300 text-sm font-semibold hover:bg-gray-50 dark:hover:bg-gray-700 transition-colors"
                >
                  Cancelar
                </button>
                <button
                  onClick={handleDeleteTicket}
                  disabled={deleting}
                  className="flex-1 rounded-xl py-3 bg-red-600 hover:bg-red-700 text-white text-sm font-semibold disabled:opacity-60 transition-colors"
                >
                  {deleting ? "Eliminando…" : "Eliminar"}
                </button>
              </div>
            </div>
          </div>
        </>
      )}

      {/* ── Modal escanear foto con OCR ─────────────────────────── */}
      {scanModal && (
        <div className="fixed inset-0 z-50 bg-black/80 flex items-center justify-center p-4">
          <div
            className="bg-white dark:bg-gray-900 rounded-2xl p-6 w-full max-w-md space-y-4 shadow-2xl"
            onClick={(e) => e.stopPropagation()}
          >
            <div className="flex items-center justify-between">
              <h3 className="font-bold text-lg text-gray-900 dark:text-white">
                Escanear ticket con OCR
              </h3>
              {(scanModal.done || scanModal.step === "confirm") && (
                <button
                  onClick={closeScanModal}
                  className="text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200 text-xl"
                >
                  ✕
                </button>
              )}
            </div>

            {scanModal.step === "confirm" ? (
              <>
                <div className="bg-amber-50 dark:bg-amber-900/20 border border-amber-200 dark:border-amber-700/50 rounded-xl p-4 space-y-1.5">
                  <p className="text-sm font-semibold text-amber-800 dark:text-amber-300">
                    ⚠ Esto consume un crédito de Azure Computer Vision
                  </p>
                  <p className="text-xs text-amber-700 dark:text-amber-400 leading-relaxed">
                    Cada escaneo realiza una llamada a la API de Azure (plan
                    gratuito: 5.000/mes). Asegurate de que el pallet esté
                    organizado antes de escanear. Una vez escaneada, una foto no
                    puede volver a escanearse.
                  </p>
                </div>
                <div className="flex gap-2 pt-1">
                  <button
                    onClick={closeScanModal}
                    className="flex-1 rounded-xl py-3 border border-gray-200 dark:border-gray-600 text-gray-700 dark:text-gray-300 text-sm font-semibold hover:bg-gray-50 dark:hover:bg-gray-700 transition-colors"
                  >
                    Cancelar
                  </button>
                  <button
                    onClick={confirmScan}
                    className="flex-1 rounded-xl py-3 bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-semibold transition-colors"
                  >
                    Sí, escanear
                  </button>
                </div>
              </>
            ) : (
              <>
                <OcrTerminal
                  log={scanModal.log}
                  done={scanModal.done}
                  eansCount={scanModal.eansCount}
                  photoId={scanModal.photo?.id}
                />
                {scanModal.done && (
                  <button
                    onClick={closeScanModal}
                    className="w-full rounded-xl py-3 bg-gray-900 dark:bg-white text-white dark:text-gray-900 text-sm font-semibold hover:bg-gray-700 dark:hover:bg-gray-100 transition-colors"
                  >
                    Cerrar
                  </button>
                )}
              </>
            )}
          </div>
        </div>
      )}
    </div>
  );
}

// Modal para agregar ticket
function AddTicketModal({ orderId, onClose, onSuccess }) {
  const [code, setCode] = useState("");
  const [note, setNote] = useState("");
  const [saving, setSaving] = useState(false);
  const [ticketId, setTicketId] = useState(null);
  const [uploading, setUploading] = useState(false);

  // Lista de fotos subidas (cada una con su botón escanear)
  const [uploadedPhotos, setUploadedPhotos] = useState([]);

  // Estado de escaneo: null | { photoId, step:'confirm'|'scanning', log, done, eansCount }
  const [scanState, setScanState] = useState(null);
  const pollRef = useRef(null);

  // Cleanup polling al desmontar
  useEffect(() => () => clearInterval(pollRef.current), []);

  async function handleCreateTicket(e) {
    e.preventDefault();
    if (!code.trim()) {
      toastError("El código del ticket es obligatorio");
      return;
    }
    setSaving(true);
    try {
      const data = await apiPost(`/orders/${orderId}/tickets`, {
        code: code.trim(),
        note: note || null,
      });
      setTicketId(data.id);
      toastSuccess("Ticket creado. Ahora podés agregar fotos.");
    } catch (e) {
      toastError(e.response?.data?.message || e.message || "Error creando ticket");
    } finally {
      setSaving(false);
    }
  }

  async function handleUploadPhoto(e) {
    const file = e.target.files?.[0];
    if (!file) return;
    if (!ticketId) { toastError("Primero creá el ticket"); return; }
    setUploading(true);
    try {
      const form = new FormData();
      form.append("photo", file);
      const res = await apiPost(
        `/orders/${orderId}/tickets/${ticketId}/photos`,
        form,
      );
      toastSuccess("Foto subida correctamente");
      setUploadedPhotos((prev) => [...prev, res.photo]);
    } catch (err) {
      toastError(err?.response?.data?.message || err?.message || "Error subiendo foto");
    } finally {
      setUploading(false);
      e.target.value = "";
    }
  }

  function openScanConfirm(photoId) {
    clearInterval(pollRef.current);
    setScanState({ photoId, step: "confirm", log: "", done: false, eansCount: null });
  }

  async function startScan() {
    if (!scanState) return;
    const { photoId } = scanState;
    setScanState((prev) =>
      prev && { ...prev, step: "scanning", log: "Iniciando escaneo OCR…" },
    );
    try {
      await apiPost(
        `/orders/${orderId}/tickets/${ticketId}/photos/${photoId}/trigger-ocr`,
      );
    } catch (e) {
      const msg = e?.response?.data?.message || e.message || "Error al iniciar OCR";
      setScanState((prev) => prev && { ...prev, log: `[ERROR] ${msg}`, done: true });
      return;
    }
    clearInterval(pollRef.current);
    pollRef.current = setInterval(async () => {
      try {
        const data = await apiGet(
          `/orders/${orderId}/tickets/${ticketId}/photos/${photoId}/ocr-status`,
        );
        if (data.ocr_log) setScanState((prev) => prev && { ...prev, log: data.ocr_log });
        if (data.ocr_processed_at !== null) {
          clearInterval(pollRef.current);
          setScanState((prev) =>
            prev && { ...prev, done: true, eansCount: data.ocr_eans_count },
          );
          setUploadedPhotos((prev) =>
            prev.map((p) =>
              p.id === photoId
                ? { ...p, ocr_processed_at: data.ocr_processed_at }
                : p,
            ),
          );
        }
      } catch {
        // silenciar errores de polling
      }
    }, 2000);
  }

  function closeScanState() {
    clearInterval(pollRef.current);
    setScanState(null);
  }

  function handleFinish() {
    clearInterval(pollRef.current);
    onSuccess();
  }

  return (
    <div className="fixed inset-0 z-50 bg-black/80 flex items-center justify-center p-4">
      <div className="bg-white dark:bg-gray-900 rounded-2xl p-6 max-w-lg w-full max-h-[92vh] overflow-y-auto">
        <div className="flex items-center justify-between mb-4">
          <h3 className="text-lg font-semibold text-gray-900 dark:text-white">
            Agregar ticket
          </h3>
          <button
            onClick={onClose}
            className="text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200 text-xl"
          >
            ✕
          </button>
        </div>

        {!ticketId ? (
          /* ── Paso 1: crear ticket ──────────────────────────── */
          <form onSubmit={handleCreateTicket} className="space-y-4">
            <div>
              <label className="block text-sm font-medium mb-1 text-gray-700 dark:text-gray-300">
                Código del ticket <span className="text-red-500">*</span>
              </label>
              <input
                type="text"
                value={code}
                onChange={(e) => setCode(e.target.value)}
                required
                className="w-full border rounded-lg px-3 py-2 dark:bg-gray-800 dark:border-gray-600 dark:text-white"
                placeholder="Ej: R-12345"
                autoFocus
              />
            </div>
            <div>
              <label className="block text-sm font-medium mb-1 text-gray-700 dark:text-gray-300">
                Nota (opcional)
              </label>
              <textarea
                value={note}
                onChange={(e) => setNote(e.target.value)}
                className="w-full border rounded-lg px-3 py-2 dark:bg-gray-800 dark:border-gray-600 dark:text-white"
                rows={2}
                placeholder="Notas adicionales..."
              />
            </div>
            <div className="flex gap-2">
              <button
                type="button"
                onClick={onClose}
                className="flex-1 border rounded-lg py-2 text-sm dark:border-gray-600 dark:text-gray-300"
              >
                Cancelar
              </button>
              <button
                type="submit"
                disabled={saving || !code.trim()}
                className="flex-1 bg-gray-900 dark:bg-white text-white dark:text-gray-900 rounded-lg py-2 text-sm disabled:opacity-40"
              >
                {saving ? "Creando..." : "Crear ticket"}
              </button>
            </div>
          </form>
        ) : (
          /* ── Paso 2: subir fotos + escanear ───────────────── */
          <div className="space-y-4">
            <div className="bg-green-50 dark:bg-green-900/20 border border-green-200 dark:border-green-700/50 rounded-lg p-3 text-sm text-green-800 dark:text-green-300">
              ✓ Ticket{" "}
              <span className="font-semibold">{code}</span> creado. Subí una
              foto y escaneá con OCR cuando el pallet esté listo.
            </div>

            {/* Lista de fotos subidas */}
            {uploadedPhotos.length > 0 && (
              <div className="space-y-2">
                {uploadedPhotos.map((photo) => {
                  const alreadyScanned = !!photo.ocr_processed_at;
                  return (
                    <div
                      key={photo.id}
                      className="flex items-center justify-between bg-gray-50 dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-xl px-3 py-2 gap-2"
                    >
                      <div className="flex items-center gap-2 min-w-0">
                        <span className="text-lg">🖼</span>
                        <span className="text-sm text-gray-700 dark:text-gray-300 font-mono truncate">
                          Foto #{photo.id}
                        </span>
                        {alreadyScanned && (
                          <span className="text-[10px] bg-green-100 dark:bg-green-900/30 text-green-700 dark:text-green-400 px-1.5 py-0.5 rounded-full font-semibold shrink-0">
                            ✓ Escaneada
                          </span>
                        )}
                      </div>
                      {!alreadyScanned && (
                        <button
                          onClick={() => openScanConfirm(photo.id)}
                          className="shrink-0 text-xs px-3 py-1.5 rounded-lg bg-indigo-600 text-white font-semibold hover:bg-indigo-700 transition-colors"
                        >
                          Escanear con OCR
                        </button>
                      )}
                    </div>
                  );
                })}
              </div>
            )}

            {/* Zona de subida */}
            <label
              className={`block w-full border-2 border-dashed rounded-lg p-4 text-center transition-colors ${
                uploading
                  ? "border-gray-200 cursor-not-allowed"
                  : "border-gray-300 dark:border-gray-600 cursor-pointer hover:bg-gray-50 dark:hover:bg-gray-800 hover:border-gray-400"
              }`}
            >
              <input
                type="file"
                accept="image/*"
                className="hidden"
                onChange={handleUploadPhoto}
                disabled={uploading}
              />
              {uploading ? (
                <div className="flex flex-col items-center gap-1">
                  <div className="animate-spin rounded-full h-7 w-7 border-b-2 border-gray-500" />
                  <span className="text-sm text-gray-500 dark:text-gray-400">
                    Subiendo foto...
                  </span>
                </div>
              ) : (
                <div className="flex flex-col items-center gap-1">
                  <span className="text-3xl">📷</span>
                  <span className="text-sm text-gray-600 dark:text-gray-400">
                    Tocar para agregar foto
                  </span>
                  <span className="text-xs text-gray-400 dark:text-gray-500">
                    Podés agregar varias
                  </span>
                </div>
              )}
            </label>

            <div className="flex gap-2 pt-1">
              <button
                onClick={handleFinish}
                className="flex-1 bg-gray-900 dark:bg-white text-white dark:text-gray-900 rounded-lg py-2.5 text-sm font-semibold"
              >
                Listo
              </button>
            </div>
          </div>
        )}
      </div>

      {/* ── Modal de escaneo OCR ─────────────────────────────────── */}
      {scanState && (
        <div className="fixed inset-0 z-[60] bg-black/80 flex items-center justify-center p-4">
          <div
            className="bg-white dark:bg-gray-900 rounded-2xl p-6 w-full max-w-md space-y-4 shadow-2xl"
            onClick={(e) => e.stopPropagation()}
          >
            <div className="flex items-center justify-between">
              <h3 className="font-bold text-lg text-gray-900 dark:text-white">
                Escanear con OCR
              </h3>
              {(scanState.done || scanState.step === "confirm") && (
                <button
                  onClick={closeScanState}
                  className="text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200 text-xl"
                >
                  ✕
                </button>
              )}
            </div>

            {scanState.step === "confirm" ? (
              <>
                <div className="bg-amber-50 dark:bg-amber-900/20 border border-amber-200 dark:border-amber-700/50 rounded-xl p-4 space-y-1.5">
                  <p className="text-sm font-semibold text-amber-800 dark:text-amber-300">
                    ⚠ Esto consume un crédito de Azure Computer Vision
                  </p>
                  <p className="text-xs text-amber-700 dark:text-amber-400 leading-relaxed">
                    Cada escaneo realiza una llamada a la API de Azure (plan
                    gratuito: 5.000/mes). Asegurate de que el pallet esté
                    organizado antes de escanear. Una vez escaneada, una foto no
                    puede volver a escanearse.
                  </p>
                </div>
                <div className="flex gap-2 pt-1">
                  <button
                    onClick={closeScanState}
                    className="flex-1 rounded-xl py-3 border border-gray-200 dark:border-gray-600 text-gray-700 dark:text-gray-300 text-sm font-semibold hover:bg-gray-50 dark:hover:bg-gray-700 transition-colors"
                  >
                    Cancelar
                  </button>
                  <button
                    onClick={startScan}
                    className="flex-1 rounded-xl py-3 bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-semibold transition-colors"
                  >
                    Sí, escanear
                  </button>
                </div>
              </>
            ) : (
              <>
                <OcrTerminal
                  log={scanState.log}
                  done={scanState.done}
                  eansCount={scanState.eansCount}
                  photoId={scanState.photoId}
                />
                {scanState.done && (
                  <button
                    onClick={closeScanState}
                    className="w-full rounded-xl py-3 bg-gray-900 dark:bg-white text-white dark:text-gray-900 text-sm font-semibold hover:bg-gray-700 dark:hover:bg-gray-100 transition-colors"
                  >
                    Cerrar
                  </button>
                )}
              </>
            )}
          </div>
        </div>
      )}
    </div>
  );
}
