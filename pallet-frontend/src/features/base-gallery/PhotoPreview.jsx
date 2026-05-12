/* eslint-disable react-hooks/set-state-in-effect */
import { useEffect, useState } from "react";
import { Stage, Layer, Text, Image as KonvaImage } from "react-konva";
import close_eye from "../../assets/icons/close_eye.svg";
import eye from "../../assets/icons/eye.svg";

export default function PhotoPreview({
  photo,
  photoUrl,
  annotations,
  showAnnotations,
  onClose,
  onEdit,
  onDelete,
  onToggleAnnotations,
}) {
  const [img, setImg] = useState(null);
  const [stageSize, setStageSize] = useState({ width: 800, height: 600 });
  const [scale, setScale] = useState(1);
  const [position, setPosition] = useState({ x: 0, y: 0 });
  const [isDragging, setIsDragging] = useState(false);
  const [dragStart, setDragStart] = useState({ x: 0, y: 0 });
  const [touchStartData, setTouchStartData] = useState(null);

  useEffect(() => {
    const i = new window.Image();
    i.crossOrigin = "anonymous";
    i.src = photoUrl;
    i.onload = () => {
      setImg(i);
      // Calcular tamaño del stage (usar los mismos límites que en PhotoAnnotator)
      const maxWidth = Math.min(window.innerWidth - 32, 800);
      const maxHeight = Math.min(window.innerHeight - 200, 600);

      let width = i.width;
      let height = i.height;

      if (width > maxWidth) {
        const ratio = maxWidth / width;
        width = maxWidth;
        height = height * ratio;
      }
      if (height > maxHeight) {
        const ratio = maxHeight / height;
        height = maxHeight;
        width = width * ratio;
      }

      setStageSize({ width, height });
    };
  }, [photoUrl]);

  // Resetear zoom al cambiar de foto
  useEffect(() => {
    setScale(1);
    setPosition({ x: 0, y: 0 });
  }, [photoUrl]);

  return (
    <div
      className="fixed inset-0 z-50 bg-black/90 p-4 flex items-center justify-center"
      onClick={onClose}
    >
      <div className="relative w-full h-full flex items-center justify-center">
        <div className="absolute top-4 right-12 flex gap-2 z-10">
          {annotations.length > 0 && (
            <button
              onClick={(e) => {
                e.stopPropagation();
                onToggleAnnotations();
              }}
              className="px-2 py-1 bg-white/20 hover:bg-white/30 text-white rounded-lg backdrop-blur-sm"
              aria-label={showAnnotations ? "Ocultar números" : "Ver números"}
            >
              {showAnnotations ? (
                <img
                  src={close_eye}
                  alt="Ocultar números"
                  className="w-5 h-5"
                />
              ) : (
                <img src={eye} alt="Ver números" className="w-5 h-5" />
              )}
            </button>
          )}
          <button
            onClick={(e) => {
              e.stopPropagation();
              onEdit();
            }}
            className="px-2 py-1 bg-blue-600 hover:bg-blue-700 text-white rounded-lg"
          >
            Editar
          </button>
          {onDelete && (
            <button
              onClick={(e) => {
                e.stopPropagation();
                onDelete();
              }}
              className="px-2 py-1 bg-red-600 hover:bg-red-700 text-white rounded-lg"
            >
              Eliminar
            </button>
          )}
          <button
            onClick={onClose}
            className="px-2 py-1 bg-white/20 hover:bg-white/30 text-white rounded-lg backdrop-blur-sm"
          >
            Cerrar
          </button>
        </div>

        <div
          onClick={(e) => e.stopPropagation()}
          onTouchStart={(e) => {
            e.stopPropagation();
            if (e.touches.length === 2) {
              // Zoom con dos dedos
              const touch1 = e.touches[0];
              const touch2 = e.touches[1];
              const distance = Math.hypot(
                touch2.clientX - touch1.clientX,
                touch2.clientY - touch1.clientY
              );
              setTouchStartData({
                distance,
                scale,
                centerX: (touch1.clientX + touch2.clientX) / 2,
                centerY: (touch1.clientY + touch2.clientY) / 2,
              });
            } else if (e.touches.length === 1 && scale > 1) {
              // Arrastre con un dedo si hay zoom
              setIsDragging(true);
              setDragStart({
                x: e.touches[0].clientX - position.x,
                y: e.touches[0].clientY - position.y,
              });
            }
          }}
          onTouchMove={(e) => {
            e.preventDefault();
            e.stopPropagation();
            if (e.touches.length === 2 && touchStartData) {
              // Zoom con dos dedos
              const touch1 = e.touches[0];
              const touch2 = e.touches[1];
              const distance = Math.hypot(
                touch2.clientX - touch1.clientX,
                touch2.clientY - touch1.clientY
              );
              const newScale = Math.max(
                1,
                Math.min(
                  5,
                  (distance / touchStartData.distance) * touchStartData.scale
                )
              );
              setScale(newScale);
            } else if (e.touches.length === 1 && isDragging && scale > 1) {
              // Arrastrar imagen con zoom
              setPosition({
                x: e.touches[0].clientX - dragStart.x,
                y: e.touches[0].clientY - dragStart.y,
              });
            }
          }}
          onTouchEnd={() => {
            setIsDragging(false);
            setTouchStartData(null);
          }}
          onWheel={(e) => {
            e.preventDefault();
            e.stopPropagation();
            const delta = e.deltaY > 0 ? 0.9 : 1.1;
            const newScale = Math.max(1, Math.min(5, scale * delta));
            setScale(newScale);
          }}
          onDoubleClick={() => {
            if (scale > 1) {
              setScale(1);
              setPosition({ x: 0, y: 0 });
            } else {
              setScale(2);
            }
          }}
          style={{ touchAction: "none", overflow: "hidden" }}
          className="relative w-full h-full flex items-center justify-center"
        >
          {img ? (
            <div
              style={{
                transform: `scale(${scale}) translate(${
                  position.x / scale
                }px, ${position.y / scale}px)`,
                transition: isDragging ? "none" : "transform 0.1s",
                transformOrigin: "center center",
                touchAction: "none",
              }}
            >
              <Stage
                width={stageSize.width}
                height={stageSize.height}
                draggable={false}
                listening={true}
              >
                <Layer>
                  <KonvaImage
                    image={img}
                    width={stageSize.width}
                    height={stageSize.height}
                  />
                </Layer>
                {showAnnotations && annotations.length > 0 && img && (
                  <Layer>
                    {annotations.map((ann) => {
                      const scaleX = stageSize.width / img.width;
                      const scaleY = stageSize.height / img.height;
                      const annotationScale = Math.min(scaleX, scaleY);

                      return (
                        <Text
                          key={ann.id}
                          text={ann.text}
                          x={ann.x * scaleX}
                          y={ann.y * scaleY}
                          fontSize={ann.fontSize * annotationScale}
                          fill={ann.color}
                        />
                      );
                    })}
                  </Layer>
                )}
              </Stage>
            </div>
          ) : (
            <img
              src={photoUrl}
              alt="Foto ampliada"
              className="max-w-full max-h-full object-contain"
              style={{
                transform: `scale(${scale}) translate(${
                  position.x / scale
                }px, ${position.y / scale}px)`,
                transition: "transform 0.1s",
              }}
            />
          )}
        </div>

        {/* Indicador de zoom */}
        {scale > 1 && (
          <div className="absolute top-4 left-4 bg-black/60 text-white text-xs px-3 py-2 rounded-lg backdrop-blur-sm z-20">
            Zoom: {Math.round(scale * 100)}%
          </div>
        )}

        <div className="absolute bottom-4 left-4 right-4 bg-black/60 text-white text-xs px-3 py-2 rounded-lg backdrop-blur-sm">
          <div>{new Date(photo.created_at).toLocaleString()}</div>
          {photo.note && <div className="mt-1">{photo.note}</div>}
        </div>
      </div>
    </div>
  );
}
