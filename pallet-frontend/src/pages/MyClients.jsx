import { useState } from "react";
import { Link } from "react-router-dom";
import { useQuery } from "@tanstack/react-query";
import { apiGet } from "../api/client";
import { toastError } from "../ui/toast";
import Title from "../ui/Title";
import { PageSpinner } from "../ui/Spinner";
import BackButton from "../ui/BackButton";
import Accordion from "../ui/Accordion";

export default function MyClients() {
  const [selectedCustomerId, setSelectedCustomerId] = useState(null);

  const { data: customers = [], isLoading } = useQuery({
    queryKey: ["customers"],
    queryFn: () => apiGet("/customers"),
    onError: (e) => toastError(e?.message || "No se pudo cargar clientes"),
  });

  const { data: selectedCustomer, isLoading: loadingOrders } = useQuery({
    queryKey: ["customer", selectedCustomerId],
    queryFn: () => apiGet(`/customers/${selectedCustomerId}`),
    enabled: !!selectedCustomerId,
    onError: (e) => toastError(e?.message || "No se pudo cargar pedidos"),
  });

  const customerOrders = selectedCustomer?.orders ?? [];

  function getOrderStatus(order) {
    if (order.status === "done") return "Completo";
    if (order.status === "paused") return "Pausado";
    return "Comenzado";
  }

  const openOrders = customerOrders.filter((o) => o.status !== "done");
  const completedOrders = customerOrders.filter((o) => o.status === "done");

  return (
    <div className="space-y-4">
      <div className="flex justify-start">
        <BackButton to={`/`} />
      </div>
      <div className="flex flex-col gap-2 items-center">
        <Title size="4xl">Mis clientes</Title>
        <p className="text-sm text-gray-600 w-[200px]">
          Lista de clientes. Tocá uno para ver su historial de pedidos.
        </p>
      </div>

      {loading ? (
        <PageSpinner />
      ) : customers.length === 0 ? (
        <div className="text-sm text-gray-600">
          Todavía no hay clientes registrados.
        </div>
      ) : selectedCustomerId ? (
        <div className="space-y-4">
          {/* Botón volver */}
          <button
            onClick={() => setSelectedCustomerId(null)}
            className="text-sm text-gray-600 underline"
          >
            ← Volver a lista de clientes
          </button>

          {/* Información del cliente */}
          <div className="bg-white border border-border rounded-2xl p-4">
            <Title size="2xl" className="font-semibold">
              {selectedCustomer?.name}
            </Title>
            {selectedCustomer?.quit && (
              <div className="text-xs text-gray-500 mt-1">
                Quit: {selectedCustomer.quit}
              </div>
            )}
          </div>

          {/* Pedidos del cliente */}
          {loadingOrders ? (
            <PageSpinner />
          ) : customerOrders.length === 0 ? (
            <div className="text-sm text-gray-600">
              Este cliente no tiene pedidos aún.
            </div>
          ) : (
            <>
              {/* Pedidos abiertos */}
              {openOrders.length > 0 && (
                <div className="space-y-2">
                  <div className="text-sm font-semibold text-gray-700">
                    Pedidos en proceso ({openOrders.length})
                  </div>
                  <div className="flex flex-col gap-2">
                    {openOrders.map((o) => (
                      <Link
                        key={o.id}
                        to={`/order/${o.id}`}
                        className="block bg-white border border-border rounded-2xl p-4 active:scale-[0.99]"
                      >
                        <div className="flex flex-col gap-1">
                          <Title size="2xl" className="font-mono font-semibold">
                            Pedido #{o.code}
                          </Title>
                          <div className="text-xs text-gray-500">
                            Estado:{" "}
                            <span className="capitalize font-medium">
                              {getOrderStatus(o)}
                            </span>
                          </div>
                          {o.created_at && (
                            <div className="text-xs text-gray-500">
                              Creado:{" "}
                              {new Date(o.created_at).toLocaleString(undefined, {
                                dateStyle: "short",
                                timeStyle: "short",
                              })}
                            </div>
                          )}
                          <div className="text-sm underline">Ver detalle</div>
                        </div>
                      </Link>
                    ))}
                  </div>
                </div>
              )}

              {/* Pedidos completados */}
              {completedOrders.length > 0 && (
                <Accordion
                  title={`Pedidos completados (${completedOrders.length})`}
                >
                  <div className="flex flex-col gap-2">
                    {completedOrders.map((o) => (
                      <Link
                        key={o.id}
                        to={`/order/${o.id}`}
                        className="block bg-white border border-border rounded-2xl p-4 active:scale-[0.99] opacity-75"
                      >
                        <div className="flex flex-col gap-1">
                          <Title size="2xl" className="font-mono font-semibold">
                            Pedido #{o.code}
                          </Title>
                          <div className="text-xs text-gray-500">
                            Estado:{" "}
                            <span className="capitalize font-medium text-green-600">
                              {getOrderStatus(o)}
                            </span>
                          </div>
                          {o.created_at && (
                            <div className="text-xs text-gray-500">
                              Creado:{" "}
                              {new Date(o.created_at).toLocaleString(undefined, {
                                dateStyle: "short",
                                timeStyle: "short",
                              })}
                            </div>
                          )}
                          <div className="text-sm underline">Ver detalle</div>
                        </div>
                      </Link>
                    ))}
                  </div>
                </Accordion>
              )}
            </>
          )}
        </div>
      ) : (
        <div className="flex flex-col gap-2">
          {customers.map((customer) => (
            <button
              key={customer.id}
              onClick={() => setSelectedCustomerId(customer.id)}
              className="block bg-white border border-border rounded-2xl p-4 text-left active:scale-[0.99]"
            >
              <div className="flex flex-col gap-1">
                <Title size="2xl" className="font-semibold">
                  {customer.name}
                </Title>
                {customer.quit && (
                  <div className="text-xs text-gray-500">Quit: {customer.quit}</div>
                )}
                <div className="text-sm text-gray-500">
                  Ver historial de pedidos →
                </div>
              </div>
            </button>
          ))}
        </div>
      )}
    </div>
  );
}

