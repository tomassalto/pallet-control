import { useEffect, useRef, useState } from "react";
import { apiGet } from "../../api/client";
import { INPUT_CLS } from "./PendingItemsShared";

export default function OrderSearch({ value, onChange }) {
  const [query, setQuery] = useState(value ? `#${value.code}` : "");
  const [results, setResults] = useState([]);
  const [loading, setLoading] = useState(false);
  const [open, setOpen] = useState(false);
  const ref = useRef(null);

  useEffect(() => {
    function outside(e) {
      if (ref.current && !ref.current.contains(e.target)) setOpen(false);
    }
    document.addEventListener("mousedown", outside);
    return () => document.removeEventListener("mousedown", outside);
  }, []);

  useEffect(() => {
    if (!query || query.startsWith("#")) return;
    const t = setTimeout(async () => {
      setLoading(true);
      try {
        const res = await apiGet(
          `/orders?search=${encodeURIComponent(query)}&limit=8`,
        );
        setResults(Array.isArray(res) ? res : (res.data ?? []));
        setOpen(true);
      } catch {
        setResults([]);
      } finally {
        setLoading(false);
      }
    }, 300);
    return () => clearTimeout(t);
  }, [query]);

  function select(order) {
    setQuery(`#${order.code}`);
    setOpen(false);
    onChange(order);
  }

  function clear() {
    setQuery("");
    onChange(null);
  }

  return (
    <div className="relative" ref={ref}>
      <div className="relative">
        <input
          value={query}
          onChange={(e) => {
            setQuery(e.target.value);
            if (value) onChange(null);
          }}
          onFocus={() => results.length && setOpen(true)}
          placeholder="Buscar por número de pedido…"
          className={INPUT_CLS}
        />
        {value && (
          <button
            type="button"
            onClick={clear}
            className="absolute right-3 top-1/2 -translate-y-1/2 text-gray-400 hover:text-gray-700 dark:hover:text-gray-200 text-lg leading-none"
          >
            ×
          </button>
        )}
      </div>
      {open && (loading || results.length > 0) && (
        <div className="absolute z-50 w-full mt-1 bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-xl shadow-lg max-h-60 overflow-y-auto">
          {loading ? (
            <div className="p-3 text-sm text-gray-400 text-center">
              Buscando…
            </div>
          ) : (
            results.map((order) => (
              <button
                key={order.id}
                type="button"
                onClick={() => select(order)}
                className="w-full text-left px-4 py-2.5 hover:bg-gray-50 dark:hover:bg-gray-700 border-b border-gray-100 dark:border-gray-700/50 last:border-0 transition-colors"
              >
                <span className="font-mono font-semibold text-sm text-gray-900 dark:text-white">
                  #{order.code}
                </span>
                {order.customer && (
                  <span className="text-xs text-gray-400 ml-2">
                    {order.customer.name}
                  </span>
                )}
              </button>
            ))
          )}
        </div>
      )}
    </div>
  );
}
