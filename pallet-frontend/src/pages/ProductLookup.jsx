import { useState } from "react";
import { apiGet } from "../api/client";
import BarcodeScanner from "../Components/BarcodeScanner.jsx";

export default function ProductLookup() {
  const [ean, setEan] = useState("");
  const [product, setProduct] = useState(null);
  const [error, setError] = useState("");
  const [loading, setLoading] = useState(false);
  const [scannerOpen, setScannerOpen] = useState(false);
  const [lastScan, setLastScan] = useState(null);

  async function searchByEan(code) {
    setLoading(true);
    setError("");
    setProduct(null);

    const tryList = [code];
    if (code.length === 12) tryList.push("0" + code);

    try {
      for (const eanTry of tryList) {
        try {
          const data = await apiGet(
            `/products/by-ean/${encodeURIComponent(eanTry)}`
          );
          setProduct(data);
          setLoading(false);
          return;
        } catch (e) {
          // seguimos intentando
        }
      }
      setError("No encontrado (probé EAN/UPC).");
    } finally {
      setLoading(false);
    }
  }

  function normalizeEan(text) {
    return String(text).replace(/\D/g, ""); // deja solo números
  }
  function normalizeBarcode(text) {
    // deja solo números (EAN/UPC son numéricos)
    return String(text || "").replace(/\D/g, "");
  }

  async function onScan(raw) {
    const clean = normalizeBarcode(raw);

    setEan(clean); // lo vemos en el input
    setLastScan({ raw, clean });

    if (!clean) {
      setError("No pude interpretar el código leído.");
      return;
    }

    await searchByEan(clean);
  }

  return (
    <div className="space-y-4">
      <h1 className="text-xl font-semibold">Buscar producto</h1>

      {/* Input manual */}
      <input
        value={ean}
        onChange={(e) => setEan(e.target.value)}
        placeholder="EAN"
        inputMode="numeric"
        className="w-full border rounded-lg px-3 py-3 text-base"
      />

      <button
        onClick={() => searchByEan(ean)}
        disabled={!ean || loading}
        className="w-full bg-black text-white py-3 rounded-lg disabled:opacity-50"
      >
        Buscar
      </button>

      <button
        onClick={() => setScannerOpen(true)}
        className="w-full border py-3 rounded-lg"
      >
        Escanear con cámara
      </button>

      {/* Resultado */}
      {loading && <div className="text-sm text-gray-500">Buscando…</div>}

      {error && (
        <div className="bg-red-50 border border-red-200 text-red-700 rounded-xl p-3">
          {error}
        </div>
      )}

      {product && (
        <div className="bg-white border rounded-xl p-4 space-y-1">
          <div className="font-semibold">{product.name}</div>
          <div className="text-sm">EAN: {product.ean}</div>
          <div className="text-xs text-gray-500">ID: {product.id}</div>
        </div>
      )}
      {lastScan && (
        <div className="bg-yellow-50 border border-yellow-200 text-yellow-900 rounded-xl p-3 text-sm">
          <div className="font-semibold">Último escaneo</div>
          <div>
            Raw: <span className="font-mono break-all">{lastScan.raw}</span>
          </div>
          <div>
            Limpio: <span className="font-mono">{lastScan.clean}</span>
          </div>
          <div className="text-xs text-yellow-700 mt-1">
            (Busco por el “Limpio”)
          </div>
        </div>
      )}

      {/* Scanner */}
      {scannerOpen && (
        <BarcodeScanner
          onDetected={onScan}
          onClose={() => setScannerOpen(false)}
        />
      )}
    </div>
  );
}
