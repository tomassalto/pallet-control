/**
 * Animated spinner icon.
 * Usage: <Spinner />   <Spinner size="lg" />   <Spinner className="text-blue-500" />
 *
 * PageSpinner: centred full-page loading state, use instead of bare "Cargando…" text.
 */

const SIZES = {
  sm:  "w-4 h-4 border-2",
  md:  "w-6 h-6 border-2",
  lg:  "w-10 h-10 border-[3px]",
  xl:  "w-14 h-14 border-4",
};

export default function Spinner({ size = "md", className = "" }) {
  const sz = SIZES[size] ?? SIZES.md;
  return (
    <span
      role="status"
      aria-label="Cargando"
      className={[
        "inline-block rounded-full border-current border-r-transparent animate-spin",
        sz,
        className,
      ].join(" ")}
    />
  );
}

/** Drop-in replacement for full-page "Cargando…" text */
export function PageSpinner({ className = "" }) {
  return (
    <div className={`flex items-center justify-center py-20 ${className}`}>
      <Spinner size="lg" className="text-gray-400 dark:text-gray-600" />
    </div>
  );
}

/** Inline row spinner — for "Cargar más" buttons etc. */
export function InlineSpinner({ label = "Cargando…" }) {
  return (
    <span className="inline-flex items-center gap-2">
      <Spinner size="sm" />
      <span>{label}</span>
    </span>
  );
}
