import { useEffect, useState } from "react";
import { Link, useParams } from "react-router-dom";
import { apiGet, apiPost, apiDelete } from "../api/client";
import { toastSuccess, toastError } from "../ui/toast";
import BackButton from "../ui/BackButton";
import PhotoAnnotator from "../ui/PhotoAnnotator";
import { Stage, Layer, Text, Image as KonvaImage } from "react-konva";
import close_eye from "../assets/icons/close_eye.svg";
import eye from "../assets/icons/eye.svg";
import Title from "../ui/Title";
// Componente para preview de foto con anotaciones
function PhotoPreview({
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
                      // Las anotaciones están guardadas con coordenadas relativas a la imagen original
                      // Calcular escala basada en el tamaño real de la imagen vs el stage
                      const scaleX = stageSize.width / img.width;
                      const scaleY = stageSize.height / img.height;
                      const annotationScale = Math.min(scaleX, scaleY);

                      // Convertir coordenadas de la imagen original al tamaño del stage
                      // Usar el mismo cálculo que en el editor para mantener consistencia
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

function getPhotoUrl(photo) {
  // Si el backend devuelve una URL completa, usarla directamente
  if (photo.url) {
    // Si es una URL absoluta (http:// o https://), usarla tal cual
    if (photo.url.startsWith("http://") || photo.url.startsWith("https://")) {
      // Eliminar barras dobles que puedan aparecer
      return photo.url.replace(/([^:]\/)\/+/g, "$1");
    }
    // Si es una ruta relativa, usarla directamente
    return photo.url.startsWith("/") ? photo.url : `/${photo.url}`;
  }
  // Fallback: construir URL desde el path
  const API_BASE = import.meta.env.VITE_API_BASE_URL || "";
  const baseUrl = API_BASE.replace("/api/v1", "").replace(/\/$/, "") || "";
  const storagePath = photo.path.startsWith("/")
    ? photo.path
    : `/${photo.path}`;
  return `${baseUrl}/storage${storagePath}`;
}

export default function BaseGallery() {
  const { palletId, baseId } = useParams();

  const [loading, setLoading] = useState(true);
  const [pallet, setPallet] = useState(null);
  const [base, setBase] = useState(null);
  const [photos, setPhotos] = useState([]);
  const [error, setError] = useState("");
  const [uploading, setUploading] = useState(false);
  const [uploadError, setUploadError] = useState(null);
  const [selectedPhoto, setSelectedPhoto] = useState(null);
  const [editingPhoto, setEditingPhoto] = useState(null);
  const [annotations, setAnnotations] = useState([]);
  const [loadingAnnotations, setLoadingAnnotations] = useState(false);
  const [showAnnotations, setShowAnnotations] = useState(false);
  const [confirmDeletePhoto, setConfirmDeletePhoto] = useState(null);

  async function load() {
    setError("");
    setLoading(true);
    try {
      const data = await apiGet(`/pallets/${palletId}`);
      setPallet(data.pallet || null);

      const foundBase = data.bases?.find((b) => b.id === parseInt(baseId, 10));
      setBase(foundBase || null);
      setPhotos(foundBase?.photos || []);
      console.log("BaseGallery - Base encontrada:", foundBase);
      console.log("BaseGallery - order_items:", foundBase?.order_items);
      console.log("BaseGallery - orderItems:", foundBase?.orderItems);
    } catch (e) {
      setError(e.message || "Error cargando datos");
      toastError(e.message || "Error cargando datos");
    } finally {
      setLoading(false);
    }
  }

  useEffect(() => {
    load();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [palletId, baseId]);

  async function onUploadPhoto(file) {
    if (!file) return;

    setUploading(true);
    setUploadError(null);
    try {
      const form = new FormData();
      form.append("photo", file);

      console.log("Subiendo foto:", {
        fileName: file.name,
        fileSize: file.size,
        fileType: file.type,
        endpoint: `/pallets/${palletId}/bases/${baseId}/photos`,
      });

      await apiPost(`/pallets/${palletId}/bases/${baseId}/photos`, form);

      toastSuccess("Foto agregada");
      await load();
    } catch (e) {
      const errorMessage =
        e.response?.data?.message || e.message || "Error subiendo las fotos";

      // Crear un string detallado con toda la información del error
      const errorDetails = JSON.stringify(
        {
          message: errorMessage,
          status: e.response?.status,
          statusText: e.response?.statusText,
          data: e.response?.data,
          fileName: file.name,
          fileSize: file.size,
          fileType: file.type,
          fullError: e.toString(),
        },
        null,
        2
      );

      console.error("Error subiendo foto:", {
        error: e,
        response: e.response,
        status: e.response?.status,
        data: e.response?.data,
      });

      // Guardar el error completo para mostrarlo en pantalla
      setUploadError(errorDetails);
      toastError(errorMessage);
    } finally {
      setUploading(false);
    }
  }

  async function loadAnnotations(photoId) {
    setLoadingAnnotations(true);
    try {
      const data = await apiGet(
        `/pallets/${palletId}/bases/${baseId}/photos/${photoId}/annotations`
      );
      // Convertir anotaciones del backend al formato esperado
      // Las anotaciones vienen con coordenadas basadas en la imagen original
      const formatted = (data.annotations || []).map((ann) => ({
        id: ann.id || crypto.randomUUID(),
        orderItemId: ann.order_item_id || null,
        text: ann.text,
        x: ann.x, // Coordenadas basadas en imagen original
        y: ann.y, // Coordenadas basadas en imagen original
        fontSize: ann.font_size || 36,
        color: ann.color || "#ffffff",
      }));
      setAnnotations(formatted);
    } catch (e) {
      console.error("Error cargando anotaciones:", e);
      setAnnotations([]);
    } finally {
      setLoadingAnnotations(false);
    }
  }

  async function handleEditPhoto(photo) {
    setEditingPhoto(photo);
    await loadAnnotations(photo.id);
  }

  async function handleViewPhoto(photo) {
    setSelectedPhoto(photo);
    // Cargar anotaciones para el preview
    await loadAnnotations(photo.id);
  }

  async function handleSaveAnnotations(annotationsToSave) {
    if (!editingPhoto) return;

    try {
      await apiPost(
        `/pallets/${palletId}/bases/${baseId}/photos/${editingPhoto.id}/annotations`,
        { annotations: annotationsToSave }
      );
      toastSuccess("Anotaciones guardadas");

      // Recargar anotaciones después de guardar
      await loadAnnotations(editingPhoto.id);

      // Volver al preview en lugar de cerrar completamente
      setEditingPhoto(null);
      // Las anotaciones ya están cargadas en el estado
    } catch (e) {
      toastError(
        e.response?.data?.message || e.message || "Error guardando anotaciones"
      );
    }
  }

  async function handleDeletePhoto(photo) {
    try {
      await apiDelete(
        `/pallets/${palletId}/bases/${baseId}/photos/${photo.id}`
      );
      toastSuccess("Foto eliminada");
      setConfirmDeletePhoto(null);

      // Si la foto eliminada estaba siendo vista o editada, cerrar los modales
      if (selectedPhoto?.id === photo.id) {
        setSelectedPhoto(null);
        setAnnotations([]);
      }
      if (editingPhoto?.id === photo.id) {
        setEditingPhoto(null);
        setAnnotations([]);
      }

      // Recargar la lista de fotos
      await load();
    } catch (e) {
      toastError(
        e.response?.data?.message || e.message || "Error eliminando foto"
      );
    }
  }

  if (loading) return <div className="text-sm text-gray-600">Cargando…</div>;

  if (error || !base) {
    return (
      <div className="space-y-3">
        <BackButton to={`/pallet/${palletId}`} />
        <div className="bg-red-50 border border-red-200 text-red-800 rounded-xl p-3">
          {error || "Base no encontrada"}
        </div>
      </div>
    );
  }

  return (
    <div className="space-y-2">
      <div className="flex justify-start">
        <BackButton to={`/pallet/${palletId}`} />
      </div>

      {/* Header */}
      <div className="bg-white p-4 border border-border rounded-2xl space-y-2">
        <Title size="3xl">{pallet.code}</Title>
        <Title size="2xl">{base.name || `Base #${base.id}`}</Title>
      </div>

      {/* Grid de fotos */}
      <div className="bg-white border border-border rounded-2xl overflow-hidden">
        <div className="p-4">
          <Title size="2xl">Fotos ({photos.length})</Title>
        </div>
        <div className="px-3 pb-4 grid grid-cols-2 gap-3">
          {/* Cuadrado para agregar foto */}
          {pallet?.status !== "done" && (
            <label className="relative aspect-square rounded-xl overflow-hidden border-2 border-dashed border-gray-300 bg-gray-50 flex items-center justify-center cursor-pointer hover:bg-gray-100 hover:border-gray-400 active:scale-[0.98] transition-colors disabled:opacity-50 disabled:cursor-not-allowed">
              <input
                type="file"
                accept="image/*"
                className="hidden"
                onChange={(e) => onUploadPhoto(e.target.files?.[0])}
                disabled={uploading}
              />
              <div className="flex flex-col items-center justify-center text-gray-400">
                {uploading ? (
                  <>
                    <div className="animate-spin rounded-full h-8 w-8 border-b-2 border-gray-600 mb-2"></div>
                    <div className="text-xs text-gray-500">Procesando...</div>
                  </>
                ) : (
                  <svg
                    xmlns="http://www.w3.org/2000/svg"
                    className="h-12 w-12"
                    fill="none"
                    viewBox="0 0 24 24"
                    stroke="currentColor"
                    strokeWidth={2}
                  >
                    <path
                      strokeLinecap="round"
                      strokeLinejoin="round"
                      d="M12 4v16m8-8H4"
                    />
                  </svg>
                )}
              </div>
            </label>
          )}

          {/* Fotos existentes */}
          {photos.map((photo) => {
            const photoUrl = getPhotoUrl(photo);
            return (
              <div
                key={photo.id}
                className="relative aspect-square rounded-xl overflow-hidden border bg-gray-100"
              >
                <button
                  onClick={() => handleViewPhoto(photo)}
                  className="w-full h-full active:scale-[0.98]"
                >
                  <img
                    src={photoUrl}
                    alt={`Foto ${photo.id}`}
                    className="w-full h-full object-cover"
                    onError={(e) => {
                      e.target.style.display = "none";
                      e.target.nextSibling.style.display = "flex";
                    }}
                  />
                  <div className="hidden w-full h-full items-center justify-center text-xs text-gray-500">
                    Error cargando
                  </div>
                  <div className="absolute bottom-0 left-0 right-0 bg-black/60 text-white text-[10px] px-2 py-1">
                    {new Date(photo.created_at).toLocaleString()}
                  </div>
                </button>
              </div>
            );
          })}
        </div>
      </div>

      {/* Modal foto ampliada */}
      {selectedPhoto && !editingPhoto && (
        <PhotoPreview
          photo={selectedPhoto}
          photoUrl={getPhotoUrl(selectedPhoto)}
          annotations={annotations}
          showAnnotations={showAnnotations}
          onClose={() => setSelectedPhoto(null)}
          onEdit={() => handleEditPhoto(selectedPhoto)}
          onDelete={() => setConfirmDeletePhoto(selectedPhoto)}
          onToggleAnnotations={() => setShowAnnotations((prev) => !prev)}
        />
      )}

      {/* Modal editor de anotaciones */}
      {editingPhoto && (
        <div className="fixed inset-0 z-50 bg-white p-4 overflow-y-auto">
          <div className="max-w-4xl mx-auto space-y-4">
            <div className="flex items-center justify-between">
              <h2 className="text-xl font-semibold">Editar anotaciones</h2>
              <button
                onClick={() => {
                  setEditingPhoto(null);
                  setAnnotations([]);
                }}
                className="px-4 py-2 border rounded-lg hover:bg-gray-50"
              >
                Cerrar
              </button>
            </div>
            {loadingAnnotations ? (
              <div className="text-center py-8">Cargando anotaciones...</div>
            ) : (
              <PhotoAnnotator
                photoUrl={getPhotoUrl(editingPhoto)}
                initial={annotations}
                onSave={handleSaveAnnotations}
                onClose={() => {
                  setEditingPhoto(null);
                  setAnnotations([]);
                }}
                orderItems={base?.order_items || base?.orderItems || []}
              />
            )}
          </div>
        </div>
      )}

      {/* Modal confirmar eliminar foto */}
      {confirmDeletePhoto && (
        <>
          <div
            className="fixed inset-0 bg-black/50 z-40"
            onClick={() => setConfirmDeletePhoto(null)}
          />
          <div className="fixed inset-0 z-50 flex items-center justify-center p-4">
            <div
              className="bg-white rounded-2xl p-6 w-full max-w-md space-y-4"
              onClick={(e) => e.stopPropagation()}
            >
              <div className="space-y-2">
                <div className="font-semibold text-lg">¿Eliminar foto?</div>
                <div className="text-sm text-gray-700">
                  Estás por eliminar esta foto. Esta acción no se puede
                  deshacer.
                </div>
              </div>

              <div className="bg-red-50 border border-red-200 rounded-lg p-4">
                <div className="text-sm text-red-800">
                  <div className="font-semibold mb-1">ADVERTENCIA:</div>
                  <div>
                    Se eliminarán todas las anotaciones asociadas a esta foto.
                  </div>
                </div>
              </div>

              <div className="flex gap-2">
                <button
                  onClick={() => handleDeletePhoto(confirmDeletePhoto)}
                  className="flex-1 rounded-lg py-3 bg-red-600 text-white"
                >
                  Eliminar
                </button>
                <button
                  onClick={() => setConfirmDeletePhoto(null)}
                  className="flex-1 rounded-lg py-3 border bg-white text-gray-700 hover:bg-gray-50"
                >
                  Cancelar
                </button>
              </div>
            </div>
          </div>
        </>
      )}

      {/* Modal de error de subida - temporal para debugging */}
      {uploadError && (
        <div className="fixed inset-0 z-[60] bg-black/80 flex items-center justify-center p-4">
          <div className="bg-white rounded-xl p-6 max-w-2xl w-full max-h-[90vh] overflow-y-auto">
            <div className="flex items-center justify-between mb-4">
              <h3 className="text-lg font-semibold text-red-600">
                Error al subir foto
              </h3>
              <button
                onClick={() => setUploadError(null)}
                className="px-3 py-1 bg-gray-200 hover:bg-gray-300 rounded-lg text-sm"
              >
                Cerrar
              </button>
            </div>
            <div className="bg-gray-50 border rounded-lg p-4">
              <pre className="text-xs text-gray-800 whitespace-pre-wrap break-words font-mono">
                {uploadError}
              </pre>
            </div>
            <div className="mt-4 text-sm text-gray-600">
              <p>
                Este mensaje es temporal para debugging. Se eliminará después.
              </p>
            </div>
          </div>
        </div>
      )}
    </div>
  );
}
