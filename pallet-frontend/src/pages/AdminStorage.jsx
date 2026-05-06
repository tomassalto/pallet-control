/**
 * AdminStorage — Panel de limpieza de datos para administradores.
 *
 * Tabs: Pallets | Pedidos
 * Cada tab muestra una lista paginada con conteos de fotos/bases/items.
 * Los ítems sin actividad (sin pedidos / sin ítems) se marcan en ámbar.
 * Borrar requiere confirmación explícita y borra archivos físicos + BD.
 */

import { useEffect, useState, useCallback } from "react";
import { apiGet, apiDelete } from "../api/client";
import { toastSuccess, toastError } from "../ui/toast";
import Title from "../ui/Title";
import { PageSpinner } from "../ui/Spinner";

// ── Badge de estado ────────────────────────────────────────────────────────────

function StatusBadge({ status }) {
  const map = {
    open: "bg-green-100 text-green-800 dark:bg-green-900/30 dark:text-green-400",
    done: "bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-400",
  };
  return (
    <span className={`text-[11px] font-semibold px-2 py-0.5 rounded-full ${map[status] ?? map.done}`}>
      {status === "open" ? "Abierto" : "Finalizado"}
    </span>
  );
}

// ── Stats card ─────────────────────────────────────────────────────────────────

function StatsBar({ stats }) {
  if (!stats) return null;
  const items = [
    { label: "Fotos totales",    value: stats.total_photos },
    { label: "Fotos de bases",   value: stats.base_photos_count },
    { label: "Fotos de pallets", value: stats.pallet_photos_count },
    { label: "Fotos de tickets", value: stats.ticket_photos_count },
    { label: "OCR logs activos", value: stats.ocr_logs_count },
    { label: "Activity logs",    value: stats.activity_logs_count },
  ];
  return (
    <div className="grid grid-cols-3 sm:grid-cols-6 gap-2 mb-6">
      {items.map((item) => (
        <div
          key={item.label}
          className="bg-white dark:bg-gray-800/60 border border-gray-200 dark:border-gray-700/50 rounded-xl px-3 py-2.5 text-center"
        >
          <p className="text-lg font-bold text-gray-900 dark:text-white">{item.value ?? "—"}</p>
          <p className="text-[10px] text-gray-400 dark:text-gray-500 leading-tight mt-0.5">{item.label}</p>
        </div>
      ))}
    </div>
  );
}

// ── Modal de confirmación ──────────────────────────────────────────────────────

function ConfirmModal({ item, type, onConfirm, onCancel, loading }) {
  if (!item) return null;
  const label = type === "pallet" ? "pallet" : "pedido";
  const detail = type === "pallet"
    ? `${item.photos_count} fotos, ${item.bases_count} bases`
    : `${item.tickets_count} tickets`;

  return (
    <div className="fixed inset-0 z-50 bg-black/60 flex items-center justify-center p-4">
      <div
        className="bg-white dark:bg-gray-900 rounded-2xl p-6 w-full max-w-md shadow-2xl space-y-4"
        onClick={(e) => e.stopPropagation()}
      >
        <div className="space-y-1">
          <p className="font-bold text-lg text-gray-900 dark:text-white">
            ¿Eliminar {label}?
          </p>
          <p className="text-sm text-gray-500 dark:text-gray-400">
            <span className="font-semibold text-gray-900 dark:text-white">{item.code}</span>
            {" "}— {detail}
          </p>
        </div>
        <div className="bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800/50 rounded-xl p-3.5">
          <p className="text-sm text-red-800 dark:text-red-300">
            <span className="font-semibold">Atención:</span> se borran todos los archivos físicos
            y registros de base de datos. Esta acción no se puede deshacer.
          </p>
        </div>
        <div className="flex gap-2">
          <button
            onClick={onCancel}
            disabled={loading}
            className="flex-1 rounded-xl py-3 border border-gray-200 dark:border-gray-600 text-gray-700 dark:text-gray-300 text-sm font-semibold hover:bg-gray-50 dark:hover:bg-gray-700 transition-colors disabled:opacity-50"
          >
            Cancelar
          </button>
          <button
            onClick={onConfirm}
            disabled={loading}
            className="flex-1 rounded-xl py-3 bg-red-600 hover:bg-red-700 text-white text-sm font-semibold transition-colors disabled:opacity-60"
          >
            {loading ? "Eliminando…" : "Sí, eliminar"}
          </button>
        </div>
      </div>
    </div>
  );
}

