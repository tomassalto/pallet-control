import { useState, useEffect } from "react";

/**
 * Componente para visualizar fotos con zoom táctil
 * Permite hacer zoom con gestos de pellizco en móviles
 */
export default function PhotoViewer({
  photoUrl,
  photo,
  onClose,
  onDelete,
  showMetadata = true,
}) {
  const [scale, setScale] = useState(1);
  const [position, setPosition] = useState({ x: 0, y: 0 });
  const [isDragging, setIsDragging] = useState(false);
  const [dragStart, setDragStart] = useState({ x: 0, y: 0 });

  // Resetear zoom y posición al cambiar de foto
  useEffect(() => {
    setScale(1);
    setPosition({ x: 0, y: 0 });
  }, [photoUrl]);

  // Manejar gestos táctiles para zoom
  const handleTouchStart = (e) => {
    if (e.touches.length === 2) {
      // Iniciar zoom con dos dedos
      const touch1 = e.touches[0];
      const touch2 = e.touches[1];
      const distance = Math.hypot(
        touch2.clientX - touch1.clientX,
        touch2.clientY - touch1.clientY
      );
      e.target.dataset.initialDistance = distance;
      e.target.dataset.initialScale = scale;
    } else if (e.touches.length === 1 && scale > 1) {
      // Iniciar arrastre si hay zoom
      setIsDragging(true);
      setDragStart({
        x: e.touches[0].clientX - position.x,
        y: e.touches[0].clientY - position.y,
      });
    }
  };

  const handleTouchMove = (e) => {
    if (e.touches.length === 2) {
      // Zoom con dos dedos
      const touch1 = e.touches[0];
      const touch2 = e.touches[1];
      const distance = Math.hypot(
        touch2.clientX - touch1.clientX,
        touch2.clientY - touch1.clientY
      );
      const initialDistance = parseFloat(e.target.dataset.initialDistance || distance);
      const initialScale = parseFloat(e.target.dataset.initialScale || 1);
      const newScale = Math.max(1, Math.min(5, (distance / initialDistance) * initialScale));
      setScale(newScale);
    } else if (e.touches.length === 1 && isDragging && scale > 1) {
      // Arrastrar imagen con zoom
      setPosition({
        x: e.touches[0].clientX - dragStart.x,
        y: e.touches[0].clientY - dragStart.y,
      });
    }
  };

  const handleTouchEnd = () => {
    setIsDragging(false);
  };

  // Manejar zoom con rueda del mouse (desktop)
  const handleWheel = (e) => {
    e.preventDefault();
    const delta = e.deltaY > 0 ? 0.9 : 1.1;
    const newScale = Math.max(1, Math.min(5, scale * delta));
    setScale(newScale);
  };

  // Doble clic para resetear zoom
  const handleDoubleClick = () => {
    if (scale > 1) {
      setScale(1);
      setPosition({ x: 0, y: 0 });
    } else {
      setScale(2);
    }
  };

  return (
    <div
      className="fixed inset-0 z-50 bg-black/90 flex items-center justify-center"
      onClick={onClose}
      style={{ touchAction: "none", width: "100vw", height: "100vh" }}
    >
      <div className="relative w-full h-full flex items-center justify-center" style={{ width: "100%", height: "100%" }}>
        {/* Botones de acción */}
        <div className="absolute top-4 right-4 flex gap-2 z-10">
          {onDelete && (
            <button
              onClick={(e) => {
                e.stopPropagation();
                onDelete();
              }}
              className="px-4 py-2 bg-red-600 hover:bg-red-700 text-white rounded-lg"
            >
              Eliminar
            </button>
          )}
          <button
            onClick={onClose}
            className="px-4 py-2 bg-white/20 hover:bg-white/30 text-white rounded-lg backdrop-blur-sm"
          >
            Cerrar
          </button>
        </div>

        {/* Imagen con zoom */}
        <div
          onClick={(e) => e.stopPropagation()}
          className="relative w-full h-full flex items-center justify-center overflow-hidden"
          onTouchStart={handleTouchStart}
          onTouchMove={handleTouchMove}
          onTouchEnd={handleTouchEnd}
          onWheel={handleWheel}
          style={{ touchAction: "none" }}
        >
          <img
            src={photoUrl}
            alt="Foto ampliada"
            className="max-w-full max-h-full object-contain select-none"
            style={{
              transform: `scale(${scale}) translate(${position.x / scale}px, ${position.y / scale}px)`,
              transition: isDragging ? "none" : "transform 0.1s",
              touchAction: "none",
              maxWidth: "100vw",
              maxHeight: "100vh",
            }}
            onDoubleClick={handleDoubleClick}
            draggable={false}
          />
        </div>

        {/* Metadata */}
        {showMetadata && photo && (
          <div className="absolute bottom-4 left-4 right-4 bg-black/60 text-white text-xs px-3 py-2 rounded-lg backdrop-blur-sm">
            <div>{new Date(photo.created_at).toLocaleString()}</div>
            {photo.note && <div className="mt-1">{photo.note}</div>}
          </div>
        )}

        {/* Indicador de zoom */}
        {scale > 1 && (
          <div className="absolute top-4 left-4 bg-black/60 text-white text-xs px-3 py-2 rounded-lg backdrop-blur-sm">
            Zoom: {Math.round(scale * 100)}%
          </div>
        )}
      </div>
    </div>
  );
}

