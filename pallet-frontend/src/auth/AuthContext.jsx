import { useEffect, useState } from "react";
import { useQueryClient } from "@tanstack/react-query";
import { apiGet, apiPost } from "../api/client";
import { toastError } from "../ui/toast";
import { AuthContext } from "./AuthContextValue";

export function AuthProvider({ children }) {
  const [user, setUser] = useState(null);
  const [booting, setBooting] = useState(true);
  // AuthProvider vive dentro de QueryClientProvider (main.jsx).
  const queryClient = useQueryClient();

  async function refreshMe() {
    try {
      const data = await apiGet("/auth/me");
      setUser(data.user);
    } catch {
      setUser(null);
      localStorage.removeItem("token");
    }
  }

  useEffect(() => {
    (async () => {
      const token = localStorage.getItem("token");
      if (token) await refreshMe();
      setBooting(false);
    })();
  }, []);

  async function login(email, password) {
    const data = await apiPost("/auth/login", { email, password });
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
      localStorage.removeItem("token");
      // La cache puede contener datos privados de la sesión que termina.
      queryClient.cancelQueries();
      queryClient.clear();
      setUser(null);
    }
  }

  return (
    <AuthContext.Provider value={{ user, booting, login, register, logout }}>
      {children}
    </AuthContext.Provider>
  );
}
