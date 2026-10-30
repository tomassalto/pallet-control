import { defineConfig } from "vitest/config";
import react from "@vitejs/plugin-react";

// Configuración propia para tests: el plugin PWA de vite.config.js no debe
// correr en este entorno (no hay build ni service worker).
export default defineConfig({
  plugins: [react()],
  test: {
    environment: "jsdom",
    setupFiles: ["./src/test/setup.js"],
    restoreMocks: true,
    include: ["src/**/*.test.{js,jsx}"],
  },
});
