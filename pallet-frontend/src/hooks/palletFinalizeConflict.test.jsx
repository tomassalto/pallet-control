import { beforeEach, describe, expect, it, vi } from "vitest";
import { renderHook, act, render, screen } from "@testing-library/react";
import { MemoryRouter, Route, Routes } from "react-router-dom";
import { QueryClientProvider } from "@tanstack/react-query";
import { ToastContainer } from "react-toastify";
import { usePalletDetailPage } from "./usePalletDetailPage";
import { createTestQueryClient } from "../test/renderWithClient";

function json(body, status = 200) {
  return Promise.resolve(
    new Response(JSON.stringify(body), {
      status,
      headers: { "Content-Type": "application/json" },
    }),
  );
}

function renderPage(palletId = 5) {
  const queryClient = createTestQueryClient();
  return renderHook(() => usePalletDetailPage(), {
    wrapper: ({ children }) => (
      <QueryClientProvider client={queryClient}>
        <MemoryRouter initialEntries={[`/pallets/${palletId}`]}>
          <Routes>
            <Route path="/pallets/:palletId" element={children} />
          </Routes>
        </MemoryRouter>
      </QueryClientProvider>
    ),
  });
}

beforeEach(() => {
  localStorage.setItem("token", "tok-test");
  vi.stubGlobal("fetch", vi.fn());
  render(<ToastContainer />);
});

describe("usePalletDetailPage — finalizar pallet", () => {
  it("un 409 del backend muestra su message en un toast", async () => {
    const msg = "Se requieren al menos 2 bases, 2 fotos en total, y todas las bases deben tener al menos 1 producto asignado.";
    fetch.mockImplementation((url, options) => {
      if (url.endsWith("/finalize") && options?.method === "POST") {
        return json({ message: msg, bases_count: 1, total_photos: 0 }, 409);
      }
      if (url.includes("/can-finalize")) return json({ can_finalize: true });
      return json({ pallet: { id: 5, code: "PAL-X", status: "open" }, orders: [], bases: [], photos: [] });
    });

    const { result } = renderPage(5);

    act(() => result.current.handleFinalize());
    expect(result.current.confirmModal?.title).toBe("Finalizar pallet");

    await act(async () => result.current.confirmModal.onConfirm());

    expect(await screen.findByText(msg)).toBeTruthy();
  });
});
