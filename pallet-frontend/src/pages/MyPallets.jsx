import { useEffect, useState, useMemo } from "react";
import { Link } from "react-router-dom";
import { apiGet } from "../api/client";
import { toastError } from "../ui/toast";
import Title from "../ui/Title";
import BackButton from "../ui/BackButton";
import Accordion from "../ui/Accordion";
import { PageSpinner, InlineSpinner } from "../ui/Spinner";

/* ── Helpers ────────────────────────────────────────────────────────────────── */

const STATUS_CONFIG = {
  done: {
    label: "Completo",
    accent: "bg-green-500",
    badge:
      "bg-green-50 text-green-700 dark:bg-green-900/30 dark:text-green-300",
    bar: "bg-green-500",
  },
  open: {
    label: "En proceso",
    accent: "bg-blue-500",
    badge: "bg-blue-50 text-blue-700 dark:bg-blue-900/30 dark:text-blue-300",
    bar: "bg-blue-500",
  },
};

function cfg(status) {
  return STATUS_CONFIG[status] ?? STATUS_CONFIG.open;
}

function formatDate(iso) {
  return new Date(iso).toLocaleDateString("es-AR", {
    day: "numeric",
    month: "short",
    year: "numeric",
  });
}

/* ── PalletCard ─────────────────────────────────────────────────────────────── */
function PalletCard({ p, dim = false }) {
  const [expanded, setExpanded] = useState(false);
  const [imgErrors, setImgErrors] = useState({});

  const c = cfg(p.status);
  const orders = p.orders ?? [];
  const photos = p.photos ?? [];
  const totalOrders = orders.length;
  const doneOrders = orders.filter((o) => o.status === "done").length;
  const pct =
    totalOrders > 0 ? Math.round((doneOrders / totalOrders) * 100) : 0;
  const hasExpand = photos.length > 0;

  function handleImgError(id) {
    setImgErrors((prev) => ({ ...prev, [id]: true }));
  }

  return (
    <div
      className={[
        "relative rounded-2xl overflow-hidden",
        "bg-white dark:bg-gray-800/60",
        "border border-gray-200 dark:border-gray-700/50",
        "shadow-sm hover:shadow-md transition-shadow duration-200",
        dim ? "opacity-55" : "",
      ]
        .filter(Boolean)
        .join(" ")}
    >
      {/* Borde izquierdo coloreado */}
      <div className={`absolute left-0 top-0 bottom-0 w-1 ${c.accent} z-10`} />

      {/* ── Zona clickeable → navega al pallet ──────────────────────── */}
      <Link
        to={`/pallet/${p.id}`}
        className="block pl-4 pr-4 pt-3 pb-3 space-y-2 hover:bg-gray-50/60 dark:hover:bg-white/[0.02] active:bg-gray-100/80 dark:active:bg-white/[0.05] transition-colors"
      >
        {/* Fila 1: código + badge + fecha */}
        <div className="flex items-center gap-2 justify-between">
          <div className="flex items-center gap-2 min-w-0">
            <span className="font-mono font-extrabold text-lg tracking-tight text-gray-900 dark:text-white leading-none">
              {p.code}
            </span>
            <span
              className={`inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[11px] font-semibold shrink-0 ${c.badge}`}
            >
              <span className={`w-1.5 h-1.5 rounded-full ${c.accent}`} />
              {c.label}
            </span>
          </div>
          <span className="text-[11px] text-gray-400 dark:text-gray-500 shrink-0 tabular-nums">
            {p.created_at ? formatDate(p.created_at) : ""}
          </span>
        </div>

        {/* Fila 2: pedidos vinculados */}
        {orders.length > 0 ? (
          <div className="flex flex-col gap-0.5">
            {orders.map((o) => (
              <div key={o.id} className="flex items-center gap-2 min-w-0">
                <span
                  className={`w-1.5 h-1.5 rounded-full shrink-0 ${
                    o.status === "done" ? "bg-green-400" : "bg-blue-400"
                  }`}
                />
                <span className="font-mono text-xs font-semibold text-gray-700 dark:text-gray-200 shrink-0">
                  #{o.code}
                </span>
                {o.customer?.name && (
                  <span className="text-xs text-gray-400 dark:text-gray-500 truncate">
                    {o.customer.name}
                  </span>
                )}
              </div>
            ))}
          </div>
        ) : (
          <p className="text-xs text-gray-400 dark:text-gray-500 italic">
            Sin pedidos asignados
          </p>
        )}

        {/* Fila 3: barra de progreso de pedidos */}
        {totalOrders > 0 && (
          <div className="space-y-1">
            <div className="flex items-center justify-between">
              <span className="text-xs text-gray-500 dark:text-gray-400 font-medium">
                {doneOrders}/{totalOrders} pedidos completados
              </span>
              <span
                className={`text-xs font-bold ${pct === 100 ? "text-green-600 dark:text-green-400" : "text-gray-500 dark:text-gray-400"}`}
              >
                {pct}%
              </span>
            </div>
            <div className="h-2 w-full rounded-full bg-gray-100 dark:bg-gray-700 overflow-hidden">
              <div
                className={`h-full rounded-full transition-all duration-500 ${c.bar}`}
                style={{ width: `${pct}%` }}
              />
            </div>
          </div>
        )}

        {/* Fila 4: contador de fotos (si hay) */}
        {photos.length > 0 && (
          <div className="flex items-center gap-1 text-[11px] text-gray-400 dark:text-gray-500">
            <svg
              className="w-3 h-3 shrink-0"
              fill="none"
              viewBox="0 0 24 24"
              strokeWidth={2}
              stroke="currentColor"
            >
              <path
                strokeLinecap="round"
                strokeLinejoin="round"
                d="M2.25 15.75l5.159-5.159a2.25 2.25 0 013.182 0l5.159 5.159m-1.5-1.5l1.409-1.409a2.25 2.25 0 013.182 0l2.909 2.909M13.5 12a2.25 2.25 0 100-4.5 2.25 2.25 0 000 4.5z"
              />
            </svg>
            {photos.length} foto{photos.length !== 1 ? "s" : ""}
          </div>
        )}
      </Link>

      {/* ── Botón expandir (solo si hay fotos) ──────────────────────── */}
      {hasExpand && (
        <button
          onClick={() => setExpanded((v) => !v)}
          className={[
            "w-full flex items-center justify-center gap-1.5 py-1.5",
            "border-t border-gray-100 dark:border-gray-700/40",
            "text-[11px] font-medium select-none",
            expanded
              ? "bg-blue-50 dark:bg-blue-900/10 text-blue-600 dark:text-blue-400"
              : "text-gray-400 dark:text-gray-500 hover:bg-gray-50 dark:hover:bg-white/[0.03] hover:text-gray-600 dark:hover:text-gray-300",
            "transition-colors",
          ].join(" ")}
        >
          <svg
            className={`w-3.5 h-3.5 transition-transform duration-300 ${expanded ? "rotate-180" : ""}`}
            fill="none"
            viewBox="0 0 24 24"
            strokeWidth={2.5}
            stroke="currentColor"
          >
            <path
              strokeLinecap="round"
              strokeLinejoin="round"
              d="M19 9l-7 7-7-7"
            />
          </svg>
          {expanded ? "Ocultar fotos" : `Ver las ${photos.length} fotos`}
        </button>
      )}

      {/* ── Panel expandido con fotos (animación CSS smooth) ─────────── */}
      <div
        className={`grid ${expanded ? "grid-rows-[1fr]" : "grid-rows-[0fr]"}`}
        style={{ transition: "grid-template-rows 280ms ease-in-out" }}
      >
        <div className="overflow-hidden">
          <div className="border-t border-gray-100 dark:border-gray-700/40 px-3 py-3 space-y-3">
            {/* Grid de fotos */}
            <div className="grid grid-cols-2 gap-2">
              {photos.map((photo) =>
                imgErrors[photo.id] ? null : (
                  <a
                    key={photo.id}
                    href={photo.url}
                    target="_blank"
                    rel="noopener noreferrer"
                    onClick={(e) => e.stopPropagation()}
                    className="block rounded-xl overflow-hidden bg-gray-100 dark:bg-gray-700 aspect-square"
                  >
                    <img
                      src={photo.url}
                      alt=""
                      onError={() => handleImgError(photo.id)}
                      className="w-full h-full object-cover hover:scale-105 transition-transform duration-200"
                    />
                  </a>
                ),
              )}
            </div>

            {/* Link a detalle completo */}
            <Link
              to={`/pallet/${p.id}`}
              className="flex items-center justify-end gap-1 text-xs font-semibold text-blue-600 dark:text-blue-400 hover:underline"
            >
              Abrir pallet completo
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
                  d="M9 5l7 7-7 7"
                />
              </svg>
            </Link>
          </div>
        </div>
      </div>
    </div>
  );
}

