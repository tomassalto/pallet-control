/**
 * ActionItem — fila de acción estilo iOS: ícono con fondo de color + label + chevron.
 * Inmediatamente legible como "elemento tapeable" en mobile y desktop.
 *
 * Props:
 *   icon     — nodo SVG (se le aplica text-white)
 *   iconBg   — clase Tailwind de bg para el cuadrado del ícono. Ej: "bg-blue-500"
 *   label    — texto principal
 *   sublabel — texto secundario opcional
 *   to       — si se provee, renderiza como <Link>
 *   onClick  — handler para <button>
 *   disabled
 *   noChevron — ocultar la flecha derecha
 *   variant  — "default" | "danger"
 *   className
 */
import { Link } from "react-router-dom";

function Chevron({ danger }) {
  return (
    <svg
      className={`w-4 h-4 flex-shrink-0 ${danger ? "text-red-300 dark:text-red-800" : "text-gray-300 dark:text-gray-600"}`}
      fill="none"
      viewBox="0 0 24 24"
      strokeWidth={2.5}
      stroke="currentColor"
    >
      <path strokeLinecap="round" strokeLinejoin="round" d="M9 5l7 7-7 7" />
    </svg>
  );
}

function Inner({ icon, iconBg, label, sublabel, noChevron, danger }) {
  return (
    <>
      {/* Ícono */}
      <div className={`w-10 h-10 rounded-xl flex items-center justify-center flex-shrink-0 shadow-sm ${iconBg}`}>
        <span className="text-white [&>svg]:w-5 [&>svg]:h-5">{icon}</span>
      </div>

      {/* Texto */}
      <div className="flex-1 text-left min-w-0">
        <span className={`text-sm font-semibold block leading-snug ${danger ? "text-red-700 dark:text-red-400" : "text-gray-800 dark:text-gray-100"}`}>
          {label}
        </span>
        {sublabel && (
          <span className={`text-xs ${danger ? "text-red-400 dark:text-red-600" : "text-gray-400 dark:text-gray-500"}`}>
            {sublabel}
          </span>
        )}
      </div>

      {/* Chevron */}
      {!noChevron && <Chevron danger={danger} />}
    </>
  );
}

export function ActionItem({
  icon,
  iconBg = "bg-gray-500",
  label,
  sublabel,
  to,
  onClick,
  disabled = false,
  noChevron = false,
  variant = "default",
  className = "",
}) {
  const isDanger = variant === "danger";

  const base = [
    "group flex items-center gap-3.5 w-full px-3.5 py-3",
    "border rounded-2xl shadow-sm",
    "hover:shadow-md hover:-translate-y-0.5",
    "active:scale-[0.99] active:shadow-none active:translate-y-0",
    "transition-all duration-150",
  ].join(" ");

  const theme = isDanger
    ? "bg-red-50 dark:bg-red-950/20 border-red-200 dark:border-red-900/40 hover:bg-red-100 dark:hover:bg-red-950/30"
    : "bg-white dark:bg-gray-800/60 border-gray-200 dark:border-gray-700/50 hover:bg-gray-50 dark:hover:bg-gray-700/50";

  const cls = [base, theme, disabled ? "opacity-40 pointer-events-none" : "", className]
    .filter(Boolean).join(" ");

  if (to) {
    return (
      <Link to={to} className={cls}>
        <Inner icon={icon} iconBg={iconBg} label={label} sublabel={sublabel} noChevron={noChevron} danger={isDanger} />
      </Link>
    );
  }
  return (
    <button onClick={onClick} disabled={disabled} className={cls}>
      <Inner icon={icon} iconBg={iconBg} label={label} sublabel={sublabel} noChevron={noChevron} danger={isDanger} />
    </button>
  );
}

// ── Íconos SVG reutilizables ─────────────────────────────────────────────
export const Icons = {
  Import: (
    <svg fill="none" viewBox="0 0 24 24" strokeWidth={2} stroke="currentColor">
      <path strokeLinecap="round" strokeLinejoin="round" d="M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5M16.5 12L12 16.5m0 0L7.5 12m4.5 4.5V3" />
    </svg>
  ),
  AssignOrder: (
    <svg fill="none" viewBox="0 0 24 24" strokeWidth={2} stroke="currentColor">
      <path strokeLinecap="round" strokeLinejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 00-3.375-3.375h-1.5A1.125 1.125 0 0113.5 7.125v-1.5a3.375 3.375 0 00-3.375-3.375H8.25m3.75 9v6m3-3H9m1.5-12H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 00-9-9z" />
    </svg>
  ),
  Link: (
    <svg fill="none" viewBox="0 0 24 24" strokeWidth={2} stroke="currentColor">
      <path strokeLinecap="round" strokeLinejoin="round" d="M13.19 8.688a4.5 4.5 0 011.242 7.244l-4.5 4.5a4.5 4.5 0 01-6.364-6.364l1.757-1.757m13.35-.622l1.757-1.757a4.5 4.5 0 00-6.364-6.364l-4.5 4.5a4.5 4.5 0 001.242 7.244" />
    </svg>
  ),
  Plus: (
    <svg fill="none" viewBox="0 0 24 24" strokeWidth={2.5} stroke="currentColor">
      <path strokeLinecap="round" strokeLinejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
    </svg>
  ),
  Gallery: (
    <svg fill="none" viewBox="0 0 24 24" strokeWidth={2} stroke="currentColor">
      <path strokeLinecap="round" strokeLinejoin="round" d="M2.25 15.75l5.159-5.159a2.25 2.25 0 013.182 0l5.159 5.159m-1.5-1.5l1.409-1.409a2.25 2.25 0 013.182 0l2.909 2.909m-18 3.75h16.5a1.5 1.5 0 001.5-1.5V6a1.5 1.5 0 00-1.5-1.5H3.75A1.5 1.5 0 002.25 6v12a1.5 1.5 0 001.5 1.5zm10.5-11.25h.008v.008h-.008V8.25zm.375 0a.375.375 0 11-.75 0 .375.375 0 01.75 0z" />
    </svg>
  ),
  History: (
    <svg fill="none" viewBox="0 0 24 24" strokeWidth={2} stroke="currentColor">
      <path strokeLinecap="round" strokeLinejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 11-18 0 9 9 0 0118 0z" />
    </svg>
  ),
  Pallet: (
    <svg fill="none" viewBox="0 0 24 24" strokeWidth={2} stroke="currentColor">
      <path strokeLinecap="round" strokeLinejoin="round" d="M20.25 7.5l-.625 10.632a2.25 2.25 0 01-2.247 2.118H6.622a2.25 2.25 0 01-2.247-2.118L3.75 7.5M10 11.25h4M3.375 7.5h17.25c.621 0 1.125-.504 1.125-1.125v-1.5c0-.621-.504-1.125-1.125-1.125H3.375c-.621 0-1.125.504-1.125 1.125v1.5c0 .621.504 1.125 1.125 1.125z" />
    </svg>
  ),
  Trash: (
    <svg fill="none" viewBox="0 0 24 24" strokeWidth={2} stroke="currentColor">
      <path strokeLinecap="round" strokeLinejoin="round" d="M14.74 9l-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 01-2.244 2.077H8.084a2.25 2.25 0 01-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 00-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 013.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 00-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 00-7.5 0" />
    </svg>
  ),
};
