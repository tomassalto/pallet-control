import { useMemo, useState } from "react";
import { Link, useLocation } from "react-router-dom";
import { useAuth } from "../auth/AuthContext";
import Title from "./Title";

function cx(...arr) {
  return arr.filter(Boolean).join(" ");
}

export default function SidebarLayout({ title = "Pallet Control", children }) {
  const [open, setOpen] = useState(false);
  const { pathname } = useLocation();
  const { user } = useAuth() || {};

  const items = useMemo(
    () => [
      { to: "/", label: "Inicio" },
      { to: "/orders/new", label: "Empezar pedido" },
      { to: "/pallets", label: "Mis pallets" },
      { to: "/orders", label: "Mis pedidos" },
      { to: "/clients", label: "Mis clientes" },
      { to: "/productos", label: "Buscar producto" },
      { to: "/logs", label: "Logs" },
    ],
    []
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
              {/* icon hamburguesa */}
              <span className="text-xl leading-none">☰</span>
            </button>

            <div className="min-w-0">
              <div className="font-semibold truncate text-xl font-['Montserrat']">
                {title}
              </div>
            </div>

            {user && (
              <div className="text-right text-xs leading-tight max-w-[120px] truncate">
                <div className="text-gray-500">Hola,</div>
                <div className="font-semibold text-gray-900 truncate">
                  {user.name}
                </div>
              </div>
            )}
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
          "fixed top-0 left-0 z-50 h-full w-72 bg-white border-r",
          "transform transition-transform duration-200",
          open ? "translate-x-0" : "-translate-x-full"
        )}
        aria-hidden={!open}
      >
        <div className="p-4 border-b flex items-center justify-between">
          <div className="flex flex-col gap-2">
            <Title size="1xl">Pallet Control</Title>
          </div>

          <button
            onClick={() => setOpen(false)}
            className="px-3 py-2 rounded-lg border"
          >
            Cerrar
          </button>
        </div>

        <nav className="p-3 space-y-2">
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
                  active ? "bg-black text-white" : "bg-white"
                )}
              >
                <div className="font-medium">{it.label}</div>
              </Link>
            );
          })}
        </nav>

        <div className="p-4 mt-auto text-xs text-gray-500">
          Tip: agregá “Lista de pallets” cuando tengas el endpoint.
        </div>
      </aside>

      {/* Page content */}
      <main className="max-w-md mx-auto px-4 flex-1 w-full overflow-y-auto min-h-0 pt-20 pb-10">
        {children}
      </main>
    </div>
  );
}
