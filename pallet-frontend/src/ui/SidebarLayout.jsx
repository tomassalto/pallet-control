import { useMemo, useState } from "react";
import { Link, useLocation } from "react-router-dom";
import { useAuth } from "../auth/AuthContext";
import { useTheme } from "../context/ThemeContext";

function cx(...arr) {
  return arr.filter(Boolean).join(" ");
}

const NAV_ICON = {
  "/": "🏠",
  "/orders/new": "➕",
  "/pallets": "📦",
  "/orders": "🗒️",
  "/clients": "👤",
  "/productos": "🔍",
  "/logs": "📋",
  "/admin/users": "👥",
};

export default function SidebarLayout({ title = "Pallet Control", children }) {
  // mobileOpen only matters on small screens; on lg+ the sidebar is always visible
  const [mobileOpen, setMobileOpen] = useState(false);
  const { pathname } = useLocation();
  const { user, logout } = useAuth() || {};
  const { dark, toggle } = useTheme();
  const [loggingOut, setLoggingOut] = useState(false);

  async function handleLogout() {
    setLoggingOut(true);
    await logout();
    setMobileOpen(false);
    setLoggingOut(false);
  }

  const isPending = user && user.role === null;

  const items = useMemo(
    () => [
      { to: "/", label: "Inicio" },
      ...(!isPending ? [{ to: "/orders/new", label: "Empezar pedido" }] : []),
      { to: "/pallets", label: "Mis pallets" },
      { to: "/orders", label: "Mis pedidos" },
      { to: "/clients", label: "Mis clientes" },
      { to: "/productos", label: "Buscar producto" },
      { to: "/logs", label: "Logs" },
      ...(["admin", "superadmin"].includes(user?.role)
        ? [{ to: "/admin/users", label: "Usuarios" }]
        : []),
    ],
    [user, isPending],
  );

  const closeMobile = () => setMobileOpen(false);

  return (
    <div className="min-h-dvh relative bg-gray-100 dark:bg-dark-gray text-gray-900 dark:text-gray-100">
      {/* ── Mobile overlay (only on small screens) ── */}
      {mobileOpen && (
        <div
          className="fixed inset-0 z-40 bg-black/60 backdrop-blur-sm lg:hidden"
          onClick={closeMobile}
          aria-hidden
        />
      )}

      {/* ══════════════════════════════════════════════════
          SIDEBAR
          - sm/md: slide-in drawer (z-50, translated)
          - lg+: always visible (translate-x-0, z-30)
      ══════════════════════════════════════════════════ */}
      <aside
        className={cx(
          // positioning + size
          "fixed top-0 left-0 bottom-0 z-50 w-64 flex flex-col",
          // colours
          "bg-white border-r border-gray-200",
          // animation
          "transition-transform duration-200 ease-in-out",
          // mobile: toggle; lg: always open
          mobileOpen ? "translate-x-0" : "-translate-x-full lg:translate-x-0",
          // lower z on lg (header can overlap if needed)
          "lg:z-30 lg:top-0",
        )}
        aria-label="Navegación principal"
      >
        {/* Sidebar header */}
        <div className="h-14 px-4 border-b border-gray-200 flex items-center justify-between flex-shrink-0">
          <span className="font-bold text-base font-['Montserrat'] tracking-tight select-none">
            Pallet Control
          </span>
          {/* Close button — mobile only */}
          <button
            onClick={closeMobile}
            className="lg:hidden w-8 h-8 flex items-center justify-center rounded-lg text-gray-500 hover:bg-gray-100 transition-colors"
            aria-label="Cerrar menú"
          >
            ✕
          </button>
        </div>

        {/* Nav links */}
        <nav className="flex-1 overflow-y-auto py-3 px-2 space-y-0.5">
          {items.map((it) => {
            const active =
              it.to === "/" ? pathname === "/" : pathname.startsWith(it.to);
            const icon = NAV_ICON[it.to] ?? "•";
            return (
              <Link
                key={it.to}
                to={it.to}
                onClick={closeMobile}
                className={cx(
                  "flex items-center gap-3 rounded-xl px-3 py-2.5 text-sm font-medium transition-colors",
                  active
                    ? "bg-gray-900 text-white"
                    : "text-gray-700 hover:bg-gray-100 hover:text-gray-900",
                )}
              >
                <span className="text-base leading-none w-5 text-center select-none">
                  {icon}
                </span>
                <span>{it.label}</span>
              </Link>
            );
          })}
        </nav>

        {/* Sidebar footer */}
        <div className="px-2 py-3 border-t border-gray-200 space-y-1 flex-shrink-0">
          {/* Theme toggle */}
          <button
            onClick={toggle}
            className="w-full flex items-center gap-3 rounded-xl px-3 py-2.5 text-sm font-medium text-gray-700 hover:bg-gray-100 hover:text-gray-900 transition-colors"
          >
            <span className="text-base leading-none w-5 text-center select-none">
              {dark ? "☀️" : "🌙"}
            </span>
            <span>{dark ? "Modo claro" : "Modo oscuro"}</span>
          </button>

          {/* User info + logout */}
          {user && (
            <div className="flex items-center gap-2 rounded-xl px-3 py-2.5">
              <div className="flex-1 min-w-0">
                <div className="text-sm font-semibold truncate text-gray-900">
                  {user.name}
                </div>
                <div className="text-xs text-gray-500 truncate">
                  {user.email}
                </div>
              </div>
              <button
                onClick={handleLogout}
                disabled={loggingOut}
                className="shrink-0 text-xs px-2.5 py-1.5 rounded-lg border border-gray-200 text-gray-600 hover:bg-gray-100 hover:text-gray-900 disabled:opacity-50 transition-colors"
              >
                {loggingOut ? "…" : "Salir"}
              </button>
            </div>
          )}
        </div>
      </aside>

      {/* ══════════════════════════════════════════════════
          HEADER  (starts at left edge on mobile; offset on lg+)
      ══════════════════════════════════════════════════ */}
      <header className="fixed top-0 right-0 left-0 lg:left-64 z-30 h-14 bg-white border-b border-gray-200 flex items-center px-4 gap-3">
        {/* Hamburger — mobile only */}
        <button
          onClick={() => setMobileOpen(true)}
          className="lg:hidden w-9 h-9 flex items-center justify-center rounded-lg text-gray-600 hover:bg-gray-100 transition-colors flex-shrink-0"
          aria-label="Abrir menú"
        >
          <svg viewBox="0 0 20 20" fill="currentColor" className="w-5 h-5">
            <path
              fillRule="evenodd"
              d="M3 5h14a1 1 0 110 2H3a1 1 0 110-2zm0 4h14a1 1 0 110 2H3a1 1 0 110-2zm0 4h14a1 1 0 110 2H3a1 1 0 110-2z"
              clipRule="evenodd"
            />
          </svg>
        </button>

        {/* Page title */}
        <div className="flex-1 min-w-0">
          <div className="font-semibold text-base truncate font-['Montserrat']">
            {title}
          </div>
        </div>

        {/* Right actions */}
        <div className="flex items-center gap-2 flex-shrink-0">
          {/* Theme toggle — visible in header on mobile (not in sidebar footer) */}
          <button
            onClick={toggle}
            className="lg:hidden w-9 h-9 flex items-center justify-center rounded-lg text-gray-600 hover:bg-gray-100 transition-colors text-base"
            aria-label={dark ? "Modo claro" : "Modo oscuro"}
          >
            {dark ? "☀️" : "🌙"}
          </button>

          {/* User greeting */}
          {user && (
            <div className="hidden sm:block text-right text-xs leading-tight max-w-[110px]">
              <div className="text-gray-500">Hola,</div>
              <div className="font-semibold text-gray-900 truncate">
                {user.name}
              </div>
            </div>
          )}
        </div>
      </header>

      {/* ══════════════════════════════════════════════════
          MAIN CONTENT
          - pt-14  accounts for fixed header height
          - lg:pl-64  accounts for permanent sidebar
          - max-w scales with breakpoints
      ══════════════════════════════════════════════════ */}
      <main className="pt-14 lg:pl-64 min-h-screen flex flex-col">
        <div
          className="flex-1 w-full mx-auto px-4 sm:px-6 lg:px-8 py-6 min-h-screen
                        max-w-xl sm:max-w-2xl md:max-w-3xl lg:max-w-4xl xl:max-w-5xl 2xl:max-w-6xl"
        >
          {/* Pending role banner */}
          {isPending && (
            <div className="mb-5 rounded-xl border border-amber-300 bg-amber-50 px-4 py-3 text-sm text-amber-800">
              <p className="font-semibold">Cuenta pendiente de activación</p>
              <p className="mt-0.5 text-xs opacity-80">
                Podés ver la información, pero necesitás que un administrador te
                asigne un rol para crear o modificar datos.
              </p>
            </div>
          )}

          {children}
        </div>
      </main>
    </div>
  );
}
