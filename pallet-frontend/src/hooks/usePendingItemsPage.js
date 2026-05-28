import { useMemo, useState } from "react";
import { useQuery, useQueryClient } from "@tanstack/react-query";
import { apiGet, apiPatch, apiDelete } from "../api/client";
import { toastSuccess, toastError } from "../ui/toast";

export function usePendingItemsPage() {
  const queryClient = useQueryClient();
  const [tab, setTab] = useState("pending");
  const [showCreate, setShowCreate] = useState(false);

  const { data: items = [], isLoading: loading } = useQuery({
    queryKey: ["pending-items"],
    queryFn: () => apiGet("/pending-items"),
    onError: () => toastError("Error al cargar los pendientes"),
  });

  const { data: requests = [], isLoading: loadingRequests } = useQuery({
    queryKey: ["missing-item-requests"],
    queryFn: () => apiGet("/admin/missing-item-requests"),
    onError: () => toastError("Error al cargar las solicitudes"),
  });

  const pendingList = useMemo(
    () => items.filter((item) => item.status === "pending"),
    [items],
  );
  const resolvedList = useMemo(
    () => items.filter((item) => item.status === "resolved"),
    [items],
  );
  const pendingRequestsCount = useMemo(
    () => requests.filter((request) => request.status === "pending_review").length,
    [requests],
  );
  const current = tab === "pending"
    ? pendingList
    : tab === "resolved"
      ? resolvedList
      : requests;

  function handleRequestUpdated(updated) {
    queryClient.setQueryData(["missing-item-requests"], (prev = []) =>
      prev.map((request) => (request.id === updated.id ? updated : request)),
    );
    if (updated.status === "approved") {
      queryClient.invalidateQueries({ queryKey: ["pending-items"] });
    }
  }

  async function handleResolve(id) {
    try {
      const updated = await apiPatch(`/pending-items/${id}`, {
        status: "resolved",
      });
      queryClient.setQueryData(["pending-items"], (prev = []) =>
        prev.map((item) => (item.id === id ? updated : item)),
      );
      toastSuccess("Marcado como entregado");
    } catch (e) {
      toastError(e?.message || "No se pudo marcar como entregado");
    }
  }

  async function handleReopen(id) {
    try {
      const updated = await apiPatch(`/pending-items/${id}`, {
        status: "pending",
      });
      queryClient.setQueryData(["pending-items"], (prev = []) =>
        prev.map((item) => (item.id === id ? updated : item)),
      );
      toastSuccess("Pendiente reabierto");
    } catch (e) {
      toastError(e?.message || "No se pudo reabrir el pendiente");
    }
  }

  async function handleDelete(id) {
    try {
      await apiDelete(`/pending-items/${id}`);
      queryClient.setQueryData(["pending-items"], (prev = []) =>
        prev.filter((item) => item.id !== id),
      );
      toastSuccess("Pendiente eliminado");
    } catch (e) {
      toastError(e?.message || "No se pudo eliminar el pendiente");
    }
  }

  function handleCreated(item) {
    queryClient.setQueryData(["pending-items"], (prev = []) => [item, ...prev]);
    setTab("pending");
  }

  return {
    tab,
    setTab,
    showCreate,
    setShowCreate,
    loading,
    loadingRequests,
    pendingList,
    resolvedList,
    pendingRequestsCount,
    current,
    handleRequestUpdated,
    handleResolve,
    handleReopen,
    handleDelete,
    handleCreated,
  };
}
