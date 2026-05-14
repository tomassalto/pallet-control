import { useEffect, useState } from "react";
import { useParams } from "react-router-dom";
import { apiGet } from "../api/client";
import { TicketSection, OrderItemsTable, PalletCard } from "./OrderPublicViewSections";
import MissingItemsForm from "./MissingItemsForm";

// ── Íconos inline ─────────────────────────────────────────────────────────────
const SunIcon = () => (
  <svg className="w-4 h-4 text-amber-400" fill="currentColor" viewBox="0 0 24 24">
    <path d="M12 2.25a.75.75 0 0 1 .75.75v2.25a.75.75 0 0 1-1.5 0V3a.75.75 0 0 1 .75-.75ZM7.5 12a4.5 4.5 0 1 1 9 0 4.5 4.5 0 0 1-9 0ZM18.894 6.166a.75.75 0 0 0-1.06-1.06l-1.591 1.59a.75.75 0 1 0 1.06 1.061l1.591-1.59ZM21.75 12a.75.75 0 0 1-.75.75h-2.25a.75.75 0 0 1 0-1.5H21a.75.75 0 0 1 .75.75ZM17.834 18.894a.75.75 0 0 0 1.06-1.06l-1.59-1.591a.75.75 0 1 0-1.061 1.06l1.59 1.591ZM12 18a.75.75 0 0 1 .75.75V21a.75.75 0 0 1-1.5 0v-2.25A.75.75 0 0 1 12 18ZM7.166 17.834a.75.75 0 0 0-1.06 1.06l1.59 1.591a.75.75 0 1 0 1.061-1.06l-1.59-1.591ZM6 12a.75.75 0 0 1-.75.75H3a.75.75 0 0 1 0-1.5h2.25A.75.75 0 0 1 6 12ZM6.166 6.166a.75.75 0 0 0 1.06-1.06L5.636 3.515a.75.75 0 0 0-1.061 1.06l1.591 1.591Z" />
  </svg>
);

const MoonIcon = () => (
  <svg className="w-4 h-4 text-gray-500" fill="currentColor" viewBox="0 0 24 24">
    <path fillRule="evenodd" d="M9.528 1.718a.75.75 0 0 1 .162.819A8.97 8.97 0 0 0 9 6a9 9 0 0 0 9 9 8.97 8.97 0 0 0 3.463-.69.75.75 0 0 1 .981.98 10.503 10.503 0 0 1-9.694 6.46c-5.799 0-10.5-4.7-10.5-10.5 0-4.368 2.667-8.112 6.46-9.694a.75.75 0 0 1 .818.162Z" clipRule="evenodd" />
  </svg>
);

const ShareIcon = () => (
  <svg className="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" strokeWidth={2} stroke="currentColor">
    <path strokeLinecap="round" strokeLinejoin="round" d="M7.217 10.907a2.25 2.25 0 1 0 0 2.186m0-2.186c.18.324.283.696.283 1.093s-.103.77-.283 1.093m0-2.186 9.566-5.314m-9.566 7.5 9.566 5.314m0 0a2.25 2.25 0 1 0 3.935 2.186 2.25 2.25 0 0 0-3.935-2.186zm0-12.814a2.25 2.25 0 1 0 3.933-2.185 2.25 2.25 0 0 0-3.933 2.185z" />
  </svg>
);

const CheckIcon = () => (
  <svg className="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" strokeWidth={2.5} stroke="currentColor">
    <path strokeLinecap="round" strokeLinejoin="round" d="M4.5 12.75l6 6 9-13.5" />
  </svg>
);

