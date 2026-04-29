import { useState } from "react";
import { useNavigate, Link } from "react-router-dom";
import { useAuth } from "../auth/AuthContext";
import { toastSuccess, toastError } from "../ui/toast";
import AuthLayout from "../ui/AuthLayout";
import Spinner from "../ui/Spinner";

export default function Register() {
  const nav = useNavigate();
  const { register } = useAuth();
  const [name, setName] = useState("");
  const [email, setEmail] = useState("");
  const [password, setPassword] = useState("");
  const [password2, setPassword2] = useState("");
  const [loading, setLoading] = useState(false);

  async function onSubmit(e) {
    e.preventDefault();
    if (password !== password2) {
      toastError("Las contraseñas no coinciden");
      return;
    }
    setLoading(true);
    try {
      const result = await register(name, email, password, password2);
      if (result.user?.role === "superadmin") {
        toastSuccess("¡Bienvenido! Cuenta de superadmin creada.");
      } else {
        toastSuccess("Cuenta creada. Esperá que un administrador te asigne un rol.");
      }
      nav("/");
    } catch (err) {
      toastError(err.response?.data?.message || err.message || "Error al registrarse");
    } finally {
      setLoading(false);
    }
  }

  return (
    <AuthLayout>
      <form onSubmit={onSubmit} className="p-6 flex flex-col gap-4">
        <h1 className="text-xl font-bold text-gray-900 text-center mb-1">
          Crear cuenta
        </h1>

        <div className="flex flex-col gap-3">
          <input
            value={name}
            onChange={(e) => setName(e.target.value)}
            placeholder="Nombre completo"
            autoComplete="name"
            required
            className="w-full px-3 py-2.5 rounded-lg border border-gray-300 bg-gray-50 text-sm focus:outline-none focus:ring-2 focus:ring-gray-900 focus:border-transparent transition-shadow"
          />
          <input
            value={email}
            onChange={(e) => setEmail(e.target.value)}
            type="email"
            placeholder="Email"
            autoComplete="email"
            required
            className="w-full px-3 py-2.5 rounded-lg border border-gray-300 bg-gray-50 text-sm focus:outline-none focus:ring-2 focus:ring-gray-900 focus:border-transparent transition-shadow"
          />
          <input
            value={password}
            onChange={(e) => setPassword(e.target.value)}
            type="password"
            placeholder="Contraseña"
            autoComplete="new-password"
            required
            className="w-full px-3 py-2.5 rounded-lg border border-gray-300 bg-gray-50 text-sm focus:outline-none focus:ring-2 focus:ring-gray-900 focus:border-transparent transition-shadow"
          />
          <input
            value={password2}
            onChange={(e) => setPassword2(e.target.value)}
            type="password"
            placeholder="Repetir contraseña"
            autoComplete="new-password"
            required
            className="w-full px-3 py-2.5 rounded-lg border border-gray-300 bg-gray-50 text-sm focus:outline-none focus:ring-2 focus:ring-gray-900 focus:border-transparent transition-shadow"
          />
        </div>

        <button
          type="submit"
          disabled={loading}
          className="w-full py-2.5 rounded-xl bg-gray-900 text-white text-sm font-semibold hover:bg-gray-700 disabled:opacity-60 transition-colors flex items-center justify-center gap-2 mt-1"
        >
          {loading ? <><Spinner size="sm" /> Creando cuenta…</> : "Registrarme"}
        </button>

        <p className="text-center text-sm text-gray-500">
          ¿Ya tenés cuenta?{" "}
          <Link to="/login" className="text-gray-900 font-medium hover:underline">
            Entrar
          </Link>
        </p>
      </form>
    </AuthLayout>
  );
}
