export const STATUS_CONFIG = {
  open: {
    label: "En proceso",
    color: "blue",
    bg: "bg-blue-50 dark:bg-blue-900/20",
    text: "text-blue-700 dark:text-blue-300",
    border: "border-blue-200 dark:border-blue-800",
    accent: "bg-blue-500",
    badge: "bg-blue-50 text-blue-700 dark:bg-blue-900/30 dark:text-blue-300",
    bar: "bg-blue-500",
  },
  paused: {
    label: "Pausado",
    color: "amber",
    bg: "bg-amber-50 dark:bg-amber-900/20",
    text: "text-amber-700 dark:text-amber-300",
    border: "border-amber-200 dark:border-amber-800",
    accent: "bg-amber-500",
    badge: "bg-amber-50 text-amber-700 dark:bg-amber-900/30 dark:text-amber-300",
    bar: "bg-amber-500",
  },
  done: {
    label: "Completo",
    color: "green",
    bg: "bg-green-50 dark:bg-green-900/20",
    text: "text-green-700 dark:text-green-300",
    border: "border-green-200 dark:border-green-800",
    accent: "bg-green-500",
    badge: "bg-green-50 text-green-700 dark:bg-green-900/30 dark:text-green-300",
    bar: "bg-green-500",
  },
};

export const PALLET_STATUS_CONFIG = {
  open:   { ...STATUS_CONFIG.open,   label: "Abierto" },
  paused: { ...STATUS_CONFIG.paused, label: "Pausado" },
  done:   { ...STATUS_CONFIG.done,   label: "Cerrado" },
};

export function getStatusConfig(status) {
  return STATUS_CONFIG[status] ?? STATUS_CONFIG.open;
}

export function getPalletStatusConfig(status) {
  return PALLET_STATUS_CONFIG[status] ?? PALLET_STATUS_CONFIG.open;
}