// ── Tabla de pallets ───────────────────────────────────────────────────────────

function PalletsTab() {
  const [items, setItems]         = useState([]);
  const [meta, setMeta]           = useState(null);
  const [loading, setLoading]     = useState(true);
  const [page, setPage]           = useState(1);
  const [emptyOnly, setEmptyOnly] = useState(false);
  const [confirm, setConfirm]     = useState(null);
  const [deleting, setDeleting]   = useState(false);

  const load = useCallback(async () => {
    setLoading(true);
    try {
      const params = new URLSearchParams({ page });
      if (emptyOnly) params.set("empty", "1");
      const data = await apiGet(`/admin/storage/pallets?${params}`);
      setItems(data.data ?? []);
      setMeta(data);
    } catch (e) {
      toastError(e.message || "Error al cargar pallets");
    } finally {
      setLoading(false);
    }
  }, [page, emptyOnly]);

  useEffect(() => { load(); }, [load]);

  async function handleDelete() {
    if (!confirm) return;
    setDeleting(true);
    try {
      await apiDelete(`/admin/storage/pallets/${confirm.id}`);
      toastSuccess(`Pallet ${confirm.code} eliminado`);
      setConfirm(null);
      load();
    } catch (e) {
      toastError(e.message || "Error al eliminar");
    } finally {
      setDeleting(false);
    }
  }

  return (
    <div className="space-y-3">
      {/* Filtros */}
      <div className="flex items-center gap-3">
        <label className="flex items-center gap-2 text-sm text-gray-600 dark:text-gray-400 cursor-pointer select-none">
          <input
            type="checkbox"
            checked={emptyOnly}
            onChange={(e) => { setEmptyOnly(e.target.checked); setPage(1); }}
            className="rounded"
          />
          Solo vacíos (sin pedidos)
        </label>
        <span className="text-xs text-gray-400 dark:text-gray-500 ml-auto">
          {meta?.total ?? "—"} pallets
        </span>
      </div>

      {loading ? (
        <PageSpinner />
      ) : items.length === 0 ? (
        <p className="text-center py-12 text-gray-400 dark:text-gray-500 text-sm">
          No hay pallets{emptyOnly ? " vacíos" : ""}.
        </p>
      ) : (
        <div className="space-y-2">
          {items.map((p) => (
            <div
              key={p.id}
              className={`flex items-center gap-3 px-4 py-3 rounded-xl border transition-colors ${
                p.orders_count === 0
                  ? "bg-amber-50 dark:bg-amber-900/10 border-amber-200 dark:border-amber-700/30"
                  : "bg-white dark:bg-gray-800/60 border-gray-200 dark:border-gray-700/50"
              }`}
            >
              {/* Info */}
              <div className="flex-1 min-w-0">
                <div className="flex items-center gap-2 flex-wrap">
                  <span className="font-mono font-bold text-sm text-gray-900 dark:text-white">
                    {p.code}
                  </span>
                  <StatusBadge status={p.status} />
                  {p.orders_count === 0 && (
                    <span className="text-[10px] font-semibold px-1.5 py-0.5 rounded bg-amber-100 dark:bg-amber-900/40 text-amber-700 dark:text-amber-400">
                      Sin pedidos
                    </span>
                  )}
                </div>
                <div className="flex items-center gap-3 mt-1">
                  <span className="text-xs text-gray-400 dark:text-gray-500">
                    {new Date(p.created_at).toLocaleDateString("es-AR", { dateStyle: "short" })}
                  </span>
                  <span className="text-xs text-gray-500 dark:text-gray-400">
                    {p.orders_count} pedido{p.orders_count !== 1 ? "s" : ""}
                  </span>
                  <span className="text-xs text-gray-500 dark:text-gray-400">
                    {p.bases_count} base{p.bases_count !== 1 ? "s" : ""}
                  </span>
                  <span className="text-xs text-gray-500 dark:text-gray-400">
                    {p.photos_count} foto{p.photos_count !== 1 ? "s" : ""}
                  </span>
                </div>
              </div>

              {/* Acción */}
              <button
                onClick={() => setConfirm(p)}
                className="shrink-0 text-xs px-3 py-1.5 rounded-lg border border-red-200 dark:border-red-800/50 text-red-600 dark:text-red-400 font-semibold hover:bg-red-50 dark:hover:bg-red-900/20 transition-colors"
              >
                Eliminar
              </button>
            </div>
          ))}
        </div>
      )}

      {/* Paginación */}
      {meta && meta.last_page > 1 && (
        <div className="flex items-center justify-center gap-2 pt-2">
          <button
            onClick={() => setPage((p) => Math.max(1, p - 1))}
            disabled={page === 1}
            className="text-sm px-3 py-1.5 rounded-lg bg-gray-100 dark:bg-gray-700 text-gray-700 dark:text-gray-300 disabled:opacity-40"
          >
            ← Anterior
          </button>
          <span className="text-sm text-gray-500 dark:text-gray-400">
            {page} / {meta.last_page}
          </span>
          <button
            onClick={() => setPage((p) => Math.min(meta.last_page, p + 1))}
            disabled={page === meta.last_page}
            className="text-sm px-3 py-1.5 rounded-lg bg-gray-100 dark:bg-gray-700 text-gray-700 dark:text-gray-300 disabled:opacity-40"
          >
            Siguiente →
          </button>
        </div>
      )}

      <ConfirmModal
        item={confirm}
        type="pallet"
        onConfirm={handleDelete}
        onCancel={() => setConfirm(null)}
        loading={deleting}
      />
    </div>
  );
}

