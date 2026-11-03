import { StrictMode } from "react";
import { createRoot } from "react-dom/client";
import { BrowserRouter } from "react-router-dom";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { AuthProvider } from "./auth/AuthContext";
import { ThemeProvider } from "./context/ThemeContext";
import App from "./App.jsx";
import { clearPrivateCaches } from "./auth/privateCaches";
import "./index.css";

// Purga única al arrancar (H-051): instalaciones que actualizan desde un
// service worker anterior pueden conservar "api-cache"/"photos-cache" en
// Cache Storage; cleanupOutdatedCaches no las toca porque no son caches
// de precache. Es idempotente y no exige Cache API.
void clearPrivateCaches();

const queryClient = new QueryClient({
  defaultOptions: {
    queries: {
      staleTime: 30_000,        // datos frescos por 30s — sin re-fetch innecesario al navegar
      gcTime: 5 * 60_000,       // caché en memoria por 5min después de desmontar
      retry: (count, err) => err?.response?.status !== 401 && count < 1,
      refetchOnWindowFocus: false,
    },
  },
});

createRoot(document.getElementById("root")).render(
  <StrictMode>
    <BrowserRouter>
      <QueryClientProvider client={queryClient}>
        <ThemeProvider>
          <AuthProvider>
            <App />
          </AuthProvider>
        </ThemeProvider>
      </QueryClientProvider>
    </BrowserRouter>
  </StrictMode>
);
