import { useTheme } from "../context/ThemeContext";

/**
 * Minimal full-screen layout for unauthenticated pages (Login, Register).
 * No sidebar, no header — just a centered card on a neutral background.
 */
export default function AuthLayout({ children }) {
  const { dark, toggle } = useTheme();

  return (
    <div className="min-h-dvh bg-gray-100 flex flex-col items-center justify-center px-4 py-12">
      {/* Theme toggle — top-right */}
      <button
        onClick={toggle}
        className="fixed top-4 right-4 w-9 h-9 flex items-center justify-center rounded-lg text-gray-500 hover:bg-gray-200 transition-colors text-base"
        aria-label={dark ? "Modo claro" : "Modo oscuro"}
      >
        {dark ? "☀️" : "🌙"}
      </button>

      {/* App name */}
      <div className="mb-8 text-center select-none">
        <div className="text-3xl font-bold font-['Montserrat'] text-gray-900 tracking-tight">
          Pallet Control
        </div>
        <div className="text-sm text-gray-500 mt-1">
          Sistema de control de pallets y pedidos
        </div>
      </div>

      {/* Card */}
      <div className="w-full max-w-sm bg-white border border-gray-200 rounded-2xl shadow-sm">
        {children}
      </div>
    </div>
  );
}
