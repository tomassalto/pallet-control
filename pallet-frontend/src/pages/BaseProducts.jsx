import { useEffect, useState, useMemo } from "react";
import { Link, useParams } from "react-router-dom";
import { apiGet, apiPatch } from "../api/client";
import { toastSuccess, toastError } from "../ui/toast";
import BackButton from "../ui/BackButton";
import Accordion from "../ui/Accordion";
import Title from "../ui/Title";

export default function BaseProducts() {
  const { palletId, baseId } = useParams();

  const [loading, setLoading] = useState(true);
  const [pallet, setPallet] = useState(null);
  const [base, setBase] = useState(null);
  const [orders, setOrders] = useState([]);
  const [error, setError] = useState("");

  // Selección de items
  const [selectingItems, setSelectingItems] = useState(false);
  const [selectedItemIds, setSelectedItemIds] = useState(new Set());
  const [confirmedItems, setConfirmedItems] = useState([]);

  async function load() {
    setError("");
    setLoading(true);
    try {
      const data = await apiGet(`/pallets/${palletId}`);
      setPallet(data.pallet || null);
      setOrders(data.orders || []);

      const foundBase = data.bases?.find((b) => b.id === parseInt(baseId, 10));
      setBase(foundBase || null);

      if (foundBase?.order_items) {
        setConfirmedItems(
          foundBase.order_items.map((item) => ({
            order_item_id: item.id,
            qty: item.pivot?.qty || item.qty || 1,
            description: item.description,
            ean: item.ean,
          }))
        );
        setSelectingItems(false);
      }
    } catch (e) {
      setError(e.message || "Error cargando datos");
      toastError(e.message || "Error cargando datos");
    } finally {
      setLoading(false);
    }
  }

  useEffect(() => {
    load();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [palletId, baseId]);

  // Obtener todos los items de los pedidos del pallet
  function getAllOrderItems() {
    const allItems = [];
    orders.forEach((order) => {
      if (order.items && Array.isArray(order.items)) {
        order.items.forEach((item) => {
          allItems.push({
            ...item,
            order_code: order.code,
            order_id: order.id,
            order_status: order.status, // Incluir el status del pedido
          });
        });
      }
    });
    return allItems;
  }

  // Verificar si un item pertenece a un pedido finalizado
  function isItemFromFinalizedOrder(orderItemId) {
    const orderItem = getAllOrderItems().find((i) => i.id === orderItemId);
    return orderItem && orderItem.order_status === "done";
  }

  // Agrupar items por pedido, filtrando los que ya están completamente asignados
  // y excluyendo items de pedidos finalizados
  const itemsByOrder = useMemo(() => {
    const grouped = {};
    orders.forEach((order) => {
      // Excluir pedidos finalizados
      if (order.status === "done") {
        return;
      }

      if (order.items && Array.isArray(order.items) && order.items.length > 0) {
        // Filtrar items que tienen disponibilidad > 0
        const availableItems = order.items.filter((item) => {
          // Calcular disponibilidad directamente aquí
          const totalQty = item.qty || 0;
          let assigned = 0;
          if (pallet?.bases) {
            pallet.bases.forEach((b) => {
              if (b.id === parseInt(baseId, 10)) return;
              if (b.order_items && Array.isArray(b.order_items)) {
                const baseItem = b.order_items.find((oi) => oi.id === item.id);
                if (baseItem) {
                  assigned += baseItem.pivot?.qty || baseItem.qty || 0;
                }
              }
            });
          }
          const available = Math.max(0, totalQty - assigned);
          return available > 0;
        });

        if (availableItems.length > 0) {
          grouped[order.id] = {
            order: order,
            items: availableItems,
          };
        }
      }
    });
    return grouped;
  }, [orders, pallet?.bases, baseId]);

  // Calcular cuánto ya está asignado de un item en todas las bases (excepto la base actual)
  function getAssignedQty(orderItemId) {
    if (!pallet?.bases) return 0;
    let total = 0;
    pallet.bases.forEach((b) => {
      if (b.id === parseInt(baseId, 10)) return;
      if (b.order_items && Array.isArray(b.order_items)) {
        const baseItem = b.order_items.find((item) => item.id === orderItemId);
        if (baseItem) {
          total += baseItem.pivot?.qty || baseItem.qty || 0;
        }
      }
    });
    return total;
  }

  // Obtener cantidad disponible de un item
  function getAvailableQty(orderItem) {
    const totalQty = orderItem.qty || 0;
    const assigned = getAssignedQty(orderItem.id);
    return Math.max(0, totalQty - assigned);
  }

  function handleItemToggle(itemId) {
    setSelectedItemIds((prev) => {
      const newSet = new Set(prev);
      if (newSet.has(itemId)) {
        newSet.delete(itemId);
      } else {
        newSet.add(itemId);
      }
      return newSet;
    });
  }

  function confirmItemSelection() {
    const allItems = getAllOrderItems();
    const selected = allItems
      .filter((item) => selectedItemIds.has(item.id))
      .map((item) => ({
        order_item_id: item.id,
        qty: 1,
        description: item.description,
        ean: item.ean,
      }));

    // Agregar los nuevos items a los existentes (evitar duplicados)
    setConfirmedItems((prev) => {
      const existingIds = new Set(prev.map((item) => item.order_item_id));
      const newItems = selected.filter(
        (item) => !existingIds.has(item.order_item_id)
      );
      return [...prev, ...newItems];
    });

    setSelectingItems(false);
    setSelectedItemIds(new Set());
  }

  function updateItemQty(orderItemId, qty) {
    setConfirmedItems((prev) =>
      prev.map((item) => {
        if (item.order_item_id === orderItemId) {
          const numValue = qty === "" ? "" : parseInt(qty, 10);
          const orderItem = getAllOrderItems().find(
            (i) => i.id === orderItemId
          );
          if (orderItem && numValue !== "") {
            const available = getAvailableQty(orderItem);
            const currentInConfirmed = prev.find(
              (i) => i.order_item_id === orderItemId
            );
            const currentQty = currentInConfirmed?.qty || 0;
            const availableWithCurrent = available + currentQty;
            if (numValue > availableWithCurrent) {
              return item;
            }
          }
          return { ...item, qty: numValue === "" ? "" : numValue || 1 };
        }
        return item;
      })
    );
  }

  function removeConfirmedItem(orderItemId) {
    setConfirmedItems((prev) =>
      prev.filter((item) => item.order_item_id !== orderItemId)
    );
  }

  async function onSave() {
    // Validar cantidades
    for (const item of confirmedItems) {
      if (!item.qty || item.qty < 1) {
        toastError("Todas las cantidades deben ser mayor a 0");
        return;
      }
      const orderItem = getAllOrderItems().find(
        (i) => i.id === item.order_item_id
      );
      if (orderItem) {
        const available = getAvailableQty(orderItem);
        if (item.qty > available) {
          toastError(
            `No se puede asignar ${item.qty} unidades de "${orderItem.description}". Solo quedan ${available} disponibles.`
          );
          return;
        }
      }
    }

    try {
      const payload = {};
      if (confirmedItems.length > 0) {
        payload.items = confirmedItems
          .filter((item) => item.qty && item.qty >= 1)
          .map((item) => ({
            order_item_id: item.order_item_id,
            qty: Math.max(1, parseInt(item.qty, 10) || 1),
          }));
      } else {
        payload.items = [];
      }

      await apiPatch(`/pallets/${palletId}/bases/${baseId}`, payload);
      toastSuccess("Productos actualizados");
      await load();
    } catch (e) {
      toastError(
        e.response?.data?.message || e.message || "No se pudo actualizar"
      );
    }
  }

  if (loading) return <div className="text-sm text-gray-600">Cargando…</div>;

  if (error || !base) {
    return (
      <div className="space-y-3">
        <BackButton to={`/pallet/${palletId}`} />
        <div className="bg-red-50 border border-red-200 text-red-800 rounded-xl p-3">
          {error || "Base no encontrada"}
        </div>
      </div>
    );
  }

  return (
    <div className="space-y-2">
      <div className="flex justify-start">
        <BackButton to={`/pallet/${palletId}`} />
      </div>

      {/* Header */}
      <div className="bg-white border border-border rounded-2xl p-4 space-y-2">
        <Title size="3xl">{pallet?.code}</Title>

        <Title size="2xl">{base.name || `Base #${base.id}`}</Title>
      </div>

      {/* Selección de items */}
      {pallet?.status === "done" && (
        <div className="bg-yellow-50 border border-yellow-200 text-yellow-800 rounded-xl p-3 text-sm">
          Este pallet está finalizado. No se pueden agregar más productos.
        </div>
      )}
      {selectingItems && pallet?.status !== "done" ? (
        <div className="bg-white border rounded-2xl p-4 space-y-3">
          <div className="font-semibold text-sm">
            Seleccionar items del pedido
          </div>
          {Object.keys(itemsByOrder).length === 0 ? (
            <div className="text-sm text-gray-500 text-center py-4">
              No hay items en los pedidos asociados a este pallet.
            </div>
          ) : (
            <>
              <div className=" space-y-2">
                {Object.values(itemsByOrder).map(({ order, items }) => (
                  <Accordion
                    key={order.id}
                    title={`Pedido #${order.code} (${items.length} producto${
                      items.length !== 1 ? "s" : ""
                    })`}
                    defaultOpen={Object.keys(itemsByOrder).length === 1}
                  >
                    <div className="space-y-2">
                      {items.map((item) => (
                        <label
                          key={item.id}
                          className="flex items-start gap-2 p-2 hover:bg-gray-50 rounded cursor-pointer"
                        >
                          <input
                            type="checkbox"
                            checked={selectedItemIds.has(item.id)}
                            onChange={() => handleItemToggle(item.id)}
                            className="mt-1"
                          />
                          <div className="flex-1 text-xs">
                            <div className="font-medium">
                              {item.description}
                            </div>
                            <div className="text-gray-500">
                              EAN: {item.ean} | Qty total: {item.qty}
                            </div>
                            {(() => {
                              const available = getAvailableQty(item);
                              return (
                                <div
                                  className={
                                    available === 0
                                      ? "text-red-600 font-medium"
                                      : "text-green-600"
                                  }
                                >
                                  Disponible: {available}
                                </div>
                              );
                            })()}
                          </div>
                        </label>
                      ))}
                    </div>
                  </Accordion>
                ))}
              </div>
              <button
                onClick={confirmItemSelection}
                disabled={selectedItemIds.size === 0}
                className="w-full rounded-lg py-2 bg-black text-white text-sm disabled:opacity-50 disabled:cursor-not-allowed"
              >
                Confirmar selección ({selectedItemIds.size})
              </button>
            </>
          )}
        </div>
      ) : (
        <div className="bg-white border-[#14213d] rounded-2xl p-4 space-y-3">
          <div className="flex items-center justify-between">
            <div className="font-semibold text-sm">Items en esta base</div>
            {pallet?.status !== "done" && (
              <button
                onClick={() => {
                  setSelectingItems(true);
                  setSelectedItemIds(new Set());
                }}
                className="text-xs px-2 py-1 border rounded"
              >
                {confirmedItems.length > 0
                  ? "Agregar más"
                  : "Seleccionar items"}
              </button>
            )}
          </div>
          {confirmedItems.length === 0 ? (
            <div className="text-sm text-gray-500 text-center py-4">
              No hay items seleccionados. Toca "Seleccionar items" para agregar.
            </div>
          ) : (
            <div className="flex flex-col gap-2">
              {confirmedItems.map((item) => (
                <div key={item.order_item_id} className="border rounded-lg p-2">
                  <div className="flex items-start justify-between">
                    <div className="flex-1 text-xs">
                      <div className="font-medium">{item.description}</div>
                      <div className="text-gray-500">EAN: {item.ean}</div>
                    </div>
                    {pallet?.status !== "done" &&
                      !isItemFromFinalizedOrder(item.order_item_id) && (
                        <button
                          onClick={() =>
                            removeConfirmedItem(item.order_item_id)
                          }
                          className="text-red-600 text-xs px-2"
                        >
                          ✕
                        </button>
                      )}
                  </div>
                  <div className="">
                    <div className="flex items-center justify-center gap-2">
                      <label className="text-xs text-gray-600">
                        Unidades en esta base:
                      </label>
                      {pallet?.status === "done" ||
                      isItemFromFinalizedOrder(item.order_item_id) ? (
                        <span className="text-xs font-semibold text-gray-900">
                          {item.qty || 0}
                        </span>
                      ) : (
                        <input
                          type="number"
                          min="1"
                          max={(() => {
                            const orderItem = getAllOrderItems().find(
                              (i) => i.id === item.order_item_id
                            );
                            if (orderItem) {
                              const available = getAvailableQty(orderItem);
                              const currentQty = item.qty || 0;
                              return available + currentQty;
                            }
                            return item.qty || 1;
                          })()}
                          value={item.qty === "" ? "" : item.qty}
                          onChange={(e) => {
                            const val = e.target.value;
                            if (
                              val === "" ||
                              (!isNaN(val) && parseInt(val, 10) >= 1)
                            ) {
                              updateItemQty(item.order_item_id, val);
                            }
                          }}
                          onBlur={(e) => {
                            if (
                              e.target.value === "" ||
                              parseInt(e.target.value, 10) < 1
                            ) {
                              updateItemQty(item.order_item_id, "1");
                            }
                          }}
                          className="w-20 border rounded px-2 py-1 text-xs"
                        />
                      )}
                    </div>
                    {isItemFromFinalizedOrder(item.order_item_id) && (
                      <div className="text-[10px] text-orange-600">
                        ⚠️ Este producto pertenece a un pedido finalizado
                      </div>
                    )}
                    {(() => {
                      const orderItem = getAllOrderItems().find(
                        (i) => i.id === item.order_item_id
                      );
                      if (orderItem) {
                        const available = getAvailableQty(orderItem);
                        const currentQty = item.qty || 0;
                        const remaining = available - currentQty;
                        return (
                          <div
                            className={`text-[10px] ${
                              remaining < 0
                                ? "text-red-600 font-medium"
                                : remaining === 0
                                ? "text-orange-600"
                                : "text-gray-500"
                            }`}
                          >
                            {remaining < 0
                              ? `⚠️ Excede por ${Math.abs(remaining)}`
                              : remaining === 0
                              ? "Sin disponibilidad restante"
                              : `Quedan ${remaining} disponibles`}
                          </div>
                        );
                      }
                      return null;
                    })()}
                  </div>
                </div>
              ))}
            </div>
          )}
          {pallet?.status !== "done" && (
            <button
              onClick={onSave}
              className="w-full rounded-lg py-2 bg-black text-white text-sm"
            >
              Guardar cambios
            </button>
          )}
        </div>
      )}
    </div>
  );
}
