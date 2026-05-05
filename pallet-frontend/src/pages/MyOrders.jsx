import { useEffect, useState, useMemo } from "react";
import { Link, useSearchParams } from "react-router-dom";
import { apiGet, apiPost } from "../api/client";
import { toastError, toastSuccess } from "../ui/toast";
import Title from "../ui/Title";
import BackButton from "../ui/BackButton";
import Accordion from "../ui/Accordion";
import { PageSpinner, InlineSpinner } from "../ui/Spinner";

/* ── Helpers ────────────────────────────────────────────────────────────────── */

const STATUS_CONFIG = {
  done:   { label: "Completo",   accent: "bg-green-500", badge: "bg-green-50 text-green-700 dark:bg-green-900/30 dark:text-green-300", bar: "bg-green-500" },
  paused: { label: "Pausado",    accent: "bg-amber-400",  badge: "bg-amber-50 text-amber-700 dark:bg-amber-900/30 dark:text-amber-300", bar: "bg-amber-400" },
  open:   { label: "En proceso", accent: "bg-blue-500",   badge: "bg-blue-50 text-blue-700 dark:bg-blue-900/30 dark:text-blue-300",   bar: "bg-blue-500"  },
};

function cfg(status) {
  return STATUS_CONFIG[status] ?? STATUS_CONFIG.open;
}

function formatDate(iso) {
  return new Date(iso).toLocaleDateString("es-AR", { day: "numeric", month: "short" });
}

/* ── Placeholder de imagen ──────────────────────────────────────────────────── */
function ProductAvatar({ description, imageUrl, size = "sm" }) {
  const [imgErr, setImgErr] = useState(false);
  const initials = description?.slice(0, 2).toUpperCase() ?? "??";
  const sz = size === "sm" ? "w-9 h-9" : "w-12 h-12";

  if (imageUrl && !imgErr) {
    return (
      <img
        src={imageUrl}
        alt={description}
        onError={() => setImgErr(true)}
        className={`${sz} rounded-lg object-cover shrink-0 bg-gray-100 dark:bg-gray-700`}
      />
    );
  }
  return (
    <div className={`${sz} rounded-lg bg-gray-100 dark:bg-gray-700 flex items-center justify-center shrink-0`}>
      <span className="text-[10px] font-bold text-gray-400 dark:text-gray-500 leading-none">
        {initials}
      </span>
    </div>
  );
}

