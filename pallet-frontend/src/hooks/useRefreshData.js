import { useQueryClient } from "@tanstack/react-query";

export function useRefreshData() {
  const queryClient = useQueryClient();

  function refreshOrder(orderId) {
    if (!orderId) return;
    queryClient.invalidateQueries({ queryKey: ["order", orderId] });
    queryClient.invalidateQueries({ queryKey: ["order", "can-finalize", orderId] });
  }

  function refreshPallet(palletId) {
    if (!palletId) return;
    queryClient.invalidateQueries({ queryKey: ["pallet", palletId] });
    queryClient.invalidateQueries({ queryKey: ["pallet", palletId, "bases"] });
  }

  function refreshOrders() {
    queryClient.invalidateQueries({ queryKey: ["orders"] });
  }

  function refreshPallets() {
    queryClient.invalidateQueries({ queryKey: ["pallets"] });
  }

  function refreshDashboard() {
    queryClient.invalidateQueries({ queryKey: ["dashboard"] });
    queryClient.invalidateQueries({ queryKey: ["stats"] });
  }

  function refreshTicket(orderId) {
    if (!orderId) return;
    queryClient.invalidateQueries({ queryKey: ["order", orderId] });
  }

  function refreshGlobal() {
    queryClient.invalidateQueries({ queryKey: ["orders"] });
    queryClient.invalidateQueries({ queryKey: ["pallets"] });
    queryClient.invalidateQueries({ queryKey: ["dashboard"] });
    queryClient.invalidateQueries({ queryKey: ["stats"] });
  }

  return {
    refreshOrder,
    refreshPallet,
    refreshOrders,
    refreshPallets,
    refreshDashboard,
    refreshTicket,
    refreshGlobal,
  };
}