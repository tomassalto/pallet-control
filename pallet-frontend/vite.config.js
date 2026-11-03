import { defineConfig } from "vite";
import react from "@vitejs/plugin-react";
import tailwindcss from "@tailwindcss/vite";
import { VitePWA } from "vite-plugin-pwa";
import { workboxConfig } from "./pwa-workbox.config.js";

export default defineConfig({
  plugins: [
    react(),
    tailwindcss(),
    VitePWA({
      registerType: "autoUpdate",
      injectRegister: "script",
      filename: "sw.js",
      manifest: {
        name: "Pallet Control",
        short_name: "Pallets",
        description: "Sistema de control y seguimiento de pallets",
        theme_color: "#1e40af",
        background_color: "#f8fafc",
        display: "standalone",
        orientation: "portrait-primary",
        scope: "/",
        start_url: "/",
        icons: [
          {
            src: "/pallet-icon-192.png",
            sizes: "192x192",
            type: "image/png",
            purpose: "any",
          },
          {
            src: "/pallet-icon-512.png",
            sizes: "512x512",
            type: "image/png",
            purpose: "any maskable",
          },
        ],
      },
      // Política de cache en pwa-workbox.config.js (H-051): sólo precache
      // del shell; sin caching en runtime de datos privados mientras
      // GAP-OFFLINE esté abierto.
      workbox: workboxConfig,
    }),
  ],
  build: {
    outDir: "../pallet-backend/public/app",
    emptyOutDir: true,
    assetsDir: "assets",
    rollupOptions: {
      output: {
        manualChunks: {
          // React core — casi nunca cambia, muy cacheable
          "vendor-react": ["react", "react-dom"],
          // Router + Query — estables entre deploys
          "vendor-router": ["react-router-dom"],
          "vendor-query": ["@tanstack/react-query"],
          // Librerías pesadas bajo demanda (ya lazy-loaded por ruta,
          // pero esto evita que se mezclen en el chunk de la página)
          "vendor-konva": ["konva", "react-konva"],
          "vendor-scanner": ["html5-qrcode"],
        },
      },
    },
  },
  server: {
    host: true,
    allowedHosts: ["decompressive-gluelike-clifton.ngrok-free.dev", "all"],
    proxy: {
      "/api": {
        target: "http://127.0.0.1:8000",
        changeOrigin: true,
        secure: false,
      },
      "/storage": {
        target: "http://127.0.0.1:8000",
        changeOrigin: true,
        secure: false,
      },
    },
  },
});
