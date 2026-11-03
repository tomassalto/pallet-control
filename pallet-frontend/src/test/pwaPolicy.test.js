import { readFileSync } from "node:fs";
import { fileURLToPath } from "node:url";
import { dirname, join } from "node:path";
import { describe, expect, it } from "vitest";
import { workboxConfig } from "../../pwa-workbox.config.js";

// Serializa incluyendo RegExp, que JSON.stringify convierte en {}.
const serialize = (value) =>
  JSON.stringify(value, (_key, v) => (v instanceof RegExp ? String(v) : v));

const root = join(dirname(fileURLToPath(import.meta.url)), "..", "..");
const viteConfigSource = readFileSync(join(root, "vite.config.js"), "utf8");

describe("política PWA/Workbox (H-051)", () => {
  it("vite.config.js usa la configuración extraída (fuente única)", () => {
    expect(viteConfigSource).toContain("workboxConfig");
    expect(viteConfigSource).not.toContain("runtimeCaching");
    expect(viteConfigSource).not.toContain("api-cache");
    expect(viteConfigSource).not.toContain("photos-cache");
  });

  it("no hay runtimeCaching: /api/v1/* no se persiste en el service worker", () => {
    expect(workboxConfig.runtimeCaching ?? []).toHaveLength(0);
    expect(serialize(workboxConfig)).not.toContain("api/v1");
  });

  it("no existe api-cache ni photos-cache", () => {
    const s = serialize(workboxConfig);
    expect(s).not.toContain("api-cache");
    expect(s).not.toContain("photos-cache");
    expect(s).not.toMatch(/cacheName/);
  });

  it("las fotos/tickets de /storage no tienen cache de datos privados", () => {
    const s = serialize(workboxConfig);
    expect(s).not.toContain("CacheFirst");
    expect(s).not.toContain("NetworkFirst");
    // /storage sólo puede aparecer en el denylist del fallback de navegación.
    const storageRefs = s.match(/storage/g) ?? [];
    expect(storageRefs.length).toBeLessThanOrEqual(1);
  });

  it("el worker importa la limpieza de caches legacy en activate", () => {
    expect(workboxConfig.importScripts).toContain("sw-cleanup.js");
  });

  it("conserva el precache del shell estático", () => {
    expect(workboxConfig.globPatterns).toContain(
      "**/*.{js,css,html,ico,png,svg,webp,woff,woff2}",
    );
    expect(workboxConfig.globIgnores).toContain("sw.js");
    expect(workboxConfig.navigateFallback).toBe("/");
  });

  it("el fallback de navegación nunca sirve el shell para /api ni /storage", () => {
    const denylist = workboxConfig.navigateFallbackDenylist.map(String);
    expect(denylist).toContain("/^\\/api/");
    expect(denylist).toContain("/^\\/storage/");
  });
});
