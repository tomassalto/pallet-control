// Si VITE_API_BASE_URL está definido, usarlo (URL completa)
// Si no, usar URL relativa (funciona en desarrollo con proxy de Vite)
const API_BASE = import.meta.env.VITE_API_BASE_URL || "/api/v1";

function getToken() {
  return localStorage.getItem("token");
}

async function apiFetch(path, options = {}) {
  const token = getToken();

  const headers = {
    Accept: "application/json",
    ...(options.body instanceof FormData
      ? {}
      : { "Content-Type": "application/json" }),
    ...(token ? { Authorization: `Bearer ${token}` } : {}),
    ...(options.headers || {}),
  };

  // 30 segundos para subidas de archivos, 15 para el resto
  const timeoutMs = options.body instanceof FormData ? 30_000 : 15_000;
  const controller = new AbortController();
  const timer = setTimeout(() => controller.abort(), timeoutMs);

  const fetchOptions = {
    ...options,
    headers,
    signal: controller.signal,
  };

  let res;
  try {
    res = await fetch(`${API_BASE}${path}`, fetchOptions);
  } finally {
    clearTimeout(timer);
  }

  const data = await res.json().catch(() => null);

  if (!res.ok) {
    // Token expirado o inválido → limpiar sesión y redirigir al login
    if (res.status === 401) {
      localStorage.removeItem("token");
      window.location.href = "/login";
      return; // no llegar al throw — la navegación cancela todo
    }
    const err = new Error(data?.message || `HTTP ${res.status}`);
    err.response = { status: res.status, data };
    throw err;
  }
  return data;
}

export const apiGet = (path) => apiFetch(path);
export const apiPost = (path, body, options = {}) =>
  apiFetch(path, {
    method: "POST",
    body: body instanceof FormData ? body : JSON.stringify(body),
    ...options,
  });

export const apiPatch = (path, body) =>
  apiFetch(path, { method: "PATCH", body: JSON.stringify(body) });

export const apiDelete = (path) => apiFetch(path, { method: "DELETE" });
