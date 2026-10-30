import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { useEffect } from "react";
import { act, render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { MemoryRouter, Route, Routes } from "react-router-dom";
import { QueryClientProvider, useQuery } from "@tanstack/react-query";
import { ToastContainer } from "react-toastify";
import { ThemeProvider } from "../context/ThemeContext";
import Login from "../pages/Login";
import RequireAuth from "./RequireAuth";
import { AuthProvider } from "./AuthContext";
import { apiGet, setOnUnauthorized } from "../api/client";
import { useAuth } from "../hooks/useAuth";
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

// Vista protegida con una query privada y botones que disparan
// peticiones autenticadas que el backend puede responder 401.
function VistaPrivada({ botones = 1 }) {
  const { data } = useQuery({
    queryKey: ["privado"],
    queryFn: () => Promise.resolve({ dato: "DATO PRIVADO" }),
  });
  return (
    <>
      <p>CONTENIDO PROTEGIDO</p>
      <p>{data?.dato ?? "cargando"}</p>
      {Array.from({ length: botones }, (_, i) => (
        <button
          key={i}
          onClick={() => apiGet("/recurso").catch(() => {})}
        >
          disparar-{i}
        </button>
      ))}
    </>
  );
}

// Sonda fuera de las rutas: refleja el user del contexto en pantalla.
function SondaUsuario() {
  const { user } = useAuth();
  return <p data-testid="sonda">{user?.email ?? "sin-usuario"}</p>;
}

function renderAppConSesion(queryClient = createTestQueryClient(), botones = 1) {
  render(
    <MemoryRouter initialEntries={["/"]}>
      <QueryClientProvider client={queryClient}>
        <AuthProvider>
        <ThemeProvider>
          <SondaUsuario />
          <Routes>
            <Route path="/login" element={<Login />} />
            <Route
              path="/"
              element={
                <RequireAuth>
                  <VistaPrivada botones={botones} />
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

afterEach(() => {
  setOnUnauthorized(null);
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
    const queryClient = createTestQueryClient();
    // Cache con datos: si corriera el flujo de sesión expirada la vaciaría.
    // gcTime para que la query sin observadores sobreviva al assert.
    queryClient.setQueryDefaults(["privado"], { gcTime: 60_000 });
    queryClient.setQueryData(["privado"], { x: 1 });
    fetch.mockImplementation((url) =>
      url.includes("/auth/login")
        ? json({ message: "Credenciales inválidas." }, 401)
        : json({ message: "nf" }, 404),
    );

    renderApp("/login", queryClient);

    await user.type(screen.getByPlaceholderText("Email"), "a@b.c");
    await user.type(screen.getByPlaceholderText(/contrase/i), "mal");
    await user.click(screen.getByRole("button", { name: /entrar|iniciar/i }));

    expect(await screen.findByText("Credenciales inválidas.")).toBeTruthy();
    // Sin token enviado no hay flujo de expiración: sigue en login y la
    // cache quedó intacta.
    expect(screen.queryByText("Sesión expirada")).toBeNull();
    expect(screen.getByText("Iniciar sesión")).toBeTruthy();
    expect(queryClient.getQueryData(["privado"])).toEqual({ x: 1 });
  });
});

describe("arranque con sesión", () => {
  it("con token almacenado y /auth/me 401: borra el token y muestra /login", async () => {
    localStorage.setItem("token", "tok-viejo");
    fetch.mockImplementation((url) =>
      url.includes("/auth/me")
        ? json({ message: "Unauthenticated." }, 401)
        : json({ message: "nf" }, 404),
    );

    renderApp("/");

    await screen.findByText("Iniciar sesión");
    expect(localStorage.getItem("token")).toBeNull();
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
  it("un 401 autenticado invalida la sesión vía AuthProvider", async () => {
    localStorage.setItem("token", "tok-1");
    const user = userEvent.setup();
    fetch.mockImplementation((url) =>
      url.includes("/auth/me")
        ? json({ user: { id: 1, email: "a@b.c", role: "admin" } })
        : url.includes("/recurso")
          ? json({ message: "Unauthenticated." }, 401)
          : json({ message: "nf" }, 404),
    );
    const queryClient = renderAppConSesion(createTestQueryClient());

    // Sesión establecida: contenido protegido, dato de la query y email.
    await screen.findByText("CONTENIDO PROTEGIDO");
    await screen.findByText("DATO PRIVADO");
    await screen.findByText("a@b.c");

    await user.click(screen.getByRole("button", { name: "disparar-0" }));

    await screen.findByText("sin-usuario");
    await screen.findByText("Iniciar sesión");
    expect(localStorage.getItem("token")).toBeNull();
    expect(queryClient.getQueryCache().getAll()).toHaveLength(0);
    expect(screen.queryByText("CONTENIDO PROTEGIDO")).toBeNull();
  });

  it("401s concurrentes invalidan la sesión una sola vez, sin errores", async () => {
    localStorage.setItem("token", "tok-1");
    const consoleError = vi
      .spyOn(console, "error")
      .mockImplementation(() => {});
    const user = userEvent.setup();
    // Los 401 de /recurso se resuelven a mano para que ambas peticiones
    // estén en vuelo a la vez.
    const respuestas = [];
    fetch.mockImplementation((url) =>
      url.includes("/auth/me")
        ? json({ user: { id: 1, email: "a@b.c", role: "admin" } })
        : url.includes("/recurso")
          ? new Promise((resolve) => respuestas.push(resolve))
          : json({ message: "nf" }, 404),
    );
    const queryClient = renderAppConSesion(createTestQueryClient(), 2);

    await screen.findByText("CONTENIDO PROTEGIDO");
    await screen.findByText("a@b.c");

    await user.click(screen.getByRole("button", { name: "disparar-0" }));
    await user.click(screen.getByRole("button", { name: "disparar-1" }));
    await waitFor(() => expect(respuestas).toHaveLength(2));
    for (const resolve of respuestas) {
      resolve(
        new Response(JSON.stringify({ message: "Unauthenticated." }), {
          status: 401,
          headers: { "Content-Type": "application/json" },
        }),
      );
    }

    await screen.findByText("sin-usuario");
    await screen.findByText("Iniciar sesión");
    await waitFor(() =>
      expect(queryClient.getQueryCache().getAll()).toHaveLength(0),
    );
    expect(localStorage.getItem("token")).toBeNull();
    const llamadasRecurso = fetch.mock.calls.filter(([url]) =>
      url.includes("/recurso"),
    );
    expect(llamadasRecurso).toHaveLength(2);
    expect(consoleError).not.toHaveBeenCalled();
    consoleError.mockRestore();
  });

  it("logout limpia la cache de queries", async () => {
    const queryClient = renderApp("/login");
    queryClient.setQueryData(["privado"], { secreto: 1 });

    // logout usa el contexto: lo invocamos dentro del provider renderizado.
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
