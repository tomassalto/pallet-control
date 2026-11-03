// Opciones de Workbox de la PWA (H-051).
//
// Política mientras GAP-OFFLINE siga abierto (FE-PWA, SEC-DATA):
// - SÓLO precache del shell estático y assets públicos del build.
// - NADA de runtimeCaching: las respuestas de /api/v1/* son privadas y
//   las fotos/tickets de /storage son evidencia de negocio; ninguna debe
//   persistir en Cache Storage reutilizable entre sesiones.
// - navigateFallback sirve el shell para rutas SPA; el denylist evita que
//   una navegación a /api o /storage reciba el index.html offline.
// La eliminación de las caches legacy "api-cache"/"photos-cache" ya
// instaladas es responsabilidad de src/auth/privateCaches.js.
export const workboxConfig = {
  // Pre-cachear todos los assets del build
  globPatterns: ["**/*.{js,css,html,ico,png,svg,webp,woff,woff2}"],
  // Ignorar el service worker mismo para evitar loops
  globIgnores: ["sw.js"],
  // Script importado por el worker generado: elimina las caches privadas
  // legacy durante activate, aunque la app todavía no haya montado (H-051).
  importScripts: ["sw-cleanup.js"],
  // Página a mostrar cuando se va a una ruta no cacheada offline
  navigateFallback: "/",
  navigateFallbackDenylist: [/^\/api/, /^\/storage/],
};
