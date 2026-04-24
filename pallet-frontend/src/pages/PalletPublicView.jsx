import { useEffect, useState } from "react";
import { useParams } from "react-router-dom";
import { apiGet } from "../api/client";

function ProductCard({ item }) {
  const [imgError, setImgError] = useState(false);

  return (
    <div className="flex items-center gap-3 py-3 border-b last:border-0">
      {/* Imagen */}
      <div className="w-16 h-16 flex-shrink-0 rounded-xl overflow-hidden bg-gray-100 flex items-center justify-center">
        {item.image_url && !imgError ? (
          <img
            src={item.image_url}
            alt={item.description}
            className="w-full h-full object-contain"
            onError={() => setImgError(true)}
          />
        ) : (
          <span className="text-2xl">📦</span>
        )}
      </div>

      {/* Info */}
      <div className="flex-1 min-w-0">
        <div className="text-sm font-medium leading-snug line-clamp-2">
          {item.description}
        </div>
        <div className="text-xs text-gray-500 mt-0.5">EAN: {item.ean}</div>
      </div>

      {/* Cantidad */}
      <div className="flex-shrink-0 text-right">
        <div className="text-2xl font-bold text-gray-800">{item.qty}</div>
        <div className="text-xs text-gray-400">unid.</div>
      </div>
    </div>
  );
}

function OrderSection({ order }) {
  return (
    <div className="bg-white rounded-2xl overflow-hidden shadow-sm border border-gray-100">
      {/* Header del pedido */}
      <div className="bg-gray-50 px-4 py-3 border-b">
        <div className="font-semibold text-gray-800">Pedido #{order.code}</div>
        <div className="text-xs text-gray-500 mt-0.5">
          {order.items.length} producto{order.items.length !== 1 ? "s" : ""}
        </div>
      </div>

      {/* Ítems */}
      {order.items.length === 0 ? (
        <div className="px-4 py-6 text-sm text-gray-400 text-center">
          Sin productos registrados
        </div>
      ) : (
        <div className="px-4">
          {order.items.map((item, idx) => (
            <ProductCard key={`${item.ean}-${idx}`} item={item} />
          ))}
        </div>
      )}
    </div>
  );
}

export default function PalletPublicView() {
  const { code } = useParams();
  const [pallet, setPallet] = useState(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);

  useEffect(() => {
    apiGet(`/public/pallets/${code}`)
      .then(setPallet)
      .catch((e) => {
        const msg = e?.response?.data?.message || "Pallet no encontrado";
        setError(msg);
      })
      .finally(() => setLoading(false));
  }, [code]);

  if (loading) {
    return (
      <div className="min-h-screen bg-gray-50 flex items-center justify-center">
        <div className="text-gray-400 text-sm animate-pulse">Cargando...</div>
      </div>
    );
  }

  if (error) {
    return (
      <div className="min-h-screen bg-gray-50 flex items-center justify-center p-6">
        <div className="text-center">
          <div className="text-5xl mb-4">📭</div>
          <div className="text-lg font-semibold text-gray-700">Pallet no encontrado</div>
          <div className="text-sm text-gray-400 mt-1">Código: {code}</div>
        </div>
      </div>
    );
  }

  const totalProducts = pallet.orders.reduce((s, o) => s + o.items.length, 0);

  return (
    <div className="min-h-screen bg-gray-50">
      {/* Header */}
      <div className="bg-white border-b sticky top-0 z-10 shadow-sm">
        <div className="max-w-lg mx-auto px-4 py-4">
          <div className="flex items-center gap-3">
            <div className="w-10 h-10 rounded-xl bg-black flex items-center justify-center flex-shrink-0">
              <span className="text-white text-lg">📦</span>
            </div>
            <div>
              <div className="font-bold text-lg leading-tight">
                Pallet {pallet.code}
              </div>
              <div className="text-xs text-gray-500">
                {pallet.orders.length} pedido{pallet.orders.length !== 1 ? "s" : ""} · {totalProducts} producto{totalProducts !== 1 ? "s" : ""}
              </div>
            </div>
          </div>
        </div>
      </div>

      {/* Contenido */}
      <div className="max-w-lg mx-auto px-4 py-5 space-y-4">
        {pallet.orders.length === 0 ? (
          <div className="text-center py-12 text-gray-400">
            <div className="text-4xl mb-3">🗂️</div>
            <div>Este pallet no tiene pedidos asignados</div>
          </div>
        ) : (
          pallet.orders.map((order) => (
            <OrderSection key={order.id} order={order} />
          ))
        )}

        {/* Footer */}
        <div className="text-center text-xs text-gray-300 pb-4 pt-2">
          Pallet Control · Solo lectura
        </div>
      </div>
    </div>
  );
}
