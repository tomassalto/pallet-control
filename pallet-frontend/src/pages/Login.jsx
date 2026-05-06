import { useState, useEffect } from "react";
import { useNavigate, Link, useLocation } from "react-router-dom";
import { useAuth } from "../auth/AuthContext";
import { toastSuccess, toastError } from "../ui/toast";
import AuthLayout from "../ui/AuthLayout";
import Spinner from "../ui/Spinner";

export default function Login() {
  const nav = useNavigate();
  const { search } = useLocation();
  const { login } = useAuth();
  const [email, setEmail] = useState("");
  const [password, setPassword] = useState("");
  const [loading, setLoading] = useState(false);
  const [showPassword, setShowPassword] = useState(false);

  useEffect(() => {
    const params = new URLSearchParams(search);
    if (params.get("verified") === "1") {
      toastSuccess("✅ Cuenta verificada. Ya podés iniciar sesión.");
    } else if (params.get("error") === "link-expirado") {
      toastError("El link de verificación expiró. Solicitá uno nuevo.");
    } else if (params.get("error") === "link-invalido") {
      toastError("El link de verificación es inválido.");
    }
    if (params.has("verified") || params.has("error")) {
      window.history.replaceState({}, "", "/login");
    }
  }, [search]);

  async function onSubmit(e) {
    e.preventDefault();
    setLoading(true);
    try {
      await login(email, password);
      toastSuccess("Sesión iniciada");
      nav("/");
    } catch (err) {
      toastError(err.response?.data?.message || err.message || "Error al iniciar sesión");
    } finally {
      setLoading(false);
    }
  }

  return (
    <AuthLayout>
      <form onSubmit={onSubmit} className="p-6 flex flex-col gap-4">
        <h1 className="text-xl font-bold text-gray-900 text-center mb-1">
          Iniciar sesión
        </h1>

        <div className="flex flex-col gap-3">
          <input
            value={email}
            onChange={(e) => setEmail(e.target.value)}
            type="email"
            placeholder="Email"
            autoComplete="email"
            required
            className="w-full px-3 py-2.5 rounded-lg border border-gray-300 bg-gray-50 text-sm focus:outline-none focus:ring-2 focus:ring-gray-900 focus:border-transparent transition-shadow"
          />

          <div className="relative">
            <input
              value={password}
              onChange={(e) => setPassword(e.target.value)}
              type={showPassword ? "text" : "password"}
              placeholder="Contraseña"
              autoComplete="current-password"
              required
              className="w-full px-3 py-2.5 pr-10 rounded-lg border border-gray-300 bg-gray-50 text-sm focus:outline-none focus:ring-2 focus:ring-gray-900 focus:border-transparent transition-shadow"
            />
            <button
              type="button"
              onClick={() => setShowPassword(!showPassword)}
              className="absolute right-3 top-1/2 -translate-y-1/2 text-gray-400 hover:text-gray-700 transition-colors"
              aria-label={showPassword ? "Ocultar contraseña" : "Mostrar contraseña"}
            >
              {showPassword ? (
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" strokeWidth={1.5} stroke="currentColor" className="w-5 h-5">
                  <path strokeLinecap="round" strokeLinejoin="round" d="M3.98 8.223A10.477 10.477 0 001.934 12C3.226 16.338 7.244 19.5 12 19.5c.993 0 1.953-.138 2.863-.395M6.228 6.228A10.45 10.45 0 0112 4.5c4.756 0 8.773 3.162 10.065 7.498a10.523 10.523 0 01-4.293 5.774M6.228 6.228L3 3m3.228 3.228l-.792-.792M16.5 12a4.5 4.5 0 11-9 0 4.5 4.5 0 019 0z" />
                </svg>
              ) : (
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" strokeWidth={1.5} stroke="currentColor" className="w-5 h-5">
                  <path strokeLinecap="round" strokeLinejoin="round" d="M2.036 12.322a1.012 1.012 0 010-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.012 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.012-9.963-7.178z" />
                  <path strokeLinecap="round" strokeLinejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                </svg>
              )}
            </button>
          </div>
        </div>

        <button
          type="submit"
          disabled={loading}
          className="w-full py-2.5 rounded-xl bg-gray-900 text-white text-sm font-semibold hover:bg-gray-700 disabled:opacity-60 transition-colors flex items-center justify-center gap-2 mt-1"
        >
          {loading ? <><Spinner size="sm" /> Entrando…</> : "Entrar"}
        </button>

        <p className="text-center text-sm text-gray-500">
          ¿No tenés cuenta?{" "}
          <Link to="/register" className="text-gray-900 font-medium hover:underline">
            Registrate
          </Link>
        </p>
      </form>
    </AuthLayout>
  );
}
