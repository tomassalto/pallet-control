export const SEC_LABEL =
  "text-xs font-semibold uppercase tracking-wider text-gray-400 dark:text-gray-500";

export const BTN_PRI =
  "w-full py-3 px-4 rounded-xl bg-gray-900 dark:bg-white text-white dark:text-gray-900 text-sm font-semibold hover:bg-gray-700 dark:hover:bg-gray-100 disabled:opacity-40 transition-colors";

export const BTN_GREEN =
  "w-full py-3 px-4 rounded-xl bg-green-600 text-white text-sm font-semibold hover:bg-green-700 disabled:opacity-40 transition-colors";

export const BTN_BLUE =
  "w-full py-3 px-4 rounded-xl bg-blue-600 text-white text-sm font-semibold hover:bg-blue-700 disabled:opacity-40 transition-colors";

export const INPUT_CLS =
  "w-full border border-gray-300 dark:border-gray-600 rounded-xl px-3 py-2.5 text-sm bg-gray-50 dark:bg-gray-700 dark:text-white focus:outline-none focus:ring-2 focus:ring-gray-900 dark:focus:ring-white transition-shadow";

export function onlyDigits(value) {
  return (value || "").replace(/\D/g, "");
}

export function formatPalletDate(iso) {
  return new Date(iso).toLocaleDateString(undefined, { dateStyle: "medium" });
}
