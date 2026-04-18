import { useMemo, useState } from "react";
import { Link, useNavigate, useParams } from "react-router-dom";
import { apiPost } from "../api/client";
import { toastSuccess, toastError } from "../ui/toast";
import Button from "../ui/Button";
import BackButton from "../ui/BackButton";

function parseLines(raw) {
  const lines = String(raw || "")
    .trim()
    .split(/\r\n|\n|\r/);

  const items = [];
  for (const lineRaw of lines) {
    const line = lineRaw.trim();
    if (!line) continue;

    // separadores típicos (copiado desde tabla)
    let parts = line.split(/\t+|\s{2,}|\s*\|\s*/);

    // Si hay una columna adicional al final con valor "1", ignorarla
    const hasExternalFlag =
      parts.length > 0 &&
      parts[parts.length - 1].trim() === "1" &&
      parts.length > 4;
    if (hasExternalFlag) {
      parts = parts.slice(0, -1); // Eliminar la última columna
    }

    if (parts.length < 4) continue;

    const ean = parts[0].replace(/\D/g, "");
    // La cantidad está en la última columna (después de eliminar el flag si existe)
    const qtyIndex = parts.length - 1;
    const qty = String(parts[qtyIndex]).split(/[.,]/)[0];
    const description = parts[1].trim();

    if (!ean || !description || !qty || qty <= 0) continue;

    items.push({ ean, description, qty });
  }
  return items;
}

export default function ImportOrder() {
  const { orderId } = useParams();
  const [raw, setRaw] = useState("");
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState("");
  const nav = useNavigate();

  const preview = useMemo(() => parseLines(raw), [raw]);

  async function onImport() {
    setError("");

    if (preview.length === 0) {
      setError(
        "No se pudo interpretar el texto. Pegá la tabla (EAN, descripción, cantidad)."
      );
      return;
    }

    setLoading(true);
    try {
      // Backend: POST /orders/:id/import { raw }
      const res = await apiPost(`/orders/${orderId}/import`, { raw });
      toastSuccess(`Importado: ${res.count} ítems`);
      nav(`/order/${orderId}`);
    } catch (e) {
      setError(e.message || "Error importando");
      toastError(e.message || "Error importando");
    } finally {
      setLoading(false);
    }
  }

  return (
    <div className="space-y-4">
      <BackButton to={`/order/${orderId}`} />

      <div>
        <h1 className="text-xl font-semibold">Importar pedido</h1>
      </div>

      <div className="bg-white border rounded-2xl p-4 space-y-2">
        <div className="text-sm font-medium">Pegá aquí la tabla</div>
        <textarea
          value={raw}
          onChange={(e) => setRaw(e.target.value)}
          placeholder={`Ej:\n7791234567890\tYerba Mate 1kg\t12\n7790000000002\tAzúcar 1kg\t6`}
          className="w-full border rounded-lg p-3 min-h-[180px] text-sm font-mono"
        />

        <Button
          onClick={onImport}
          disabled={loading}
          text={loading ? "Importando..." : "Importar y guardar"}
          size="md"
          color="black"
          className="w-full rounded-lg"
        />

        <p className="text-xs text-gray-500">
          Formato: <b>EAN</b> + <b>Descripción</b> + <b>Cantidad</b>. Separado
          por TAB o espacios.
        </p>
      </div>

      {error && (
        <div className="bg-red-50 border border-red-200 text-red-800 rounded-xl p-3">
          {error}
        </div>
      )}

      <div className="bg-white border rounded-2xl overflow-hidden">
        <div className="px-4 py-3 border-b">
          <div className="font-semibold">Vista previa</div>
          <div className="text-xs text-gray-500">
            Detectados: {preview.length}
          </div>
        </div>

        {preview.length === 0 ? (
          <div className="p-4 text-sm text-gray-600">
            Todavía no hay ítems detectados.
          </div>
        ) : (
          <div className="overflow-x-auto">
            <table className="w-full text-sm">
              <thead className="bg-gray-50 text-gray-600">
                <tr>
                  <th className="text-left p-3">EAN</th>
                  <th className="text-left p-3">Descripción</th>
                  <th className="text-right p-3">Cant.</th>
                </tr>
              </thead>
              <tbody>
                {preview.map((it, idx) => (
                  <tr key={`${it.ean}-${idx}`} className="border-t">
                    <td className="p-3 font-mono">{it.ean}</td>
                    <td className="p-3">{it.description}</td>
                    <td className="p-3 text-right font-semibold">{it.qty}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </div>
    </div>
  );
}