// ── Vista principal ───────────────────────────────────────────────────────────
export default function OrderPublicView() {
  const { code } = useParams();
  const [order, setOrder] = useState(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);
  const [copied, setCopied] = useState(false);

  const [isDark, setIsDark] = useState(() => {
    try {
      const saved = localStorage.getItem("theme");
      if (saved !== null) return saved === "dark";
    } catch {}
    return window.matchMedia("(prefers-color-scheme: dark)").matches;
  });

  useEffect(() => {
    document.documentElement.classList.toggle("dark", isDark);
    try { localStorage.setItem("theme", isDark ? "dark" : "light"); } catch {}
  }, [isDark]);

  useEffect(() => {
    apiGet(`/public/orders/${code}`)
      .then(setOrder)
      .catch((e) => setError(e?.response?.data?.message || "Pedido no encontrado"))
      .finally(() => setLoading(false));
  }, [code]);

  async function handleShare() {
    const url = window.location.href;
    const title = `Pedido ${code} — Pallet Control`;
    const text = `Ver el contenido del pedido ${code}`;
    if (navigator.share) {
      try { await navigator.share({ title, text, url }); } catch {}
    } else {
      try {
        await navigator.clipboard.writeText(url);
      } catch {
        const el = document.createElement("textarea");
        el.value = url;
        document.body.appendChild(el);
        el.select();
        document.execCommand("copy");
        document.body.removeChild(el);
      }
      setCopied(true);
      setTimeout(() => setCopied(false), 2000);
    }
  }

  if (loading) {
    return (
      <div className="min-h-screen bg-gray-50 dark:bg-gray-900 flex items-center justify-center">
        <p className="text-gray-400 text-sm animate-pulse">Cargando…</p>
      </div>
    );
  }

  if (error) {
    return (
      <div className="min-h-screen bg-gray-50 dark:bg-gray-900 flex items-center justify-center p-6">
        <div className="text-center">
          <p className="text-5xl mb-4">📭</p>
          <p className="text-lg font-semibold text-gray-700 dark:text-gray-200">Pedido no encontrado</p>
          <p className="text-sm text-gray-400 mt-1">Código: {code}</p>
        </div>
      </div>
    );
  }

  const isDone = order.status === "done";
  const totalPallets = order.pallets?.length ?? 0;
  const totalItems = order.items?.length ?? 0;

  return (
    <div className="min-h-screen bg-gray-50 dark:bg-gray-900">
      {/* ── Header sticky ──────────────────────────────────────── */}
      <div className="bg-white dark:bg-gray-800 border-b border-gray-200 dark:border-gray-700 sticky top-0 z-10 shadow-sm">
        <div className="max-w-lg mx-auto px-4 py-3 flex items-center gap-2">
          <div className="w-10 h-10 rounded-xl bg-indigo-600 flex items-center justify-center shrink-0 text-xl">🧾</div>
          <div className="flex-1 min-w-0">
            <p className="font-bold text-base leading-tight text-gray-900 dark:text-white">#{order.code}</p>
            <p className="text-xs text-gray-500 dark:text-gray-400 truncate">
              {order.customer && `${order.customer} · `}{totalItems} prod. · {totalPallets} pallet{totalPallets !== 1 ? "s" : ""}
            </p>
          </div>
          <span className={`shrink-0 text-xs font-semibold px-2.5 py-1 rounded-full ${isDone ? "bg-green-100 dark:bg-green-900/30 text-green-700 dark:text-green-300" : "bg-amber-100 dark:bg-amber-900/30 text-amber-700 dark:text-amber-300"}`}>
            {isDone ? "✅ Listo" : "🔄 En prep."}
          </span>
          <button
            onClick={() => setIsDark((v) => !v)}
            title={isDark ? "Modo claro" : "Modo oscuro"}
            className="shrink-0 w-8 h-8 rounded-xl bg-gray-100 dark:bg-gray-700 hover:bg-gray-200 dark:hover:bg-gray-600 flex items-center justify-center transition-colors"
          >
            {isDark ? <SunIcon /> : <MoonIcon />}
          </button>
          <button
            onClick={handleShare}
            title="Compartir"
            className="shrink-0 flex items-center gap-1.5 px-3 py-1.5 rounded-xl bg-blue-600 hover:bg-blue-700 text-white text-xs font-semibold transition-colors active:scale-95"
          >
            {copied ? <><CheckIcon /><span>Copiado</span></> : <><ShareIcon /><span className="hidden sm:inline">Compartir</span></>}
          </button>
        </div>
      </div>

      {/* ── Contenido ─────────────────────────────────────────── */}
      <div className="max-w-lg mx-auto px-4 py-5 space-y-8">

        {/* Tickets con OCR */}
        {order.ticket_sections?.length > 0 && (
          <section>
            <h2 className="text-xs font-bold uppercase tracking-widest text-gray-400 dark:text-gray-500 mb-3">
              🧾 Tickets del cliente
            </h2>
            <p className="text-xs text-gray-400 dark:text-gray-500 mb-3 -mt-1">
              Los productos resaltados fueron detectados en el ticket por OCR.
            </p>
            <TicketSection sections={order.ticket_sections} />
          </section>
        )}

        {/* Resumen de ítems */}
        <section>
          <h2 className="text-xs font-bold uppercase tracking-widest text-gray-400 dark:text-gray-500 mb-3">
            📋 Productos del pedido
          </h2>
          <OrderItemsTable items={order.items} totalPrice={order.total_price} />
        </section>

        {/* Pallets y bases */}
        {order.pallets?.length > 0 && (
          <section>
            <h2 className="text-xs font-bold uppercase tracking-widest text-gray-400 dark:text-gray-500 mb-3">
              📦 Pallets y bases
            </h2>
            <div className="space-y-3">
              {order.pallets.map((pallet, i) => (
                <PalletCard key={pallet.id} pallet={pallet} palletNum={i + 1} />
              ))}
            </div>
          </section>
        )}

        {/* Formulario de faltantes */}
        <section>
          <h2 className="text-xs font-bold uppercase tracking-widest text-gray-400 dark:text-gray-500 mb-3">
            ⚠️ ¿Falta algo?
          </h2>
          <MissingItemsForm items={order.items} orderCode={order.code} />
        </section>

        <p className="text-center text-xs text-gray-300 dark:text-gray-600 pb-4 pt-2">
          Pallet Control · Solo lectura
        </p>
      </div>
    </div>
  );
}
