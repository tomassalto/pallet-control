import { useMemo, useState } from "react";
import { useNavigate, useParams } from "react-router-dom";
import { apiPost } from "../api/client";
import { toastSuccess, toastError } from "../ui/toast";
import Button from "../ui/Button";
import BackButton from "../ui/BackButton";

/**
 * Columnas fijas del archivo (separadas por TAB):
 *  0  EAN
 *  1  Descripción
 *  2  Cant Pedida        → qty
 *  3  Cant Real          → cantReal (> 0 = done, = 0 = pending)
 *  4  Precio Unitario    → price
 *  5  Precio Base        → ignorar
 *  6  Desc. Base         → ignorar
 *  7  Desc. Medio Pago   → desc_medio_pago  (10.00 = lunes/viernes)
 *  8  Controlado         → is_controlled    (1 = sí)
 */
function parseLines(raw) {
  const lines = String(raw || "").trim().split(/\r\n|\n|\r/);
  const items = [];

  for (const lineRaw of lines) {
    const line = lineRaw.trim();
    if (!line) continue;

    // Dividir SOLO por tab para preservar posiciones de columnas vacías
    const parts = line.split("\t");

    // Necesitamos al menos EAN, Descripción, Cant Pedida
    if (parts.length < 3) continue;

    // Col 0: EAN
    const ean = (parts[0] || "").replace(/\D/g, "");
    if (!ean) continue;

    // Col 1: Descripción
    const description = (parts[1] || "").trim();
    if (!description) continue;

    // Col 2: Cant Pedida
    const cantPedidaStr = (parts[2] || "0").replace(",", ".").replace(/[^0-9.]/g, "");
    const cantPedida = Math.floor(parseFloat(cantPedidaStr) || 0);

    // Col 3: Cant Real (lo que realmente se encontró/entregó)
    const cantRealStr = (parts[3] || "0").replace(",", ".").replace(/[^0-9.]/g, "");
    const cantReal = Math.floor(parseFloat(cantRealStr) || 0);

    // Saltar si no hay ninguna cantidad
    if (cantPedida <= 0 && cantReal <= 0) continue;

    // Si Cant Pedida = 0 pero Cant Real > 0 (producto extra no pedido),
    // usamos Cant Real como qty para que el conteo tenga sentido
    const qty = cantPedida > 0 ? cantPedida : cantReal;
    const isDone = cantReal > 0;

    // Col 4: Precio Unitario
    const priceStr = (parts[4] || "").replace(",", ".").replace(/[^0-9.]/g, "");
    const price = priceStr ? parseFloat(priceStr) : null;

    // Col 7: Desc. Medio Pago (10.00 si aplica descuento lunes/viernes)
    const dmpStr = (parts[7] || "").replace(",", ".").replace(/[^0-9.]/g, "");
    const descMedioPago = dmpStr && parseFloat(dmpStr) > 0 ? parseFloat(dmpStr) : null;

    // Col 8: Controlado (1 = sí)
    const isControlled = (parts[8] || "").trim() === "1";

    items.push({ ean, description, qty, cantReal, isDone, price, descMedioPago, isControlled });
  }

  return items;
}

