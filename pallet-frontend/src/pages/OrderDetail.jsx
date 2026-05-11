import { useEffect, useMemo, useState } from "react";
import { useParams } from "react-router-dom";
import { apiGet, apiPost, apiDelete } from "../api/client";
import { toastSuccess, toastError } from "../ui/toast";
import BackButton from "../ui/BackButton";
import OrganizeModal from "../Components/OrganizeModal";
import QtyConflictModal from "../Components/QtyConflictModal";
import { PageSpinner } from "../ui/Spinner";
import { ActionItem, Icons } from "../ui/ActionList";
import { TicketCard, AddTicketModal } from "../features/tickets/TicketSection";
import ItemCard from "../features/order-items/ItemCard";
import ItemActionModal from "../features/order-items/ItemActionModal";
import { useOrganize } from "../hooks/useOrganize";
import { useItemAction } from "../hooks/useItemAction";
import { useOrderDetail } from "../hooks/useOrderDetail";
import { OrderHeader } from "../features/orders/OrderHeader";
import { OrderActions } from "../features/orders/OrderActions";
import DoneOrderProducts from "../features/orders/DoneOrderProducts";

function onlyDigits(v) {
  return (v || "").replace(/\D/g, "");
}

// ── Estilos de botón compartidos ─────────────────────────────────────────
const SEC_LABEL =
  "text-xs font-semibold uppercase tracking-wider text-gray-400 dark:text-gray-500";
const BTN_SEC =
  "flex items-center justify-center w-full py-3 px-4 rounded-xl bg-gray-100 dark:bg-gray-700/60 text-gray-700 dark:text-gray-200 text-sm font-semibold hover:bg-gray-200 dark:hover:bg-gray-700 disabled:opacity-40 transition-colors";

export default function OrderDetail() {
  const { orderId } = useParams();

  // React Query hook - datos cacheados con staleTime de 30s
  const {
    order,
    pallets,
    items,
    tickets,
    highlightsReady,
    pendingItemsCount,
    isLoading,
    error: queryError,
    canFinalize,
    attachPallet,
    detachPallet,
    finalize,
    finalizing,
    refetch,
  } = useOrderDetail(orderId);

  // Función de refetch para los hooks que necesitan recargar
  const load = () => refetch();

  const [tab, setTab] = useState("pending"); // pending | done | removed

  // add manual
  const [eanOrLast4, setEanOrLast4] = useState("");
  const [qty, setQty] = useState("1");
  const [saving, setSaving] = useState(false);
  // asociar pallet
  const [openAttachPallet, setOpenAttachPallet] = useState(false);
  const [availablePallets, setAvailablePallets] = useState([]);
  const [loadingPallets, setLoadingPallets] = useState(false);

  // modal agregar producto
  const [openAddProduct, setOpenAddProduct] = useState(false);

  // modal reabrir pallet + organizar → extraído a useOrganize

  // desvincular pallet
  const [detachingPallet, setDetachingPallet] = useState(null);
  const [confirmDetachPallet, setConfirmDetachPallet] = useState(null);

  // tickets
  const [openAddTicket, setOpenAddTicket] = useState(false);

  const {
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
  } = useOrganize({ items, setPallets: () => refetch(), load });

  const {
    actionItem,
    setActionItem,
    actionQty,
    setActionQty,
    qtyConflict,
    setQtyConflict,
    tryApplyAction,
    setConflictKeep,
    resolveConflictAndSave,
  } = useItemAction({ items, load });

  async function loadAvailablePallets() {
    setLoadingPallets(true);
    try {
      const data = await apiGet(`/pallets?limit=200`);
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

    try {
      await finalize();
      toastSuccess("Pedido finalizado correctamente");
      refetch();
    } catch (e) {
      toastError(
        e?.response?.data?.message || e?.message || "Error al finalizar pedido",
      );
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

      await apiPost(`/orders/${orderId}/items`, body);
      refetch(); // Recargar datos desde el servidor
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

  function rowClass(status) {
    if (status === "done") return "border-green-500";
    if (status === "removed") return "border-red-500 opacity-80";
    // pendiente / default: tarjeta neutra
    return "border-gray-200";
  }

  if (isLoading) return <PageSpinner />;

  if (queryError) {
    return (
      <div className="space-y-3">
        <div className="bg-red-50 border border-red-200 text-red-800 rounded-xl p-3">
          {queryError.message || "Error cargando pedido"}
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

      <OrderHeader
        order={order}
        pallets={pallets}
        modalItems={modalItems}
        onOrganize={openOrganizeModal}
        onDetach={setConfirmDetachPallet}
        onReopen={setReopenModal}
        detachingPallet={detachingPallet}
        canFinalize={canFinalize}
      />

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
                highlightsReady={highlightsReady}
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
              <ActionItem
                icon={Icons.Check}
                iconBg="bg-green-500"
                label={finalizing ? "Finalizando…" : "Finalizar pedido"}
                sublabel="Todos los productos están distribuidos en bases"
                onClick={handleFinalize}
                disabled={finalizing}
                noChevron
              />
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
      {order?.status === "done" ? (
        <DoneOrderProducts
          pallets={pallets}
          items={items}
          pendingItemsCount={pendingItemsCount}
        />
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
      <ItemActionModal
        actionItem={actionItem}
        actionQty={actionQty}
        setActionQty={setActionQty}
        onClose={() => setActionItem(null)}
        onSave={tryApplyAction}
        orderStatus={order?.status}
      />

      {/* ── Modal reabrir pallet para organizar ──────────────────────────────── */}
      {reopenModal && (
        <div className="fixed inset-0 z-50 bg-black/60 flex items-center justify-center p-4">
          <div className="w-full max-w-sm bg-white dark:bg-gray-800 rounded-2xl p-6 space-y-4 shadow-2xl">
            <div className="text-center space-y-2">
              <div className="text-4xl">🔒</div>
              <p className="font-bold text-lg text-gray-900 dark:text-white">Pallet finalizado</p>
              <p className="text-sm text-gray-600 dark:text-gray-300 leading-snug">
                <span className="font-semibold font-mono">
                  {reopenModal.pallet.code}
                </span>{" "}
                está cerrado. Para organizar productos necesitás reabrirlo.
              </p>
              <p className="text-xs text-gray-400 dark:text-gray-500">
                Podés volver a finalizarlo después de hacer los cambios.
              </p>
            </div>
            <div className="flex gap-2">
              <button
                onClick={() => setReopenModal(null)}
                disabled={reopenModal.reopening}
                className="flex-1 rounded-xl py-3 border border-gray-200 dark:border-gray-600 text-sm text-gray-600 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-700 disabled:opacity-50 transition-colors"
              >
                Cancelar
              </button>
              <button
                onClick={reopenAndOrganize}
                disabled={reopenModal.reopening}
                className="flex-1 rounded-xl py-3 bg-amber-500 hover:bg-amber-600 text-white text-sm font-semibold disabled:opacity-60 transition-colors"
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

    </div>
  );
}

