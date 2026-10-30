import { describe, expect, it, vi, beforeEach } from "vitest";
import { render, screen } from "@testing-library/react";
import { MemoryRouter } from "react-router-dom";
import SidebarLayout from "./SidebarLayout";
import { AuthContext } from "../auth/AuthContextValue";
import { ThemeProvider } from "../context/ThemeContext";

function renderSidebar(user) {
  render(
    <MemoryRouter>
      <AuthContext.Provider value={{ user, booting: false, logout: vi.fn() }}>
        <ThemeProvider>
          <SidebarLayout title="T">
            <p>contenido</p>
          </SidebarLayout>
        </ThemeProvider>
      </AuthContext.Provider>
    </MemoryRouter>,
  );
}

beforeEach(() => {
  localStorage.setItem("token", "tok-test");
  vi.stubGlobal("fetch", vi.fn(() => Promise.resolve(
    new Response(JSON.stringify({ pending_count: 0 }), { status: 200 }),
  )));
});

describe("SidebarLayout — entradas por rol", () => {
  // NOTA: esto sólo prueba la PRESENTACIÓN — ocultar enlaces no es
  // autorización. El backend responde 403 y está cubierto en tests del API.
  it("un usuario sin rol admin no ve los enlaces de administración", () => {
    renderSidebar({ id: 1, name: "U", role: "user" });

    expect(screen.queryByRole("link", { name: /Usuarios/ })).toBeNull();
    expect(screen.queryByRole("link", { name: /Limpieza/ })).toBeNull();
    expect(screen.getByRole("link", { name: /Inicio/ })).toBeTruthy();
  });

  it("un usuario admin ve Usuarios y Limpieza", () => {
    renderSidebar({ id: 1, name: "A", role: "admin" });

    expect(screen.getByRole("link", { name: /Usuarios/ })).toBeTruthy();
    expect(screen.getByRole("link", { name: /Limpieza/ })).toBeTruthy();
  });
});
