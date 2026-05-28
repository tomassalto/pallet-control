import { STATUS_CONFIG as STATUS } from "../../constants/status";

const EMPTY_LIST = [];

export function getOrderStatusConfig(status) {
  return STATUS[status] ?? STATUS.open;
}

export function formatOrderDate(iso) {
  return new Date(iso).toLocaleDateString("es-AR", {
    day: "numeric",
    month: "short",
  });
}

export function formatPeso(amount) {
  if (!amount || amount <= 0) return null;
  return "$" + Math.round(amount).toLocaleString("es-AR");
}

export function normalizePendingItemIds(rawIds) {
  if (Array.isArray(rawIds)) return rawIds.map(Number).filter(Boolean);
  if (!rawIds) return EMPTY_LIST;
  return String(rawIds)
    .split(",")
    .map(Number)
    .filter(Boolean);
}

export function getOrdersFromPages(pages = EMPTY_LIST) {
  return pages.flatMap((page) =>
    Array.isArray(page) ? page : (page.data ?? EMPTY_LIST),
  );
}
