import { useEffect, useState, useMemo } from "react";
import { Link } from "react-router-dom";
import { apiGet } from "../api/client";
import { toastError } from "../ui/toast";
import Title from "../ui/Title";
import BackButton from "../ui/BackButton";
import Accordion from "../ui/Accordion";
import { PageSpinner, InlineSpinner } from "../ui/Spinner";
import EntityCard from "../ui/EntityCard";

function palletBadge(status) {
  if (status === "done") return { label: "Completo", color: "green" };
  return { label: "En proceso", color: "blue" };
}

function formatDate(iso) {
  return new Date(iso).toLocaleDateString(undefined, { dateStyle: "medium" });
}

function PalletCard({ p, dim = false }) {
  const badge = palletBadge(p.status);
  return (
    <Link to={`/pallet/${p.id}`} className="block">
      <EntityCard
        accent={badge.color}
        dim={dim}
        badge={badge}
        title={p.code}
        meta={[p.created_at && `Creado el ${formatDate(p.created_at)}`]}
      />
    </Link>
  );
}

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
      toastError(e?.message || e?.response?.data?.message || "No se pudo cargar pallets");
    } finally {
      setLoading(false);
    }
  }

  useEffect(() => { load(1, { append: false }); }, []);

  const { openPallets, completedPallets } = useMemo(() => ({
    openPallets: pallets.filter((p) => p.status !== "done"),
    completedPallets: pallets.filter((p) => p.status === "done"),
  }), [pallets]);

  return (
    <div className="space-y-6">
      <div className="flex justify-start">
        <BackButton to="/" />
      </div>

      <div className="flex flex-col gap-1.5 items-center text-center">
        <Title size="4xl">Mis pallets</Title>
        <p className="text-sm text-gray-500 dark:text-gray-400 max-w-xs">
          Historial de pallets creados. Tocá uno para ver el detalle.
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
          {/* Pallets abiertos */}
          {openPallets.length > 0 && (
            <section className="space-y-2">
              <p className="text-xs font-semibold uppercase tracking-wider text-gray-400 dark:text-gray-500 px-1">
                En proceso ({openPallets.length})
              </p>
              <div className="flex flex-col gap-2">
                {openPallets.map((p) => <PalletCard key={p.id} p={p} />)}
              </div>
            </section>
          )}

          {/* Pallets completados */}
          {completedPallets.length > 0 && (
            <Accordion title={`Completados (${completedPallets.length})`}>
              <div className="flex flex-col gap-2 pt-1">
                {completedPallets.map((p) => <PalletCard key={p.id} p={p} dim />)}
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
