import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { apiGet, apiPost, setNavigate } from "./client";

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
  setNavigate(null);
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

  it("en 401 limpia el token, navega a /login y lanza 'Sesión expirada'", async () => {
    localStorage.setItem("token", "tok-viejo");
    const navigate = vi.fn();
    setNavigate(navigate);
    fetch.mockImplementation(() => fetchError(401, { message: "Unauthenticated." }));

    const err = await apiGet("/recurso").catch((e) => e);

    expect(err.message).toBe("Sesión expirada");
    expect(err.response.status).toBe(401);
    expect(localStorage.getItem("token")).toBeNull();
    expect(navigate).toHaveBeenCalledWith("/login", { replace: true });
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
