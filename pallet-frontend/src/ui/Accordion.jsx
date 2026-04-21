import { useState } from "react";

export default function Accordion({ title, children, defaultOpen = false }) {
  const [isOpen, setIsOpen] = useState(defaultOpen);

  return (
    <div className="border rounded-xl overflow-hidden">
      <button
        onClick={() => setIsOpen(!isOpen)}
        className="w-full px-4 py-3 bg-gray-50 hover:bg-gray-100 flex items-center justify-between text-left"
      >
        <span className="font-semibold text-sm">{title}</span>
        <span className="text-gray-500 text-lg">{isOpen ? "−" : "+"}</span>
      </button>
      {isOpen && <div className="p-2">{children}</div>}
    </div>
  );
}
