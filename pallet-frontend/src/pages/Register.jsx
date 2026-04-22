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
