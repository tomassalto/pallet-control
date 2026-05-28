export default function AddProductModal({
  open,
  onClose,
  eanOrLast4,
  setEanOrLast4,
  qty,
  setQty,
  saving,
  onSubmit,
}) {
  if (!open) return null;

  return (
    <>
      <div
        className="fixed inset-0 bg-black/50 z-40"
        onClick={onClose}
      />
      <div className="fixed inset-0 z-50 flex items-center justify-center p-4">
        <div
          className="bg-white rounded-2xl p-6 w-full max-w-md space-y-4"
          onClick={(e) => e.stopPropagation()}
        >
          <div className="flex items-center justify-between">
            <div className="font-semibold text-lg">Agregar producto</div>
            <button
              onClick={onClose}
              className="text-gray-500 hover:text-gray-700"
            >
              ×
            </button>
          </div>
          <form onSubmit={onSubmit} className="space-y-3">
            <input
              value={eanOrLast4}
              onChange={(e) => setEanOrLast4(e.target.value.replace(/\D/g, ""))}
              placeholder="EAN completo o últimos 4"
              inputMode="numeric"
              className="w-full border rounded-lg px-3 py-3"
              autoFocus
            />
            <div className="flex gap-2">
              <input
                value={qty}
                onChange={(e) => setQty(e.target.value.replace(/\D/g, ""))}
                inputMode="numeric"
                placeholder="Qty"
                className="w-28 border rounded-lg px-3 py-3"
              />
              <button
                type="submit"
                disabled={saving}
                className="flex-1 rounded-lg py-3 bg-black text-white disabled:opacity-60"
              >
                {saving ? "Agregando..." : "Agregar"}
              </button>
            </div>
            <p className="text-xs text-gray-500">
              Tip: un escáner tipo pistola escribe el EAN en el input como teclado.
            </p>
          </form>
        </div>
      </div>
    </>
  );
}