// ── Tabla de pedidos ───────────────────────────────────────────────────────────

function OrdersTab() {
  const [items, setItems]         = useState([]);
  const [meta, setMeta]           = useState(null);
  const [loading, setLoading]     = useState(true);
  const [page, setPage]           = useState(1);
  const [emptyOnly, setEmptyOnly] = useState(false);
  const [confirm, setConfirm]     = useState(null);
  const [deleting, setDeleting]   = useState(false);

  const load = useCallback(async () => {
    setLoading(true);
    try {
      const params = new URLSearchParams({ page });
      if (emptyOnly) params.set("empty", "1");
      const data = await apiGet(`/admin/storage/orders?${params}`);
      setItems(data.data ?? []);
      setMeta(data);
    } catch (e) {
      toastError(e.message || "Error al cargar pedidos");
    } finally {
      setLoading(false);
    }
  }, [page, emptyOnly]);

  useEffect(() => { load(); }, [load]);

  async function handleDelete() {
    if (!confirm) return;
    setDeleting(true);
    try {
      await apiDelete(`/admin/storage/orders/${confirm.id}`);
      toastSuccess(`Pedido ${confirm.code} eliminado`);
      setConfirm(null);
      load();
    } catch (e) {
      toastError(e.message || "Error al eliminar");
    } finally {
      setDeleting(false);
    }
  }

  return (
    <div className="space-y-3">
      {/* Filtros */}
      <div className="flex items-center gap-3">
        <label className="flex items-center gap-2 text-sm text-gray-600 dark:text-gray-400 cursor-pointer select-none">
          <input
            type="checkbox"
            checked={emptyOnly}
            onChange={(e) => { setEmptyOnly(e.target.checked); setPage(1); }}
            className="rounded"
          />
          Solo vacíos (sin ítems importados)
        </label>
        <span className="text-xs text-gray-400 dark:text-gray-500 ml-auto">
          {meta?.total ?? "—"} pedidos
        </span>
      </div>

      {loading ? (
        <PageSpinner />
      ) : items.length === 0 ? (
        <p className="text-center py-12 text-gray-400 dark:text-gray-500 text-sm">
          No hay pedidos{emptyOnly ? " vacíos" : ""}.
        </p>
      ) : (
        <div className="space-y-2">
          {items.map((o) => (
            <div
              key={o.id}
              className={`flex items-center gap-3 px-4 py-3 rounded-xl border transition-colors ${
                o.items_count === 0
                  ? "bg-amber-50 dark:bg-amber-900/10 border-amber-200 dark:border-amber-700/30"
                  : "bg-white dark:bg-gray-800/60 border-gray-200 dark:border-gray-700/50"
              }`}
            >
              {/* Info */}
              <div className="flex-1 min-w-0">
                <div className="flex items-center gap-2 flex-wrap">
                  <span className="font-mono font-bold text-sm text-gray-900 dark:text-white">
                    #{o.code}
                  </span>
                  <StatusBadge status={o.status} />
                  {o.items_count === 0 && (
                    <span className="text-[10px] font-semibold px-1.5 py-0.5 rounded bg-amber-100 dark:bg-amber-900/40 text-amber-700 dark:text-amber-400">
                      Sin ítems
                    </span>
                  )}
                </div>
                <div className="flex items-center gap-3 mt-1">
                  <span className="text-xs text-gray-400 dark:text-gray-500">
                    {new Date(o.created_at).toLocaleDateString("es-AR", { dateStyle: "short" })}
                  </span>
                  <span className="text-xs text-gray-500 dark:text-gray-400">
                    {o.items_count} ítem{o.items_count !== 1 ? "s" : ""}
                  </span>
                  <span className="text-xs text-gray-500 dark:text-gray-400">
                    {o.tickets_count} ticket{o.tickets_count !== 1 ? "s" : ""}
                  </span>
                </div>
              </div>

              {/* Acción */}
              <button
                onClick={() => setConfirm(o)}
                className="shrink-0 text-xs px-3 py-1.5 rounded-lg border border-red-200 dark:border-red-800/50 text-red-600 dark:text-red-400 font-semibold hover:bg-red-50 dark:hover:bg-red-900/20 transition-colors"
              >
                Eliminar
              </button>
            </div>
          ))}
        </div>
      )}

      {/* Paginación */}
      {meta && meta.last_page > 1 && (
        <div className="flex items-center justify-center gap-2 pt-2">
          <button
            onClick={() => setPage((p) => Math.max(1, p - 1))}
            disabled={page === 1}
            className="text-sm px-3 py-1.5 rounded-lg bg-gray-100 dark:bg-gray-700 text-gray-700 dark:text-gray-300 disabled:opacity-40"
          >
            ← Anterior
          </button>
          <span className="text-sm text-gray-500 dark:text-gray-400">
            {page} / {meta.last_page}
          </span>
          <button
            onClick={() => setPage((p) => Math.min(meta.last_page, p + 1))}
            disabled={page === meta.last_page}
            className="text-sm px-3 py-1.5 rounded-lg bg-gray-100 dark:bg-gray-700 text-gray-700 dark:text-gray-300 disabled:opacity-40"
          >
            Siguiente →
          </button>
        </div>
      )}

      <ConfirmModal
        item={confirm}
        type="order"
        onConfirm={handleDelete}
        onCancel={() => setConfirm(null)}
        loading={deleting}
      />
    </div>
  );
}

