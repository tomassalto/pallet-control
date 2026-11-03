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
  // dispositivo compartido. clearPrivateCaches nunca rechaza, así que no
  // puede romper el cierre de sesión en navegadores sin Cache API.
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
    // Defensa adicional ante cambio de usuario en el mismo dispositivo:
    // cualquier resto cacheado de una sesión anterior se descarta antes
    // de establecer la nueva identidad (H-051).
    void clearPrivateCaches();
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
      // Primer usuario (superadmin) — loguear directo
      void clearPrivateCaches();
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
