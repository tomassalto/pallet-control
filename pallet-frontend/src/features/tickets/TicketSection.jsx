/**
 * Ticket-related components extracted from OrderDetail.jsx
 *
 * Exports:
 *  - TicketCard      — shows a single ticket with photos, OCR, delete
 *  - AddTicketModal  — two-step modal: create ticket → upload + scan photos
 */

import { useEffect, useRef, useState } from "react";
import { apiPost, apiDelete } from "../../api/client";
import { usePhotoOcr } from "../../hooks/usePhotoOcr";
import { toastSuccess, toastError } from "../../ui/toast";
import PhotoViewer from "../../ui/PhotoViewer";
import HighlightOverlay from "./HighlightOverlay";

// ── OCR Badge ────────────────────────────────────────────────────────────────

function OcrBadge({ photo }) {
  if (photo.ocr_processed_at) {
    return (
      <span className="inline-flex items-center bg-green-100 dark:bg-green-900/30 text-green-700 dark:text-green-400 text-[10px] font-semibold px-1.5 py-0.5 rounded-full">
        ✓ Escaneado
      </span>
    );
  }
  if (photo.ocr_log) {
    return (
      <span className="inline-flex items-center gap-1 bg-amber-100 dark:bg-amber-900/30 text-amber-700 dark:text-amber-400 text-[10px] font-semibold px-1.5 py-0.5 rounded-full">
        <span className="animate-pulse">●</span> Procesando…
      </span>
    );
  }
  return (
    <span className="inline-flex items-center bg-gray-100 dark:bg-gray-700 text-gray-400 dark:text-gray-500 text-[10px] font-semibold px-1.5 py-0.5 rounded-full">
      Sin escanear
    </span>
  );
}

// ── OCR Terminal ─────────────────────────────────────────────────────────────

function OcrTerminal({ log, done, eansCount, photoId }) {
  const logEndRef = useRef(null);
  useEffect(() => {
    logEndRef.current?.scrollIntoView({ behavior: "smooth" });
  }, [log]);

  if (!log) return null;
  return (
    <div>
      <div className="flex items-center justify-between mb-1">
        <span className="text-xs font-mono font-semibold text-gray-500 uppercase tracking-wider">
          OCR Log{photoId ? ` (foto #${photoId})` : ""}
        </span>
        {!done ? (
          <span className="flex items-center gap-1 text-xs text-amber-600">
            <span className="animate-pulse">●</span> procesando…
          </span>
        ) : eansCount !== null ? (
          <span
            className={`text-xs font-semibold ${eansCount > 0 ? "text-green-600" : "text-red-500"}`}
          >
            {eansCount > 0
              ? `✓ ${eansCount} EAN(s) encontrado(s)`
              : "✗ Sin coincidencias"}
          </span>
        ) : null}
      </div>
      <div className="bg-gray-950 rounded-lg p-3 h-48 overflow-y-auto font-mono text-xs leading-relaxed">
        {log.split("\n").map((line, i) => {
          const isError = line.includes("[ERROR]") || line.includes("ERROR:");
          const isOk =
            line.includes("→ EAN:") ||
            line.includes("OK") ||
            line.includes("completado");
          const isWarn =
            line.includes("WARN") ||
            line.includes("fallback") ||
            line.includes("falló");
          return (
            <div
              key={i}
              className={
                isError
                  ? "text-red-400"
                  : isOk
                    ? "text-green-400"
                    : isWarn
                      ? "text-yellow-400"
                      : "text-gray-300"
              }
            >
              {line || " "}
            </div>
          );
        })}
        <div ref={logEndRef} />
      </div>
    </div>
  );
}

// ── TicketCard ────────────────────────────────────────────────────────────────

