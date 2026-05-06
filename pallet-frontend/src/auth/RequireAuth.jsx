import { Navigate } from "react-router-dom";
import { useAuth } from "./AuthContext";
import { PageSpinner } from "../ui/Spinner";

export default function RequireAuth({ children }) {
  const { user, booting } = useAuth();

  // Mientras el contexto verifica el token con /auth/me, no redirigir todavía
  if (booting) return <PageSpinner />;

  if (!user) return <Navigate to="/login" replace />;

  return children;
}