function fmt(n) {
  if (n == null) return "—";
  return Number(n).toLocaleString("es-AR", { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

export default function ImportOrder() {
  const { orderId } = useParams();
  const [raw, setRaw] = useState("");
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState("");
  const nav = useNavigate();

  const preview = useMemo(() => parseLines(raw), [raw]);
  const doneCount    = preview.filter((it) => it.isDone).length;
  const pendingCount = preview.filter((it) => !it.isDone).length;

  async function onImport() {
    setError("");

    if (preview.length === 0) {
      setError("No se pudo interpretar el texto. Pegá la tabla copiada directamente desde el sistema.");
      return;
    }

    setLoading(true);
    try {
      const res = await apiPost(`/orders/${orderId}/import`, { raw });
      toastSuccess(`Importado: ${res.done} encontrados, ${res.pending} pendientes`);
      nav(`/order/${orderId}`);
    } catch (e) {
      const msg = e?.response?.data?.message || e.message || "Error importando";
      setError(msg);
      toastError(msg);
    } finally {
      setLoading(false);
    }
  }

  return (
    <div className="space-y-4">
      <BackButton to={`/order/${orderId}`} />

      <div>
        <h1 className="text-xl font-semibold">Importar pedido</h1>
        <p className="text-xs text-gray-500 mt-1">
          Copiá la tabla desde el sistema y pegala acá. Las columnas deben estar
          separadas por TAB (como al copiar desde Excel o una tabla web).
        </p>
      </div>

      <div className="bg-white border rounded-2xl p-4 space-y-3">
        <div className="text-sm font-medium">Pegá aquí la tabla</div>
        <textarea
          value={raw}
          onChange={(e) => setRaw(e.target.value)}
          placeholder={"EAN\tDescripción\tCant Pedida\tCant Real\tPrecio Unitario\tPrecio Base\tDesc. Base\tDesc. Medio Pago\tControlado"}
          className="w-full border rounded-lg p-3 min-h-[160px] text-sm font-mono"
        />

        <Button
          onClick={onImport}
          disabled={loading || preview.length === 0}
          text={
            loading
              ? "Importando..."
              : preview.length > 0
                ? `Importar · ${doneCount} encontrados, ${pendingCount} pendientes`
                : "Importar"
          }
          size="md"
          color="black"
          className="w-full rounded-lg"
        />

        <p className="text-xs text-gray-500">
          Columnas usadas: <b>EAN · Descripción · Cant Pedida · Cant Real · Precio Unitario · Desc. Medio Pago · Controlado</b>
        </p>
      </div>

      {error && (
        <div className="bg-red-50 border border-red-200 text-red-800 rounded-xl p-3 text-sm">
          {error}
        </div>
      )}

      {/* Vista previa */}
      <div className="bg-white border rounded-2xl overflow-hidden">
        <div className="px-4 py-3 border-b">
          <div className="font-semibold">Vista previa</div>
          <div className="text-xs text-gray-500">
            {preview.length === 0
              ? "Pegá la tabla arriba para ver los ítems detectados"
              : `${preview.length} ítem${preview.length !== 1 ? "s" : ""} · ${doneCount} encontrados · ${pendingCount} pendientes`}
          </div>
        </div>

        {preview.length > 0 && (
          <div className="px-4 py-2 border-b bg-gray-50 flex gap-4 text-[11px] text-gray-500">
            <span className="flex items-center gap-1.5">
              <span className="inline-block w-3 h-3 rounded-sm bg-green-100 border border-green-300"/>
              Encontrado (C.Real &gt; 0)
            </span>
            <span className="flex items-center gap-1.5">
              <span className="inline-block w-3 h-3 rounded-sm bg-gray-100 border border-gray-300"/>
              Pendiente (C.Real = 0)
            </span>
          </div>
        )}

        {preview.length === 0 ? (
          <div className="p-4 text-sm text-gray-500 text-center">
            Todavía no hay ítems detectados.
          </div>
        ) : (
          <div className="overflow-x-auto">
            <table className="w-full text-xs">
              <thead className="bg-gray-50 text-gray-600 text-left">
                <tr>
                  <th className="px-3 py-2">EAN</th>
                  <th className="px-3 py-2">Descripción</th>
                  <th className="px-3 py-2 text-right">C.Ped.</th>
                  <th className="px-3 py-2 text-right">C.Real</th>
                  <th className="px-3 py-2 text-right">Precio</th>
                  <th className="px-3 py-2 text-center">Desc.MP</th>
                  <th className="px-3 py-2 text-center">Ctrl.</th>
                </tr>
              </thead>
              <tbody>
                {preview.map((it, idx) => (
                  <tr
                    key={`${it.ean}-${idx}`}
                    className={`border-t ${it.isDone ? "bg-green-50" : "bg-gray-50/40"}`}
                  >
                    <td className="px-3 py-2 font-mono text-gray-600">{it.ean}</td>
                    <td className="px-3 py-2 max-w-[160px]">
                      <div className="truncate" title={it.description}>{it.description}</div>
                    </td>
                    <td className="px-3 py-2 text-right font-semibold">{it.qty}</td>
                    <td className={`px-3 py-2 text-right font-semibold ${it.isDone ? "text-green-700" : "text-gray-400"}`}>
                      {it.cantReal > 0 ? it.cantReal : <span className="text-gray-300">—</span>}
                    </td>
                    <td className="px-3 py-2 text-right text-gray-600">{fmt(it.price)}</td>
                    <td className="px-3 py-2 text-center">
                      {it.descMedioPago
                        ? <span className="bg-blue-100 text-blue-700 px-1.5 py-0.5 rounded text-[10px] font-medium">{fmt(it.descMedioPago)}</span>
                        : <span className="text-gray-300">—</span>}
                    </td>
                    <td className="px-3 py-2 text-center">
                      {it.isControlled
                        ? <span className="bg-amber-100 text-amber-700 px-1.5 py-0.5 rounded text-[10px] font-medium">✓</span>
                        : <span className="text-gray-300">—</span>}
                    </td>
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
