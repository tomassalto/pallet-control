import { useMemo, useState } from "react";
import { useQuery, useQueryClient } from "@tanstack/react-query";
import { useSearchParams } from "react-router-dom";
import { apiGet, apiPatch, apiDelete } from "../api/client";
import { toastSuccess, toastError } from "../ui/toast";

export function usePendingItemsPage() {
  const queryClient = useQueryClient();
  const [searchParams] = useSearchParams();
  const initialTab = searchParams.get("tab") === "requests" ? "requests" : "pending";
  const [tab, setTab] = useState(initialTab);
  const [showCreate, setShowCreate] = useState(false);
  const [pendingPage, setPendingPage] = useState(1);
  const [resolvedPage, setResolvedPage] = useState(1);

  const { data: pendingResponse, isLoading: loadingPending, isFetching: fetchingPending } = useQuery({
    queryKey: ["pending-items", "pending", pendingPage],
    queryFn: () => apiGet(`/pending-items?status=pending&page=${pendingPage}&per_page=25`),
    placeholderData: (previous) => previous,
    onError: () => toastError("Error al cargar los pendientes"),
  });

  const { data: resolvedResponse, isLoading: loadingResolved, isFetching: fetchingResolved } = useQuery({
    queryKey: ["pending-items", "resolved", resolvedPage],
    queryFn: () => apiGet(`/pending-items?status=resolved&page=${resolvedPage}&per_page=25`),
    placeholderData: (previous) => previous,
    onError: () => toastError("Error al cargar los pendientes resueltos"),
  });

  const { data: requests = [], isLoading: loadingRequests } = useQuery({
    queryKey: ["missing-item-requests"],
    queryFn: () => apiGet("/admin/missing-item-requests"),
    onError: () => toastError("Error al cargar las solicitudes"),
  });

  const pendingList = pendingResponse?.data ?? [];
  const resolvedList = resolvedResponse?.data ?? [];
  const pendingMeta = pendingResponse?.meta ?? { current_page: 1, last_page: 1, total: 0 };
  const resolvedMeta = resolvedResponse?.meta ?? { current_page: 1, last_page: 1, total: 0 };
  const pendingRequestsCount = useMemo(
    () => requests.filter((request) => request.status === "pending_review").length,
    [requests],
  );
  const current = tab === "pending"
    ? pendingList
    : tab === "resolved"
      ? resolvedList
      : requests;
  const pagination = tab === "pending"
    ? { ...pendingMeta, setPage: setPendingPage, fetching: fetchingPending }
    : tab === "resolved"
      ? { ...resolvedMeta, setPage: setResolvedPage, fetching: fetchingResolved }
      : null;
  const loading = tab === "pending" ? loadingPending : loadingResolved;

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
      await apiPatch(`/pending-items/${id}`, {
        status: "resolved",
      });
      setPendingPage(1);
      setResolvedPage(1);
      queryClient.invalidateQueries({ queryKey: ["pending-items"] });
      toastSuccess("Marcado como entregado");
    } catch (e) {
      toastError(e?.message || "No se pudo marcar como entregado");
    }
  }

  async function handleReopen(id) {
    try {
      await apiPatch(`/pending-items/${id}`, {
        status: "pending",
      });
      setPendingPage(1);
      setResolvedPage(1);
      queryClient.invalidateQueries({ queryKey: ["pending-items"] });
      toastSuccess("Pendiente reabierto");
    } catch (e) {
      toastError(e?.message || "No se pudo reabrir el pendiente");
    }
  }

  async function handleDelete(id) {
    try {
      await apiDelete(`/pending-items/${id}`);
      if (tab === "pending") setPendingPage(1);
      if (tab === "resolved") setResolvedPage(1);
      queryClient.invalidateQueries({ queryKey: ["pending-items"] });
      toastSuccess("Pendiente eliminado");
    } catch (e) {
      toastError(e?.message || "No se pudo eliminar el pendiente");
    }
  }

  function handleCreated() {
    setPendingPage(1);
    queryClient.invalidateQueries({ queryKey: ["pending-items"] });
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
    pendingTotal: pendingMeta.total,
    resolvedTotal: resolvedMeta.total,
    pendingRequestsCount,
    current,
    pagination,
    handleRequestUpdated,
    handleResolve,
    handleReopen,
    handleDelete,
    handleCreated,
  };
}
