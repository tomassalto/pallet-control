import { useEffect, useMemo, useState } from "react";
import { Link, useParams } from "react-router-dom";
import { apiGet, apiPost, apiPatch, apiDelete } from "../api/client";
import { toastSuccess, toastError } from "../ui/toast";
import BackButton from "../ui/BackButton";
import Title from "../ui/Title";
import Accordion from "../ui/Accordion";
import PhotoViewer from "../ui/PhotoViewer";
import QRModal from "../ui/QRModal";

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
  const shortEan = (it.ean && String(it.ean).slice(-4).padStart(4, "0")) || "—";

  return (
    <button
      onClick={onSelect}
      className={`w-full text-left rounded-xl border ${borderColor} ${bgColor} text-black px-3 py-3 text-sm active:scale-[0.99]`}
    >
      <div className="flex items-center justify-between gap-3">
        <div className="flex flex-col gap-1 flex-1 min-w-0">
          <div className="text-[10px] text-gray-500">EAN</div>
          <div className="font-mono font-semibold text-lg">{shortEan}</div>

          <div className={`text-sm ${textClass(it.status)} wrap-break-word`}>
            {it.description}
          </div>

          {/* Badges: precio, descuento MP, controlado */}
          <div className="flex flex-wrap gap-1 mt-0.5">
            {it.price != null && (
              <span className="text-[10px] text-gray-500 font-mono">
                ${Number(it.price).toLocaleString("es-AR", { minimumFractionDigits: 2 })}
              </span>
            )}
            {it.desc_medio_pago != null && (
              <span className="text-[10px] bg-blue-100 text-blue-700 px-1.5 py-0.5 rounded font-medium">
                💳 -{Number(it.desc_medio_pago).toLocaleString("es-AR", { minimumFractionDigits: 2 })}
              </span>
            )}
            {it.is_controlled && (
              <span className="text-[10px] bg-amber-100 text-amber-700 px-1.5 py-0.5 rounded font-medium">
                Controlado
              </span>
            )}
          </div>

          {/* Ubicaciones del producto - solo mostrar si está marcado como "listo" */}
          {it.status === "done" && it.locations && it.locations.length > 0 && (
            <div className="mt-2 space-y-1">
              <div className="text-[10px] text-gray-500">Ubicación:</div>
              {it.locations.map((loc, idx) => (
                <div
                  key={idx}
                  className="text-xs text-gray-600 bg-gray-100 rounded px-2 py-1"
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

  // desvincular pallet
  const [detachingPallet, setDetachingPallet] = useState(null);
  const [confirmDetachPallet, setConfirmDetachPallet] = useState(null);

  // QR modal
  const [showQR, setShowQR] = useState(false);

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
        "No se puede finalizar. Debe haber 0 productos pendientes y al menos 1 producto marcado como listo.",
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
      setItems((prev) => prev.map((it) => (it.id === itemId ? updated : it)));
    } catch (e) {
      toastError(e.message || "No se pudo actualizar");
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

  function rowClass(status) {
    if (status === "done") return "border-green-500";
    if (status === "removed") return "border-red-500 opacity-80";
    // pendiente / default: tarjeta neutra
    return "border-gray-200";
  }

  if (loading) return <div className="text-sm text-gray-600">Cargando…</div>;

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
          <div className="w-full max-w-md bg-white rounded-2xl p-4 max-h-[80vh] flex flex-col">
            <div className="flex items-center justify-between mb-4">
              <div className="font-semibold text-lg">Asociar a pallet</div>
              <button
                onClick={() => setOpenAttachPallet(false)}
                className="px-3 py-2 border rounded-lg text-sm"
              >
                Cerrar
              </button>
            </div>

            {/* Botón crear pallet nuevo */}
            <div className="mb-4">
              <button
                onClick={async () => {
                  try {
                    const pallet = await apiPost(`/pallets`, { note: null });
                    toastSuccess(`Pallet creado: ${pallet.code}`);
                    // Asociar el pallet recién creado al pedido
                    await onAttachPallet(pallet.id);
                  } catch (e) {
                    toastError(
                      e?.data?.message || e?.message || "Error creando pallet",
                    );
                  }
                }}
                className="w-full bg-black text-white rounded-xl p-3 text-sm font-medium active:scale-[0.99]"
              >
                + Crear pallet nuevo
              </button>
            </div>

            <div className="flex-1 overflow-y-auto">
              {loadingPallets ? (
                <div className="text-sm text-gray-600 text-center py-8">
                  Cargando pallets...
                </div>
              ) : availablePallets.length === 0 ? (
                <div className="text-sm text-gray-600 text-center py-8">
                  No hay pallets disponibles para asociar
                </div>
              ) : (
                <>
                  <div className="text-xs text-gray-500 mb-2">
                    Pallets existentes:
                  </div>
                  <div className="space-y-2">
                    {availablePallets.map((pallet) => (
                      <button
                        key={pallet.id}
                        onClick={() => onAttachPallet(pallet.id)}
                        className="w-full text-left bg-white border rounded-xl p-4 hover:bg-gray-50 active:scale-[0.99]"
                      >
                        <div className="text-xs text-gray-500">Código</div>
                        <div className="font-mono font-semibold">
                          {pallet.code}
                        </div>
                        <div className="mt-2 text-xs text-gray-500">Estado</div>
                        <div className="capitalize text-sm font-medium">
                          {pallet.status}
                        </div>
                      </button>
                    ))}
                  </div>
                </>
              )}
            </div>
          </div>
        </div>
      )}

      {pallets.length > 0 ? (
        <div className="flex justify-start">
          <BackButton to={`/pallet/${pallets[0].id}`} />
        </div>
      ) : (
        <div className="flex justify-start">
          <BackButton to="/" />
        </div>
      )}

      <div className="bg-white border border-border rounded-2xl p-4 flex flex-col gap-2">
        <div className="flex items-center justify-between gap-2">
          <Title size="3xl">Pedido #{order?.code}</Title>
          <button
            onClick={() => setShowQR(true)}
            title="Ver QR del pedido"
            className="shrink-0 p-2 rounded-xl border border-gray-200 hover:bg-gray-50 active:scale-95 transition-transform"
          >
            <svg
              xmlns="http://www.w3.org/2000/svg"
              className="w-6 h-6 text-gray-600"
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

        {pallets.length > 0 && (
          <div className="flex flex-col gap-2">
            <div className="text-sm text-gray-500">Pallets asociados:</div>
            {pallets.map((p) => (
              <div
                key={p.id}
                className="flex items-center justify-between bg-light-gray rounded-lg p-2"
              >
                <Link
                  to={`/pallet/${p.id}`}
                  className="flex-1 text-sm text-left text-gray-800 font-semibold hover:underline"
                >
                  {p.code}
                </Link>
                {order?.status !== "done" && (
                  <button
                    onClick={() => setConfirmDetachPallet(p)}
                    disabled={detachingPallet === p.id}
                    className=" px-2 py-1 text-xs  bg-red-600 text-white border border-red-300 rounded hover:bg-red-50 disabled:opacity-50"
                  >
                    Desvincular
                  </button>
                )}
              </div>
            ))}
          </div>
        )}
      </div>

      {/* Tickets */}
      <div className="bg-white flex flex-col gap-2 border p-4 border-border rounded-2xl overflow-hidden">
        <div className="flex flex-col gap-2 items-center justify-center">
          <Title size="2xl">Ticket del pedido</Title>
          {tickets.length === 0 && order?.status !== "done" && (
            <button
              onClick={() => setOpenAddTicket(true)}
              className="text-xs px-2 py-1 border rounded hover:bg-gray-50"
            >
              Agregar ticket
            </button>
          )}
        </div>
        {tickets.length === 0 ? (
          <div className=" text-sm text-gray-500 text-center">
            No hay tickets agregados
          </div>
        ) : (
          <div>
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
      </div>

      {/* Botones de acción */}
      {order?.status !== "done" ? (
        <div className="bg-white border border-border rounded-2xl p-2 grid grid-cols-2 gap-2">
          <Link
            to={`/order/${orderId}/import`}
            className="inline-flex items-center justify-center rounded-xl px-3 py-2 bg-light-gray text-black text-sm active:scale-[0.99]"
          >
            Importar pedido
          </Link>
          <button
            onClick={() => {
              setOpenAttachPallet(true);
              loadAvailablePallets();
            }}
            className="inline-flex items-center justify-center rounded-xl px-3 py-2 border border-border bg-light-gray text-black text-sm sh active:scale-[0.99]"
          >
            Asociar pallet
          </button>
          <button
            onClick={() => setOpenAddProduct(true)}
            className="inline-flex items-center justify-center rounded-xl px-3 py-2 border border-border bg-light-gray text-black text-sm active:scale-[0.99]"
          >
            Agregar producto
          </button>
          <Link
            to={`/order/${orderId}/history`}
            className="inline-flex items-center justify-center rounded-xl px-3 py-2 border border-border bg-light-gray text-black text-sm active:scale-[0.99]"
          >
            Historial
          </Link>
          {order?.status === "open" && canFinalize && (
            <button
              onClick={handleFinalize}
              disabled={finalizing}
              className="col-span-2 rounded-lg py-2 bg-green-600 text-white text-sm disabled:opacity-60"
            >
              {finalizing ? "Finalizando..." : "Finalizar pedido"}
            </button>
          )}
        </div>
      ) : (
        <div className="flex justify-center items-center">
          <Link
            to={`/order/${orderId}/history`}
            className="inline-flex items-center justify-center w-[100px] rounded-xl px-3 py-2 border bg-white text-sm active:scale-[0.99]"
          >
            Historial
          </Link>
        </div>
      )}

      {/* tabs - solo mostrar si el pedido no está finalizado y hay productos */}
      {order?.status !== "done" && items.length > 0 && (
        <div className="bg-white border-border rounded-2xl p-2 grid grid-cols-3 gap-2 text-sm">
          <button
            onClick={() => setTab("pending")}
            className={`rounded-xl py-2 border text-sm ${
              tab === "pending"
                ? "bg-black text-white border-black font-semibold"
                : "bg-white text-gray-500 border-gray-200"
            }`}
          >
            Pend.
          </button>
          <button
            onClick={() => setTab("done")}
            className={`rounded-xl py-2 border text-sm ${
              tab === "done"
                ? "bg-black text-white border-black font-semibold"
                : "bg-white text-gray-500 border-gray-200"
            }`}
          >
            Listos
          </button>
          <button
            onClick={() => setTab("removed")}
            className={`rounded-xl py-2 border text-sm ${
              tab === "removed"
                ? "bg-black text-white border-black font-semibold"
                : "bg-white text-gray-500 border-gray-200"
            }`}
          >
            Quit.
          </button>
        </div>
      )}

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
        // Mensaje cuando no hay productos
        <div className="bg-white border-border rounded-2xl p-8 text-center">
          <div className="text-gray-600">
            <div className="font-semibold text-base mb-2">
              No hay productos en este pedido
            </div>
            <div className="text-sm text-gray-500">
              Importá un pedido para comenzar a utilizar las funciones
            </div>
          </div>
        </div>
      ) : (
        // Vista normal con tabs para pedidos no finalizados
        <div className="bg-white border border-border rounded-2xl overflow-hidden">
          <div className="px-4 py-3">
            <div className="font-semibold">Ítems</div>
            <div className="text-xs text-gray-500">
              Pendiente / Listo / Quitado
            </div>
          </div>

          {filtered.length === 0 ? (
            <div className="p-4 text-sm text-gray-600">
              No hay ítems en este filtro.
            </div>
          ) : (
            <div className="p-2 flex flex-col gap-3">
              {filtered.map((it) => (
                <ItemCard
                  key={it.id}
                  item={it}
                  onSelect={() => {
                    setActionItem(it);
                    // Si está removido, inicializar con 0, sino con la cantidad actual
                    setActionQty(
                      it.status === "removed" ? "0" : String(it.qty ?? ""),
                    );
                  }}
                  borderColor={rowClass(it.status)}
                  bgColor="bg-gray-50"
                />
              ))}
            </div>
          )}

          <button
            onClick={load}
            className="m-4 w-[calc(100%-2rem)] rounded-lg py-2 border text-sm"
          >
            Refrescar
          </button>
        </div>
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
                  onClick={async () => {
                    const q = parseInt(onlyDigits(actionQty), 10);
                    if (isNaN(q) || q < 0) {
                      toastError("La cantidad debe ser 0 o mayor.");
                      return;
                    }

                    if (q === 0) {
                      // Quitar producto
                      await updateItem(actionItem.id, { status: "removed" });
                      toastSuccess("Producto quitado");
                    } else {
                      // Marcar como listo con la cantidad especificada
                      await updateItem(actionItem.id, {
                        status: "done",
                        qty: q,
                      });
                      toastSuccess(
                        `Producto marcado como listo: ${q} unidades`,
                      );
                    }
                    setActionItem(null);
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

      {/* QR Modal */}
      {showQR && order && (
        <QRModal order={order} onClose={() => setShowQR(false)} />
      )}
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
      e.target.value = ""; // Reset input
    }
  }

  async function handleDeletePhoto(photoId) {
    if (!window.confirm("¿Eliminar esta foto?")) return;

    try {
      await apiDelete(
        `/orders/${orderId}/tickets/${ticket.id}/photos/${photoId}`,
      );
      toastSuccess("Foto eliminada");
      onUpdate();
    } catch (e) {
      toastError(
        e.response?.data?.message || e.message || "Error eliminando foto",
      );
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
      toastError(
        e.response?.data?.message || e.message || "Error eliminando ticket",
      );
    } finally {
      setDeleting(false);
    }
  }

  return (
    <div className="bg-light-gray rounded-lg p-2 w-full flex flex-col gap-2">
      <div className="flex items-center justify-between w-full">
        <div className="font-semibold text-sm text-left min-w-[100px]">
          {ticket.code || "Sin código"}
        </div>
        {ticket.note && (
          <div className="text-xs text-gray-500 text-left flex-1 mx-2">
            {ticket.note}
          </div>
        )}
        <div className="flex gap-2">
          <button
            onClick={() => setExpanded(!expanded)}
            className="text-xs px-2 py-1 bg-[#101652] text-white border rounded hover:bg-gray-50"
          >
            {expanded ? "Ocultar" : "Ver fotos"}
          </button>
          <button
            onClick={() => setConfirmDeleteTicket(true)}
            className="text-xs px-2 py-1 border border-red-300  bg-red-600 text-white rounded hover:bg-red-50"
          >
            Eliminar
          </button>
        </div>
      </div>

      {expanded && (
        <div className="space-y-2 mt-2 w-full">
          <div className="grid grid-cols-2 gap-2 w-full">
            {ticket.photos?.map((photo) => {
              const photoUrl = getPhotoUrl(photo);
              return (
                <div
                  key={photo.id}
                  className="relative aspect-square rounded-lg overflow-hidden border bg-gray-100 min-h-[120px]"
                >
                  <button
                    onClick={() => setSelectedPhoto(photo)}
                    className="w-full h-full flex items-center justify-center"
                  >
                    <img
                      src={photoUrl}
                      alt={`Foto ${photo.id}`}
                      className="w-full h-full object-cover"
                      onError={(e) => {
                        e.target.style.display = "none";
                        e.target.parentElement.innerHTML =
                          '<div class="w-full h-full flex items-center justify-center text-gray-400 text-xs">Error cargando imagen</div>';
                      }}
                    />
                  </button>
                  <button
                    onClick={(e) => {
                      e.stopPropagation();
                      handleDeletePhoto(photo.id);
                    }}
                    className="absolute top-1 right-1 bg-red-600 text-white rounded-full w-6 h-6 flex items-center justify-center text-xs z-10 hover:bg-red-700"
                  >
                    ✕
                  </button>
                </div>
              );
            })}
            <label className="relative aspect-square rounded-lg overflow-hidden border-2 border-dashed border-gray-300 bg-gray-50 flex items-center justify-center cursor-pointer hover:bg-gray-100 hover:border-gray-400 active:scale-[0.98] transition-colors disabled:opacity-50 disabled:cursor-not-allowed">
              <input
                type="file"
                accept="image/*"
                className="hidden"
                onChange={handleUploadPhoto}
                disabled={uploading}
              />
              <div className="flex flex-col items-center justify-center text-gray-400">
                {uploading ? (
                  <>
                    <div className="animate-spin rounded-full h-6 w-6 border-b-2 border-gray-600 mb-1"></div>
                    <div className="text-xs text-gray-500">Subiendo...</div>
                  </>
                ) : (
                  <svg
                    xmlns="http://www.w3.org/2000/svg"
                    className="h-8 w-8"
                    fill="none"
                    viewBox="0 0 24 24"
                    stroke="currentColor"
                    strokeWidth={2}
                  >
                    <path
                      strokeLinecap="round"
                      strokeLinejoin="round"
                      d="M12 4v16m8-8H4"
                    />
                  </svg>
                )}
              </div>
            </label>
          </div>
        </div>
      )}

      {/* Modal para ver foto ampliada */}
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
              className="bg-white rounded-2xl p-6 w-full max-w-md space-y-4"
              onClick={(e) => e.stopPropagation()}
            >
              <div className="space-y-2">
                <div className="font-semibold text-lg">¿Eliminar ticket?</div>
                <div className="text-sm text-gray-700">
                  Estás por eliminar el ticket{" "}
                  <span className="font-semibold">
                    {ticket.code || "Sin código"}
                  </span>
                  . Esta acción no se puede deshacer.
                </div>
              </div>

              <div className="bg-red-50 border border-red-200 rounded-lg p-4">
                <div className="text-sm text-red-800">
                  <div className="font-semibold mb-1">ADVERTENCIA:</div>
                  <div>
                    Se eliminarán todas las fotos asociadas a este ticket.
                  </div>
                </div>
              </div>

              <div className="flex gap-2">
                <button
                  onClick={handleDeleteTicket}
                  disabled={deleting}
                  className="flex-1 rounded-lg py-3 bg-red-600 text-white disabled:opacity-60"
                >
                  {deleting ? "Eliminando..." : "Eliminar"}
                </button>
                <button
                  onClick={() => setConfirmDeleteTicket(false)}
                  className="flex-1 rounded-lg py-3 border bg-white text-gray-700 hover:bg-gray-50"
                >
                  Cancelar
                </button>
              </div>
            </div>
          </div>
        </>
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

  async function handleCreateTicket(e) {
    e.preventDefault();
    setSaving(true);

    try {
      const data = await apiPost(`/orders/${orderId}/tickets`, {
        code: code || null,
        note: note || null,
      });

      setTicketId(data.id);
      toastSuccess("Ticket creado. Ahora podés agregar fotos.");
    } catch (e) {
      toastError(
        e.response?.data?.message || e.message || "Error creando ticket",
      );
    } finally {
      setSaving(false);
    }
  }

  async function handleUploadPhoto(e) {
    const file = e.target.files?.[0];
    if (!file) return;

    if (!ticketId) {
      toastError("Primero creá el ticket");
      return;
    }

    setUploading(true);
    try {
      const form = new FormData();
      form.append("photo", file);

      await apiPost(`/orders/${orderId}/tickets/${ticketId}/photos`, form);

      toastSuccess("Foto agregada");
    } catch (e) {
      toastError(
        e.response?.data?.message || e.message || "Error subiendo foto",
      );
    } finally {
      setUploading(false);
      e.target.value = ""; // Reset input
    }
  }

  function handleFinish() {
    onSuccess();
  }

  return (
    <div className="fixed inset-0 z-50 bg-black/80 flex items-center justify-center p-4">
      <div className="bg-white rounded-2xl p-6 max-w-md w-full max-h-[90vh] overflow-y-auto">
        <div className="flex items-center justify-between mb-4">
          <h3 className="text-lg font-semibold">Agregar ticket</h3>
          <button
            onClick={onClose}
            className="text-gray-500 hover:text-gray-700"
          >
            ✕
          </button>
        </div>

        {!ticketId ? (
          <form onSubmit={handleCreateTicket} className="space-y-4">
            <div>
              <label className="block text-sm font-medium mb-1">
                Código del ticket (opcional)
              </label>
              <input
                type="text"
                value={code}
                onChange={(e) => setCode(e.target.value)}
                className="w-full border rounded-lg px-3 py-2"
                placeholder="Ej: TKT-12345"
              />
            </div>

            <div>
              <label className="block text-sm font-medium mb-1">
                Nota (opcional)
              </label>
              <textarea
                value={note}
                onChange={(e) => setNote(e.target.value)}
                className="w-full border rounded-lg px-3 py-2"
                rows={3}
                placeholder="Notas adicionales..."
              />
            </div>

            <div className="flex gap-2">
              <button
                type="button"
                onClick={onClose}
                className="flex-1 border rounded-lg py-2 text-sm"
              >
                Cancelar
              </button>
              <button
                type="submit"
                disabled={saving}
                className="flex-1 bg-black text-white rounded-lg py-2 text-sm disabled:opacity-50"
              >
                {saving ? "Creando..." : "Crear ticket"}
              </button>
            </div>
          </form>
        ) : (
          <div className="space-y-4">
            <div className="bg-green-50 border border-green-200 rounded-lg p-3 text-sm text-green-800">
              ✓ Ticket creado. Agregá fotos del ticket (podés agregar varias).
            </div>

            <div>
              <label className="block text-sm font-medium mb-2">
                Agregar foto del ticket
              </label>
              <label className="block w-full border-2 border-dashed border-gray-300 rounded-lg p-4 text-center cursor-pointer hover:bg-gray-50">
                <input
                  type="file"
                  accept="image/*"
                  className="hidden"
                  onChange={handleUploadPhoto}
                  disabled={uploading}
                />
                {uploading ? (
                  <div className="flex flex-col items-center">
                    <div className="animate-spin rounded-full h-8 w-8 border-b-2 border-gray-600 mb-2"></div>
                    <div className="text-sm text-gray-500">Subiendo...</div>
                  </div>
                ) : (
                  <div className="flex flex-col items-center">
                    <svg
                      xmlns="http://www.w3.org/2000/svg"
                      className="h-12 w-12 text-gray-400"
                      fill="none"
                      viewBox="0 0 24 24"
                      stroke="currentColor"
                      strokeWidth={2}
                    >
                      <path
                        strokeLinecap="round"
                        strokeLinejoin="round"
                        d="M12 4v16m8-8H4"
                      />
                    </svg>
                    <div className="mt-2 text-sm text-gray-600">
                      Tocar para agregar foto
                    </div>
                    <div className="text-xs text-gray-400 mt-1">
                      Podés agregar varias fotos
                    </div>
                  </div>
                )}
              </label>
            </div>

            <div className="flex gap-2">
              <button
                onClick={handleFinish}
                className="flex-1 bg-black text-white rounded-lg py-2 text-sm"
              >
                Finalizar
              </button>
            </div>
          </div>
        )}
      </div>
    </div>
  );
}
