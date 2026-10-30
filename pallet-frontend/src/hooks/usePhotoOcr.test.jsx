import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { renderHook, act } from "@testing-library/react";
import { usePhotoOcr, OCR_MAX_ERRORS, OCR_MAX_MS } from "./usePhotoOcr";

function json(body, status = 200) {
  return Promise.resolve(
    new Response(JSON.stringify(body), {
      status,
      headers: { "Content-Type": "application/json" },
    }),
  );
}

const deps = { orderId: 1, ticketId: 2, onDone: vi.fn() };

beforeEach(() => {
  vi.useFakeTimers();
  vi.stubGlobal("fetch", vi.fn());
});

afterEach(() => {
  vi.clearAllMocks();
});

describe("usePhotoOcr", () => {
  it("confirma, dispara trigger-ocr y sondea cada 2s hasta ocr_processed_at", async () => {
    fetch.mockImplementation((url) => {
      if (url.includes("trigger-ocr")) return json({});
      if (url.includes("ocr-status")) {
        const calls = fetch.mock.calls.filter(([u]) =>
          u.includes("ocr-status"),
        ).length;
        if (calls < 2) return json({ ocr_processed_at: null, ocr_log: "Leyendo…" });
        return json({ ocr_processed_at: "now", ocr_eans_count: 3, ocr_log: "Listo" });
      }
      return json({}, 404);
    });
    const onDone = vi.fn();
    const { result } = renderHook(() => usePhotoOcr({ ...deps, onDone }));

    act(() => result.current.openScan(9));
    await act(async () => result.current.confirmScan());

    expect(fetch.mock.calls.some(([u]) => u.includes("trigger-ocr"))).toBe(true);

    await act(async () => { await vi.advanceTimersByTimeAsync(2000); });
    expect(result.current.scanState.log).toBe("Leyendo…");
    expect(result.current.scanState.done).toBe(false);

    await act(async () => { await vi.advanceTimersByTimeAsync(2000); });
    expect(result.current.scanState.done).toBe(true);
    expect(result.current.scanState.eansCount).toBe(3);
    expect(onDone).toHaveBeenCalledWith(9, expect.objectContaining({ ocr_eans_count: 3 }));
  });

  it("si trigger-ocr falla muestra '[ERROR] mensaje' y termina", async () => {
    fetch.mockImplementation((url) =>
      url.includes("trigger-ocr")
        ? json({ message: "rate limit" }, 429)
        : json({}, 404),
    );
    const { result } = renderHook(() => usePhotoOcr(deps));

    act(() => result.current.openScan(9));
    await act(async () => result.current.confirmScan());

    expect(result.current.scanState.log).toBe("[ERROR] rate limit");
    expect(result.current.scanState.done).toBe(true);
  });

  it("corta a los 5 errores consecutivos con mensaje recuperable", async () => {
    fetch.mockImplementation((url) => {
      if (url.includes("trigger-ocr")) return json({});
      if (url.includes("ocr-status")) return json({ message: "boom" }, 500);
      return json({}, 404);
    });
    const { result } = renderHook(() => usePhotoOcr(deps));

    act(() => result.current.openScan(9));
    await act(async () => result.current.confirmScan());

    await act(async () => { await vi.advanceTimersByTimeAsync(60_000); });
    expect(result.current.scanState.done).toBe(true);
    expect(result.current.scanState.log).toBe(
      "No se pudo consultar el estado del escaneo. Intentá de nuevo más tarde.",
    );

    // Ya no hay más consultas tras el corte.
    const callsAtStop = fetch.mock.calls.filter(([u]) => u.includes("ocr-status")).length;
    expect(callsAtStop).toBe(OCR_MAX_ERRORS);
    await act(async () => { await vi.advanceTimersByTimeAsync(10_000); });
    expect(
      fetch.mock.calls.filter(([u]) => u.includes("ocr-status")).length,
    ).toBe(callsAtStop);
  });

  it("un éxito reinicia el contador de errores", async () => {
    let calls = 0;
    fetch.mockImplementation((url) => {
      if (url.includes("trigger-ocr")) return json({});
      if (url.includes("ocr-status")) {
        calls += 1;
        // Errores en 1-4 y 6-10; un único éxito en el 5 reinicia la cuenta.
        if (calls === 5) return json({ ocr_processed_at: null, ocr_log: "ok" });
        return json({ message: "boom" }, 500);
      }
      return json({}, 404);
    });
    const { result } = renderHook(() => usePhotoOcr(deps));

    act(() => result.current.openScan(9));
    await act(async () => result.current.confirmScan());

    // 4 errores + 1 éxito (reset) + 4 errores: sigue sondeando.
    await act(async () => { await vi.advanceTimersByTimeAsync(18_000); });
    expect(result.current.scanState.done).toBe(false);

    // El 5º error consecutivo tras el reset corta.
    await act(async () => { await vi.advanceTimersByTimeAsync(2_000); });
    expect(result.current.scanState.done).toBe(true);
    expect(result.current.scanState.log).toBe(
      "No se pudo consultar el estado del escaneo. Intentá de nuevo más tarde.",
    );
  });

  it("corta al superar 5 minutos totales con mensaje recuperable", async () => {
    fetch.mockImplementation((url) => {
      if (url.includes("trigger-ocr")) return json({});
      return json({ ocr_processed_at: null, ocr_log: "…" });
    });
    const { result } = renderHook(() => usePhotoOcr(deps));

    act(() => result.current.openScan(9));
    await act(async () => result.current.confirmScan());

    await act(async () => { await vi.advanceTimersByTimeAsync(OCR_MAX_MS); });
    expect(result.current.scanState.done).toBe(true);
    expect(result.current.scanState.log).toBe(
      "El escaneo está demorando más de lo esperado. Cerrá y volvé a abrir la foto más tarde.",
    );

    const callsAtStop = fetch.mock.calls.filter(([u]) => u.includes("ocr-status")).length;
    await act(async () => { await vi.advanceTimersByTimeAsync(10_000); });
    expect(
      fetch.mock.calls.filter(([u]) => u.includes("ocr-status")).length,
    ).toBe(callsAtStop);
  });
});
