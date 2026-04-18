import { useEffect, useState, useMemo } from "react";
import { Link } from "react-router-dom";
import { apiGet, apiPost } from "../api/client";
import { toastError, toastSuccess } from "../ui/toast";
import Title from "../ui/Title";
import BackButton from "../ui/BackButton";
import Accordion from "../ui/Accordion";

export default function MyOrders() {
  const [loading, setLoading] = useState(true);
  const [orders, setOrders] = useState([]);
  const [page, setPage] = useState(1);
  const [hasMore, setHasMore] = useState(false);
  const [canFinalizeMap, setCanFinalizeMap] = useState(new Map());

  async function load(nextPage = 1, { append = false } = {}) {
    setLoading(true);
    try {
      const res = await apiGet(`/orders?page=${nextPage}`);
      const rows = Array.isArray(res) ? res : res.data || [];

      setOrders((prev) => (append ? [...prev, ...rows] : rows));

      const current = res.current_page ?? nextPage;
      const last = res.last_page ?? nextPage;
      setPage(current);
      setHasMore(current < last);

      // Verificar qué pedidos pueden finalizarse
      const openRows = rows.filter((o) => o.status === "open");
      const canFinalizePromises = openRows.map(async (order) => {
        try {
          const data = await apiGet(`/orders/${order.id}/can-finalize`);
          return { orderId: order.id, canFinalize: data.can_finalize };
        } catch {
          return { orderId: order.id, canFinalize: false };
        }
      });

      const canFinalizeResults = await Promise.all(canFinalizePromises);
      setCanFinalizeMap((prev) => {
        const next = new Map(prev);
        canFinalizeResults.forEach(({ orderId, canFinalize }) => {
          next.set(orderId, canFinalize);
        });
        return next;
      });
    } catch (e) {
      toastError(
        e?.message || e?.response?.data?.message || "No se pudo cargar pedidos"
      );
    } finally {
      setLoading(false);
    }
  }

  useEffect(() => {
    load(1, { append: false });
  }, []);

  const [finalizing, setFinalizing] = useState(new Set());

  const { openOrders, completedOrders } = useMemo(() => {
    const open = orders.filter((o) => o.status !== "done");
    const completed = orders.filter((o) => o.status === "done");
    return { openOrders: open, completedOrders: completed };
  }, [orders]);

  async function checkCanFinalize(orderId) {
    try {
      const data = await apiGet(`/orders/${orderId}/can-finalize`);
      return data.can_finalize;
    } catch {
      return false;
    }
  }

  async function handleFinalize(orderId, e) {
    e.preventDefault();
    e.stopPropagation();

    const canFinalize = await checkCanFinalize(orderId);
    if (!canFinalize) {
      toastError(
        "No se puede finalizar. Debe haber 0 productos pendientes y al menos 1 producto marcado como listo."
      );
      return;
    }

    if (!window.confirm("¿Estás seguro de finalizar este pedido?")) {
      return;
    }

    setFinalizing((prev) => new Set(prev).add(orderId));
    try {
      await apiPost(`/orders/${orderId}/finalize`);
      toastSuccess("Pedido finalizado correctamente");
      // Actualizar el mapa para que el botón desaparezca
      setCanFinalizeMap((prev) => {
        const next = new Map(prev);
        next.set(orderId, false);
        return next;
      });
      load(1, { append: false }); // Recargar lista
    } catch (e) {
      toastError(e?.response?.data?.message || "Error al finalizar pedido");
    } finally {
      setFinalizing((prev) => {
        const next = new Set(prev);
        next.delete(orderId);
        return next;
      });
    }
  }

  function getOrderStatus(order) {
    // El estado del pedido se determina por el status del order
    // 'open' = comenzado, 'done' = completo, 'paused' = pausado
    if (order.status === "done") return "Completo";
    if (order.status === "paused") return "Pausado";
    return "Comenzado";
  }

  return (
    <div className="space-y-4">
      <div className="flex justify-start">
        <BackButton to={`/`} />
      </div>
      <div className="flex flex-col gap-2 items-center">
        <Title size="4xl">Mis pedidos</Title>
        <p className="text-sm text-gray-600 w-[200px]">
          Lista de pedidos. Tocá uno para ver el detalle y ubicación de
          productos.
        </p>
      </div>

      {loading && orders.length === 0 ? (
        <div className="text-sm text-gray-600">Cargando pedidos…</div>
      ) : orders.length === 0 ? (
        <div className="text-sm text-gray-600">
          Todavía no hay pedidos en el historial.
        </div>
      ) : (
        <>
          {/* Pedidos abiertos */}
          {openOrders.length > 0 && (
            <div className="flex flex-col gap-2">
              <div className="text-sm font-semibold text-gray-700">
                Pedidos en proceso ({openOrders.length})
              </div>
              <div className="flex flex-col gap-2">
                {openOrders.map((o) => (
                  <div
                    key={o.id}
                    className="block bg-white border-border rounded-2xl p-2"
                  >
                    <Link
                      to={`/order/${o.id}`}
                      className="block active:scale-[0.99]"
                    >
                      <div className="flex flex-col gap-1">
                        <Title size="2xl" className="font-mono font-semibold">
                          Pedido #{o.code}
                        </Title>

                        <div className=" text-xs text-gray-500">
                          Estado:{" "}
                          <span className="capitalize font-medium">
                            {getOrderStatus(o)}
                          </span>
                        </div>

                        {o.customer && (
                          <div className=" text-xs text-gray-500">
                            Cliente: {o.customer.name}
                          </div>
                        )}

                        {o.created_at && (
                          <div className=" text-xs text-gray-500">
                            Creado:{" "}
                            {new Date(o.created_at).toLocaleString(undefined, {
                              dateStyle: "short",
                              timeStyle: "short",
                            })}
                          </div>
                        )}

                        <div className="text-sm underline">Ver detalle</div>
                      </div>
                    </Link>

                    {o.status === "open" &&
                      canFinalizeMap.get(o.id) === true && (
                        <button
                          onClick={(e) => handleFinalize(o.id, e)}
                          disabled={finalizing.has(o.id)}
                          className="mt-3 w-full rounded-lg py-2 bg-green-600 text-white text-sm disabled:opacity-60"
                        >
                          {finalizing.has(o.id)
                            ? "Finalizando..."
                            : "Finalizar pedido"}
                        </button>
                      )}
                  </div>
                ))}
              </div>
            </div>
          )}

          {/* Pedidos completados */}
          {completedOrders.length > 0 && (
            <Accordion
              title={`Pedidos completados (${completedOrders.length})`}
            >
              <div className="flex flex-col gap-2">
                {completedOrders.map((o) => (
                  <Link
                    key={o.id}
                    to={`/order/${o.id}`}
                    className="block bg-white border border-border rounded-2xl p-4 active:scale-[0.99] opacity-75"
                  >
                    <div className="flex flex-col gap-1">
                      <Title size="2xl" className="font-mono font-semibold">
                        Pedido #{o.code}
                      </Title>

                      <div className="text-xs text-gray-500">
                        Estado:{" "}
                        <span className="capitalize font-medium text-green-600">
                          {getOrderStatus(o)}
                        </span>
                      </div>

                      {o.customer && (
                        <div className=" text-xs text-gray-500">
                          Cliente: {o.customer.name}
                        </div>
                      )}

                      {o.created_at && (
                        <div className=" text-xs text-gray-500">
                          Creado:{" "}
                          {new Date(o.created_at).toLocaleString(undefined, {
                            dateStyle: "short",
                            timeStyle: "short",
                          })}
                        </div>
                      )}

                      <div className="text-sm underline">Ver detalle</div>
                    </div>
                  </Link>
                ))}
              </div>
            </Accordion>
          )}

          {openOrders.length === 0 && completedOrders.length === 0 && (
            <div className="text-sm text-gray-600">
              No hay pedidos para mostrar.
            </div>
          )}
        </>
      )}

      {hasMore && (
        <button
          disabled={loading}
          onClick={() => load(page + 1, { append: true })}
          className="w-full rounded-xl py-3 border bg-white text-sm disabled:opacity-60"
        >
          {loading ? "Cargando..." : "Cargar más"}
        </button>
      )}
    </div>
  );
}
