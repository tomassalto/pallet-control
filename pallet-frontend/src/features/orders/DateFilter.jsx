function today() {
  return new Date().toISOString().slice(0, 10);
}

function daysAgo(n) {
  const d = new Date();
  d.setDate(d.getDate() - n);
  return d.toISOString().slice(0, 10);
}

function startOfWeek() {
  const d = new Date();
  d.setDate(d.getDate() - d.getDay() + (d.getDay() === 0 ? -6 : 1));
  return d.toISOString().slice(0, 10);
}

function startOfMonth() {
  const d = new Date();
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, "0")}-01`;
}

export default function DateFilter({ dateFrom, dateTo, onChange }) {
  const presets = [
    { label: "Hoy", from: today(), to: today() },
    { label: "Ayer", from: daysAgo(1), to: daysAgo(1) },
    { label: "Esta semana", from: startOfWeek(), to: today() },
    { label: "Este mes", from: startOfMonth(), to: today() },
  ];
  const activePreset = presets.find(
    (preset) => preset.from === dateFrom && preset.to === dateTo,
  );

  return (
    <div className="space-y-2">
      <div className="flex flex-wrap gap-1.5 justify-center items-center">
        <button
          onClick={() => onChange("", "")}
          className={[
            "px-3 py-1 rounded-xl text-xs font-semibold border transition-colors",
            !dateFrom && !dateTo
              ? "bg-gray-900 dark:bg-white text-white dark:text-gray-900 border-transparent"
              : "bg-white dark:bg-gray-800 border-gray-200 dark:border-gray-700 text-gray-600 dark:text-gray-400 hover:bg-gray-50 dark:hover:bg-gray-700",
          ].join(" ")}
        >
          Todos
        </button>
        {presets.map((preset) => {
          const active = preset.from === dateFrom && preset.to === dateTo;
          return (
            <button
              key={preset.label}
              onClick={() => onChange(preset.from, preset.to)}
              className={[
                "px-3 py-1 rounded-xl text-xs font-semibold border transition-colors",
                active
                  ? "bg-blue-600 text-white border-transparent"
                  : "bg-white dark:bg-gray-800 border-gray-200 dark:border-gray-700 text-gray-600 dark:text-gray-400 hover:bg-gray-50 dark:hover:bg-gray-700",
              ].join(" ")}
            >
              {preset.label}
            </button>
          );
        })}
      </div>

      {!activePreset && (dateFrom || dateTo) && (
        <div className="flex items-center gap-2">
          <input
            type="date"
            value={dateFrom}
            onChange={(e) => onChange(e.target.value, dateTo)}
            className="flex-1 text-xs border border-gray-200 dark:border-gray-700 rounded-xl px-3 py-2 bg-white dark:bg-gray-800 text-gray-800 dark:text-gray-100"
          />
          <span className="text-xs text-gray-400">→</span>
          <input
            type="date"
            value={dateTo}
            onChange={(e) => onChange(dateFrom, e.target.value)}
            className="flex-1 text-xs border border-gray-200 dark:border-gray-700 rounded-xl px-3 py-2 bg-white dark:bg-gray-800 text-gray-800 dark:text-gray-100"
          />
        </div>
      )}
    </div>
  );
}
