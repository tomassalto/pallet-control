import { useQuery, useMutation, useQueryClient } from "@tanstack/react-query";
import { apiGet, apiPost, apiDelete, apiPatch } from "../api/client";

export function useOrderDetail(orderId) {
  const queryClient = useQueryClient();

  const orderQuery = useQuery({
    queryKey: ["order", orderId],
    queryFn: async () => {
      const data = await apiGet(`/orders/${orderId}`);
      return data;
    },
    enabled: !!orderId,
    staleTime: 30000,
  });

  const canFinalizeQuery = useQuery({
    queryKey: ["order", "can-finalize", orderId],
    queryFn: async () => {
      const data = await apiGet(`/orders/${orderId}/can-finalize`);
      return data;
    },
    enabled: !!orderId && orderQuery.data?.order?.status === "open",
  });

  const attachPalletMutation = useMutation({
    mutationFn: (palletId) =>
      apiPost(`/orders/${orderId}/attach-pallet`, { pallet_id: palletId }),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ["order", orderId] });
    },
  });

  const detachPalletMutation = useMutation({
    mutationFn: (palletId) =>
      apiDelete(`/orders/${orderId}/detach-pallet/${palletId}`),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ["order", orderId] });
    },
  });

  const finalizeMutation = useMutation({
    mutationFn: () => apiPost(`/orders/${orderId}/finalize`),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ["order", orderId] });
      queryClient.invalidateQueries({ queryKey: ["orders"] });
    },
  });

  const updateStatusMutation = useMutation({
    mutationFn: (status) =>
      apiPatch(`/orders/${orderId}/status`, { status }),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ["order", orderId] });
    },
  });

  return {
    order: orderQuery.data?.order,
    pallets: orderQuery.data?.pallets || [],
    items: orderQuery.data?.items || [],
    tickets: orderQuery.data?.order?.tickets || [],
    highlightsReady: orderQuery.data?.highlights_ready ?? false,
    pendingItemsCount: orderQuery.data?.pending_items_count ?? 0,
    pendingItemIds: orderQuery.data?.pending_item_ids ?? [],
    isLoading: orderQuery.isLoading,
    error: orderQuery.error,
    canFinalize: canFinalizeQuery.data?.can_finalize ?? false,
    canFinalizeLoading: canFinalizeQuery.isLoading,
    refetch: orderQuery.refetch,
    attachPallet: attachPalletMutation.mutate,
    attachPalletLoading: attachPalletMutation.isPending,
    detachPallet: detachPalletMutation.mutate,
    detachPalletLoading: detachPalletMutation.isPending,
    finalize: finalizeMutation.mutate,
    finalizing: finalizeMutation.isPending,
    updateStatus: updateStatusMutation.mutate,
  };
}

export function useAvailablePallets(excludeOrderId) {
  return useQuery({
    queryKey: ["pallets", "available", excludeOrderId],
    queryFn: async () => {
      const data = await apiGet(`/pallets?limit=200`);
      const allPallets = Array.isArray(data) ? data : data.data || [];
      return allPallets;
    },
    staleTime: 60000,
  });
}