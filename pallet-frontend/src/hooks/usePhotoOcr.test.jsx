import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { renderHook, act } from "@testing-library/react";
import { usePhotoOcr } from "./usePhotoOcr";

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

  it("_actual_: errores de sondeo se silencian y el polling sigue para siempre", async () => {
    fetch.mockImplementation((url) => {
      if (url.includes("trigger-ocr")) return json({});
      if (url.includes("ocr-status")) return json({ message: "boom" }, 500);
      return json({}, 404);
    });
    const { result } = renderHook(() => usePhotoOcr(deps));

    act(() => result.current.openScan(9));
    await act(async () => result.current.confirmScan());

    // Tras muchos errores seguidos el polling continúa sin avisar.
    await act(async () => { await vi.advanceTimersByTimeAsync(60_000); });
    const callsBefore = fetch.mock.calls.filter(([u]) => u.includes("ocr-status")).length;
    expect(callsBefore).toBeGreaterThan(20);
    expect(result.current.scanState.done).toBe(false);

    await act(async () => { await vi.advanceTimersByTimeAsync(4000); });
    expect(
      fetch.mock.calls.filter(([u]) => u.includes("ocr-status")).length,
    ).toBeGreaterThan(callsBefore);
  });

  it("_actual_: nunca corta por tiempo total transcurrido", async () => {
    fetch.mockImplementation((url) => {
      if (url.includes("trigger-ocr")) return json({});
      return json({ ocr_processed_at: null, ocr_log: "…" });
    });
    const { result } = renderHook(() => usePhotoOcr(deps));

    act(() => result.current.openScan(9));
    await act(async () => result.current.confirmScan());

    await act(async () => { await vi.advanceTimersByTimeAsync(6 * 60 * 1000); });
    expect(result.current.scanState.done).toBe(false);
    expect(
      fetch.mock.calls.filter(([u]) => u.includes("ocr-status")).length,
    ).toBeGreaterThan(150);
  });
});
