import { StatusBadge } from "../../ui/EntityCard";
import { formatPalletDate } from "./palletDetailUi";

export default function PalletDetailHeader({ pallet }) {
  const palletDone = pallet.status === "done";

  return (
    <div className="space-y-2.5">
      <h1 className="font-mono font-bold text-2xl md:text-3xl text-gray-900 dark:text-white leading-tight">
        {pallet.code}
      </h1>
      <div className="flex items-center gap-3 flex-wrap">
        <StatusBadge
          label={palletDone ? "Completo" : "En proceso"}
          color={palletDone ? "green" : "blue"}
        />
        {pallet.created_at && (
          <span className="text-xs text-gray-400 dark:text-gray-500">
            Creado el {formatPalletDate(pallet.created_at)}
          </span>
        )}
      </div>
    </div>
  );
}
