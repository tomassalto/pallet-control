import { createContext, useContext, useEffect, useState } from "react";
import { apiGet, apiPost } from "../api/client";
import { toastError } from "../ui/toast";

const AuthContext = createContext(null);

export function AuthProvider({ children }) {
  const [user, setUser] = useState(null);
  const [booting, setBooting] = useState(true);

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

  async function register(name, email, password, password_confirmation) {
    const data = await apiPost("/auth/register", {
      name,
      email,
      password,
      password_confirmation,
    });
    localStorage.setItem("token", data.token);
    setUser(data.user);
    return data.user;
  }

  async function logout() {
    try {
      await apiPost("/auth/logout", {});
    } catch (e) {
      // si falla igual limpiamos local
      toastError(e?.message || "Error en logout");
    } finally {
      localStorage.removeItem("token");
      setUser(null);
    }
  }

  return (
    <AuthContext.Provider value={{ user, booting, login, register, logout }}>
      {children}
    </AuthContext.Provider>
  );
}

export function useAuth() {
  return useContext(AuthContext);
}
