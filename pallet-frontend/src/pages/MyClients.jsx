import { useEffect, useState } from "react";
import { Link } from "react-router-dom";
import { apiGet } from "../api/client";
import { toastError } from "../ui/toast";
import Title from "../ui/Title";
import { PageSpinner, InlineSpinner } from "../ui/Spinner";
import BackButton from "../ui/BackButton";
import Accordion from "../ui/Accordion";

export default function MyClients() {
  const [loading, setLoading] = useState(true);
  const [customers, setCustomers] = useState([]);
  const [selectedCustomer, setSelectedCustomer] = useState(null);
  const [customerOrders, setCustomerOrders] = useState([]);
  const [loadingOrders, setLoadingOrders] = useState(false);

  async function loadCustomers() {
    setLoading(true);
    try {
      const data = await apiGet("/customers");
      setCustomers(data);
    } catch (e) {
      toastError(
        e?.message || e?.response?.data?.message || "No se pudo cargar clientes"
      );
    } finally {
      setLoading(false);
    }
  }

  async function loadCustomerOrders(customerId) {
    setLoadingOrders(true);
    try {
      const customer = await apiGet(`/customers/${customerId}`);
      setSelectedCustomer(customer);
      setCustomerOrders(customer.orders || []);
    } catch (e) {
      toastError(
        e?.message || e?.response?.data?.message || "No se pudo cargar pedidos"
      );
    } finally {
      setLoadingOrders(false);
    }
  }

  useEffect(() => {
    loadCustomers();
  }, []);

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
      ) : selectedCustomer ? (
        <div className="space-y-4">
          {/* Botón volver */}
          <button
            onClick={() => {
              setSelectedCustomer(null);
              setCustomerOrders([]);
            }}
            className="text-sm text-gray-600 underline"
          >
            ← Volver a lista de clientes
          </button>

          {/* Información del cliente */}
          <div className="bg-white border border-border rounded-2xl p-4">
            <Title size="2xl" className="font-semibold">
              {selectedCustomer.name}
            </Title>
            {selectedCustomer.quit && (
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
              onClick={() => loadCustomerOrders(customer.id)}
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

