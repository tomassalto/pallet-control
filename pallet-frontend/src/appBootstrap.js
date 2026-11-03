import { clearPrivateCaches } from "./auth/privateCaches";

/**
 * Bootstrap de la aplicación (H-051).
 *
 * La purga de caches privadas legacy es una barrera previa al montaje:
 * ninguna sesión ni request autenticada puede comenzar mientras restos
 * de una sesión anterior sigan en Cache Storage. `clearPrivateCaches`
 * nunca rechaza por contrato; el `finally` garantiza además que un
 * fallo inesperado de Cache API no bloquee permanentemente el arranque.
 *
 * @param {() => void} mount función que monta React (createRoot().render)
 */
export async function bootstrapApp(mount) {
  // clearPrivateCaches nunca rechaza por contrato; el catch cubre un
  // fallo inesperado de Cache API para que no bloquee el arranque.
  await clearPrivateCaches().catch(() => {});
  mount();
}
