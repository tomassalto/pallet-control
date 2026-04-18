import { useEffect, useState } from "react";
import { Link, useParams } from "react-router-dom";
import { apiGet, apiPost, apiDelete } from "../api/client";
import { toastSuccess, toastError } from "../ui/toast";
import BackButton from "../ui/BackButton";
import Title from "../ui/Title";
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

  if (loading) return <div className="text-sm text-gray-600">Cargando…</div>;

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
    <div className="space-y-2">
      <div className="flex justify-start">
        <BackButton to={`/pallet/${palletId}`} />
      </div>
      {/* Header */}
      <div className="bg-white p-4 flex flex-col gap-2">
        <Title size="3xl">Galeria de fotos</Title>
        <Title size="1xl">{pallet.code}</Title>
      </div>

      {/* Grid de fotos */}
      <div className="bg-white border rounded-2xl overflow-hidden">
        <div className="px-4 py-3 border-b">
          <div className="font-semibold">Fotos ({photos.length})</div>
        </div>
        <div className="p-3 grid grid-cols-2 gap-3">
          {/* Cuadrado para agregar foto */}
          {pallet?.status !== "done" && (
            <label className="relative aspect-square rounded-xl overflow-hidden border-2 border-dashed border-gray-300 bg-gray-50 flex items-center justify-center cursor-pointer hover:bg-gray-100 hover:border-gray-400 active:scale-[0.98] transition-colors">
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
              <button
                key={photo.id}
                onClick={() => setSelectedPhoto(photo)}
                className="relative aspect-square rounded-xl overflow-hidden border bg-gray-100 active:scale-[0.98]"
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
            );
          })}
        </div>
      </div>

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