/* ── OrderCard ──────────────────────────────────────────────────────────────── */
function OrderCard({ o, dim = false, canFinalize = false, finalizing = false, onFinalize }) {
  const [expanded, setExpanded] = useState(false);

  const c           = cfg(o.status);
  const totalQty    = o.total_qty    ?? 0;
  const assignedQty = o.assigned_qty ?? 0;
  const pct         = totalQty > 0 ? Math.round((assignedQty / totalQty) * 100) : 0;
  const pallets     = o.pallets ?? [];
  const items       = o.items   ?? [];
  const preview     = items.slice(0, 3);
  const extra       = items.length - preview.length;

  return (
    <div
      className={[
        "relative rounded-2xl overflow-hidden",
        "bg-white dark:bg-gray-800/60",
        "border border-gray-200 dark:border-gray-700/50",
        "shadow-sm hover:shadow-md transition-shadow duration-200",
        dim ? "opacity-55" : "",
      ].filter(Boolean).join(" ")}
    >
      {/* Borde izquierdo coloreado */}
      <div className={`absolute left-0 top-0 bottom-0 w-1 ${c.accent} z-10`} />

      {/* ── Zona clickeable → navega al pedido ──────────────────────── */}
      <Link
        to={`/order/${o.id}`}
        className="block pl-4 pr-4 pt-3 pb-3 space-y-2 hover:bg-gray-50/60 dark:hover:bg-white/[0.02] active:bg-gray-100/80 dark:active:bg-white/[0.05] transition-colors"
      >
        {/* Fila 1: código + badge + fecha */}
        <div className="flex items-start gap-2 justify-between">
          <div className="flex flex-wrap items-center gap-1.5 min-w-0">
            <span className="font-mono font-extrabold text-lg tracking-tight text-gray-900 dark:text-white leading-none">
              #{o.code}
            </span>
            <span className={`inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[11px] font-semibold shrink-0 ${c.badge}`}>
              <span className={`w-1.5 h-1.5 rounded-full ${c.accent}`} />
              {c.label}
            </span>
            {canFinalize && (
              <span className="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[11px] font-semibold bg-green-100 text-green-700 dark:bg-green-900/40 dark:text-green-300 shrink-0">
                ✓ Listo
              </span>
            )}
          </div>
          <span className="text-[11px] text-gray-400 dark:text-gray-500 shrink-0 tabular-nums pt-0.5">
            {o.created_at ? formatDate(o.created_at) : ""}
          </span>
        </div>

        {/* Fila 2: preview de productos (primeros 3) */}
        {preview.length > 0 && (
          <div className="flex flex-wrap gap-x-2 gap-y-0.5">
            {preview.map((item) => (
              <span key={item.id} className="text-xs text-gray-700 dark:text-gray-200">
                {item.description}
                <span className="text-gray-400 dark:text-gray-500 ml-0.5">×{item.qty}</span>
              </span>
            ))}
            {extra > 0 && (
              <span className="text-xs text-gray-400 dark:text-gray-500 italic">+{extra} más</span>
            )}
          </div>
        )}

        {/* Fila 3: barra de progreso (unidades en bases) */}
        {totalQty > 0 && (
          <div className="space-y-1">
            <div className="flex items-center justify-between">
              <span className="text-xs text-gray-500 dark:text-gray-400 font-medium">
                {assignedQty}/{totalQty} uds. en bases
              </span>
              <span className={`text-xs font-bold ${pct === 100 ? "text-green-600 dark:text-green-400" : "text-gray-500 dark:text-gray-400"}`}>
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

        {/* Fila 4: pallets vinculados */}
        {pallets.length > 0 && (
          <div className="flex flex-wrap gap-1.5">
            {pallets.map((p) => (
              <span
                key={p.id}
                className="font-mono text-[11px] bg-gray-100 dark:bg-gray-700/70 text-gray-600 dark:text-gray-300 px-2 py-0.5 rounded-md"
              >
                {p.code}
              </span>
            ))}
          </div>
        )}
      </Link>

      {/* ── Botón expandir ───────────────────────────────────────────── */}
      {items.length > 0 && (
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
            fill="none" viewBox="0 0 24 24" strokeWidth={2.5} stroke="currentColor"
          >
            <path strokeLinecap="round" strokeLinejoin="round" d="M19 9l-7 7-7-7" />
          </svg>
          {expanded ? "Ocultar" : `Ver los ${items.length} productos`}
        </button>
      )}

      {/* ── Panel expandido (siempre montado, animación CSS) ─────────── */}
      <div
        className={`grid ${expanded ? "grid-rows-[1fr]" : "grid-rows-[0fr]"}`}
        style={{ transition: "grid-template-rows 280ms ease-in-out" }}
      >
        <div className="overflow-hidden">
          <div className="border-t border-gray-100 dark:border-gray-700/40 px-4 py-3 space-y-3">

            {/* Lista completa con imágenes */}
            <div className="space-y-2">
              {items.map((item) => (
                <div key={item.id} className="flex items-center gap-3 min-w-0">
                  <ProductAvatar description={item.description} imageUrl={item.image_url} />
                  <div className="flex-1 min-w-0">
                    <p className="text-xs text-gray-800 dark:text-gray-100 leading-snug truncate">
                      {item.description}
                    </p>
                    <p className="text-[11px] text-gray-400 dark:text-gray-500 mt-0.5">
                      {item.qty} unidades
                    </p>
                  </div>
                  <span className={`w-1.5 h-1.5 rounded-full shrink-0 ${
                    item.status === "done" ? "bg-green-400" : "bg-gray-300 dark:bg-gray-600"
                  }`} />
                </div>
              ))}
            </div>

            {/* Botón finalizar */}
            {canFinalize && (
              <button
                onClick={onFinalize}
                disabled={finalizing}
                className={[
                  "w-full flex items-center gap-3 px-3.5 py-2.5 rounded-2xl",
                  "border border-green-200 dark:border-green-800/40",
                  "bg-green-50 dark:bg-green-900/20",
                  "hover:bg-green-100 dark:hover:bg-green-900/30",
                  "active:scale-[0.99] transition-all duration-150",
                  "disabled:opacity-50 disabled:pointer-events-none",
                ].join(" ")}
              >
                <div className="w-9 h-9 rounded-xl bg-green-500 shadow-sm flex items-center justify-center shrink-0">
                  <svg className="w-5 h-5 text-white" fill="none" viewBox="0 0 24 24" strokeWidth={2.5} stroke="currentColor">
                    <path strokeLinecap="round" strokeLinejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                  </svg>
                </div>
                <span className="text-sm font-semibold text-green-700 dark:text-green-400">
                  {finalizing ? "Finalizando…" : "Finalizar pedido"}
                </span>
              </button>
            )}

            {/* Link a detalle completo */}
            <Link
              to={`/order/${o.id}`}
              className="flex items-center justify-end gap-1 text-xs font-semibold text-blue-600 dark:text-blue-400 hover:underline"
            >
              Abrir pedido completo
              <svg className="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" strokeWidth={2.5} stroke="currentColor">
                <path strokeLinecap="round" strokeLinejoin="round" d="M9 5l7 7-7 7" />
              </svg>
            </Link>
          </div>
        </div>
      </div>
    </div>
  );
}

/* ── Página ─────────────────────────────────────────────────────────────────── */
/* ── Filtro de fecha ─────────────────────────────────────────────────────────── */
function DateFilter({ dateFrom, dateTo, onChange }) {
  const presets = [
    { label: "Hoy",         from: today(),          to: today() },
    { label: "Ayer",        from: daysAgo(1),        to: daysAgo(1) },
    { label: "Esta semana", from: startOfWeek(),     to: today() },
    { label: "Este mes",    from: startOfMonth(),    to: today() },
  ];

  const activePreset = presets.find((p) => p.from === dateFrom && p.to === dateTo);

  return (
    <div className="space-y-2">
      {/* Chips de preset */}
      <div className="flex flex-wrap gap-1.5">
        <button
          onClick={() => onChange("", "")}
          className={[
            "px-3 py-1 rounded-xl text-xs font-semibold border transition-colors",
            !dateFrom && !dateTo
              ? "bg-gray-900 dark:bg-white text-white dark:text-gray-900 border-transparent"
              : "bg-white dark:bg-gray-800 border-gray-200 dark:border-gray-700 text-gray-600 dark:text-gray-400 hover:bg-gray-50 dark:hover:bg-gray-700",
          ].join(" ")}
        >
          Todos
        </button>
        {presets.map((p) => {
          const active = p.from === dateFrom && p.to === dateTo;
          return (
            <button
              key={p.label}
              onClick={() => onChange(p.from, p.to)}
              className={[
                "px-3 py-1 rounded-xl text-xs font-semibold border transition-colors",
                active
                  ? "bg-blue-600 text-white border-transparent"
                  : "bg-white dark:bg-gray-800 border-gray-200 dark:border-gray-700 text-gray-600 dark:text-gray-400 hover:bg-gray-50 dark:hover:bg-gray-700",
              ].join(" ")}
            >
              {p.label}
            </button>
          );
        })}
      </div>

      {/* Inputs de fecha custom (solo si no hay preset activo o querés rango propio) */}
      {!activePreset && (dateFrom || dateTo) && (
        <div className="flex items-center gap-2">
          <input
            type="date"
            value={dateFrom}
            onChange={(e) => onChange(e.target.value, dateTo)}
            className="flex-1 text-xs border border-gray-200 dark:border-gray-700 rounded-xl px-3 py-2 bg-white dark:bg-gray-800 text-gray-800 dark:text-gray-100"
          />
          <span className="text-xs text-gray-400">→</span>
          <input
            type="date"
            value={dateTo}
            onChange={(e) => onChange(dateFrom, e.target.value)}
            className="flex-1 text-xs border border-gray-200 dark:border-gray-700 rounded-xl px-3 py-2 bg-white dark:bg-gray-800 text-gray-800 dark:text-gray-100"
          />
        </div>
      )}
    </div>
  );
}

function today()        { return new Date().toISOString().slice(0, 10); }
function daysAgo(n)     { const d = new Date(); d.setDate(d.getDate() - n); return d.toISOString().slice(0, 10); }
function startOfWeek()  { const d = new Date(); d.setDate(d.getDate() - d.getDay() + (d.getDay() === 0 ? -6 : 1)); return d.toISOString().slice(0, 10); }
function startOfMonth() { const d = new Date(); return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, "0")}-01`; }

/* ── Página ─────────────────────────────────────────────────────────────────── */
export default function MyOrders() {
  const [searchParams, setSearchParams]     = useSearchParams();
  const [loading, setLoading]               = useState(true);
  const [orders, setOrders]                 = useState([]);
  const [page, setPage]                     = useState(1);
  const [hasMore, setHasMore]               = useState(false);
  const [canFinalizeMap, setCanFinalizeMap] = useState(new Map());
  const [finalizing, setFinalizing]         = useState(new Set());

  // Filtros de fecha desde URL params (para que el link desde home funcione)
  const dateFrom = searchParams.get("date_from") ?? "";
  const dateTo   = searchParams.get("date_to")   ?? "";

  function handleDateChange(from, to) {
    const params = {};
    if (from) params.date_from = from;
    if (to)   params.date_to   = to;
    setSearchParams(params);
  }

  async function load(nextPage = 1, { append = false, from = dateFrom, to = dateTo } = {}) {
    setLoading(true);
    try {
      let url = `/orders?page=${nextPage}`;
      if (from) url += `&date_from=${from}`;
      if (to)   url += `&date_to=${to}`;
      const res  = await apiGet(url);
      const rows = Array.isArray(res) ? res : res.data || [];
      setOrders((prev) => (append ? [...prev, ...rows] : rows));
      const current = res.current_page ?? nextPage;
      const last    = res.last_page    ?? nextPage;
      setPage(current);
      setHasMore(current < last);

      const openRows = rows.filter((o) => o.status === "open");
      if (openRows.length > 0) {
        try {
          const result = await apiPost("/orders/can-finalize-batch", {
            order_ids: openRows.map((o) => o.id),
          });
          setCanFinalizeMap((prev) => {
            const next = new Map(prev);
            Object.entries(result).forEach(([id, val]) => next.set(Number(id), val));
            return next;
          });
        } catch { /* silently ignore */ }
      }
    } catch (e) {
      toastError(e?.message || e?.response?.data?.message || "No se pudo cargar pedidos");
    } finally {
      setLoading(false);
    }
  }

  // Recargar cuando cambian los filtros de fecha
  useEffect(() => { load(1, { append: false, from: dateFrom, to: dateTo }); }, [dateFrom, dateTo]);

  const { openOrders, completedOrders } = useMemo(() => ({
    openOrders:      orders.filter((o) => o.status !== "done"),
    completedOrders: orders.filter((o) => o.status === "done"),
  }), [orders]);

  async function handleFinalize(orderId) {
    try {
      const result = await apiPost("/orders/can-finalize-batch", { order_ids: [orderId] });
      if (!result[orderId]) {
        toastError("No se puede finalizar. Todos los productos deben estar distribuidos en bases.");
        return;
      }
    } catch { return; }

    if (!window.confirm("¿Estás seguro de finalizar este pedido?")) return;

    setFinalizing((prev) => new Set(prev).add(orderId));
    try {
      await apiPost(`/orders/${orderId}/finalize`);
      toastSuccess("Pedido finalizado correctamente");
      setCanFinalizeMap((prev) => { const n = new Map(prev); n.set(orderId, false); return n; });
      load(1, { append: false });
    } catch (e) {
      toastError(e?.response?.data?.message || "Error al finalizar pedido");
    } finally {
      setFinalizing((prev) => { const n = new Set(prev); n.delete(orderId); return n; });
    }
  }

  return (
    <div className="space-y-6">
      <div className="flex justify-start">
        <BackButton to="/" />
      </div>

      <div className="flex flex-col gap-1.5 items-center text-center">
        <Title>Mis pedidos</Title>
        <p className="text-sm text-gray-500 dark:text-gray-400 max-w-xs">
          Tocá un pedido para ver el detalle y la ubicación de los productos.
        </p>
      </div>

      <DateFilter dateFrom={dateFrom} dateTo={dateTo} onChange={handleDateChange} />

      {loading && orders.length === 0 ? (
        <PageSpinner />
      ) : orders.length === 0 ? (
        <p className="text-sm text-gray-500 dark:text-gray-400 text-center py-10">
          {dateFrom || dateTo ? "No hay pedidos para ese rango de fechas." : "Todavía no hay pedidos en el historial."}
        </p>
      ) : (
        <div className="space-y-6">
          {openOrders.length > 0 && (
            <section className="space-y-2">
              <p className="text-xs font-semibold uppercase tracking-wider text-gray-400 dark:text-gray-500 px-1">
                En proceso ({openOrders.length})
              </p>
              <div className="flex flex-col gap-2">
                {openOrders.map((o) => (
                  <OrderCard
                    key={o.id}
                    o={o}
                    canFinalize={o.status === "open" && canFinalizeMap.get(o.id) === true}
                    finalizing={finalizing.has(o.id)}
                    onFinalize={() => handleFinalize(o.id)}
                  />
                ))}
              </div>
            </section>
          )}

          {completedOrders.length > 0 && (
            <Accordion title={`Completados (${completedOrders.length})`}>
              <div className="flex flex-col gap-2 pt-1">
                {completedOrders.map((o) => (
                  <OrderCard key={o.id} o={o} dim />
                ))}
              </div>
            </Accordion>
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
