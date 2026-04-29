import { useEffect, useState } from "react";
import { Link, useParams } from "react-router-dom";
import { apiGet, apiPost, apiDelete } from "../api/client";
import { toastSuccess, toastError } from "../ui/toast";
import BackButton from "../ui/BackButton";
import { PageSpinner } from "../ui/Spinner";
import PhotoViewer from "../ui/PhotoViewer";

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

export default function PalletGallery() {
  const { palletId } = useParams();

  const [loading, setLoading] = useState(true);
  const [pallet, setPallet] = useState(null);
  const [photos, setPhotos] = useState([]);
  const [error, setError] = useState("");
  const [uploading, setUploading] = useState(false);
  const [selectedPhoto, setSelectedPhoto] = useState(null);

  async function load() {
    setError("");
    setLoading(true);
    try {
      const data = await apiGet(`/pallets/${palletId}`);
      setPallet(data.pallet || null);
      setPhotos(data.photos || []);
    } catch (e) {
      setError(e.message || "Error cargando pallet");
      toastError(e.message || "Error cargando pallet");
    } finally {
      setLoading(false);
    }
  }

  useEffect(() => {
    load();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [palletId]);

  async function onUploadPhoto(file) {
    if (!file) return;

    setUploading(true);
    try {
      const form = new FormData();
      form.append("photo", file);

      console.log("Subiendo foto:", {
        fileName: file.name,
        fileSize: file.size,
        fileType: file.type,
        endpoint: `/pallets/${palletId}/photos`,
      });

      await apiPost(`/pallets/${palletId}/photos`, form);

      toastSuccess("Foto subida");
      await load();
    } catch (e) {
      console.error("Error subiendo foto:", {
        error: e,
        response: e.response,
        status: e.response?.status,
        data: e.response?.data,
      });
      toastError(
        e.response?.data?.message || e.message || "No se pudo subir foto"
      );
    } finally {
      setUploading(false);
    }
  }

  async function handleDeletePhoto(photo) {
    if (!window.confirm("¿Estás seguro de que querés eliminar esta foto?")) {
      return;
    }

    try {
      await apiDelete(`/pallets/${palletId}/photos/${photo.id}`);
      toastSuccess("Foto eliminada");

      // Si la foto eliminada estaba siendo vista, cerrar el modal
      if (selectedPhoto?.id === photo.id) {
        setSelectedPhoto(null);
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

  if (error) {
    return (
      <div className="space-y-3">
        <div className="flex justify-start">
          <BackButton to={`/pallet/${palletId}`} />
        </div>
        <div className="bg-red-50 border border-red-200 text-red-800 rounded-xl p-3">
          {error}
        </div>
      </div>
    );
  }

  return (
    <div className="space-y-6 pb-8">
      <BackButton to={`/pallet/${palletId}`} />

      {/* Header */}
      <div className="space-y-1">
        <h1 className="font-mono font-bold text-2xl md:text-3xl text-gray-900 dark:text-white leading-tight">
          {pallet.code}
        </h1>
        <p className="text-xs font-semibold uppercase tracking-wider text-gray-400 dark:text-gray-500">
          Galería de fotos · {photos.length} foto{photos.length !== 1 ? "s" : ""}
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
            <button
              key={photo.id}
              onClick={() => setSelectedPhoto(photo)}
              className="relative aspect-square rounded-2xl overflow-hidden bg-gray-100 dark:bg-gray-800 shadow-sm hover:shadow-md hover:-translate-y-0.5 active:scale-[0.98] transition-all duration-150"
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
              <div className="absolute bottom-0 left-0 right-0 bg-gradient-to-t from-black/70 to-transparent text-white text-[10px] px-2 py-2">
                {new Date(photo.created_at).toLocaleDateString(undefined, { dateStyle: "short" })}
              </div>
            </button>
          );
        })}
      </div>

      {photos.length === 0 && pallet?.status === "done" && (
        <div className="text-center py-10">
          <p className="text-sm text-gray-500 dark:text-gray-400">No hay fotos en este pallet.</p>
        </div>
      )}

      {/* Modal foto ampliada */}
      {selectedPhoto && (
        <PhotoViewer
          photoUrl={getPhotoUrl(selectedPhoto)}
          photo={selectedPhoto}
          onClose={() => setSelectedPhoto(null)}
          onDelete={() => {
            handleDeletePhoto(selectedPhoto);
            setSelectedPhoto(null);
          }}
        />
      )}
    </div>
  );
}