export function TicketCard({ ticket, orderId, highlightsReady, onUpdate }) {
  const [expanded, setExpanded] = useState(false);
  const [uploading, setUploading] = useState(false);
  const [deleting, setDeleting] = useState(false);
  const [selectedPhoto, setSelectedPhoto] = useState(null);
  const [confirmDeleteTicket, setConfirmDeleteTicket] = useState(false);

  // Highlight overlay state: null | { photo }
  const [highlightPhoto, setHighlightPhoto] = useState(null);

  // Modal de escaneo (estado gestionado por hook)
  const { scanState: scanModal, openScan: openScanById, confirmScan, closeScan: closeScanModal } = usePhotoOcr({
    orderId,
    ticketId: ticket.id,
    onDone:   onUpdate,
  });

  function getPhotoUrl(photo) {
    if (photo.url) {
      if (photo.url.startsWith("http://") || photo.url.startsWith("https://")) {
        return photo.url.replace(/([^:]\/)\/+/g, "$1");
      }
      return photo.url.startsWith("/") ? photo.url : `/${photo.url}`;
    }
    const API_BASE = import.meta.env.VITE_API_BASE_URL || "";
    const baseUrl = API_BASE.replace("/api/v1", "").replace(/\/$/, "") || "";
    const storagePath = photo.path.startsWith("/")
      ? photo.path
      : `/${photo.path}`;
    return `${baseUrl}/storage${storagePath}`;
  }

  async function handleUploadPhoto(e) {
    const file = e.target.files?.[0];
    if (!file) return;

    setUploading(true);
    try {
      const form = new FormData();
      form.append("photo", file);

      await apiPost(`/orders/${orderId}/tickets/${ticket.id}/photos`, form);

      toastSuccess("Foto agregada");
      onUpdate();
    } catch (e) {
      toastError(
        e.response?.data?.message || e.message || "Error subiendo foto",
      );
    } finally {
      setUploading(false);
      e.target.value = "";
    }
  }

  async function handleDeletePhoto(photoId) {
    if (!window.confirm("¿Eliminar esta foto?")) return;
    try {
      await apiDelete(
        `/orders/${orderId}/tickets/${ticket.id}/photos/${photoId}`,
      );
      toastSuccess("Foto eliminada");
      onUpdate();
    } catch (e) {
      toastError(
        e.response?.data?.message || e.message || "Error eliminando foto",
      );
    }
  }

  async function handleDeleteTicket() {
    setDeleting(true);
    try {
      await apiDelete(`/orders/${orderId}/tickets/${ticket.id}`);
      toastSuccess("Ticket eliminado");
      setConfirmDeleteTicket(false);
      onUpdate();
    } catch (e) {
      toastError(
        e.response?.data?.message || e.message || "Error eliminando ticket",
      );
    } finally {
      setDeleting(false);
    }
  }

  function openScanModal(photo) {
    openScanById(photo.id);
  }

  const totalPhotos = ticket.photos?.length ?? 0;
  const scannedCount =
    ticket.photos?.filter((p) => p.ocr_processed_at).length ?? 0;

  return (
    <div className="relative bg-white dark:bg-gray-800/60 border border-gray-200 dark:border-gray-700/50 rounded-2xl overflow-hidden shadow-sm">
      {/* Acento izquierdo */}
      <div className="absolute left-0 top-0 bottom-0 w-1 bg-indigo-500" />

      <div className="pl-5 pr-4 py-3.5 space-y-0">
        {/* ── Header ─────────────────────────────────────────────── */}
        <div className="flex items-start gap-2">
          <div className="flex-1 min-w-0">
            <p className="font-mono font-bold text-gray-900 dark:text-white leading-tight">
              {ticket.code || "Sin código"}
            </p>
            {ticket.note && (
              <p className="text-xs text-gray-500 dark:text-gray-400 mt-0.5">
                {ticket.note}
              </p>
            )}
            {totalPhotos > 0 && (
              <p className="text-[11px] text-gray-400 dark:text-gray-500 mt-0.5">
                {scannedCount}/{totalPhotos} foto{totalPhotos !== 1 ? "s" : ""}{" "}
                escaneada{totalPhotos !== 1 ? "s" : ""}
              </p>
            )}
          </div>

          <div className="flex items-center gap-1.5 shrink-0">
            {/* Contador de fotos */}
            {totalPhotos > 0 && (
              <span className="text-xs px-3 py-1.5 bg-indigo-50 dark:bg-indigo-900/20 text-indigo-700 dark:text-indigo-300 px-2 py-0.5 rounded-full font-semibold">
                {totalPhotos} foto{totalPhotos !== 1 ? "s" : ""}
              </span>
            )}
            {/* Toggle ver fotos */}
            <button
              onClick={() => setExpanded(!expanded)}
              className="text-xs px-3 py-1.5 rounded-lg bg-gray-100 dark:bg-gray-700/60 text-gray-700 dark:text-gray-300 font-semibold hover:bg-gray-200 dark:hover:bg-gray-700 transition-colors"
            >
              {expanded ? "Ocultar" : "Ver fotos"}
            </button>
            {/* Eliminar */}
            <button
              onClick={() => setConfirmDeleteTicket(true)}
              className="text-xs px-3 py-1.5 rounded-lg border border-red-200 dark:border-red-800/50 text-red-600 dark:text-red-400 font-semibold hover:bg-red-50 dark:hover:bg-red-900/20 transition-colors"
            >
              Eliminar
            </button>
          </div>
        </div>

        {/* ── Grilla de fotos ─────────────────────────────────────── */}
        {expanded && (
          <div className="mt-3 grid grid-cols-2 sm:grid-cols-3 gap-2">
            {ticket.photos?.map((photo) => {
              const photoUrl = getPhotoUrl(photo);
              const isUnscanned = !photo.ocr_processed_at && !photo.ocr_log;
              return (
                <div
                  key={photo.id}
                  className="flex flex-col rounded-xl overflow-hidden border border-gray-200 dark:border-gray-600 bg-gray-100 dark:bg-gray-700 shadow-sm hover:shadow-md hover:-translate-y-0.5 transition-all duration-150"
                >
                  <div className="relative aspect-square">
                    <button
                      onClick={() => setSelectedPhoto(photo)}
                      className="w-full h-full"
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
                      <div className="hidden w-full h-full items-center justify-center text-xs text-gray-500 dark:text-gray-400">
                        Error cargando
                      </div>
                    </button>
                    <button
                      onClick={(e) => {
                        e.stopPropagation();
                        handleDeletePhoto(photo.id);
                      }}
                      className="absolute top-1.5 right-1.5 bg-black/50 hover:bg-red-600 text-white rounded-full w-6 h-6 flex items-center justify-center text-xs z-10 transition-colors"
                    >
                      ✕
                    </button>
                    <div className="absolute bottom-0 left-0 right-0 bg-gradient-to-t from-black/60 to-transparent text-white text-[10px] px-2 py-1.5">
                      {new Date(photo.created_at).toLocaleDateString(
                        undefined,
                        { dateStyle: "short" },
                      )}
                    </div>
                  </div>
                  {/* Franja OCR */}
                  <div className="px-2 py-1.5 bg-white dark:bg-gray-800 flex items-center justify-between gap-1 border-t border-gray-100 dark:border-gray-700">
                    <OcrBadge photo={photo} />
                    <div className="flex items-center gap-1">
                      {/* Botón "Ver mapa" — visible solo cuando highlightsReady Y foto escaneada */}
                      {highlightsReady && photo.ocr_processed_at && (
                        <button
                          onClick={(e) => {
                            e.stopPropagation();
                            setHighlightPhoto(photo);
                          }}
                          className="shrink-0 text-[10px] px-2 py-0.5 rounded-md bg-violet-600 text-white font-semibold hover:bg-violet-700 transition-colors"
                        >
                          🗺 Mapa
                        </button>
                      )}
                      {isUnscanned && (
                        <button
                          onClick={() => openScanModal(photo)}
                          className="shrink-0 text-[10px] px-2 py-0.5 rounded-md bg-indigo-600 text-white font-semibold hover:bg-indigo-700 transition-colors"
                        >
                          Escanear
                        </button>
                      )}
                    </div>
                  </div>
                </div>
              );
            })}

            {/* Celda para subir foto */}
            <label className="rounded-xl border-2 border-dashed border-gray-300 dark:border-gray-600 bg-gray-50 dark:bg-gray-800/40 flex flex-col items-center justify-center gap-1.5 cursor-pointer hover:bg-gray-100 dark:hover:bg-gray-700/40 hover:border-gray-400 active:scale-[0.98] transition-colors min-h-[110px]">
              <input
                type="file"
                accept="image/*"
                className="hidden"
                onChange={handleUploadPhoto}
                disabled={uploading}
              />
              {uploading ? (
                <>
                  <div className="w-6 h-6 rounded-full border-2 border-gray-400 border-r-transparent animate-spin" />
                  <span className="text-[10px] text-gray-400 dark:text-gray-500">
                    Subiendo…
                  </span>
                </>
              ) : (
                <>
                  <svg
                    className="w-7 h-7 text-gray-400 dark:text-gray-500"
                    fill="none"
                    viewBox="0 0 24 24"
                    strokeWidth={1.5}
                    stroke="currentColor"
                  >
                    <path
                      strokeLinecap="round"
                      strokeLinejoin="round"
                      d="M6.827 6.175A2.31 2.31 0 0 1 5.186 7.23c-.38.054-.757.112-1.134.175C2.999 7.58 2.25 8.507 2.25 9.574V18a2.25 2.25 0 0 0 2.25 2.25h15A2.25 2.25 0 0 0 21.75 18V9.574c0-1.067-.75-1.994-1.802-2.169a47.865 47.865 0 0 0-1.134-.175 2.31 2.31 0 0 1-1.64-1.055l-.822-1.316a2.192 2.192 0 0 0-1.736-1.039 48.774 48.774 0 0 0-5.232 0 2.192 2.192 0 0 0-1.736 1.039l-.821 1.316z"
                    />
                    <path
                      strokeLinecap="round"
                      strokeLinejoin="round"
                      d="M16.5 12.75a4.5 4.5 0 1 1-9 0 4.5 4.5 0 0 1 9 0zM18.75 10.5h.008v.008h-.008V10.5z"
                    />
                  </svg>
                  <span className="text-[10px] font-semibold text-gray-400 dark:text-gray-500">
                    Agregar foto
                  </span>
                </>
              )}
            </label>
          </div>
        )}
      </div>

      {/* Modal foto ampliada */}
      {selectedPhoto && (
        <PhotoViewer
          photoUrl={getPhotoUrl(selectedPhoto)}
          photo={selectedPhoto}
          onClose={() => setSelectedPhoto(null)}
          onDelete={() => {
            handleDeletePhoto(selectedPhoto.id);
            setSelectedPhoto(null);
          }}
        />
      )}

      {/* Modal confirmar eliminar ticket */}
      {confirmDeleteTicket && (
        <>
          <div
            className="fixed inset-0 bg-black/50 z-40"
            onClick={() => setConfirmDeleteTicket(false)}
          />
          <div className="fixed inset-0 z-50 flex items-center justify-center p-4">
            <div
              className="bg-white dark:bg-gray-800 rounded-2xl p-6 w-full max-w-md space-y-4 shadow-2xl"
              onClick={(e) => e.stopPropagation()}
            >
              <div className="space-y-1.5">
                <p className="font-bold text-lg text-gray-900 dark:text-white">
                  ¿Eliminar ticket?
                </p>
                <p className="text-sm text-gray-600 dark:text-gray-400">
                  Ticket{" "}
                  <span className="font-semibold text-gray-900 dark:text-white">
                    {ticket.code || "Sin código"}
                  </span>{" "}
                  y todas sus fotos serán eliminados permanentemente.
                </p>
              </div>

              <div className="bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800/50 rounded-xl p-3.5">
                <p className="text-sm text-red-800 dark:text-red-300">
                  <span className="font-semibold">Atención:</span> esta acción
                  no se puede deshacer.
                </p>
              </div>

              <div className="flex gap-2">
                <button
                  onClick={() => setConfirmDeleteTicket(false)}
                  className="flex-1 rounded-xl py-3 border border-gray-200 dark:border-gray-600 bg-white dark:bg-transparent text-gray-700 dark:text-gray-300 text-sm font-semibold hover:bg-gray-50 dark:hover:bg-gray-700 transition-colors"
                >
                  Cancelar
                </button>
                <button
                  onClick={handleDeleteTicket}
                  disabled={deleting}
                  className="flex-1 rounded-xl py-3 bg-red-600 hover:bg-red-700 text-white text-sm font-semibold disabled:opacity-60 transition-colors"
                >
                  {deleting ? "Eliminando…" : "Eliminar"}
                </button>
              </div>
            </div>
          </div>
        </>
      )}

      {/* ── Mapa de distribución (HighlightOverlay) ─────────────── */}
      {highlightPhoto && (
        <HighlightOverlay
          photoUrl={getPhotoUrl(highlightPhoto)}
          highlightsUrl={`/orders/${orderId}/tickets/${ticket.id}/photos/${highlightPhoto.id}/highlights`}
          onClose={() => setHighlightPhoto(null)}
        />
      )}

      {/* ── Modal escanear foto con OCR ─────────────────────────── */}
      {scanModal && (
        <div className="fixed inset-0 z-50 bg-black/80 flex items-center justify-center p-4">
          <div
            className="bg-white dark:bg-gray-900 rounded-2xl p-6 w-full max-w-md space-y-4 shadow-2xl"
            onClick={(e) => e.stopPropagation()}
          >
            <div className="flex items-center justify-between">
              <h3 className="font-bold text-lg text-gray-900 dark:text-white">
                Escanear ticket con OCR
              </h3>
              {(scanModal.done || scanModal.step === "confirm") && (
                <button
                  onClick={closeScanModal}
                  className="text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200 text-xl"
                >
                  ✕
                </button>
              )}
            </div>

            {scanModal.step === "confirm" ? (
              <>
                <div className="bg-amber-50 dark:bg-amber-900/20 border border-amber-200 dark:border-amber-700/50 rounded-xl p-4 space-y-1.5">
                  <p className="text-sm font-semibold text-amber-800 dark:text-amber-300">
                    ⚠ Esto consume un crédito de Azure Computer Vision
                  </p>
                  <p className="text-xs text-amber-700 dark:text-amber-400 leading-relaxed">
                    Cada escaneo realiza una llamada a la API de Azure (plan
                    gratuito: 5.000/mes). Asegurate de que el pallet esté
                    organizado antes de escanear. Una vez escaneada, una foto no
                    puede volver a escanearse.
                  </p>
                </div>
                <div className="flex gap-2 pt-1">
                  <button
                    onClick={closeScanModal}
                    className="flex-1 rounded-xl py-3 border border-gray-200 dark:border-gray-600 text-gray-700 dark:text-gray-300 text-sm font-semibold hover:bg-gray-50 dark:hover:bg-gray-700 transition-colors"
                  >
                    Cancelar
                  </button>
                  <button
                    onClick={confirmScan}
                    className="flex-1 rounded-xl py-3 bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-semibold transition-colors"
                  >
                    Sí, escanear
                  </button>
                </div>
              </>
            ) : (
              <>
                <OcrTerminal
                  log={scanModal.log}
                  done={scanModal.done}
                  eansCount={scanModal.eansCount}
                  photoId={scanModal.photoId}
                />
                {scanModal.done && (
                  <button
                    onClick={closeScanModal}
                    className="w-full rounded-xl py-3 bg-gray-900 dark:bg-white text-white dark:text-gray-900 text-sm font-semibold hover:bg-gray-700 dark:hover:bg-gray-100 transition-colors"
                  >
                    Cerrar
                  </button>
                )}
              </>
            )}
          </div>
        </div>
      )}
    </div>
  );
}

