import { useState } from "react";
import { useNavigate, Link } from "react-router-dom";
import { useAuth } from "../auth/AuthContext";
import { toastSuccess, toastError } from "../ui/toast";
import Button from "../ui/Button";
import Title from "../ui/Title";

export default function Register() {
  const nav = useNavigate();
  const { register } = useAuth();
  const [name, setName] = useState("");
  const [email, setEmail] = useState("");
  const [password, setPassword] = useState("");
  const [password2, setPassword2] = useState("");
  const [loading, setLoading] = useState(false);
  const [needsVerification, setNeedsVerification] = useState(false);

  async function onSubmit(e) {
    e.preventDefault();
    if (password !== password2) {
      toastError("Las contraseñas no coinciden");
      return;
    }

    setLoading(true);
    try {
      const result = await register(name, email, password, password2);

      if (result.autoLogin) {
        // Primer usuario (superadmin) — ya logueado, ir al inicio
        toastSuccess("¡Bienvenido! Cuenta de superadmin creada.");
        nav("/");
      } else {
        // Usuarios siguientes — necesitan verificar email
        setNeedsVerification(true);
        toastSuccess("Cuenta creada. Revisá tu correo para activar tu cuenta.");
      }
    } catch (err) {
      toastError(err.response?.data?.message || err.message || "Error");
    } finally {
      setLoading(false);
    }
  }

  if (needsVerification) {
    return (
      <div className="min-h-dvh flex flex-col gap-6 items-center justify-center mt-[-50px] mb-[-40px]">
        <Title size="4xl">Revisá tu correo</Title>
        <div className="bg-green-50 border border-green-200 text-sm rounded-2xl p-6 space-y-3 w-full">
          <p className="font-semibold text-green-900 text-base">¡Cuenta creada!</p>
          <p className="text-green-800">
            Te enviamos un enlace de verificación a <strong>{email}</strong>.
            Hacé clic en el link del correo para activar tu cuenta y poder iniciar sesión.
          </p>
          <p className="text-green-700 text-xs">
            El link expira en 48 horas. Si no recibís el correo, revisá la carpeta de spam.
          </p>
        </div>
        <div className="text-sm">
          ¿Ya verificaste?{" "}
          <Link className="underline" to="/login">
            Iniciar sesión
          </Link>
        </div>
      </div>
    );
  }

  return (
    <div className="min-h-dvh flex flex-col gap-6 items-center justify-center mt-[-50px] mb-[-40px]">
      <Title size="4xl">Crear cuenta</Title>

      <form
        onSubmit={onSubmit}
        className="bg-white py-6 px-4 border border-border rounded-2xl flex flex-col gap-4 w-full items-center"
      >
        <input
          value={name}
          onChange={(e) => setName(e.target.value)}
          placeholder="Nombre"
          className="w-full px-3 py-3 bg-[#eaeaea]"
        />
        <input
          value={email}
          onChange={(e) => setEmail(e.target.value)}
          type="email"
          placeholder="Email"
          className="w-full px-3 py-3 bg-[#eaeaea]"
        />
        <input
          value={password}
          onChange={(e) => setPassword(e.target.value)}
          type="password"
          placeholder="Contraseña"
          className="w-full px-3 py-3 bg-[#eaeaea]"
        />
        <input
          value={password2}
          onChange={(e) => setPassword2(e.target.value)}
          type="password"
          placeholder="Repetir contraseña"
          className="w-full px-3 py-3 bg-[#eaeaea]"
        />

        <Button
          disabled={loading}
          text={loading ? "Creando..." : "Registrarme"}
          size="md"
          color="black"
          className="w-3/4 rounded-xl"
          type="submit"
        />
      </form>

      <div className="text-sm">
        ¿Ya tenés cuenta?{" "}
        <Link className="underline" to="/login">
          Entrar
        </Link>
      </div>
    </div>
  );
}
