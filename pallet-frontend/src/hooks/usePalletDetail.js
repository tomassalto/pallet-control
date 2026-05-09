import { useQuery, useMutation, useQueryClient } from "@tanstack/react-query";
import { apiGet, apiPost, apiPatch, apiDelete } from "../api/client";

export function usePalletDetail(palletId) {
  const queryClient = useQueryClient();

  const palletQuery = useQuery({
    queryKey: ["pallet", palletId],
    queryFn: async () => {
      const data = await apiGet(`/pallets/${palletId}`);
      return data;
    },
    enabled: !!palletId,
    staleTime: 30000,
  });

  const canFinalizeQuery = useQuery({
    queryKey: ["pallet", "can-finalize", palletId],
    queryFn: async () => {
      const data = await apiGet(`/pallets/${palletId}/can-finalize`);
      return data;
    },
    enabled: !!palletId && palletQuery.data?.pallet?.status === "open",
  });

  const createBaseMutation = useMutation({
    mutationFn: (baseData) =>
      apiPost(`/pallets/${palletId}/bases`, baseData),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ["pallet", palletId] });
    },
  });

  const updateBaseMutation = useMutation({
    mutationFn: ({ baseId, data }) =>
      apiPatch(`/pallets/${palletId}/bases/${baseId}`, data),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ["pallet", palletId] });
    },
  });

  const deleteBaseMutation = useMutation({
    mutationFn: (baseId) =>
      apiDelete(`/pallets/${palletId}/bases/${baseId}`),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ["pallet", palletId] });
    },
  });

  const updateStatusMutation = useMutation({
    mutationFn: (status) =>
      apiPatch(`/pallets/${palletId}/status`, { status }),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ["pallet", palletId] });
      queryClient.invalidateQueries({ queryKey: ["pallets"] });
    },
  });

  const finalizeMutation = useMutation({
    mutationFn: () => apiPost(`/pallets/${palletId}/finalize`),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ["pallet", palletId] });
    },
  });

  const reopenMutation = useMutation({
    mutationFn: () => apiPost(`/pallets/${palletId}/reopen`),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ["pallet", palletId] });
    },
  });

  return {
    pallet: palletQuery.data?.pallet,
    orders: palletQuery.data?.orders || [],
    bases: palletQuery.data?.bases || [],
    photos: palletQuery.data?.photos || [],
    isLoading: palletQuery.isLoading,
    error: palletQuery.error,
    canFinalize: canFinalizeQuery.data?.can_finalize ?? false,
    refetch: palletQuery.refetch,
    createBase: createBaseMutation.mutate,
    createBaseLoading: createBaseMutation.isPending,
    updateBase: updateBaseMutation.mutate,
    updateBaseLoading: updateBaseMutation.isPending,
    deleteBase: deleteBaseMutation.mutate,
    deleteBaseLoading: deleteBaseMutation.isPending,
    updateStatus: updateStatusMutation.mutate,
    finalize: finalizeMutation.mutate,
    finalizing: finalizeMutation.isPending,
    reopen: reopenMutation.mutate,
    reopening: reopenMutation.isPending,
  };
}