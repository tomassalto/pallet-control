import { useContext } from "react";
import { AuthContext } from "../auth/AuthContextValue";

export function useAuth() {
  return useContext(AuthContext);
}