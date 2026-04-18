import { useEffect, useState, useMemo } from "react";
import { Link } from "react-router-dom";
import { apiGet } from "../api/client";
import { toastError } from "../ui/toast";
import Title from "../ui/Title";
import BackButton from "../ui/BackButton";
import Accordion from "../ui/Accordion";

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
        e?.message || e?.response?.data?.message || "No se pudo cargar pallets"
      );
    } finally {
      setLoading(false);
    }
  }

  useEffect(() => {
    load(1, { append: false });
  }, []);

  const { openPallets, completedPallets } = useMemo(() => {
    const open = pallets.filter((p) => p.status !== "done");
    const completed = pallets.filter((p) => p.status === "done");
    return { openPallets: open, completedPallets: completed };
  }, [pallets]);

  return (
    <div className="space-y-4">
      <div className="flex justify-start">
        <BackButton to={`/`} />
      </div>
      <div className="flex flex-col gap-2 items-center">
        <Title size="4xl">Mis pallets</Title>
        <p className="text-sm text-gray-600 w-[200px]">
          Historial de pallets creados. Tocá uno para ver el detalle.
        </p>
      </div>

      {loading && pallets.length === 0 ? (
        <div className="text-sm text-gray-600">Cargando pallets…</div>
      ) : pallets.length === 0 ? (
        <div className="text-sm text-gray-600">
          Todavía no hay pallets en el historial.
        </div>
      ) : (
        <>
          {/* Pallets abiertos */}
          {openPallets.length > 0 && (
            <div className="flex flex-col gap-2">
              <div className="text-sm font-semibold text-gray-700">
                Pallets en proceso ({openPallets.length})
              </div>
              <div className="flex flex-col gap-2">
                {openPallets.map((p) => (
                  <Link
                    key={p.id}
                    to={`/pallet/${p.id}`}
                    className="block bg-white border-border rounded-2xl p-4 active:scale-[0.99]"
                  >
                    <div className="flex flex-col gap-1">
                      <Title size="2xl" className="font-mono font-semibold">
                        {p.code}
                      </Title>

                      <div className="text-sm text-gray-500">
                        Estado:{" "}
                        <span className="capitalize font-semibold text-gray-900">
                          {p.status}
                        </span>
                      </div>

                      {p.created_at && (
                        <div className="text-xs text-gray-500">
                          Creado:{" "}
                          {new Date(p.created_at).toLocaleString(undefined, {
                            dateStyle: "short",
                            timeStyle: "short",
                          })}
                        </div>
                      )}

                      <div className=" text-sm underline">Ver detalle</div>
                    </div>
                  </Link>
                ))}
              </div>
            </div>
          )}

          {/* Pallets completados */}
          {completedPallets.length > 0 && (
            <Accordion
              title={`Pallets completados (${completedPallets.length})`}
            >
              <div className="flex flex-col gap-2">
                {completedPallets.map((p) => (
                  <Link
                    key={p.id}
                    to={`/pallet/${p.id}`}
                    className="block bg-white border border-border rounded-2xl p-4 active:scale-[0.99] opacity-75"
                  >
                    <div className="flex flex-col gap-1">
                      <Title size="2xl" className="font-mono font-semibold">
                        {p.code}
                      </Title>

                      <div className="text-sm text-gray-500">
                        Estado:{" "}
                        <span className="capitalize font-semibold text-green-600">
                          {p.status}
                        </span>
                      </div>

                      {p.created_at && (
                        <div className="text-xs text-gray-500">
                          Creado:{" "}
                          {new Date(p.created_at).toLocaleString(undefined, {
                            dateStyle: "short",
                            timeStyle: "short",
                          })}
                        </div>
                      )}

                      <div className=" text-sm underline">Ver detalle</div>
                    </div>
                  </Link>
                ))}
              </div>
            </Accordion>
          )}

          {openPallets.length === 0 && completedPallets.length === 0 && (
            <div className="text-sm text-gray-600">
              No hay pallets para mostrar.
            </div>
          )}
        </>
      )}

      {hasMore && (
        <button
          disabled={loading}
          onClick={() => load(page + 1, { append: true })}
          className="w-full rounded-xl py-3 border bg-white text-sm disabled:opacity-60"
        >
          {loading ? "Cargando..." : "Cargar más"}
        </button>
      )}
    </div>
  );
}
