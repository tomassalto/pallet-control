import { Link } from "react-router-dom";
import { Icons } from "./Icons";

function Chevron({ danger }) {
  return (
    <svg
      className={`w-4 h-4 shrink-0 ${danger ? "text-red-300 dark:text-red-800" : "text-gray-300 dark:text-gray-600"}`}
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
      <div
        className={`w-10 h-10 rounded-xl flex items-center justify-center shrink-0 shadow-sm ${iconBg}`}
      >
        <span className="text-white [&>svg]:w-5 [&>svg]:h-5">{icon}</span>
      </div>

      {/* Texto */}
      <div className="flex-1 text-left min-w-0">
        <span
          className={`text-sm font-semibold block leading-snug ${danger ? "text-red-700 dark:text-red-400" : "text-gray-800 dark:text-gray-100"}`}
        >
          {label}
        </span>
        {sublabel && (
          <span
            className={`text-xs ${danger ? "text-red-400 dark:text-red-600" : "text-gray-400 dark:text-gray-500"}`}
          >
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
  href,
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

  const cls = [
    base,
    theme,
    disabled ? "opacity-40 pointer-events-none" : "",
    className,
  ]
    .filter(Boolean)
    .join(" ");

  const inner = (
    <Inner
      icon={icon}
      iconBg={iconBg}
      label={label}
      sublabel={sublabel}
      noChevron={noChevron}
      danger={isDanger}
    />
  );

  if (to) {
    return <Link to={to} className={cls}>{inner}</Link>;
  }
  if (href) {
    return (
      <a href={href} target="_blank" rel="noopener noreferrer" className={cls}>
        {inner}
      </a>
    );
  }
  return (
    <button onClick={onClick} disabled={disabled} className={cls}>
      {inner}
    </button>
  );
}

export { Icons };