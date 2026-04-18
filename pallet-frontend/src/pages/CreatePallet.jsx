import { useState } from "react";
import { useNavigate } from "react-router-dom";
import { apiPost } from "../api/client";
import { toastSuccess, toastError } from "../ui/toast";
import Button from "../ui/Button";
import Title from "../ui/Title";

export default function CreatePallet() {
  const nav = useNavigate();
  const [loading, setLoading] = useState(false);

  async function onCreate() {
    setLoading(true);
    try {
      const pallet = await apiPost(`/pallets`, { note: null });

      toastSuccess(`Pallet creado: ${pallet.code}`);
      nav(`/pallet/${pallet.id}`);
    } catch (e) {
      toastError(e?.data?.message || e?.message || "Error creando pallet");
    } finally {
      setLoading(false);
    }
  }

  return (
    <div className="min-h-dvh flex items-center justify-center mt-[-120px] mb-[-40px]">
      <div className="flex flex-col gap-6 w-full max-w-md px-4 items-center">
        <Title size="5xl">Crear pallet</Title>

        <Button
          onClick={onCreate}
          disabled={loading}
          text={loading ? "Creando..." : "Crear pallet"}
          size="md"
          color="black"
          className="w-3/4 rounded-xl"
        />

        <p className="text-sm text-gray-600">
          Inicializá un pallet vacío. Luego, asignás pedido(s) y/o importás
          pedido(s).
        </p>
      </div>
    </div>
  );
}
