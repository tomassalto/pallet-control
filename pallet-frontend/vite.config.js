import { defineConfig } from "vite";
import react from "@vitejs/plugin-react";
import tailwindcss from "@tailwindcss/vite";
import { VitePWA } from "vite-plugin-pwa";

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
      workbox: {
        // Pre-cachear todos los assets del build
        globPatterns: ["**/*.{js,css,html,ico,png,svg,webp,woff,woff2}"],
        // Ignorar el service worker mismo para evitar loops
        globIgnores: ["sw.js"],
        runtimeCaching: [
          {
            // Fotos/imágenes del storage → CacheFirst (no cambian)
            urlPattern: /\/storage\/.+\.webp$/,
            handler: "CacheFirst",
            options: {
              cacheName: "photos-cache",
              expiration: {
                maxEntries: 500,
                maxAgeSeconds: 7 * 24 * 60 * 60, // 7 días
              },
            },
          },
          {
            // Llamadas a la API → NetworkFirst (datos frescos, con fallback)
            urlPattern: /\/api\/v1\/.*/,
            handler: "NetworkFirst",
            options: {
              cacheName: "api-cache",
              networkTimeoutSeconds: 10,
              expiration: {
                maxEntries: 100,
                maxAgeSeconds: 24 * 60 * 60, // 1 día
              },
            },
          },
        ],
        // Página a mostrar cuando se va a una ruta no cacheada offline
        navigateFallback: "/",
        navigateFallbackDenylist: [/^\/api/, /^\/storage/],
      },
    }),
  ],
  build: {
    outDir: "../pallet-backend/public/app",
    emptyOutDir: true,
    assetsDir: "assets",
  },
  server: {
    host: true,
    allowedHosts: ["decompressive-gluelike-clifton.ngrok-free.dev", "all"],
    proxy: {
      "/api": {
        target: "https://pallet-control.onrender.com",
        changeOrigin: true,
        secure: false,
      },
      "/storage": {
        target: "https://pallet-control.onrender.com",
        changeOrigin: true,
        secure: false,
      },
    },
  },
});
