import { useCallback, useEffect, useState } from "react";
import { useQueryClient } from "@tanstack/react-query";
import { apiGet, apiPost, setOnUnauthorized } from "../api/client";
import { clearPrivateCaches } from "./privateCaches";
import { toastError } from "../ui/toast";
import { AuthContext } from "./AuthContextValue";

export function AuthProvider({ children }) {
  const [user, setUser] = useState(null);
  const [booting, setBooting] = useState(true);
  // AuthProvider vive dentro de QueryClientProvider (main.jsx).
  const queryClient = useQueryClient();

  // Única autoridad de invalidación de sesión: un 401 autenticado
  // (registrado en api/client) o un logout terminan acá. Idempotente.
  // También purga las caches privadas del service worker (H-051): Cache
  // Storage no se limpia solo y sobreviviría al cambio de usuario en un
  // dispositivo compartido. Es fire-and-forget a propósito: el borrado
  // es inherentemente asíncrono y la barrera para una identidad nueva ya
  // la dan el bootstrap y login/register, que sí esperan la purga.
  // clearPrivateCaches nunca rechaza, así que no puede romper el cierre
  // de sesión en navegadores sin Cache API.
  const endSession = useCallback(() => {
    localStorage.removeItem("token");
    queryClient.cancelQueries();
    queryClient.clear();
    setUser(null);
    void clearPrivateCaches();
  }, [queryClient]);

  useEffect(() => {
    setOnUnauthorized(endSession);
    return () => setOnUnauthorized(null);
  }, [endSession]);

  const refreshMe = useCallback(async () => {
    try {
      const data = await apiGet("/auth/me");
      setUser(data.user);
    } catch {
      endSession();
    }
  }, [endSession]);

  useEffect(() => {
    (async () => {
      const token = localStorage.getItem("token");
      if (token) await refreshMe();
      setBooting(false);
    })();
  }, [refreshMe]);

  async function login(email, password) {
    const data = await apiPost("/auth/login", { email, password });
    // Barrera ante cambio de usuario en el mismo dispositivo (H-051): la
    // identidad nueva no se publica hasta que termine la purga privada.
    // El catch evita que una Cache API rota bloquee el login.
    await clearPrivateCaches().catch(() => {});
    localStorage.setItem("token", data.token);
    setUser(data.user);
    return data.user;
  }

  /**
   * register() returns:
   *   { needsVerification: true }   → user must check email
   *   { token, user }               → first user (superadmin), auto-logged in
   */
  async function register(name, email, password, password_confirmation) {
    const data = await apiPost("/auth/register", {
      name,
      email,
      password,
      password_confirmation,
    });

    if (data.token) {
      // Primer usuario (superadmin) — misma barrera que login (H-051)
      await clearPrivateCaches().catch(() => {});
      localStorage.setItem("token", data.token);
      setUser(data.user);
      return { autoLogin: true, user: data.user };
    }

    // Usuarios siguientes — necesitan verificar email
    return { autoLogin: false };
  }

  async function logout() {
    try {
      await apiPost("/auth/logout", {});
    } catch (e) {
      toastError(e?.message || "Error en logout");
    } finally {
      endSession();
    }
  }

  return (
    <AuthContext.Provider value={{ user, booting, login, register, logout }}>
      {children}
    </AuthContext.Provider>
  );
}
