import Title from "../ui/Title";
import BackButton from "../ui/BackButton";
import Accordion from "../ui/Accordion";
import { PageSpinner, InlineSpinner } from "../ui/Spinner";
import DateFilter from "../features/orders/DateFilter";
import MyOrderCard from "../features/orders/MyOrderCard";
import { useMyOrdersPage } from "../hooks/useMyOrdersPage";

export default function MyOrders() {
  const {
    dateFrom,
    dateTo,
    orders,
    openOrders,
    completedOrders,
    canFinalizeMap,
    finalizing,
    fetchNextPage,
    hasNextPage,
    isFetchingNextPage,
    isLoading,
    handleDateChange,
    handleFinalize,
  } = useMyOrdersPage();

  return (
    <div className="space-y-6">
      <div className="flex justify-start">
        <BackButton to="/" />
      </div>

      <div className="flex flex-col gap-1.5 items-center text-center">
        <Title>Mis pedidos</Title>
        <p className="text-sm text-gray-500 dark:text-gray-400 max-w-xs w-[210px] md:w-[250px]">
          Tocá un pedido para ver el detalle y la ubicación de los productos.
        </p>
      </div>

      <div className="flex flex-col w-full items-center justify-center">
        <div className="flex flex-wrap w-[266px] md:w-full items-center justify-center">
          <DateFilter
            dateFrom={dateFrom}
            dateTo={dateTo}
            onChange={handleDateChange}
          />
        </div>
      </div>

      {isLoading ? (
        <PageSpinner />
      ) : orders.length === 0 ? (
        <p className="text-sm text-gray-500 dark:text-gray-400 text-center py-10">
          {dateFrom || dateTo
            ? "No hay pedidos para ese rango de fechas."
            : "Todavía no hay pedidos en el historial."}
        </p>
      ) : (
        <div className="space-y-6">
          {openOrders.length > 0 && (
            <section className="space-y-2">
              <p className="text-xs font-semibold uppercase tracking-wider text-gray-400 dark:text-gray-500 px-1">
                En proceso ({openOrders.length})
              </p>
              <div className="flex flex-col gap-2">
                {openOrders.map((order) => (
                  <MyOrderCard
                    key={order.id}
                    order={order}
                    canFinalize={
                      order.status === "open" &&
                      canFinalizeMap.get(order.id) === true
                    }
                    finalizing={finalizing.has(order.id)}
                    onFinalize={() => handleFinalize(order.id)}
                  />
                ))}
              </div>
            </section>
          )}

          {completedOrders.length > 0 && (
            <Accordion
              title={`Completados (${completedOrders.length})`}
              defaultOpen
            >
              <div className="flex flex-col gap-2 pt-1">
                {completedOrders.map((order) => (
                  <MyOrderCard key={order.id} order={order} dim />
                ))}
              </div>
            </Accordion>
          )}
        </div>
      )}

      {hasNextPage && (
        <button
          disabled={isFetchingNextPage}
          onClick={() => fetchNextPage()}
          className="w-full rounded-xl py-3 border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 text-sm text-gray-600 dark:text-gray-400 hover:bg-gray-50 dark:hover:bg-gray-700 disabled:opacity-60 transition-colors"
        >
          {isFetchingNextPage ? (
            <InlineSpinner label="Cargando…" />
          ) : (
            "Cargar más"
          )}
        </button>
      )}
    </div>
  );
}
