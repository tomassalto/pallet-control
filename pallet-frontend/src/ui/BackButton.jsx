import { Link } from "react-router-dom";
import backIcon from "../assets/icons/back.svg";

export default function BackButton({ to, className = "" }) {
  return (
    <Link
      to={to}
      className={`inline-flex items-center font-semibold gap-1 text-sm text-gray-600 hover:text-gray-900 ${className}`}
    >
      {" "}
      <img src={backIcon} alt="Volver" className="w-4 h-4" />{" "}
      <span>Volver</span>
    </Link>
  );
}
