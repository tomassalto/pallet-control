import { readFileSync } from "node:fs";
import { fileURLToPath } from "node:url";
import { dirname, join } from "node:path";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { act, render } from "@testing-library/react";
import { QueryClientProvider } from "@tanstack/react-query";
import { MemoryRouter } from "react-router-dom";
import { AuthProvider } from "./AuthContext";
import { useAuth } from "../hooks/useAuth";
import { bootstrapApp } from "../appBootstrap";
import { clearPrivateCaches } from "./privateCaches";
import { createTestQueryClient } from "../test/renderWithClient";

// La purga se controla por test con promesas diferidas: la barrera sólo
// se acredita si la identidad no se publica antes de que resuelva.
vi.mock("./privateCaches", async (orig) => {
  const mod = await orig();
  return { ...mod, clearPrivateCaches: vi.fn(async () => []) };
});

function deferred() {
  let resolve, reject;
  const promise = new Promise((res, rej) => {
    resolve = res;
    reject = rej;
  });
  return { promise, resolve, reject };
}

const flush = () => act(async () => new Promise((r) => setTimeout(r, 0)));

function json(body, status = 200) {
  return Promise.resolve(
    new Response(JSON.stringify(body), {
      status,
      headers: { "Content-Type": "application/json" },
    }),
  );
}

function AuthProbe({ onReady }) {
  const auth = useAuth();
  onReady(auth);
  return <p data-testid="sonda">{auth.user?.email ?? "sin-usuario"}</p>;
}

function renderAuth(onReady) {
  const queryClient = createTestQueryClient();
  render(
    <MemoryRouter>
      <QueryClientProvider client={queryClient}>
        <AuthProvider>
          <AuthProbe onReady={onReady} />
        </AuthProvider>
      </QueryClientProvider>
    </MemoryRouter>,
  );
}

beforeEach(() => {
  vi.stubGlobal("fetch", vi.fn(() => json({ message: "nf" }, 404)));
  clearPrivateCaches.mockResolvedValue([]);
});

afterEach(() => {
  clearPrivateCaches.mockReset();
});

describe("bootstrap (H-051)", () => {
  it("React no monta hasta que la purga inicial termina", async () => {
    const d = deferred();
    clearPrivateCaches.mockReturnValue(d.promise);
    const mount = vi.fn();

    const boot = bootstrapApp(mount);
    await flush();
    expect(mount).not.toHaveBeenCalled();

    d.resolve([]);
    await flush();
    await boot;
    expect(mount).toHaveBeenCalledTimes(1);
  });

  it("un fallo inesperado de Cache API no bloquea el arranque", async () => {
    const d = deferred();
    clearPrivateCaches.mockReturnValue(d.promise);
    const mount = vi.fn();

    const boot = bootstrapApp(mount);
    d.reject(new Error("CacheStorage roto"));
    await flush();
    await boot;

    expect(mount).toHaveBeenCalledTimes(1);
  });
});

describe("barrera de identidad nueva (H-051)", () => {
  it("login no publica token ni usuario antes de terminar la purga", async () => {
    const d = deferred();
    clearPrivateCaches.mockReturnValue(d.promise);
    fetch.mockImplementation((url) =>
      url.includes("/auth/login")
        ? json({ token: "tok-1", user: { id: 1, email: "a@b.c" } })
        : json({ message: "nf" }, 404),
    );

    let auth;
    renderAuth((a) => (auth = a));

    let loginDone = false;
    const loginPromise = auth
      .login("a@b.c", "secreto")
      .then((u) => ((loginDone = true), u));

    // La respuesta ya llegó pero la purga sigue abierta: nada publicado.
    await flush();
    expect(loginDone).toBe(false);
    expect(localStorage.getItem("token")).toBeNull();
    expect(auth.user).toBeNull();

    d.resolve([]);
    const user = await loginPromise;
    await flush();
    expect(user.email).toBe("a@b.c");
    expect(localStorage.getItem("token")).toBe("tok-1");
    expect(auth.user.email).toBe("a@b.c");
  });

  it("el auto-login de register tampoco publica antes de la purga", async () => {
    const d = deferred();
    clearPrivateCaches.mockReturnValue(d.promise);
    fetch.mockImplementation((url) =>
      url.includes("/auth/register")
        ? json({ token: "tok-2", user: { id: 2, email: "n@b.c" } })
        : json({ message: "nf" }, 404),
    );

    let auth;
    renderAuth((a) => (auth = a));

    let done = false;
    const regPromise = auth
      .register("N", "n@b.c", "secreto", "secreto")
      .then((r) => ((done = true), r));

    await flush();
    expect(done).toBe(false);
    expect(localStorage.getItem("token")).toBeNull();
    expect(auth.user).toBeNull();

    d.resolve([]);
    const result = await regPromise;
    await flush();
    expect(result.autoLogin).toBe(true);
    expect(localStorage.getItem("token")).toBe("tok-2");
    expect(auth.user.email).toBe("n@b.c");
  });
});

describe("limpieza en activate del service worker (H-051)", () => {
  const swCleanupPath = join(
    dirname(fileURLToPath(import.meta.url)),
    "..",
    "..",
    "public",
    "sw-cleanup.js",
  );
  const source = readFileSync(swCleanupPath, "utf8");

  function runActivate(existing) {
    const handlers = {};
    const deleted = [];
    const fakeSelf = {
      addEventListener: (type, fn) => (handlers[type] = fn),
    };
    const fakeCaches = {
      keys: async () => existing,
      delete: async (name) => (deleted.push(name), true),
    };
    // El script se evalúa en el scope del worker: recibe self y caches.
    new Function("self", "caches", source)(fakeSelf, fakeCaches);

    let waited;
    handlers.activate({ waitUntil: (p) => (waited = p) });
    return { waited, deleted };
  }

  it("activate elimina exactamente api-cache y photos-cache", async () => {
    const { waited, deleted } = runActivate([
      "api-cache",
      "photos-cache",
      "workbox-precache-v2-https://app/",
      "cache-de-otra-app",
    ]);

    await waited;
    expect(deleted.sort()).toEqual(["api-cache", "photos-cache"]);
  });

  it("es idempotente y no exige caches presentes", async () => {
    const { waited, deleted } = runActivate([]);
    await waited;
    expect(deleted).toEqual([]);
  });

  it("un fallo de Cache API no impide la activación", async () => {
    const handlers = {};
    const fakeSelf = {
      addEventListener: (type, fn) => (handlers[type] = fn),
    };
    const fakeCaches = {
      keys: async () => {
        throw new Error("roto");
      },
      delete: vi.fn(),
    };
    new Function("self", "caches", source)(fakeSelf, fakeCaches);

    let waited;
    handlers.activate({ waitUntil: (p) => (waited = p) });
    await expect(waited).resolves.toBeUndefined();
  });
});
