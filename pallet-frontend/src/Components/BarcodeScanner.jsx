import { useEffect, useId, useRef, useState } from "react";
import { Html5Qrcode, Html5QrcodeSupportedFormats } from "html5-qrcode";

export default function BarcodeScanner({ onDetected, onClose }) {
  const reactId = useId();
  const scannerId = `html5qrcode-${reactId.replaceAll(":", "")}`; // id estable

  const scannerRef = useRef(null);
  const [error, setError] = useState("");

  useEffect(() => {
    let cancelled = false;

    async function cleanup() {
      const scanner = scannerRef.current;
      if (!scanner) return;

      try {
        await scanner.stop();
      } catch (_e) {}

      try {
        await scanner.clear();
      } catch (_e) {}

      scannerRef.current = null;
    }

    async function start() {
      setError("");

      if (!navigator?.mediaDevices?.getUserMedia) {
        setError(
          "Este navegador no soporta cámara (getUserMedia). Usá HTTPS y un navegador actualizado."
        );
        return;
      }

      const scanner = new Html5Qrcode(scannerId);
      scannerRef.current = scanner;

      try {
        await scanner.start(
          {
            facingMode: "environment",
          },

          {
            fps: 30,
            qrbox: { width: 1920, height: 1080 },
            formatsToSupport: [
              Html5QrcodeSupportedFormats.EAN_13,
              Html5QrcodeSupportedFormats.EAN_8,
              Html5QrcodeSupportedFormats.CODE_128,
              Html5QrcodeSupportedFormats.UPC_A,
              Html5QrcodeSupportedFormats.UPC_E,
            ],
          },
          async (decodedText) => {
            if (cancelled) return;
            cancelled = true;

            await cleanup();
            onDetected?.(decodedText);
            onClose?.();
          },
          () => {}
        );
      } catch (e) {
        setError(
          e?.message ||
            "No se pudo iniciar el escáner. Revisá permisos de cámara / HTTPS."
        );
        await cleanup();
      }
    }

    const t = setTimeout(() => {
      if (!cancelled) start();
    }, 0);

    return () => {
      cancelled = true;
      clearTimeout(t);
      cleanup();
    };
  }, [scannerId, onDetected, onClose]);

  return (
    <div className="fixed inset-0 z-50 bg-black/70 flex items-center justify-center p-4">
      <div className="w-full max-w-sm bg-white rounded-2xl p-4">
        <div className="flex items-center justify-between">
          <h2 className="font-semibold">Escanear código</h2>
          <button onClick={onClose} className="px-3 py-2 rounded-lg border">
            Cerrar
          </button>
        </div>

        <div className="mt-3 rounded-xl overflow-hidden bg-black">
          <div id={scannerId} className="w-full h-72" />
        </div>

        <p className="mt-3 text-xs text-gray-600">
          Apuntá al código. Si no enfoca, alejás/acercás un poco.
        </p>

        {error && (
          <div className="mt-3 bg-red-50 border border-red-200 text-red-800 rounded-xl p-3 text-sm">
            {error}
          </div>
        )}
      </div>
    </div>
  );
}
