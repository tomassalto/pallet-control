// Verificación post-build del artefacto PWA (H-051).
//
// jsdom no ejecuta service workers, así que el retiro de caches privadas
// se comprueba inspeccionando el sw.js generado por generateSW:
//   - existe sw.js y manifest.webmanifest (PWA sigue instalable);
//   - conserva el precache del shell (precacheAndRoute);
//   - NO registra caches privadas ni estrategias runtime sobre /api o
//     /storage: los nombres y handlers retirados no pueden aparecer.
//
// Uso: node scripts/verify-sw.mjs   (después de `npm run build`)
import { existsSync, readFileSync } from "node:fs";
import { dirname, join } from "node:path";
import { fileURLToPath } from "node:url";

const appDir = join(
  dirname(fileURLToPath(import.meta.url)),
  "..",
  "..",
  "pallet-backend",
  "public",
  "app",
);

const fail = (msg) => {
  console.error(`verify-sw: ${msg}`);
  process.exit(1);
};

const swPath = join(appDir, "sw.js");
if (!existsSync(swPath)) fail(`no se encontró ${swPath} — ¿corrió npm run build?`);
const sw = readFileSync(swPath, "utf8");

// Caches y handlers retirados: no deben aparecer en el artefacto.
// "api/v1" no aparece en denylist (que usa \/api), así que su presencia
// indicaría una ruta runtime sobre la API.
const forbidden = [
  "api-cache",
  "photos-cache",
  "NetworkFirst",
  "CacheFirst",
  "api/v1",
];
const hits = forbidden.filter((token) => sw.includes(token));
if (hits.length) {
  fail(`sw.js contiene referencias privadas retiradas: ${hits.join(", ")}`);
}

// La PWA sigue instalable con precache del shell estático.
if (!sw.includes("precacheAndRoute")) fail("sw.js sin precache del shell");
if (!existsSync(join(appDir, "manifest.webmanifest"))) {
  fail("manifest.webmanifest ausente en el build");
}

// El worker nuevo debe importar la limpieza de caches legacy en activate
// (H-051): sin importScripts la eliminación dependería sólo del JS de la
// página, que el worker viejo puede seguir sirviendo hasta activarse.
if (!sw.includes("importScripts") || !sw.includes("sw-cleanup.js")) {
  fail("sw.js no importa sw-cleanup.js — falta la limpieza en activate");
}
const cleanupPath = join(appDir, "sw-cleanup.js");
if (!existsSync(cleanupPath)) {
  fail("sw-cleanup.js no llegó al artefacto del build");
}
const cleanup = readFileSync(cleanupPath, "utf8");
for (const token of ["activate", "api-cache", "photos-cache"]) {
  if (!cleanup.includes(token)) {
    fail(`sw-cleanup.js sin "${token}" — limpieza de activate incompleta`);
  }
}

console.log(
  "verify-sw: OK — sw.js sin caches privadas, precache del shell y manifest presentes.",
);
