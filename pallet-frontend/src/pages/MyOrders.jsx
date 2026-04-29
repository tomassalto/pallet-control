import { useEffect, useState, useMemo } from "react";
import { Link } from "react-router-dom";
import { apiGet, apiPost } from "../api/client";
import { toastError, toastSuccess } from "../ui/toast";
import Title from "../ui/Title";
import BackButton from "../ui/BackButton";
import Accordion from "../ui/Accordion";
import { PageSpinner, InlineSpinner } from "../ui/Spinner";
import EntityCard from "../ui/EntityCard";

function orderBadge(status) {
  if (status === "done")   return { label: "Completo", color: "green" };
  if (status === "paused") return { label: "Pausado",  color: "amber" };
  return                          { label: "En proceso", color: "blue" };
}

function formatDate(iso) {
  return new Date(iso).toLocaleDateString(undefined, { dateStyle: "medium" });
}

export default function MyOrders() {
  const [loading, setLoading]           = useState(true);
  const [orders, setOrders]             = useState([]);
  const [page, setPage]                 = useState(1);
  const [hasMore, setHasMore]           = useState(false);
  const [canFinalizeMap, setCanFinalizeMap] = useState(new Map());
  const [finalizing, setFinalizing]     = useState(new Set());

  async function load(nextPage = 1, { append = false } = {}) {
    setLoading(true);
    try {
      const res  = await apiGet(`/orders?page=${nextPage}`);
      const rows = Array.isArray(res) ? res : res.data || [];
      setOrders((prev) => (append ? [...prev, ...rows] : rows));
      const current = res.current_page ?? nextPage;
      const last    = res.last_page    ?? nextPage;
      setPage(current);
      setHasMore(current < last);

      const openRows = rows.filter((o) => o.status === "open");
      if (openRows.length > 0) {
        try {
          const result = await apiPost("/orders/can-finalize-batch", {
            order_ids: openRows.map((o) => o.id),
          });
          setCanFinalizeMap((prev) => {
            const next = new Map(prev);
            Object.entries(result).forEach(([id, val]) => next.set(Number(id), val));
            return next;
          });
        } catch { /* silently ignore */ }
      }
    } catch (e) {
      toastError(e?.message || e?.response?.data?.message || "No se pudo cargar pedidos");
    } finally {
      setLoading(false);
    }
  }

  useEffect(() => { load(1, { append: false }); }, []);

  const { openOrders, completedOrders } = useMemo(() => ({
    openOrders:      orders.filter((o) => o.status !== "done"),
    completedOrders: orders.filter((o) => o.status === "done"),
  }), [orders]);

  async function handleFinalize(orderId, e) {
    e.preventDefault();
    e.stopPropagation();

    try {
      const result = await apiPost("/orders/can-finalize-batch", { order_ids: [orderId] });
      if (!result[orderId]) {
        toastError("No se puede finalizar. Debe haber 0 productos pendientes y al menos 1 listo.");
        return;
      }
    } catch { return; }

    if (!window.confirm("¿Estás seguro de finalizar este pedido?")) return;

    setFinalizing((prev) => new Set(prev).add(orderId));
    try {
      await apiPost(`/orders/${orderId}/finalize`);
      toastSuccess("Pedido finalizado correctamente");
      setCanFinalizeMap((prev) => { const n = new Map(prev); n.set(orderId, false); return n; });
      load(1, { append: false });
    } catch (e) {
      toastError(e?.response?.data?.message || "Error al finalizar pedido");
    } finally {
      setFinalizing((prev) => { const n = new Set(prev); n.delete(orderId); return n; });
    }
  }

  function OrderCard({ o, dim = false }) {
    const badge = orderBadge(o.status);
    const meta  = [
      o.customer?.name && `Cliente: ${o.customer.name}`,
      o.created_at     && `Creado el ${formatDate(o.created_at)}`,
    ];
    const canFinalize = o.status === "open" && canFinalizeMap.get(o.id) === true;

    return (
      <Link to={`/order/${o.id}`} className="block">
        <EntityCard
          accent={badge.color}
          dim={dim}
          badge={badge}
          title={`Pedido #${o.code}`}
          meta={meta}
          action={
            canFinalize ? (
              <button
                onClick={(e) => handleFinalize(o.id, e)}
                disabled={finalizing.has(o.id)}
                className="w-full rounded-xl py-2 bg-green-600 hover:bg-green-700 text-white text-sm font-medium disabled:opacity-60 transition-colors"
              >
                {finalizing.has(o.id) ? <InlineSpinner label="Finalizando…" /> : "Finalizar pedido"}
              </button>
            ) : null
          }
        />
      </Link>
    );
  }

  return (
    <div className="space-y-6">
      <div className="flex justify-start">
        <BackButton to="/" />
      </div>

      <div className="flex flex-col gap-1.5 items-center text-center">
        <Title>Mis pedidos</Title>
        <p className="text-sm text-gray-500 dark:text-gray-400 max-w-xs">
          Lista de pedidos. Tocá uno para ver el detalle y ubicación de productos.
        </p>
      </div>

      {loading && orders.length === 0 ? (
        <PageSpinner />
      ) : orders.length === 0 ? (
        <p className="text-sm text-gray-500 dark:text-gray-400 text-center py-10">
          Todavía no hay pedidos en el historial.
        </p>
      ) : (
        <div className="space-y-6">
          {/* Pedidos abiertos */}
          {openOrders.length > 0 && (
            <section className="space-y-2">
              <p className="text-xs font-semibold uppercase tracking-wider text-gray-400 dark:text-gray-500 px-1">
                En proceso ({openOrders.length})
              </p>
              <div className="flex flex-col gap-2">
                {openOrders.map((o) => <OrderCard key={o.id} o={o} />)}
              </div>
            </section>
          )}

          {/* Pedidos completados */}
          {completedOrders.length > 0 && (
            <Accordion title={`Completados (${completedOrders.length})`}>
              <div className="flex flex-col gap-2 pt-1">
                {completedOrders.map((o) => <OrderCard key={o.id} o={o} dim />)}
              </div>
            </Accordion>
          )}

          {openOrders.length === 0 && completedOrders.length === 0 && (
            <p className="text-sm text-gray-500 dark:text-gray-400 text-center py-10">
              No hay pedidos para mostrar.
            </p>
          )}
        </div>
      )}

      {hasMore && (
        <button
          disabled={loading}
          onClick={() => load(page + 1, { append: true })}
          className="w-full rounded-xl py-3 border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 text-sm text-gray-600 dark:text-gray-400 hover:bg-gray-50 dark:hover:bg-gray-700 disabled:opacity-60 transition-colors"
        >
          {loading ? <InlineSpinner label="Cargando…" /> : "Cargar más"}
        </button>
      )}
    </div>
  );
}
