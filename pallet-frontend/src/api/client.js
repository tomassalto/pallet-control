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

  // Para FormData, no establecer timeout (las fotos pueden tardar más)
  const fetchOptions = {
    ...options,
    headers,
    ...(options.body instanceof FormData ? { signal: null } : {}), // Sin timeout para FormData
  };

  const res = await fetch(`${API_BASE}${path}`, fetchOptions);

  const data = await res.json().catch(() => null);

  if (!res.ok) {
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
