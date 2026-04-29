import { useState } from "react";
import { useNavigate } from "react-router-dom";
import { apiPost } from "../api/client";
import { toastSuccess, toastError } from "../ui/toast";
import CustomerAutocomplete from "../ui/CustomerAutocomplete";

function onlyDigits(v) {
  return (v || "").replace(/\D/g, "");
}

const SEC_LABEL = "text-xs font-semibold uppercase tracking-wider text-gray-400 dark:text-gray-500";
const INPUT_CLS = "w-full border border-gray-300 dark:border-gray-600 rounded-xl px-4 py-3 bg-white dark:bg-gray-700 dark:text-white focus:outline-none focus:ring-2 focus:ring-gray-900 dark:focus:ring-white transition-shadow";

export default function CreateOrder() {
  const nav = useNavigate();
  const [loading, setLoading] = useState(false);
  const [orderCode, setOrderCode] = useState("");
  const [selectedCustomer, setSelectedCustomer] = useState(null);

  async function onCreate() {
    const clean = onlyDigits(orderCode);
    if (!clean) { toastError("El número de pedido debe ser numérico."); return; }
    setLoading(true);
    try {
      const order = await apiPost(`/orders`, {
        code: clean,
        customer_id: selectedCustomer?.id || null,
        note: null,
      });
      toastSuccess(`Pedido creado: ${order.code}`);
      nav(`/order/${order.id}`);
    } catch (e) {
      toastError(e?.data?.message || e?.message || "Error creando pedido");
    } finally {
      setLoading(false);
    }
  }

  return (
    <div className="flex flex-col justify-center min-h-[calc(100dvh-7rem)] py-8 w-full">
      <div className="space-y-8 w-full max-w-md mx-auto">

        {/* ── Header ─────────────────────────────────────────────── */}
        <div className="space-y-1">
          <h1 className="font-bold text-2xl md:text-3xl text-gray-900 dark:text-white tracking-tight">
            Empezar pedido
          </h1>
          <p className="text-sm text-gray-500 dark:text-gray-400 leading-relaxed">
            Creá un nuevo pedido y luego asocialo a un pallet.
          </p>
        </div>

        {/* ── Formulario ─────────────────────────────────────────── */}
        <div className="space-y-5">
          {/* Número de pedido */}
          <div className="space-y-1.5">
            <label className={SEC_LABEL}>Número de pedido</label>
            <input
              value={orderCode}
              onChange={(e) => setOrderCode(onlyDigits(e.target.value))}
              inputMode="numeric"
              placeholder="Ej: 1310153"
              className={`${INPUT_CLS} text-center font-mono text-lg tracking-wider`}
              onKeyDown={(e) => { if (e.key === "Enter") onCreate(); }}
              autoFocus
            />
          </div>

          {/* Cliente */}
          <div className="space-y-1.5">
            <label className={SEC_LABEL}>
              Cliente{" "}
              <span className="normal-case font-normal tracking-normal text-gray-300 dark:text-gray-600">
                (opcional)
              </span>
            </label>
            <CustomerAutocomplete
              value={selectedCustomer}
              onChange={setSelectedCustomer}
              placeholder="Buscar por nombre o quit..."
              className="w-full"
            />
            {selectedCustomer && (
              <p className="text-xs text-gray-500 dark:text-gray-400">
                Seleccionado:{" "}
                <span className="font-semibold text-gray-700 dark:text-gray-300">
                  {selectedCustomer.name}
                </span>
                {selectedCustomer.quit && <> · Quit: {selectedCustomer.quit}</>}
              </p>
            )}
          </div>
        </div>

        {/* ── Acción ─────────────────────────────────────────────── */}
        <button
          onClick={onCreate}
          disabled={loading || !orderCode.trim()}
          className="w-full py-3.5 rounded-2xl bg-gray-900 dark:bg-white text-white dark:text-gray-900 font-bold text-sm hover:bg-gray-700 dark:hover:bg-gray-100 disabled:opacity-40 transition-colors"
        >
          {loading ? "Creando…" : "Crear pedido"}
        </button>
      </div>
    </div>
  );
}
