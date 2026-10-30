import { beforeEach, describe, expect, it, vi } from "vitest";
import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { MemoryRouter, Route, Routes } from "react-router-dom";
import { ToastContainer } from "react-toastify";
import { ThemeProvider } from "../context/ThemeContext";
import Login from "../pages/Login";
import RequireAuth from "./RequireAuth";
import { AuthProvider } from "./AuthContext";
import { setNavigate } from "../api/client";

function json(body, status = 200) {
  return Promise.resolve(
    new Response(JSON.stringify(body), {
      status,
      headers: { "Content-Type": "application/json" },
    }),
  );
}

function renderApp(initial = "/login") {
  render(
    <MemoryRouter initialEntries={[initial]}>
      <AuthProvider>
        <ThemeProvider>
          <Routes>
            <Route path="/login" element={<Login />} />
            <Route
              path="/"
              element={
                <RequireAuth>
                  <p>CONTENIDO PROTEGIDO</p>
                </RequireAuth>
              }
            />
          </Routes>
          <ToastContainer />
        </ThemeProvider>
      </AuthProvider>
    </MemoryRouter>,
  );
}

beforeEach(() => {
  vi.stubGlobal("fetch", vi.fn());
});

describe("login", () => {
  it("login exitoso guarda el token y navega al inicio", async () => {
    const user = userEvent.setup();
    fetch.mockImplementation((url) =>
      url.includes("/auth/login")
        ? json({ token: "tok-1", user: { id: 1, email: "a@b.c", role: "admin" } })
        : json({ message: "nf" }, 404),
    );

    renderApp("/login");

    await user.type(screen.getByPlaceholderText("Email"), "a@b.c");
    await user.type(screen.getByPlaceholderText(/contrase/i), "secreto");
    await user.click(screen.getByRole("button", { name: /entrar|iniciar/i }));

    await screen.findByText("CONTENIDO PROTEGIDO");
    expect(localStorage.getItem("token")).toBe("tok-1");
  });
});

describe("_actual_ login con credenciales inválidas", () => {
  it("BUG: muestra 'Sesión expirada' en vez del mensaje del backend", async () => {
    const user = userEvent.setup();
    fetch.mockImplementation((url) =>
      url.includes("/auth/login")
        ? json({ message: "Credenciales inválidas." }, 401)
        : json({ message: "nf" }, 404),
    );

    renderApp("/login");

    await user.type(screen.getByPlaceholderText("Email"), "a@b.c");
    await user.type(screen.getByPlaceholderText(/contrase/i), "mal");
    await user.click(screen.getByRole("button", { name: /entrar|iniciar/i }));

    // El cliente trata TODO 401 como sesión expirada, incluso sin token.
    expect(await screen.findByText("Sesión expirada")).toBeTruthy();
    expect(screen.queryByText("Credenciales inválidas.")).toBeNull();
  });
});

describe("arranque con sesión", () => {
  it("con token almacenado y /auth/me 401: borra el token y redirige a /login", async () => {
    localStorage.setItem("token", "tok-viejo");
    const navigate = vi.fn();
    setNavigate(navigate);
    fetch.mockImplementation((url) =>
      url.includes("/auth/me")
        ? json({ message: "Unauthenticated." }, 401)
        : json({ message: "nf" }, 404),
    );

    renderApp("/");

    await screen.findByText("Iniciar sesión");
    expect(localStorage.getItem("token")).toBeNull();
    expect(navigate).toHaveBeenCalledWith("/login", { replace: true });
    expect(screen.queryByText("CONTENIDO PROTEGIDO")).toBeNull();
  });

  it("RequireAuth muestra spinner mientras verifica la sesión", () => {
    localStorage.setItem("token", "tok-viejo");
    fetch.mockImplementation(() => new Promise(() => {}));

    renderApp("/");

    expect(screen.getAllByRole("status").length).toBeGreaterThan(0);
    expect(screen.queryByText("Iniciar sesión")).toBeNull();
    expect(screen.queryByText("CONTENIDO PROTEGIDO")).toBeNull();
  });
});