/* ── Página ─────────────────────────────────────────────────────────────────── */
export default function MyPallets() {
  const [loading, setLoading] = useState(true);
  const [pallets, setPallets] = useState([]);
  const [page, setPage] = useState(1);
  const [hasMore, setHasMore] = useState(false);

  async function load(nextPage = 1, { append = false } = {}) {
    setLoading(true);
    try {
      const res = await apiGet(`/pallets?page=${nextPage}`);
      const rows = Array.isArray(res) ? res : res.data || [];
      setPallets((prev) => (append ? [...prev, ...rows] : rows));
      const current = res.current_page ?? nextPage;
      const last = res.last_page ?? nextPage;
      setPage(current);
      setHasMore(current < last);
    } catch (e) {
      toastError(
        e?.message || e?.response?.data?.message || "No se pudo cargar pallets",
      );
    } finally {
      setLoading(false);
    }
  }

  useEffect(() => {
    load(1, { append: false });
  }, []);

  const { openPallets, completedPallets } = useMemo(
    () => ({
      openPallets: pallets.filter((p) => p.status !== "done"),
      completedPallets: pallets.filter((p) => p.status === "done"),
    }),
    [pallets],
  );

  return (
    <div className="space-y-6">
      <div className="flex justify-start">
        <BackButton to="/" />
      </div>

      <div className="flex flex-col gap-1.5 items-center text-center">
        <Title size="4xl">Mis pallets</Title>
        <p className="text-sm text-gray-500 dark:text-gray-400 max-w-xs">
          Tocá un pallet para ver el detalle y los pedidos asignados.
        </p>
      </div>

      {loading && pallets.length === 0 ? (
        <PageSpinner />
      ) : pallets.length === 0 ? (
        <p className="text-sm text-gray-500 dark:text-gray-400 text-center py-10">
          Todavía no hay pallets en el historial.
        </p>
      ) : (
        <div className="space-y-6">
          {openPallets.length > 0 && (
            <section className="space-y-2">
              <p className="text-xs font-semibold uppercase tracking-wider text-gray-400 dark:text-gray-500 px-1">
                En proceso ({openPallets.length})
              </p>
              <div className="flex flex-col gap-2">
                {openPallets.map((p) => (
                  <PalletCard key={p.id} p={p} />
                ))}
              </div>
            </section>
          )}

          {completedPallets.length > 0 && (
            <Accordion title={`Completados (${completedPallets.length})`}>
              <div className="flex flex-col gap-2 pt-1">
                {completedPallets.map((p) => (
                  <PalletCard key={p.id} p={p} dim />
                ))}
              </div>
            </Accordion>
          )}

          {openPallets.length === 0 && completedPallets.length === 0 && (
            <p className="text-sm text-gray-500 dark:text-gray-400 text-center py-10">
              No hay pallets para mostrar.
            </p>
          )}
        </div>
      )}

      {hasMore && (
        <button
          disabled={loading}
          onClick={() => load(page + 1, { append: true })}
          className="w-full rounded-xl py-3 border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 text-sm text-gray-600 dark:text-gray-400 hover:bg-gray-50 dark:hover:bg-gray-700 disabled:opacity-60 transition-colors"
        >
          {loading ? <InlineSpinner label="Cargando…" /> : "Cargar más"}
        </button>
      )}
    </div>
  );
}
