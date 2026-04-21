import { useEffect, useState } from "react";
import { useAuth } from "../auth/AuthContext";
import { apiGet, apiPatch, apiPost } from "../api/client";
import { toastSuccess, toastError } from "../ui/toast";
import Title from "../ui/Title";

const ROLE_LABELS = {
  superadmin: { label: "Superadmin", bg: "bg-purple-100 text-purple-800" },
  admin:      { label: "Admin",      bg: "bg-blue-100 text-blue-800" },
  user:       { label: "Usuario",    bg: "bg-gray-100 text-gray-700" },
};

export default function AdminUsers() {
  const { user: me } = useAuth();
  const [users, setUsers] = useState([]);
  const [loading, setLoading] = useState(true);

  async function load() {
    try {
      const data = await apiGet("/admin/users");
      setUsers(Array.isArray(data) ? data : []);
    } catch (err) {
      toastError(err.message || "Error al cargar usuarios");
    } finally {
      setLoading(false);
    }
  }

  useEffect(() => { load(); }, []);

  async function changeRole(userId, role) {
    try {
      await apiPatch(`/admin/users/${userId}/role`, { role });
      toastSuccess("Rol actualizado");
      load();
    } catch (err) {
      toastError(err.response?.data?.error || err.message || "Error");
    }
  }

  async function toggleActive(userId) {
    try {
      await apiPost(`/admin/users/${userId}/toggle-active`, {});
      toastSuccess("Estado actualizado");
      load();
    } catch (err) {
      toastError(err.response?.data?.error || err.message || "Error");
    }
  }

  if (loading) return <p className="text-center py-10 text-gray-500">Cargando...</p>;

  return (
    <div className="flex flex-col gap-4">
      <Title>Usuarios</Title>

      {users.map((u) => {
        const isMe = u.id === me?.id;
        const role = ROLE_LABELS[u.role] ?? ROLE_LABELS.user;

        return (
          <div key={u.id} className="border rounded-xl p-4 flex flex-col gap-3 bg-white">
            <div className="flex items-start justify-between gap-2">
              <div className="min-w-0">
                <p className="font-semibold truncate">{u.name} {isMe && <span className="text-xs text-gray-400">(vos)</span>}</p>
                <p className="text-sm text-gray-500 truncate">{u.email}</p>
                {!u.email_verified_at && (
                  <p className="text-xs text-amber-600 mt-0.5">⚠️ Email no verificado</p>
                )}
              </div>
              <div className="flex flex-col items-end gap-1 shrink-0">
                <span className={`text-xs font-medium px-2 py-0.5 rounded-full ${role.bg}`}>
                  {role.label}
                </span>
                <span className={`text-xs px-2 py-0.5 rounded-full ${u.is_active ? "bg-green-100 text-green-700" : "bg-red-100 text-red-600"}`}>
                  {u.is_active ? "Activo" : "Inactivo"}
                </span>
              </div>
            </div>

            {!isMe && (
              <div className="flex flex-wrap gap-2 pt-1 border-t">
                {/* Cambiar rol */}
                {(me?.role === 'superadmin' || u.role !== 'superadmin') && (
                  <select
                    value={u.role}
                    onChange={(e) => changeRole(u.id, e.target.value)}
                    className="text-sm border rounded-lg px-2 py-1 bg-white"
                  >
                    {me?.role === 'superadmin' && <option value="superadmin">Superadmin</option>}
                    <option value="admin">Admin</option>
                    <option value="user">Usuario</option>
                  </select>
                )}

                {/* Activar / desactivar */}
                {u.role !== 'superadmin' && (
                  <button
                    onClick={() => toggleActive(u.id)}
                    className={`text-sm px-3 py-1 rounded-lg border font-medium ${
                      u.is_active
                        ? "border-red-300 text-red-600 hover:bg-red-50"
                        : "border-green-400 text-green-700 hover:bg-green-50"
                    }`}
                  >
                    {u.is_active ? "Desactivar" : "Activar"}
                  </button>
                )}
              </div>
            )}
          </div>
        );
      })}
    </div>
  );
}