// ── Página principal ───────────────────────────────────────────────────────────

const TABS = [
  { id: "pallets", label: "Pallets" },
  { id: "orders",  label: "Pedidos" },
];

export default function AdminStorage() {
  const [stats, setStats]   = useState(null);
  const [tab, setTab]       = useState("pallets");

  useEffect(() => {
    apiGet("/admin/storage/stats")
      .then(setStats)
      .catch(() => {});
  }, []);

  return (
    <div className="max-w-3xl mx-auto px-4 py-6 space-y-5">
      <Title>Limpieza de datos</Title>

      {/* Aviso informativo */}
      <div className="bg-blue-50 dark:bg-blue-900/20 border border-blue-200 dark:border-blue-700/40 rounded-xl px-4 py-3 text-sm text-blue-800 dark:text-blue-300 leading-relaxed">
        <span className="font-semibold">Panel de administración.</span>{" "}
        Eliminá pallets o pedidos de prueba junto con todos sus archivos físicos.
        El archivado automático corre el primer día de cada mes a las 3:00am.
      </div>

      {/* Stats */}
      <StatsBar stats={stats} />

      {/* Tabs */}
      <div className="flex border-b border-gray-200 dark:border-gray-700">
        {TABS.map((t) => (
          <button
            key={t.id}
            onClick={() => setTab(t.id)}
            className={`px-5 py-2.5 text-sm font-semibold border-b-2 transition-colors ${
              tab === t.id
                ? "border-gray-900 dark:border-white text-gray-900 dark:text-white"
                : "border-transparent text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-200"
            }`}
          >
            {t.label}
          </button>
        ))}
      </div>

      {/* Contenido */}
      {tab === "pallets" ? <PalletsTab /> : <OrdersTab />}
    </div>
  );
}
