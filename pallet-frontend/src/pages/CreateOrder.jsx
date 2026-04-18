import { useState } from "react";
import { useNavigate } from "react-router-dom";
import { apiPost } from "../api/client";
import { toastSuccess, toastError } from "../ui/toast";
import Button from "../ui/Button";
import Title from "../ui/Title";
import CustomerAutocomplete from "../ui/CustomerAutocomplete";

function onlyDigits(v) {
  return (v || "").replace(/\D/g, "");
}

export default function CreateOrder() {
  const nav = useNavigate();
  const [loading, setLoading] = useState(false);
  const [orderCode, setOrderCode] = useState("");
  const [selectedCustomer, setSelectedCustomer] = useState(null);

  async function onCreate() {
    const clean = onlyDigits(orderCode);
    if (!clean) {
      toastError("El número de pedido debe ser numérico.");
      return;
    }

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
    <div className="min-h-dvh flex items-center justify-center mt-[-50px] mb-[-40px]">
      <div className="flex flex-col gap-6 w-full max-w-md px-4 items-center">
        <Title size="5xl">Empezar pedido</Title>

        <div className="w-full space-y-4">
          <div className="space-y-2">
            <label className="block text-md font-medium text-gray-700">
              Número de pedido
            </label>
            <input
              value={orderCode}
              onChange={(e) => setOrderCode(onlyDigits(e.target.value))}
              inputMode="numeric"
              placeholder="Ej: 123456"
              className="w-full bg-white border rounded-lg px-3 py-2 text-center text-lg font-mono"
              onKeyDown={(e) => {
                if (e.key === "Enter") {
                  onCreate();
                }
              }}
            />
          </div>

          <div className="space-y-2">
            <label className="block text-md font-medium text-gray-700">
              Cliente (opcional)
            </label>
            <CustomerAutocomplete
              value={selectedCustomer}
              onChange={setSelectedCustomer}
              placeholder="Buscar por nombre o quit..."
              className="w-full"
            />
            {selectedCustomer && (
              <div className="text-xs text-gray-500">
                Cliente seleccionado:{" "}
                <span className="font-semibold">{selectedCustomer.name}</span>
                {selectedCustomer.quit && <> (Quit: {selectedCustomer.quit})</>}
              </div>
            )}
          </div>
        </div>

        <Button
          onClick={onCreate}
          disabled={loading || !orderCode.trim()}
          text={loading ? "Creando..." : "Crear pedido"}
          size="md"
          color="black"
          className="w-3/4 rounded-xl"
        />

        <p className="text-sm text-gray-600 text-center w-3/4">
          Creá un nuevo pedido. Luego podrás asociarlo a un pallet existente o
          crear uno nuevo.
        </p>
      </div>
    </div>
  );
}
