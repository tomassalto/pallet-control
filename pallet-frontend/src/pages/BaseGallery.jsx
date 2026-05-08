import { lazy, Suspense, useEffect, useState } from "react";
import { useParams } from "react-router-dom";
import { apiGet, apiPost, apiDelete } from "../api/client";
import { toastSuccess, toastError } from "../ui/toast";
import BackButton from "../ui/BackButton";
import PhotoAnnotator from "../ui/PhotoAnnotator";
import { PageSpinner } from "../ui/Spinner";

const PhotoPreview = lazy(() => import("../features/base-gallery/PhotoPreview.jsx"));

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

  if (loading) return <PageSpinner />;

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
    <div className="space-y-6 pb-8">
      <BackButton to={`/pallet/${palletId}`} />

      {/* Header */}
      <div className="space-y-1">
        <p className="text-xs font-semibold uppercase tracking-wider text-gray-400 dark:text-gray-500">
          {pallet.code}
        </p>
        <h1 className="font-semibold text-2xl md:text-3xl text-gray-900 dark:text-white leading-tight">
          {base.name || `Base #${base.id}`}
        </h1>
        <p className="text-xs font-semibold uppercase tracking-wider text-gray-400 dark:text-gray-500">
          Galería · {photos.length} foto{photos.length !== 1 ? "s" : ""}
        </p>
      </div>

      {/* Grid de fotos */}
      <div className="grid grid-cols-2 sm:grid-cols-3 gap-3">
        {/* Celda para agregar foto */}
        {pallet?.status !== "done" && (
          <label className="relative aspect-square rounded-2xl overflow-hidden border-2 border-dashed border-gray-300 dark:border-gray-600 bg-gray-50 dark:bg-gray-800/40 flex flex-col items-center justify-center gap-2 cursor-pointer hover:bg-gray-100 dark:hover:bg-gray-700/40 hover:border-gray-400 active:scale-[0.98] transition-colors">
            <input
              type="file"
              accept="image/*"
              className="hidden"
              onChange={(e) => onUploadPhoto(e.target.files?.[0])}
              disabled={uploading}
            />
            {uploading ? (
              <>
                <div className="w-8 h-8 rounded-full border-2 border-gray-400 border-r-transparent animate-spin" />
                <span className="text-xs text-gray-400 dark:text-gray-500">Procesando…</span>
              </>
            ) : (
              <>
                <svg className="w-8 h-8 text-gray-400 dark:text-gray-500" fill="none" viewBox="0 0 24 24" strokeWidth={1.5} stroke="currentColor">
                  <path strokeLinecap="round" strokeLinejoin="round" d="M6.827 6.175A2.31 2.31 0 015.186 7.23c-.38.054-.757.112-1.134.175C2.999 7.58 2.25 8.507 2.25 9.574V18a2.25 2.25 0 002.25 2.25h15A2.25 2.25 0 0021.75 18V9.574c0-1.067-.75-1.994-1.802-2.169a47.865 47.865 0 00-1.134-.175 2.31 2.31 0 01-1.64-1.055l-.822-1.316a2.192 2.192 0 00-1.736-1.039 48.774 48.774 0 00-5.232 0 2.192 2.192 0 00-1.736 1.039l-.821 1.316z" />
                  <path strokeLinecap="round" strokeLinejoin="round" d="M16.5 12.75a4.5 4.5 0 11-9 0 4.5 4.5 0 019 0zM18.75 10.5h.008v.008h-.008V10.5z" />
                </svg>
                <span className="text-xs font-semibold text-gray-400 dark:text-gray-500">Agregar foto</span>
              </>
            )}
          </label>
        )}

        {/* Fotos existentes */}
        {photos.map((photo) => {
          const photoUrl = getPhotoUrl(photo);
          return (
            <div key={photo.id} className="relative aspect-square rounded-2xl overflow-hidden bg-gray-100 dark:bg-gray-800 shadow-sm hover:shadow-md hover:-translate-y-0.5 active:scale-[0.98] transition-all duration-150">
              <button onClick={() => handleViewPhoto(photo)} className="w-full h-full">
                <img
                  src={photoUrl}
                  alt={`Foto ${photo.id}`}
                  loading="lazy"
                  className="w-full h-full object-cover"
                  onError={(e) => {
                    e.target.style.display = "none";
                    e.target.nextSibling.style.display = "flex";
                  }}
                />
                <div className="hidden w-full h-full items-center justify-center text-xs text-gray-500">
                  Error cargando
                </div>
                <div className="absolute bottom-0 left-0 right-0 bg-gradient-to-t from-black/70 to-transparent text-white text-[10px] px-2 py-2">
                  {new Date(photo.created_at).toLocaleDateString(undefined, { dateStyle: "short" })}
                </div>
              </button>
            </div>
          );
        })}
      </div>

      {photos.length === 0 && pallet?.status === "done" && (
        <div className="text-center py-10">
          <p className="text-sm text-gray-500 dark:text-gray-400">No hay fotos en esta base.</p>
        </div>
      )}

      {/* Modal foto ampliada */}
      {selectedPhoto && !editingPhoto && (
        <Suspense fallback={null}>
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
        </Suspense>
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
              <PageSpinner />
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
