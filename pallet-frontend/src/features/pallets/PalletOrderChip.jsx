import { Link } from "react-router-dom";

function ExternalLinkIcon() {
  return (
    <svg
      className="w-3.5 h-3.5"
      fill="none"
      viewBox="0 0 24 24"
      strokeWidth={2.5}
      stroke="currentColor"
    >
      <path
        strokeLinecap="round"
        strokeLinejoin="round"
        d="M13.5 6H5.25A2.25 2.25 0 003 8.25v10.5A2.25 2.25 0 005.25 21h10.5A2.25 2.25 0 0018 18.75V10.5m-10.5 6L21 3m0 0h-5.25M21 3v5.25"
      />
    </svg>
  );
}

export default function PalletOrderChip({ order, activeOrderId, onSelect }) {
  const active = order.id === activeOrderId;

  return (
    <button
      onClick={() => onSelect(order.id)}
      className={[
        "inline-flex items-center gap-2 px-3 py-2 rounded-xl text-sm font-semibold transition-colors",
        active
          ? "bg-gray-900 dark:bg-white text-white dark:text-gray-900"
          : "bg-gray-100 dark:bg-gray-700/60 text-gray-700 dark:text-gray-200 hover:bg-gray-200 dark:hover:bg-gray-700",
      ].join(" ")}
    >
      <span>Pedido #{order.code}</span>
      {active && (
        <Link
          to={`/order/${order.id}`}
          onClick={(event) => event.stopPropagation()}
          className="opacity-60 hover:opacity-100 transition-opacity"
          title="Abrir detalle del pedido"
        >
          <ExternalLinkIcon />
        </Link>
      )}
    </button>
  );
}
