import { useState, useEffect, useRef } from "react";
import { apiGet } from "../api/client";

/**
 * Componente de autocomplete para seleccionar clientes
 * Permite buscar por nombre o quit
 */
export default function CustomerAutocomplete({
  value,
  onChange,
  placeholder = "Buscar cliente por nombre o quit...",
  className = "",
}) {
  const [search, setSearch] = useState("");
  const [customers, setCustomers] = useState([]);
  const [loading, setLoading] = useState(false);
  const [showDropdown, setShowDropdown] = useState(false);
  const [selectedCustomer, setSelectedCustomer] = useState(null);
  const inputRef = useRef(null);
  const dropdownRef = useRef(null);

  // Cargar cliente seleccionado inicial
  useEffect(() => {
    if (value) {
      // Si value es un objeto (customer completo), usarlo directamente
      if (typeof value === "object" && value.id) {
        setSelectedCustomer(value);
        setSearch(value.name || value.quit || "");
      } else if (typeof value === "number") {
        // Si es solo el ID, cargar el cliente
        loadCustomer(value);
      }
    }
  }, [value]);

  async function loadCustomer(customerId) {
    try {
      const customer = await apiGet(`/customers/${customerId}`);
      setSelectedCustomer(customer);
      setSearch(customer.name || customer.quit || "");
    } catch (e) {
      console.error("Error cargando cliente:", e);
    }
  }

  // Buscar clientes cuando cambia el texto de búsqueda
  useEffect(() => {
    if (search.length >= 1) {
      const timeoutId = setTimeout(() => {
        searchCustomers(search);
      }, 300); // Debounce de 300ms

      return () => clearTimeout(timeoutId);
    } else {
      setCustomers([]);
      setShowDropdown(false);
    }
  }, [search]);

  async function searchCustomers(query) {
    if (!query || query.length < 1) {
      setCustomers([]);
      return;
    }

    setLoading(true);
    try {
      const results = await apiGet(`/customers?search=${encodeURIComponent(query)}&limit=10`);
      setCustomers(results);
      setShowDropdown(true);
    } catch (e) {
      console.error("Error buscando clientes:", e);
      setCustomers([]);
    } finally {
      setLoading(false);
    }
  }

  function handleSelect(customer) {
    setSelectedCustomer(customer);
    setSearch(customer.name || customer.quit || "");
    setShowDropdown(false);
    onChange(customer);
  }

  function handleClear() {
    setSelectedCustomer(null);
    setSearch("");
    setCustomers([]);
    setShowDropdown(false);
    onChange(null);
    inputRef.current?.focus();
  }

  // Cerrar dropdown al hacer clic fuera
  useEffect(() => {
    function handleClickOutside(event) {
      if (
        dropdownRef.current &&
        !dropdownRef.current.contains(event.target) &&
        inputRef.current &&
        !inputRef.current.contains(event.target)
      ) {
        setShowDropdown(false);
      }
    }

    document.addEventListener("mousedown", handleClickOutside);
    return () => document.removeEventListener("mousedown", handleClickOutside);
  }, []);

  return (
    <div className={`relative ${className}`}>
      <div className="relative">
        <input
          ref={inputRef}
          type="text"
          value={search}
          onChange={(e) => {
            setSearch(e.target.value);
            setSelectedCustomer(null);
            onChange(null);
          }}
          onFocus={() => {
            if (customers.length > 0) {
              setShowDropdown(true);
            }
          }}
          placeholder={placeholder}
          className="w-full bg-white border rounded-lg px-3 py-2 pr-8"
        />
        {selectedCustomer && (
          <button
            onClick={handleClear}
            className="absolute right-2 top-1/2 -translate-y-1/2 text-gray-400 hover:text-gray-600"
            type="button"
          >
            ✕
          </button>
        )}
      </div>

      {/* Dropdown de resultados */}
      {showDropdown && (loading || customers.length > 0) && (
        <div
          ref={dropdownRef}
          className="absolute z-50 w-full mt-1 bg-white border rounded-lg shadow-lg max-h-60 overflow-y-auto"
        >
          {loading ? (
            <div className="p-3 text-sm text-gray-500 text-center">
              Buscando...
            </div>
          ) : customers.length === 0 ? (
            <div className="p-3 text-sm text-gray-500 text-center">
              No se encontraron clientes
            </div>
          ) : (
            customers.map((customer) => (
              <button
                key={customer.id}
                onClick={() => handleSelect(customer)}
                className="w-full text-left px-3 py-2 hover:bg-gray-100 border-b last:border-b-0"
                type="button"
              >
                <div className="font-medium">{customer.name}</div>
                {customer.quit && (
                  <div className="text-xs text-gray-500">Quit: {customer.quit}</div>
                )}
              </button>
            ))
          )}
        </div>
      )}
    </div>
  );
}

