import { useMemo, useState } from "react";
import { useInfiniteQuery, useQueryClient } from "@tanstack/react-query";
import { useSearchParams } from "react-router-dom";
import { apiGet, apiPost } from "../api/client";
import { toastError, toastSuccess } from "../ui/toast";
import { getOrdersFromPages } from "../features/orders/myOrdersUtils";

export function useMyOrdersPage() {
  const [searchParams, setSearchParams] = useSearchParams();
  const [canFinalizeMap, setCanFinalizeMap] = useState(new Map());
  const [finalizing, setFinalizing] = useState(new Set());
  const queryClient = useQueryClient();

  const dateFrom = searchParams.get("date_from") ?? "";
  const dateTo = searchParams.get("date_to") ?? "";

  function handleDateChange(from, to) {
    const params = {};
    if (from) params.date_from = from;
    if (to) params.date_to = to;
    setSearchParams(params);
  }

  const { data, fetchNextPage, hasNextPage, isFetchingNextPage, isLoading } =
    useInfiniteQuery({
      queryKey: ["orders", { dateFrom, dateTo }],
      queryFn: async ({ pageParam = 1 }) => {
        let url = `/orders?page=${pageParam}`;
        if (dateFrom) url += `&date_from=${dateFrom}`;
        if (dateTo) url += `&date_to=${dateTo}`;
        const res = await apiGet(url);
        const rows = Array.isArray(res) ? res : (res.data ?? []);
        const openIds = rows
          .filter((order) => order.status === "open")
          .map((order) => order.id);

        if (openIds.length > 0) {
          apiPost("/orders/can-finalize-batch", { order_ids: openIds })
            .then((result) => {
              setCanFinalizeMap((prev) => {
                const next = new Map(prev);
                Object.entries(result).forEach(([id, value]) =>
                  next.set(Number(id), value),
                );
                return next;
              });
            })
            .catch(() => {});
        }

        return res;
      },
      initialPageParam: 1,
      getNextPageParam: (lastPage) => {
        if (Array.isArray(lastPage)) return undefined;
        const current = lastPage.current_page ?? 1;
        const last = lastPage.last_page ?? 1;
        return current < last ? current + 1 : undefined;
      },
    });

  const orders = useMemo(
    () => getOrdersFromPages(data?.pages),
    [data?.pages],
  );
  const { openOrders, completedOrders } = useMemo(
    () => ({
      openOrders: orders.filter((order) => order.status !== "done"),
      completedOrders: orders.filter((order) => order.status === "done"),
    }),
    [orders],
  );

  async function handleFinalize(orderId) {
    try {
      const result = await apiPost("/orders/can-finalize-batch", {
        order_ids: [orderId],
      });
      if (!result[orderId]) {
        toastError(
          "No se puede finalizar. Todos los productos deben estar distribuidos en bases.",
        );
        return;
      }
    } catch {
      return;
    }

    if (!window.confirm("¿Estás seguro de finalizar este pedido?")) return;

    setFinalizing((prev) => new Set(prev).add(orderId));
    try {
      await apiPost(`/orders/${orderId}/finalize`);
      toastSuccess("Pedido finalizado correctamente");
      setCanFinalizeMap((prev) => {
        const next = new Map(prev);
        next.set(orderId, false);
        return next;
      });
      queryClient.invalidateQueries({ queryKey: ["orders"] });
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

  return {
    dateFrom,
    dateTo,
    orders,
    openOrders,
    completedOrders,
    canFinalizeMap,
    finalizing,
    fetchNextPage,
    hasNextPage,
    isFetchingNextPage,
    isLoading,
    handleDateChange,
    handleFinalize,
  };
}
