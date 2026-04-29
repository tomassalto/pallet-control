/**
 * EntityCard — Card moderna para pallets y pedidos.
 *
 * Props:
 *   accent   — color del borde izquierdo: "blue" | "green" | "amber" | "gray"
 *   dim      — opacidad reducida (para completados)
 *   badge    — { label, color } — pill de estado
 *   title    — texto principal (código)
 *   meta     — array de strings o null para filas de metadata
 *   action   — nodo JSX opcional al pie (ej: botón Finalizar)
 *   onClick  — handler opcional si no es link (wrappear con Link afuera)
 *   className
 *   children — opcional, contenido libre
 */

const ACCENT = {
  blue:  "bg-blue-500",
  green: "bg-green-500",
  amber: "bg-amber-400",
  gray:  "bg-gray-300 dark:bg-gray-600",
};

const BADGE = {
  blue:  "bg-blue-50 text-blue-700 dark:bg-blue-900/30 dark:text-blue-300",
  green: "bg-green-50 text-green-700 dark:bg-green-900/30 dark:text-green-300",
  amber: "bg-amber-50 text-amber-700 dark:bg-amber-900/30 dark:text-amber-300",
  gray:  "bg-gray-100 text-gray-500 dark:bg-gray-700 dark:text-gray-400",
};

function ChevronRight() {
  return (
    <svg
      className="w-4 h-4 shrink-0"
      fill="none"
      viewBox="0 0 24 24"
      strokeWidth={2.5}
      stroke="currentColor"
    >
      <path strokeLinecap="round" strokeLinejoin="round" d="M9 5l7 7-7 7" />
    </svg>
  );
}

export function StatusBadge({ label, color = "gray" }) {
  return (
    <span
      className={`inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-semibold tracking-wide ${BADGE[color] ?? BADGE.gray}`}
    >
      <span className={`w-1.5 h-1.5 rounded-full ${ACCENT[color] ?? ACCENT.gray}`} />
      {label}
    </span>
  );
}

export default function EntityCard({
  accent = "blue",
  dim = false,
  badge,
  title,
  meta = [],
  action,
  className = "",
  children,
}) {
  return (
    <div
      className={[
        "relative group bg-white dark:bg-gray-800/60",
        "border border-gray-200 dark:border-gray-700/50",
        "rounded-2xl overflow-hidden",
        "shadow-sm hover:shadow-md",
        "hover:-translate-y-0.5",
        "transition-all duration-200 ease-in-out",
        "active:scale-[0.99] active:shadow-none active:translate-y-0",
        dim ? "opacity-60" : "",
        className,
      ]
        .filter(Boolean)
        .join(" ")}
    >
      {/* Borde izquierdo de color */}
      <div
        className={`absolute left-0 top-0 bottom-0 w-1 ${ACCENT[accent] ?? ACCENT.gray}`}
      />

      <div className="pl-4 pr-4 pt-3.5 pb-3.5">
        {/* Fila principal: título + badge + chevron */}
        <div className="flex items-start gap-3">
          <div className="flex-1 min-w-0">
            <div className="flex items-center gap-2 flex-wrap">
              <span className="font-mono font-bold text-base text-gray-900 dark:text-white leading-tight truncate">
                {title}
              </span>
              {badge && <StatusBadge label={badge.label} color={badge.color} />}
            </div>

            {/* Metadata */}
            {meta.filter(Boolean).length > 0 && (
              <div className="mt-1.5 flex flex-wrap gap-x-3 gap-y-0.5">
                {meta.filter(Boolean).map((item, i) => (
                  <span key={i} className="text-xs text-gray-400 dark:text-gray-500">
                    {item}
                  </span>
                ))}
              </div>
            )}

            {children}
          </div>

          {/* Chevron */}
          <div className="mt-0.5 text-gray-300 dark:text-gray-600 group-hover:text-gray-500 dark:group-hover:text-gray-400 transition-colors">
            <ChevronRight />
          </div>
        </div>

        {/* Acción al pie (ej: botón Finalizar) */}
        {action && <div className="mt-3">{action}</div>}
      </div>
    </div>
  );
}
