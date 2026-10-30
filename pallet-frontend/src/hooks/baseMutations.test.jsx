import { beforeEach, describe, expect, it, vi } from "vitest";
import { renderHook, act, render, screen } from "@testing-library/react";
import { ToastContainer } from "react-toastify";
import { useBaseMutations } from "./useBaseMutations";
import { useItemAction } from "./useItemAction";

function json(body, status = 200) {
  return Promise.resolve(
    new Response(JSON.stringify(body), {
      status,
      headers: { "Content-Type": "application/json" },
    }),
  );
}

beforeEach(() => {
  localStorage.setItem("token", "tok-test");
  vi.stubGlobal("fetch", vi.fn());
  render(<ToastContainer />);
});

describe("useBaseMutations", () => {
  it("rechaza crear una base vacía con un toast", async () => {
    const { result } = renderHook(() =>
      useBaseMutations({ palletId: 1, load: vi.fn(), setConfirmModal: vi.fn() }),
    );

    await act(() => result.current.onCreateBase());

    expect(await screen.findByText("Agregá un nombre o una nota")).toBeTruthy();
    expect(fetch).not.toHaveBeenCalled();
  });

  it("ante un error del backend el formulario mantiene su contenido", async () => {
    fetch.mockImplementation((url) =>
      url.includes("/pallets/1/bases/7")
        ? json({ message: "No se puede actualizar", available: 3, requested: 10 }, 409)
        : json({ message: "nf" }, 404),
    );

    const load = vi.fn();
    const { result } = renderHook(() =>
      useBaseMutations({ palletId: 1, load, setConfirmModal: vi.fn() }),
    );

    act(() =>
      result.current.startEditBase({ id: 7, name: "Bodega", note: "nota" }),
    );
    await act(() => result.current.onUpdateBase({ id: 7 }));

    expect(await screen.findByText("No se puede actualizar")).toBeTruthy();
    // El formulario quedó abierto con la base en edición y su contenido.
    expect(result.current.editingBase).toEqual({ id: 7, name: "Bodega", note: "nota" });
    expect(result.current.baseName).toBe("Bodega");
    expect(load).not.toHaveBeenCalled();
  });
});

describe("useItemAction", () => {
  it("una reducción por debajo de lo organizado abre el modal de conflicto", () => {
    const { result } = renderHook(() =>
      useItemAction({ setItems: vi.fn(), load: vi.fn() }),
    );
    const item = {
      id: 9,
      locations: [
        { base_id: 1, qty: 6 },
        { base_id: 2, qty: 4 },
      ],
    };

    act(() => result.current.tryApplyAction(item, 5));

    expect(result.current.qtyConflict).toMatchObject({
      newQty: 5,
      totalOrganized: 10,
      deficit: 5,
    });
    expect(result.current.actionItem).toBeNull();
    expect(fetch).not.toHaveBeenCalled();
  });
});
