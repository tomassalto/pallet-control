export function relativeDate(iso) {
  if (!iso) return "";
  const d = new Date(iso);
  const now = new Date();
  const diff = Math.floor((now - d) / 1000);
  if (diff < 60) return "hace un momento";
  if (diff < 3600) return `hace ${Math.floor(diff / 60)} min`;
  if (diff < 86400) return `hace ${Math.floor(diff / 3600)} h`;
  return d.toLocaleDateString("es-AR", { day: "numeric", month: "short" });
}

export const INPUT_CLS =
  "w-full border border-gray-300 dark:border-gray-600 rounded-xl px-4 py-3 bg-white dark:bg-gray-700 dark:text-white focus:outline-none focus:ring-2 focus:ring-gray-900 dark:focus:ring-white transition-shadow";

export const SEC_LABEL =
  "text-xs font-semibold uppercase tracking-wider text-gray-400 dark:text-gray-500";

export function onlyDigits(value) {
  return String(value ?? "").replace(/\D/g, "");
}
