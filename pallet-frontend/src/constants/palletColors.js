export const PALETTE_COLORS = [
  {
    hex: "#3B82F6",
    bg: "bg-blue-50 dark:bg-blue-950/40",
    border: "border-blue-200 dark:border-blue-800",
    badge: "bg-blue-600",
    dot: "bg-blue-500",
    customer: "text-gray-700 dark:text-blue-200",
  },
  {
    hex: "#10B981",
    bg: "bg-emerald-50 dark:bg-emerald-950/40",
    border: "border-emerald-200 dark:border-emerald-800",
    badge: "bg-emerald-600",
    dot: "bg-emerald-500",
    customer: "text-gray-700 dark:text-emerald-200",
  },
  {
    hex: "#A855F7",
    bg: "bg-violet-50 dark:bg-violet-950/40",
    border: "border-violet-200 dark:border-violet-800",
    badge: "bg-violet-600",
    dot: "bg-violet-500",
    customer: "text-gray-700 dark:text-violet-200",
  },
  {
    hex: "#F59E0B",
    bg: "bg-amber-50 dark:bg-amber-950/40",
    border: "border-amber-200 dark:border-amber-800",
    badge: "bg-amber-500",
    dot: "bg-amber-400",
    customer: "text-gray-700 dark:text-amber-200",
  },
  {
    hex: "#EC4899",
    bg: "bg-rose-50 dark:bg-rose-950/40",
    border: "border-rose-200 dark:border-rose-800",
    badge: "bg-rose-600",
    dot: "bg-rose-500",
    customer: "text-gray-700 dark:text-rose-200",
  },
  {
    hex: "#06B6D4",
    bg: "bg-cyan-50 dark:bg-cyan-950/40",
    border: "border-cyan-200 dark:border-cyan-800",
    badge: "bg-cyan-600",
    dot: "bg-cyan-500",
    customer: "text-gray-700 dark:text-cyan-200",
  },
  {
    hex: "#8B5CF6",
    bg: "bg-purple-50 dark:bg-purple-950/40",
    border: "border-purple-200 dark:border-purple-800",
    badge: "bg-purple-600",
    dot: "bg-purple-500",
    customer: "text-gray-700 dark:text-purple-200",
  },
];

export function getPalletColorTheme(index) {
  return PALETTE_COLORS[index % PALETTE_COLORS.length];
}

export const SPLIT_COLOR = "#6B7280";

export const PALLET_COLORS = PALETTE_COLORS.map((c) => c.hex);

export function palletColor(colorIndex) {
  return getPalletColorTheme(colorIndex).hex;
}