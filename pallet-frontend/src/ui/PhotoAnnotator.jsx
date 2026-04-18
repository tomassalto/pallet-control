import { useEffect, useState, useMemo } from "react";
import { Stage, Layer, Text, Image as KonvaImage } from "react-konva";

function useImage(url) {
  const [img, setImg] = useState(null);
  useEffect(() => {
    const i = new window.Image();
    i.crossOrigin = "anonymous";
    i.src = url;
    i.onload = () => setImg(i);
  }, [url]);
  return img;
}

// Colores estilo Age of Empires para productos
const PRODUCT_COLORS = [
  "#FF0000", // Rojo
  "#00FF00", // Verde
  "#0000FF", // Azul
  "#FFFF00", // Amarillo
  "#FF00FF", // Magenta
  "#00FFFF", // Cyan
  "#FFA500", // Naranja
  "#800080", // Púrpura
  "#FFC0CB", // Rosa
  "#A52A2A", // Marrón
  "#808080", // Gris
  "#000080", // Azul marino
];

export default function PhotoAnnotator({
  photoUrl,
  initial = [],
  onSave,
  onClose,
  orderItems = [], // Productos asociados a la base
}) {
  const img = useImage(photoUrl);
  const [selectedProductId, setSelectedProductId] = useState(null);
  const [localItems, setLocalItems] = useState([]);

  // Calcular tamaño del stage basado en la imagen
  const stageSize = useMemo(() => {
    if (!img) return { width: 360, height: 480 };

    const maxWidth = Math.min(window.innerWidth - 32, 800);
    const maxHeight = Math.min(window.innerHeight - 200, 600);

    let width = img.width;
    let height = img.height;

    // Escalar si es necesario
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

    return { width, height };
  }, [img]);

  // Convertir coordenadas de imagen original a coordenadas del stage
  const convertedInitial = useMemo(() => {
    if (!img || initial.length === 0) return initial;

    const scaleX = stageSize.width / img.width;
    const scaleY = stageSize.height / img.height;
    const scale = Math.min(scaleX, scaleY);

    return initial.map((ann) => ({
      ...ann,
      x: ann.x * scaleX,
      y: ann.y * scaleY,
      fontSize: ann.fontSize * scale,
    }));
  }, [img, initial, stageSize]);

  // Sincronizar items locales con initial convertido
  useEffect(() => {
    setLocalItems(convertedInitial);
  }, [convertedInitial]);

  // Usar items locales para edición
  const items = localItems;

  // Asignar colores a productos
  const productsWithColors = useMemo(() => {
    console.log("PhotoAnnotator - orderItems recibidos:", orderItems);
    if (!orderItems || orderItems.length === 0) {
      return [];
    }
    return orderItems.map((item, index) => ({
      ...item,
      color: PRODUCT_COLORS[index % PRODUCT_COLORS.length],
    }));
  }, [orderItems]);

  // Obtener el siguiente número para el producto seleccionado
  function getNextNumberForProduct(productId) {
    if (!productId) return String(items.length + 1);

    const productItems = items.filter((it) => it.orderItemId === productId);
    return String(productItems.length + 1);
  }

  // Obtener el color del producto seleccionado
  function getColorForProduct(productId) {
    if (!productId) return "#ffffff";
    const product = productsWithColors.find((p) => p.id === productId);
    return product?.color || "#ffffff";
  }

  function addNumber() {
    if (!selectedProductId && orderItems.length > 0) {
      // Si hay productos pero no se seleccionó ninguno, seleccionar el primero
      setSelectedProductId(orderItems[0].id);
      return;
    }

    const nextNumber = getNextNumberForProduct(selectedProductId);
    const color = getColorForProduct(selectedProductId);

    // Calcular fontSize inicial basado en el tamaño del stage, pero limitado
    // El fontSize se escalará cuando se guarde, así que calculamos uno razonable
    const initialFontSize = Math.max(24, Math.min(100, Math.floor(stageSize.width / 15)));
    
    setLocalItems([
      ...items,
      {
        id: crypto.randomUUID(),
        orderItemId: selectedProductId,
        text: nextNumber,
        x: 40,
        y: 40,
        fontSize: initialFontSize,
        color: color,
      },
    ]);
  }

  function updatePos(id, x, y) {
    setLocalItems((prev) =>
      prev.map((it) => (it.id === id ? { ...it, x, y } : it))
    );
  }

  function deleteItem(id) {
    setLocalItems((prev) => prev.filter((it) => it.id !== id));
  }

  async function handleSave() {
    // Convertir items a formato para guardar (sin id, solo datos)
    // Convertir coordenadas del stage a coordenadas de la imagen original
    const annotationsToSave = items.map((item) => {
      if (!img) {
        // Si no hay imagen, guardar tal cual pero limitar fontSize
        return {
          text: item.text,
          x: item.x,
          y: item.y,
          fontSize: Math.max(10, Math.min(200, Math.round(item.fontSize))),
          color: item.color,
          orderItemId: item.orderItemId || null,
        };
      }

      // Calcular escala inversa (de stage a imagen original)
      const scaleX = img.width / stageSize.width;
      const scaleY = img.height / stageSize.height;
      const scale =
        Math.min(img.width, img.height) /
        Math.min(stageSize.width, stageSize.height);

      // Convertir coordenadas del stage a coordenadas de la imagen original
      // Calcular fontSize escalado pero limitado a 200 (máximo permitido por backend)
      const scaledFontSize = Math.round(item.fontSize * scale);
      const clampedFontSize = Math.max(10, Math.min(200, scaledFontSize));
      
      return {
        text: item.text,
        x: Math.round(item.x * scaleX * 100) / 100, // Redondear a 2 decimales
        y: Math.round(item.y * scaleY * 100) / 100, // Redondear a 2 decimales
        fontSize: clampedFontSize, // Limitar entre 10 y 200
        color: item.color,
        orderItemId: item.orderItemId || null,
      };
    });
    await onSave(annotationsToSave);
  }

  return (
    <div className="space-y-3">
      {/* Selector de producto */}
      {orderItems.length > 0 && (
        <div className="bg-white border rounded-xl p-3">
          <label className="block text-sm font-medium mb-2">Producto:</label>
          <select
            value={selectedProductId || ""}
            onChange={(e) =>
              setSelectedProductId(
                e.target.value ? parseInt(e.target.value, 10) : null
              )
            }
            className="w-full px-3 py-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500"
          >
            <option value="">Sin producto</option>
            {productsWithColors.map((item) => (
              <option key={item.id} value={item.id}>
                {item.description || `Producto ${item.id}`}
                {item.ean && ` (EAN: ${item.ean})`}
              </option>
            ))}
          </select>
          {selectedProductId && (
            <div className="mt-2 flex items-center gap-2">
              <div
                className="w-6 h-6 rounded border"
                style={{
                  backgroundColor: getColorForProduct(selectedProductId),
                }}
              />
              <span className="text-xs text-gray-600">
                Color asignado a este producto
              </span>
            </div>
          )}
        </div>
      )}

      <div className="flex gap-2 flex-wrap">
        <button
          className="px-3 py-2 rounded-lg border hover:bg-gray-50"
          onClick={addNumber}
          disabled={orderItems.length > 0 && !selectedProductId}
        >
          + Número
        </button>

        <button
          className="px-3 py-2 rounded-lg border bg-green-600 text-white hover:bg-green-700"
          onClick={handleSave}
        >
          Guardar
        </button>

        {onClose && (
          <button
            className="px-3 py-2 rounded-lg border hover:bg-gray-50"
            onClick={onClose}
          >
            Cerrar
          </button>
        )}
      </div>

      <div className="rounded-xl overflow-hidden bg-black flex justify-center">
        <Stage width={stageSize.width} height={stageSize.height}>
          <Layer>
            {img && (
              <KonvaImage
                image={img}
                width={stageSize.width}
                height={stageSize.height}
              />
            )}
          </Layer>

          <Layer>
            {items.map((it) => (
              <Text
                key={it.id}
                text={it.text}
                x={it.x}
                y={it.y}
                fontSize={it.fontSize}
                fill={it.color}
                draggable
                onDragEnd={(e) => updatePos(it.id, e.target.x(), e.target.y())}
                onClick={(e) => {
                  if (
                    e.evt.button === 2 ||
                    (e.evt.ctrlKey && e.evt.button === 0)
                  ) {
                    deleteItem(it.id);
                  }
                }}
                onContextMenu={(e) => {
                  e.evt.preventDefault();
                  deleteItem(it.id);
                }}
              />
            ))}
          </Layer>
        </Stage>
      </div>
      <p className="text-xs text-gray-500 text-center">
        Arrastrá los números para moverlos. Click derecho o Ctrl+Click para
        eliminar.
      </p>
    </div>
  );
}
