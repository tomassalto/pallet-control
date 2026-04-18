import { useEffect, useState } from "react";
import { Link } from "react-router-dom";
import { apiGet } from "../api/client";

import Title from "../ui/Title";

export default function Home() {
  const [loading, setLoading] = useState(true);

  const [lastOpenOrder, setLastOpenOrder] = useState(null);

  async function load() {
    setLoading(true);

    try {
      // Cargar último pedido abierto
      const orderData = await apiGet("/orders/last-open");
      setLastOpenOrder(orderData?.order || null);
    } catch {
      // No mostrar error, simplemente no hay último pedido
      setLastOpenOrder(null);
    } finally {
      setLoading(false);
    }
  }

  useEffect(() => {
    load();
  }, []);

  if (loading) {
    return (
      <div className="space-y-6 pt-[120px]">
        <div className="flex flex-col gap-2">
          <Title size="3xl">Pallet Control</Title>
          <p className="text-sm text-gray-600">
            Control rápido de pallets, pedidos, importación y movimientos.
          </p>
        </div>

        {/* Loading state */}
        <div className="bg-white border rounded-2xl p-8 text-center">
          <div className="text-sm text-gray-600">Cargando...</div>
        </div>
      </div>
    );
  }

  return (
    <div className="space-y-6 pt-4">
      {/* Header */}
      <div className="flex flex-col gap-2">
        <Title size="3xl">Pallet Control</Title>
        <p className="text-sm text-gray-600">
          Control rápido de pallets, pedidos, importación y movimientos.
        </p>
      </div>

      {/* Último pedido abierto */}
      {lastOpenOrder && (
        <div className="bg-white border-border rounded-2xl p-2">
          <div className="flex items-center justify-center">
            <div>
              <Title size="1xl">Continuar último pedido abierto</Title>
            </div>
          </div>

          <Link
            to={`/order/${lastOpenOrder.id}`}
            className="block rounded-xl p-2 bg-gray-50 active:scale-[0.99]"
          >
            <div className="text-sm text-gray-500">Código</div>
            <div className="font-mono font-semibold">{lastOpenOrder.code}</div>

            {lastOpenOrder.pallets && lastOpenOrder.pallets.length > 0 && (
              <>
                <div className="mt-2 text-xs text-gray-500">Pallets</div>
                <div className="text-sm font-medium">
                  {lastOpenOrder.pallets.map((p) => p.code).join(", ")}
                </div>
              </>
            )}

            <div className="text-sm underline">Abrir pedido</div>
          </Link>
        </div>
      )}

      {/* Quick actions */}
      <div className="grid gap-3 shadow-2xl">
        <Link
          to="/orders/new"
          className="bg-black text-white rounded-2xl p-4 active:scale-[0.99]"
        >
          <Title size="1xl">Empezar pedido</Title>
          <div className="text-xs opacity-80">
            Apreta aca para crear un nuevo pedido y asociálo a un pallet
            existente o creá uno nuevo.
          </div>
        </Link>
      </div>
    </div>
  );
}
