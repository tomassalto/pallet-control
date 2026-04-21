import { useEffect } from "react";
import QRCode from "react-qr-code";

export default function QRModal({ order, onClose }) {
  const url = `${window.location.origin}/order/${order.id}`;

  // Cerrar con Escape
  useEffect(() => {
    const handler = (e) => e.key === "Escape" && onClose();
    window.addEventListener("keydown", handler);
    return () => window.removeEventListener("keydown", handler);
  }, [onClose]);

  function handlePrint() {
    const win = window.open("", "_blank", "width=500,height=600");
    const qrMarkup = document.getElementById("qr-svg-content")?.innerHTML ?? "";
    win.document.write(`
      <!DOCTYPE html>
      <html lang="es">
      <head>
        <meta charset="UTF-8" />
        <title>Pedido #${order.code}</title>
        
        <style>
          @page {
            margin: 0;
            size: auto;
          }
          body {
            font-family: sans-serif;
            margin: 0;
            padding: 3mm;
            box-sizing: border-box;
          }
          .label {
            display: inline-flex;
            flex-direction: column;
            align-items: center;
            justify-content: flex-start;
            gap: 0;
          }
          .qr-wrap {
            width: 55mm;
            height: 55mm;
            margin: 0;
            padding: 0;
            line-height: 0;
            display: flex;
            align-items: center;
            justify-content: center;
          }
          .qr-wrap svg {
            width: 100%;
            height: 100%;
            display: block;
            margin: 0;
          }
          .order-code {
            font-size: 24px;
            font-weight: 700;
            color: #000;
            line-height: 1;
            text-align: center;
            margin: 1mm 0 0;
          }
          @media print { button { display: none; } }
        </style>
      </head>
      <body>
        <div class="label">
          <div class="qr-wrap">${qrMarkup}</div>
          <div class="order-code">Pedido #${order.code}</div>
        </div>
        
        <script>window.onload = () => { window.print(); }</script>
      </body>
      </html>
    `);
    win.document.close();
  }

  async function handleDownloadPng() {
    try {
      const qrContainer = document.getElementById("qr-svg-content");
      const qrSvg = qrContainer?.querySelector("svg");
      if (!qrSvg) return;

      const qrSize = 800;
      const textHeight = 90;
      const padding = 24;
      const canvas = document.createElement("canvas");
      canvas.width = qrSize + padding * 2;
      canvas.height = qrSize + textHeight + padding * 2;

      const ctx = canvas.getContext("2d");
      if (!ctx) return;

      ctx.fillStyle = "#ffffff";
      ctx.fillRect(0, 0, canvas.width, canvas.height);

      const svgString = new XMLSerializer().serializeToString(qrSvg);
      const blob = new Blob([svgString], { type: "image/svg+xml;charset=utf-8" });
      const svgUrl = URL.createObjectURL(blob);

      await new Promise((resolve, reject) => {
        const img = new Image();
        img.onload = () => {
          ctx.drawImage(img, padding, padding, qrSize, qrSize);
          URL.revokeObjectURL(svgUrl);
          resolve();
        };
        img.onerror = () => {
          URL.revokeObjectURL(svgUrl);
          reject(new Error("No se pudo generar la imagen del QR"));
        };
        img.src = svgUrl;
      });

      ctx.fillStyle = "#000000";
      ctx.font = "700 52px sans-serif";
      ctx.textAlign = "center";
      ctx.textBaseline = "middle";
      ctx.fillText(
        `Pedido #${order.code}`,
        canvas.width / 2,
        padding + qrSize + textHeight / 2
      );

      const link = document.createElement("a");
      link.href = canvas.toDataURL("image/png");
      link.download = `pedido-${order.code}-qr.png`;
      document.body.appendChild(link);
      link.click();
      link.remove();
    } catch {
      // Silenciar errores para no romper el flujo del modal
    }
  }

  return (
    <div
      className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4"
      onClick={onClose}
    >
      <div
        className="bg-white rounded-2xl shadow-xl p-6 flex flex-col items-center gap-4 w-full max-w-xs"
        onClick={(e) => e.stopPropagation()}
      >
        <div className="w-full flex items-center justify-end">
          <button
            onClick={onClose}
            className="text-gray-400 hover:text-gray-600 text-xl leading-none"
          >
            ✕
          </button>
        </div>

        {order.customer?.name && (
          <p className="text-sm text-gray-500 -mt-2 self-start">
            {order.customer.name}
          </p>
        )}

        {/* QR Code */}
        <div id="qr-svg-content" className="p-3 border rounded-xl bg-white">
          <QRCode
            value={url}
            size={200}
            bgColor="#ffffff"
            fgColor="#000000"
            level="M"
          />
        </div>

        <h2 className="text-lg font-bold">Pedido #{order.code}</h2>

        <button
          onClick={handlePrint}
          className="w-full bg-blue-700 hover:bg-blue-800 text-white font-semibold py-2.5 rounded-xl text-sm transition-colors"
        >
          Imprimir QR
        </button>

        <button
          onClick={handleDownloadPng}
          className="w-full bg-green-700 hover:bg-green-800 text-white font-semibold py-2.5 rounded-xl text-sm transition-colors"
        >
          Descargar QR (PNG)
        </button>
      </div>
    </div>
  );
}
