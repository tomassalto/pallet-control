// Autoridad única para eliminar caches privadas del navegador (H-051).
//
// Versiones anteriores del service worker persistían respuestas
// autenticadas de /api/v1/* en "api-cache" y fotos/tickets de /storage
// en "photos-cache". Esas caches viven en Cache Storage por origen y no
// se borran al cerrar sesión ni al actualizar el service worker:
// cleanupOutdatedCaches sólo limpia caches de precache de Workbox.
//
// La lista es cerrada y por nombre exacto: nunca se borran caches ajenas
// a Pallet Control (workbox-precache-*, caches de otras apps del origen).
export const PRIVATE_CACHE_NAMES = ["api-cache", "photos-cache"];

/**
 * Elimina las caches privadas listadas. Idempotente y segura:
 * - resuelve [] cuando Cache API no existe (navegador sin soporte);
 * - nunca rechaza: una Cache API rota no puede romper login, logout,
 *   401 ni el arranque;
 * - devuelve los nombres efectivamente borrados.
 */
export async function clearPrivateCaches() {
  if (typeof caches === "undefined" || !caches) return [];

  let names;
  try {
    names = await caches.keys();
  } catch {
    return [];
  }

  const targets = names.filter((name) => PRIVATE_CACHE_NAMES.includes(name));
  const results = await Promise.allSettled(
    targets.map((name) => caches.delete(name)),
  );
  return targets.filter(
    (_, i) => results[i].status === "fulfilled" && results[i].value === true,
  );
}
