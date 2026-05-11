export const PALLET_COLORS = ["#3B82F6", "#F97316", "#A855F7", "#10B981", "#EC4899"];
export const SPLIT_COLOR = "#6B7280";

export function palletColor(colorIndex) {
  return PALLET_COLORS[colorIndex % PALLET_COLORS.length] ?? SPLIT_COLOR;
}
