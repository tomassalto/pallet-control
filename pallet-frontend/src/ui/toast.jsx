import { toast } from "react-toastify";

export function toastSuccess(msg) {
  toast.success(msg, { position: "bottom-center", autoClose: 1800 });
}

export function toastError(msg) {
  toast.error(msg, { position: "bottom-center", autoClose: 3000 });
}

export function toastInfo(msg) {
  toast.info(msg, { position: "bottom-center", autoClose: 2000 });
}
