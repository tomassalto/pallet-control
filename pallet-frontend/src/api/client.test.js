import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { apiGet, apiPost, setOnUnauthorized } from "./client";

function fetchOk(payload = { ok: true }) {
  return Promise.resolve(
    new Response(JSON.stringify(payload), {
      status: 200,
      headers: { "Content-Type": "application/json" },
    }),
  );
}

function fetchError(status, payload, { json = true } = {}) {
  return Promise.resolve(
    new Response(json ? JSON.stringify(payload) : payload, {
      status,
      headers: json ? { "Content-Type": "application/json" } : undefined,
    }),
  );
}

beforeEach(() => {
  vi.stubGlobal("fetch", vi.fn());
});

afterEach(() => {
  setOnUnauthorized(null);
});

describe("cabeceras", () => {
  it("envía Authorization Bearer con el token de localStorage", async () => {
    localStorage.setItem("token", "tok-123");
    fetch.mockImplementation(() => fetchOk());

    await apiGet("/recurso");

    const [, options] = fetch.mock.calls[0];
    expect(options.headers.Authorization).toBe("Bearer tok-123");
  });

  it("no envía Authorization sin token", async () => {
    fetch.mockImplementation(() => fetchOk());

    await apiGet("/recurso");

    const [, options] = fetch.mock.calls[0];
    expect(options.headers.Authorization).toBeUndefined();
  });

  it("usa Content-Type JSON para objetos pero no para FormData", async () => {
    fetch.mockImplementation(() => fetchOk());

    await apiPost("/recurso", { a: 1 });
    const [, jsonOptions] = fetch.mock.calls[0];
    expect(jsonOptions.headers["Content-Type"]).toBe("application/json");
    expect(jsonOptions.body).toBe('{"a":1}');

    await apiPost("/subida", new FormData());
    const [, formOptions] = fetch.mock.calls[1];
    expect(formOptions.headers["Content-Type"]).toBeUndefined();
  });
});

describe("respuestas", () => {
  it("devuelve el JSON parseado cuando la respuesta es OK", async () => {
    fetch.mockImplementation(() => fetchOk({ total: 3 }));

    await expect(apiGet("/recurso")).resolves.toEqual({ total: 3 });
  });

  it("lanza Error con message del body y err.response para no-OK", async () => {
    fetch.mockImplementation(() =>
      fetchError(422, { message: "Datos inválidos", errors: { x: ["mal"] } }),
    );

    const err = await apiGet("/recurso").catch((e) => e);
    expect(err).toBeInstanceOf(Error);
    expect(err.message).toBe("Datos inválidos");
    expect(err.response.status).toBe(422);
    expect(err.response.data).toEqual({
      message: "Datos inválidos",
      errors: { x: ["mal"] },
    });
  });

  it("usa HTTP <status> cuando el cuerpo de error no es JSON", async () => {
    fetch.mockImplementation(() => fetchError(500, "<html>oops</html>", { json: false }));

    const err = await apiGet("/recurso").catch((e) => e);
    expect(err.message).toBe("HTTP 500");
    expect(err.response.status).toBe(500);
  });

  it("en 401 con handler registrado lo invoca una vez y lanza 'Sesión expirada'", async () => {
    localStorage.setItem("token", "tok-viejo");
    const onUnauthorized = vi.fn(() => localStorage.removeItem("token"));
    setOnUnauthorized(onUnauthorized);
    fetch.mockImplementation(() => fetchError(401, { message: "Unauthenticated." }));

    const err = await apiGet("/recurso").catch((e) => e);

    expect(err.message).toBe("Sesión expirada");
    expect(err.response.status).toBe(401);
    expect(onUnauthorized).toHaveBeenCalledTimes(1);
  });

  it("en 401 sin handler registrado limpia el token", async () => {
    localStorage.setItem("token", "tok-viejo");
    fetch.mockImplementation(() => fetchError(401, { message: "Unauthenticated." }));

    const err = await apiGet("/recurso").catch((e) => e);

    expect(err.message).toBe("Sesión expirada");
    expect(localStorage.getItem("token")).toBeNull();
  });

  it("401s concurrentes con el mismo token invocan el handler una sola vez", async () => {
    localStorage.setItem("token", "tok-viejo");
    const onUnauthorized = vi.fn(() => localStorage.removeItem("token"));
    setOnUnauthorized(onUnauthorized);
    fetch.mockImplementation(() => fetchError(401, { message: "Unauthenticated." }));

    const [err1, err2] = await Promise.all([
      apiGet("/recurso-a").catch((e) => e),
      apiGet("/recurso-b").catch((e) => e),
    ]);

    expect(err1.message).toBe("Sesión expirada");
    expect(err2.message).toBe("Sesión expirada");
    expect(onUnauthorized).toHaveBeenCalledTimes(1);
  });

  it("un 401 tardío de un token viejo no invalida la sesión nueva", async () => {
    localStorage.setItem("token", "tok-viejo");
    const onUnauthorized = vi.fn(() => localStorage.removeItem("token"));
    setOnUnauthorized(onUnauthorized);

    let resolveFetch;
    fetch.mockImplementation(
      () => new Promise((resolve) => (resolveFetch = resolve)),
    );
    const promise = apiGet("/recurso");

    // Re-login antes de que llegue el 401: el token ya es otro.
    localStorage.setItem("token", "tok-nuevo");
    resolveFetch(
      new Response(JSON.stringify({ message: "Unauthenticated." }), {
        status: 401,
        headers: { "Content-Type": "application/json" },
      }),
    );

    const err = await promise.catch((e) => e);
    expect(err.message).toBe("Sesión expirada");
    expect(onUnauthorized).not.toHaveBeenCalled();
    expect(localStorage.getItem("token")).toBe("tok-nuevo");
  });

  it("401 sin token no invoca el handler y conserva el mensaje del body", async () => {
    const onUnauthorized = vi.fn();
    setOnUnauthorized(onUnauthorized);
    fetch.mockImplementation(() =>
      fetchError(401, { message: "Credenciales inválidas." }),
    );

    const err = await apiPost("/auth/login", {}).catch((e) => e);

    expect(err.message).toBe("Credenciales inválidas.");
    expect(onUnauthorized).not.toHaveBeenCalled();
  });
});

describe("timeout por abort", () => {
  it("aborta a los 15s en llamadas JSON", async () => {
    vi.useFakeTimers();
    fetch.mockImplementation((url, options) => {
      return new Promise((resolve) => {
        options.signal.addEventListener("abort", () => {
          resolve(fetchOk());
        });
      });
    });

    const promise = apiGet("/recurso");
    await vi.advanceTimersByTimeAsync(15_000);
    await promise;

    const [, options] = fetch.mock.calls[0];
    expect(options.signal.aborted).toBe(true);
  });

  it("aborta a los 30s en llamadas con FormData", async () => {
    vi.useFakeTimers();
    fetch.mockImplementation((url, options) => {
      return new Promise((resolve) => {
        options.signal.addEventListener("abort", () => {
          resolve(fetchOk());
        });
      });
    });

    const promise = apiPost("/subida", new FormData());
    await vi.advanceTimersByTimeAsync(14_999);
    let [, options] = fetch.mock.calls[0];
    expect(options.signal.aborted).toBe(false);

    await vi.advanceTimersByTimeAsync(15_001);
    await promise;
    [, options] = fetch.mock.calls[0];
    expect(options.signal.aborted).toBe(true);
  });
});
