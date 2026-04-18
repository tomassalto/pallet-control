import { useState } from "react";
import { Link } from "react-router-dom";
import { apiPost } from "../api/client";
import { toastSuccess, toastError } from "../ui/toast";
import Button from "../ui/Button";
import Title from "../ui/Title";

export default function Register() {
  const [name, setName] = useState("");
  const [email, setEmail] = useState("");
  const [password, setPassword] = useState("");
  const [password2, setPassword2] = useState("");
  const [loading, setLoading] = useState(false);
  const [verificationUrl, setVerificationUrl] = useState("");
  const [createdEmail, setCreatedEmail] = useState("");

  async function onSubmit(e) {
    e.preventDefault();
    if (password !== password2) {
      toastError("Las contraseñas no coinciden");
      return;
    }

    setLoading(true);
    try {
      const res = await apiPost("/auth/register", {
        name,
        email,
        password,
        password_confirmation: password2,
      });

      setVerificationUrl(res.verification_url);
      setCreatedEmail(email);
      toastSuccess("Cuenta creada. Revisá tu correo para verificarla.");
    } catch (err) {
      toastError(err.response?.data?.message || err.message || "Error");
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

      {verificationUrl && (
        <div className="bg-green-50 border border-green-200 text-sm rounded-2xl p-4 space-y-2">
          <p className="font-semibold text-green-900">Verificá tu cuenta</p>
          <p className="text-green-800">
            Te enviamos un enlace de verificación. Si no te llega, podés abrirlo
            desde acá.
          </p>
          <div className="space-y-1">
            {createdEmail && (
              <p className="text-green-900 font-medium">
                Correo: {createdEmail}
              </p>
            )}
            <a
              href={verificationUrl}
              target="_blank"
              rel="noreferrer"
              className="underline text-green-900 break-all"
            >
              Abrir enlace de verificación
            </a>
          </div>
        </div>
      )}

      <div className="text-sm">
        ¿Ya tenés cuenta?{" "}
        <Link className="underline" to="/login">
          Entrar
        </Link>
      </div>
    </div>
  );
}
