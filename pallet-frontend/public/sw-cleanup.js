// Limpieza de caches privadas legacy en el ciclo de vida del service
// worker (H-051). Se inyecta en el sw.js generado vía importScripts de
// generateSW, así que corre dentro del worker nuevo: la eliminación de
// "api-cache"/"photos-cache" ocurre al activarse, aunque la aplicación
// todavía no haya montado ni ejecutado su propio bootstrap.
//
// La lista es cerrada y por nombre exacto: nunca borra caches ajenas ni
// los precaches de Workbox (workbox-precache-*), que administra
// cleanupOutdatedCaches.
const LEGACY_PRIVATE_CACHES = ["api-cache", "photos-cache"];

self.addEventListener("activate", (event) => {
  event.waitUntil(
    caches
      .keys()
      .then((names) =>
        Promise.all(
          names
            .filter((name) => LEGACY_PRIVATE_CACHES.includes(name))
            .map((name) => caches.delete(name)),
        ),
      )
      // Una Cache API rota no puede impedir la activación del worker.
      .catch(() => {}),
  );
});
