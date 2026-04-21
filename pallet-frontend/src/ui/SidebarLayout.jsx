import { useMemo, useState } from "react";
import { Link, useLocation } from "react-router-dom";
import { useAuth } from "../auth/AuthContext";
import { useTheme } from "../context/ThemeContext";
import Title from "./Title";

function cx(...arr) {
  return arr.filter(Boolean).join(" ");
}

export default function SidebarLayout({ title = "Pallet Control", children }) {
  const [open, setOpen] = useState(false);
  const { pathname } = useLocation();
  const { user } = useAuth() || {};
  const { dark, toggle } = useTheme();

  const isPending = user && user.role === null;

  const items = useMemo(
    () => [
      { to: "/", label: "Inicio" },
      // Ocultar acciones de escritura a usuarios sin rol
      ...(!isPending ? [
        { to: "/orders/new", label: "Empezar pedido" },
      ] : []),
      { to: "/pallets", label: "Mis pallets" },
      { to: "/orders", label: "Mis pedidos" },
      { to: "/clients", label: "Mis clientes" },
      { to: "/productos", label: "Buscar producto" },
      { to: "/logs", label: "Logs" },
      ...(['admin','superadmin'].includes(user?.role)
        ? [{ to: "/admin/users", label: "👥 Usuarios" }]
        : []),
    ],
    [user, isPending]
  );

  return (
    <div className="min-h-dvh relative w-full text-gray-900 flex flex-col overflow-hidden">
      {/* Top bar */}
      <header className="fixed top-0 left-0 right-0 z-40 bg-white border-b shrink-0">
        <div className="max-w-md mx-auto p-4 flex items-center justify-between">
          <div className="flex items-center justify-between w-full">
            <button
              onClick={() => setOpen(true)}
              className="w-7 h-10 grid justify-between items-center bg-white active:scale-[0.98]"
              aria-label="Abrir menú"
            >
              <span className="text-xl leading-none">☰</span>
            </button>

            <div className="min-w-0">
              <div className="font-semibold truncate text-xl font-['Montserrat']">
                {title}
              </div>
            </div>

            <div className="flex items-center gap-2 flex-shrink-0">
              {/* Theme toggle */}
              <button
                onClick={toggle}
                className="w-8 h-8 flex items-center justify-center text-base rounded-lg hover:bg-gray-100 transition-colors"
                aria-label={dark ? "Activar modo claro" : "Activar modo oscuro"}
              >
                {dark ? "☀️" : "🌙"}
              </button>

              {user && (
                <div className="text-right text-xs leading-tight max-w-[100px] truncate">
                  <div className="text-gray-500">Hola,</div>
                  <div className="font-semibold text-gray-900 truncate">
                    {user.name}
                  </div>
                </div>
              )}
            </div>
          </div>
        </div>
      </header>

      {/* Drawer overlay */}
      {open && (
        <div
          className="fixed inset-0 z-50 bg-black/50"
          onClick={() => setOpen(false)}
        />
      )}

      {/* Drawer */}
      <aside
        className={cx(
          "fixed top-0 left-0 z-50 h-full w-72 bg-white border-r flex flex-col",
          "transform transition-transform duration-200",
          open ? "translate-x-0" : "-translate-x-full"
        )}
        aria-hidden={!open}
      >
        <div className="p-4 border-b flex items-center justify-between flex-shrink-0">
          <Title size="1xl">Pallet Control</Title>
          <button
            onClick={() => setOpen(false)}
            className="px-3 py-2 rounded-lg border text-sm"
          >
            Cerrar
          </button>
        </div>

        <nav className="p-3 space-y-2 flex-1 overflow-y-auto">
          {items.map((it) => {
            const active =
              it.to === "/" ? pathname === "/" : pathname.startsWith(it.to);

            return (
              <Link
                key={it.to}
                to={it.to}
                onClick={() => setOpen(false)}
                className={cx(
                  "block rounded-xl px-4 py-3 border",
                  active ? "bg-black text-white border-black" : "bg-white"
                )}
              >
                <div className="font-medium">{it.label}</div>
              </Link>
            );
          })}
        </nav>

        {/* Theme toggle en el sidebar */}
        <div className="p-3 border-t flex-shrink-0">
          <button
            onClick={toggle}
            className="w-full flex items-center gap-3 rounded-xl px-4 py-3 border text-sm font-medium bg-white hover:bg-gray-50 transition-colors"
          >
            <span className="text-base">{dark ? "☀️" : "🌙"}</span>
            <span>{dark ? "Modo claro" : "Modo oscuro"}</span>
          </button>
        </div>
      </aside>

      {/* Page content */}
      <main className="max-w-md mx-auto px-4 flex-1 w-full overflow-y-auto min-h-0 pt-20 pb-10">
        {/* Banner: cuenta sin rol asignado */}
        {isPending && (
          <div className="mb-4 rounded-xl border border-amber-300 bg-amber-50 px-4 py-3 text-sm text-amber-800">
            <p className="font-semibold">Cuenta pendiente de activación</p>
            <p className="mt-0.5 text-xs opacity-80">
              Podés ver la información, pero necesitás que un administrador te asigne un rol para crear o modificar datos.
            </p>
          </div>
        )}
        {children}
      </main>
    </div>
  );
}