// ── AddTicketModal ────────────────────────────────────────────────────────────

export function AddTicketModal({ orderId, onClose, onSuccess }) {
  const [code, setCode] = useState("");
  const [note, setNote] = useState("");
  const [saving, setSaving] = useState(false);
  const [ticketId, setTicketId] = useState(null);
  const [uploading, setUploading] = useState(false);

  // Lista de fotos subidas (cada una con su botón escanear)
  const [uploadedPhotos, setUploadedPhotos] = useState([]);

  // Estado de escaneo (gestionado por hook)
  const { scanState, openScan: openScanConfirm, confirmScan: startScan, closeScan: closeScanState } = usePhotoOcr({
    orderId,
    ticketId,
    onDone: (photoId, data) => {
      setUploadedPhotos((prev) =>
        prev.map((p) =>
          p.id === photoId ? { ...p, ocr_processed_at: data.ocr_processed_at } : p,
        ),
      );
    },
  });

  async function handleCreateTicket(e) {
    e.preventDefault();
    if (!code.trim()) {
      toastError("El código del ticket es obligatorio");
      return;
    }
    setSaving(true);
    try {
      const data = await apiPost(`/orders/${orderId}/tickets`, {
        code: code.trim(),
        note: note || null,
      });
      setTicketId(data.id);
      toastSuccess("Ticket creado. Ahora podés agregar fotos.");
    } catch (e) {
      toastError(
        e.response?.data?.message || e.message || "Error creando ticket",
      );
    } finally {
      setSaving(false);
    }
  }

  async function handleUploadPhoto(e) {
    const file = e.target.files?.[0];
    if (!file) return;
    if (!ticketId) {
      toastError("Primero creá el ticket");
      return;
    }
    setUploading(true);
    try {
      const form = new FormData();
      form.append("photo", file);
      const res = await apiPost(
        `/orders/${orderId}/tickets/${ticketId}/photos`,
        form,
      );
      toastSuccess("Foto subida correctamente");
      setUploadedPhotos((prev) => [...prev, res.photo]);
    } catch (err) {
      toastError(
        err?.response?.data?.message || err?.message || "Error subiendo foto",
      );
    } finally {
      setUploading(false);
      e.target.value = "";
    }
  }

  function handleFinish() {
    closeScanState();
    onSuccess();
  }

  return (
    <div className="fixed inset-0 z-50 bg-black/80 flex items-center justify-center p-4">
      <div className="bg-white dark:bg-gray-900 rounded-2xl p-6 max-w-lg w-full max-h-[92vh] overflow-y-auto">
        <div className="flex items-center justify-between mb-4">
          <h3 className="text-lg font-semibold text-gray-900 dark:text-white">
            Agregar ticket
          </h3>
          <button
            onClick={onClose}
            className="text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200 text-xl"
          >
            ✕
          </button>
        </div>

        {!ticketId ? (
          /* ── Paso 1: crear ticket ──────────────────────────── */
          <form onSubmit={handleCreateTicket} className="space-y-4">
            <div>
              <label className="block text-sm font-medium mb-1 text-gray-700 dark:text-gray-300">
                Código del ticket <span className="text-red-500">*</span>
              </label>
              <input
                type="text"
                value={code}
                onChange={(e) => setCode(e.target.value)}
                required
                className="w-full border rounded-lg px-3 py-2 dark:bg-gray-800 dark:border-gray-600 dark:text-white"
                placeholder="Ej: R-12345"
                autoFocus
              />
            </div>
            <div>
              <label className="block text-sm font-medium mb-1 text-gray-700 dark:text-gray-300">
                Nota (opcional)
              </label>
              <textarea
                value={note}
                onChange={(e) => setNote(e.target.value)}
                className="w-full border rounded-lg px-3 py-2 dark:bg-gray-800 dark:border-gray-600 dark:text-white"
                rows={2}
                placeholder="Notas adicionales..."
              />
            </div>
            <div className="flex gap-2">
              <button
                type="button"
                onClick={onClose}
                className="flex-1 border rounded-lg py-2 text-sm dark:border-gray-600 dark:text-gray-300"
              >
                Cancelar
              </button>
              <button
                type="submit"
                disabled={saving || !code.trim()}
                className="flex-1 bg-gray-900 dark:bg-white text-white dark:text-gray-900 rounded-lg py-2 text-sm disabled:opacity-40"
              >
                {saving ? "Creando..." : "Crear ticket"}
              </button>
            </div>
          </form>
        ) : (
          /* ── Paso 2: subir fotos + escanear ───────────────── */
          <div className="space-y-4">
            <div className="bg-green-50 dark:bg-green-900/20 border border-green-200 dark:border-green-700/50 rounded-lg p-3 text-sm text-green-800 dark:text-green-300">
              ✓ Ticket <span className="font-semibold">{code}</span> creado.
              Subí una foto y escaneá con OCR cuando el pallet esté listo.
            </div>

            {/* Lista de fotos subidas */}
            {uploadedPhotos.length > 0 && (
              <div className="space-y-2">
                {uploadedPhotos.map((photo) => {
                  const alreadyScanned = !!photo.ocr_processed_at;
                  return (
                    <div
                      key={photo.id}
                      className="flex items-center justify-between bg-gray-50 dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-xl px-3 py-2 gap-2"
                    >
                      <div className="flex items-center gap-2 min-w-0">
                        <span className="text-lg">🖼</span>
                        <span className="text-sm text-gray-700 dark:text-gray-300 font-mono truncate">
                          Foto #{photo.id}
                        </span>
                        {alreadyScanned && (
                          <span className="text-[10px] bg-green-100 dark:bg-green-900/30 text-green-700 dark:text-green-400 px-1.5 py-0.5 rounded-full font-semibold shrink-0">
                            ✓ Escaneada
                          </span>
                        )}
                      </div>
                      {!alreadyScanned && (
                        <button
                          onClick={() => openScanConfirm(photo.id)}
                          className="shrink-0 text-xs px-3 py-1.5 rounded-lg bg-indigo-600 text-white font-semibold hover:bg-indigo-700 transition-colors"
                        >
                          Escanear con OCR
                        </button>
                      )}
                    </div>
                  );
                })}
              </div>
            )}

            {/* Zona de subida */}
            <label
              className={`block w-full border-2 border-dashed rounded-lg p-4 text-center transition-colors ${
                uploading
                  ? "border-gray-200 cursor-not-allowed"
                  : "border-gray-300 dark:border-gray-600 cursor-pointer hover:bg-gray-50 dark:hover:bg-gray-800 hover:border-gray-400"
              }`}
            >
              <input
                type="file"
                accept="image/*"
                className="hidden"
                onChange={handleUploadPhoto}
                disabled={uploading}
              />
              {uploading ? (
                <div className="flex flex-col items-center gap-1">
                  <div className="animate-spin rounded-full h-7 w-7 border-b-2 border-gray-500" />
                  <span className="text-sm text-gray-500 dark:text-gray-400">
                    Subiendo foto...
                  </span>
                </div>
              ) : (
                <div className="flex flex-col items-center gap-1">
                  <span className="text-3xl">📷</span>
                  <span className="text-sm text-gray-600 dark:text-gray-400">
                    Tocar para agregar foto
                  </span>
                  <span className="text-xs text-gray-400 dark:text-gray-500">
                    Podés agregar varias
                  </span>
                </div>
              )}
            </label>

            <div className="flex gap-2 pt-1">
              <button
                onClick={handleFinish}
                className="flex-1 bg-gray-900 dark:bg-white text-white dark:text-gray-900 rounded-lg py-2.5 text-sm font-semibold"
              >
                Listo
              </button>
            </div>
          </div>
        )}
      </div>

      {/* ── Modal de escaneo OCR ─────────────────────────────────── */}
      {scanState && (
        <div className="fixed inset-0 z-[60] bg-black/80 flex items-center justify-center p-4">
          <div
            className="bg-white dark:bg-gray-900 rounded-2xl p-6 w-full max-w-md space-y-4 shadow-2xl"
            onClick={(e) => e.stopPropagation()}
          >
            <div className="flex items-center justify-between">
              <h3 className="font-bold text-lg text-gray-900 dark:text-white">
                Escanear con OCR
              </h3>
              {(scanState.done || scanState.step === "confirm") && (
                <button
                  onClick={closeScanState}
                  className="text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200 text-xl"
                >
                  ✕
                </button>
              )}
            </div>

            {scanState.step === "confirm" ? (
              <>
                <div className="bg-amber-50 dark:bg-amber-900/20 border border-amber-200 dark:border-amber-700/50 rounded-xl p-4 space-y-1.5">
                  <p className="text-sm font-semibold text-amber-800 dark:text-amber-300">
                    ⚠ Esto consume un crédito de Azure Computer Vision
                  </p>
                  <p className="text-xs text-amber-700 dark:text-amber-400 leading-relaxed">
                    Cada escaneo realiza una llamada a la API de Azure (plan
                    gratuito: 5.000/mes). Asegurate de que el pallet esté
                    organizado antes de escanear. Una vez escaneada, una foto no
                    puede volver a escanearse.
                  </p>
                </div>
                <div className="flex gap-2 pt-1">
                  <button
                    onClick={closeScanState}
                    className="flex-1 rounded-xl py-3 border border-gray-200 dark:border-gray-600 text-gray-700 dark:text-gray-300 text-sm font-semibold hover:bg-gray-50 dark:hover:bg-gray-700 transition-colors"
                  >
                    Cancelar
                  </button>
                  <button
                    onClick={startScan}
                    className="flex-1 rounded-xl py-3 bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-semibold transition-colors"
                  >
                    Sí, escanear
                  </button>
                </div>
              </>
            ) : (
              <>
                <OcrTerminal
                  log={scanState.log}
                  done={scanState.done}
                  eansCount={scanState.eansCount}
                  photoId={scanState.photoId}
                />
                {scanState.done && (
                  <button
                    onClick={closeScanState}
                    className="w-full rounded-xl py-3 bg-gray-900 dark:bg-white text-white dark:text-gray-900 text-sm font-semibold hover:bg-gray-700 dark:hover:bg-gray-100 transition-colors"
                  >
                    Cerrar
                  </button>
                )}
              </>
            )}
          </div>
        </div>
      )}
    </div>
  );
}
