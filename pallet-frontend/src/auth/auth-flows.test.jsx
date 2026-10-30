import { beforeEach, describe, expect, it, vi } from "vitest";
import { useEffect } from "react";
import { act, render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { MemoryRouter, Route, Routes } from "react-router-dom";
import { QueryClientProvider } from "@tanstack/react-query";
import { ToastContainer } from "react-toastify";
import { ThemeProvider } from "../context/ThemeContext";
import Login from "../pages/Login";
import RequireAuth from "./RequireAuth";
import { AuthProvider } from "./AuthContext";
import { setNavigate, setOnUnauthorized, apiPost } from "../api/client";
import { createTestQueryClient } from "../test/renderWithClient";

function json(body, status = 200) {
  return Promise.resolve(
    new Response(JSON.stringify(body), {
      status,
      headers: { "Content-Type": "application/json" },
    }),
  );
}

function renderApp(initial = "/login", queryClient = createTestQueryClient()) {
  render(
    <MemoryRouter initialEntries={[initial]}>
      <QueryClientProvider client={queryClient}>
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
      </QueryClientProvider>
    </MemoryRouter>,
  );
  return queryClient;
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

describe("login con credenciales inválidas", () => {
  it("muestra el mensaje 401 del backend, sin flujo de sesión expirada", async () => {
    const user = userEvent.setup();
    const onUnauthorized = vi.fn();
    setOnUnauthorized(onUnauthorized);
    fetch.mockImplementation((url) =>
      url.includes("/auth/login")
        ? json({ message: "Credenciales inválidas." }, 401)
        : json({ message: "nf" }, 404),
    );

    renderApp("/login");

    await user.type(screen.getByPlaceholderText("Email"), "a@b.c");
    await user.type(screen.getByPlaceholderText(/contrase/i), "mal");
    await user.click(screen.getByRole("button", { name: /entrar|iniciar/i }));

    expect(await screen.findByText("Credenciales inválidas.")).toBeTruthy();
    // Sin token enviado no hay flujo de expiración: ni callback ni
    // "Sesión expirada".
    expect(onUnauthorized).not.toHaveBeenCalled();
    expect(screen.queryByText("Sesión expirada")).toBeNull();
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

describe("cache de sesión", () => {
  it("un 401 autenticado limpia la cache de queries", async () => {
    localStorage.setItem("token", "tok-1");
    const queryClient = renderApp("/login");
    queryClient.setQueryData(["privado"], { secreto: 1 });
    setOnUnauthorized(() => {
      queryClient.cancelQueries();
      queryClient.clear();
    });

    fetch.mockImplementation(() => json({ message: "Unauthenticated." }, 401));
    await expect(apiPost("/recurso", {})).rejects.toThrow("Sesión expirada");

    expect(queryClient.getQueryData(["privado"])).toBeUndefined();
  });

  it("logout limpia la cache de queries", async () => {
    const queryClient = renderApp("/login");
    queryClient.setQueryData(["privado"], { secreto: 1 });

    // logout usa el contexto: lo invocamos dentro del provider renderizado.
    const { useAuth } = await import("../hooks/useAuth.js");
    let logoutFn;
    function Probe() {
      const { logout } = useAuth();
      useEffect(() => {
        logoutFn = logout;
      });
      return null;
    }
    render(
      <MemoryRouter>
        <QueryClientProvider client={queryClient}>
          <AuthProvider>
            <Probe />
          </AuthProvider>
        </QueryClientProvider>
      </MemoryRouter>,
    );

    fetch.mockImplementation((url) =>
      url.includes("/auth/logout") ? json({ ok: true }) : json({}, 404),
    );
    await act(async () => logoutFn());

    expect(queryClient.getQueryData(["privado"])).toBeUndefined();
    expect(localStorage.getItem("token")).toBeNull();
  });
});
