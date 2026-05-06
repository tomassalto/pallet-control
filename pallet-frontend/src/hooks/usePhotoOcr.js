/**
 * usePhotoOcr — hook compartido para el flujo de escaneo OCR de fotos de ticket.
 *
 * Encapsula el estado del modal, el polling y el cleanup,
 * eliminando duplicación entre TicketCard y AddTicketModal.
 *
 * @param {object}        options
 * @param {string|number} options.orderId
 * @param {string|number} options.ticketId
 * @param {function}      [options.onDone] — callback(photoId, ocrData) cuando el OCR finaliza
 *
 * @returns {{ scanState, openScan, confirmScan, closeScan }}
 *   scanState: null | { photoId, step: 'confirm'|'scanning', log, done, eansCount }
 */

import { useEffect, useRef, useState } from "react";
import { apiGet, apiPost } from "../api/client";

export function usePhotoOcr({ orderId, ticketId, onDone }) {
  const [scanState, setScanState] = useState(null);
  const pollRef    = useRef(null);

  // Cleanup del intervalo al desmontar
  useEffect(() => () => clearInterval(pollRef.current), []);

  function openScan(photoId) {
    clearInterval(pollRef.current);
    setScanState({
      photoId,
      step:      "confirm",
      log:       "",
      done:      false,
      eansCount: null,
    });
  }

  async function confirmScan() {
    if (!scanState) return;
    const { photoId } = scanState;
    setScanState(
      (prev) => prev && { ...prev, step: "scanning", log: "Iniciando escaneo OCR…" },
    );
    try {
      await apiPost(
        `/orders/${orderId}/tickets/${ticketId}/photos/${photoId}/trigger-ocr`,
      );
    } catch (e) {
      const msg = e?.response?.data?.message || e.message || "Error al iniciar OCR";
      setScanState((prev) => prev && { ...prev, log: `[ERROR] ${msg}`, done: true });
      return;
    }
    clearInterval(pollRef.current);
    pollRef.current = setInterval(async () => {
      try {
        const data = await apiGet(
          `/orders/${orderId}/tickets/${ticketId}/photos/${photoId}/ocr-status`,
        );
        if (data.ocr_log)
          setScanState((prev) => prev && { ...prev, log: data.ocr_log });
        if (data.ocr_processed_at !== null) {
          clearInterval(pollRef.current);
          setScanState(
            (prev) => prev && { ...prev, done: true, eansCount: data.ocr_eans_count },
          );
          onDone?.(photoId, data);
        }
      } catch {
        // silenciar errores de polling
      }
    }, 2000);
  }

  function closeScan() {
    clearInterval(pollRef.current);
    setScanState(null);
  }

  return { scanState, openScan, confirmScan, closeScan };
}
