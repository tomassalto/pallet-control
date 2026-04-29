import { useNavigate } from "react-router-dom";

/**
 * BackButton — usa navigate(-1) para respetar el historial real de navegación.
 * Si no hay historial previo dentro de la app (el usuario llegó directo a la URL),
 * cae al `to` como fallback.
 */
export default function BackButton({ to = "/", className = "" }) {
  const navigate = useNavigate();

  function handleBack() {
    // Si hay historial previo, volver atrás
    if (window.history.length > 1) {
      navigate(-1);
    } else {
      // Sin historial (URL directa / bookmark), ir al destino de fallback
      navigate(to);
    }
  }

  return (
    <button
      onClick={handleBack}
      className={`inline-flex items-center gap-1.5 px-3 py-1.5 rounded-xl bg-gray-100 dark:bg-gray-800 text-gray-600 dark:text-gray-400 hover:bg-gray-200 dark:hover:bg-gray-700 hover:text-gray-900 dark:hover:text-white text-sm font-semibold transition-colors ${className}`}
    >
      <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" strokeWidth={2.5} stroke="currentColor">
        <path strokeLinecap="round" strokeLinejoin="round" d="M15 19l-7-7 7-7" />
      </svg>
      <span>Volver</span>
    </button>
  );
}
