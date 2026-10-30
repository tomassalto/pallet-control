import { describe, expect, it, vi, beforeEach } from "vitest";
import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { QueryClientProvider } from "@tanstack/react-query";
import { MemoryRouter } from "react-router-dom";
import { ToastContainer } from "react-toastify";
import PendingItems from "./PendingItems";
import { createTestQueryClient } from "../test/renderWithClient";

function pendingItem(overrides = {}) {
  return {
    id: 1,
    description: "Harina 0000 25kg",
    order_code: "PED-0001",
    ean: "7790001112223",
    qty_missing: 3,
    status: "pending",
    note: null,
    image_url: null,
    units_per_bulto: null,
    created_by_name: "Ana",
    created_at: new Date().toISOString(),
    resolved_by_name: null,
    resolved_at: null,
    ...overrides,
  };
}

function jsonResponse(body, status = 200) {
  return Promise.resolve(
    new Response(JSON.stringify(body), {
      status,
      headers: { "Content-Type": "application/json" },
    }),
  );
}

function mockPendingApi(handler) {
  vi.stubGlobal(
    "fetch",
    vi.fn((url) => {
      if (url.includes("/pending-items") || url.includes("/admin/missing-item-requests")) {
        return handler(url);
      }
      return Promise.reject(new Error(`fetch inesperado: ${url}`));
    }),
  );
}

function renderPage() {
  render(
    <QueryClientProvider client={createTestQueryClient()}>
      <MemoryRouter>
        <PendingItems />
        <ToastContainer />
      </MemoryRouter>
    </QueryClientProvider>,
  );
}

beforeEach(() => {
  localStorage.setItem("token", "tok-test");
});

describe("PendingItems", () => {
  it("muestra el estado de carga antes de resolver los datos", async () => {
    const resolvers = [];
    mockPendingApi((url) =>
      url.includes("/admin/missing-item-requests")
        ? jsonResponse([])
        : new Promise((resolve) => resolvers.push(resolve)),
    );

    renderPage();

    // Mientras la query no responde: ni lista ni estado vacío.
    expect(screen.queryByText("¡Todo al día!")).toBeNull();
    expect(screen.getByRole("heading", { name: "Pendientes" })).toBeTruthy();

    resolvers.forEach((resolve) =>
      resolve(jsonResponse({ data: [], meta: { current_page: 1, last_page: 1, total: 0 } })),
    );
    expect(await screen.findByText("¡Todo al día!")).toBeTruthy();
  });

  it("muestra los pendientes y los totales del meta", async () => {
    mockPendingApi((url) => {
      if (url.includes("status=pending")) {
        return jsonResponse({
          data: [pendingItem(), pendingItem({ id: 2, description: "Azúcar 1kg" })],
          meta: { current_page: 1, last_page: 3, total: 52 },
        });
      }
      if (url.includes("status=resolved")) {
        return jsonResponse({ data: [], meta: { current_page: 1, last_page: 1, total: 0 } });
      }
      return jsonResponse([]);
    });

    renderPage();

    expect(await screen.findByText("Harina 0000 25kg")).toBeTruthy();
    expect(screen.getByText("Azúcar 1kg")).toBeTruthy();
    expect(screen.getByText("52 pendientes sin resolver")).toBeTruthy();
    expect(screen.getByText("Página 1 de 3 · 52 en total")).toBeTruthy();
  });

  it("muestra el estado vacío cuando no hay pendientes", async () => {
    mockPendingApi((url) => {
      if (url.includes("/admin/missing-item-requests")) return jsonResponse([]);
      return jsonResponse({ data: [], meta: { current_page: 1, last_page: 1, total: 0 } });
    });

    renderPage();

    expect(await screen.findByText("¡Todo al día!")).toBeTruthy();
    expect(screen.getByText("No hay productos con faltantes pendientes")).toBeTruthy();
  });

  it("ante un error muestra alerta con reintento y un solo toast", async () => {
    const user = userEvent.setup();
    let failing = true;
    mockPendingApi((url) => {
      if (url.includes("/admin/missing-item-requests")) return jsonResponse([]);
      if (failing) {
        return jsonResponse({ message: "Ocurrió un error inesperado.", error_id: "x" }, 500);
      }
      return jsonResponse({
        data: [pendingItem()],
        meta: { current_page: 1, last_page: 1, total: 1 },
      });
    });

    renderPage();

    expect(await screen.findByText("No se pudieron cargar los datos.")).toBeTruthy();
    expect(screen.getAllByRole("alert").length).toBeGreaterThan(0);
    expect(screen.queryByText("¡Todo al día!")).toBeNull();
    expect(
      (await screen.findAllByText("Error al cargar los pendientes")).length,
    ).toBe(1);

    failing = false;
    await user.click(screen.getByRole("button", { name: "Reintentar" }));

    expect(await screen.findByText("Harina 0000 25kg")).toBeTruthy();
    expect(screen.queryByText("No se pudieron cargar los datos.")).toBeNull();
  });

  it("pide la página siguiente con el parámetro correcto", async () => {
    const user = userEvent.setup();
    const urls = [];
    mockPendingApi((url) => {
      urls.push(url);
      if (url.includes("status=pending&page=1")) {
        return jsonResponse({
          data: [pendingItem()],
          meta: { current_page: 1, last_page: 2, total: 26 },
        });
      }
      if (url.includes("status=pending&page=2")) {
        return jsonResponse({
          data: [pendingItem({ id: 26, description: "Aceite 5L" })],
          meta: { current_page: 2, last_page: 2, total: 26 },
        });
      }
      if (url.includes("status=resolved")) {
        return jsonResponse({ data: [], meta: { current_page: 1, last_page: 1, total: 0 } });
      }
      return jsonResponse([]);
    });

    renderPage();

    await user.click(await screen.findByRole("button", { name: "Siguiente" }));

    expect(await screen.findByText("Aceite 5L")).toBeTruthy();
    expect(screen.getByText("Página 2 de 2 · 26 en total")).toBeTruthy();
    expect(urls).toContain("/api/v1/pending-items?status=pending&page=2&per_page=25");
  });
});
