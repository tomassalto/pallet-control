import { afterEach, describe, expect, it, vi } from "vitest";
import { clearPrivateCaches, PRIVATE_CACHE_NAMES } from "./privateCaches";

function stubCaches(existing, { deleteImpl } = {}) {
  const deleted = [];
  vi.stubGlobal("caches", {
    keys: vi.fn(async () => existing),
    delete: deleteImpl ?? vi.fn(async (name) => (deleted.push(name), true)),
  });
  return { deleted };
}

afterEach(() => {
  vi.unstubAllGlobals();
});

describe("clearPrivateCaches (H-051)", () => {
  it("elimina exactamente api-cache y photos-cache", async () => {
    const { deleted } = stubCaches(["api-cache", "photos-cache"]);

    const removed = await clearPrivateCaches();

    expect(removed.sort()).toEqual(["api-cache", "photos-cache"]);
    expect(deleted.sort()).toEqual(["api-cache", "photos-cache"]);
  });

  it("no borra caches ajenas a Pallet Control", async () => {
    const { deleted } = stubCaches([
      "api-cache",
      "photos-cache",
      "workbox-precache-v2-https://app/",
      "otra-app-cache",
    ]);

    await clearPrivateCaches();

    expect(deleted.sort()).toEqual(["api-cache", "photos-cache"]);
    expect(caches.delete).not.toHaveBeenCalledWith("otra-app-cache");
    expect(caches.delete).not.toHaveBeenCalledWith(
      "workbox-precache-v2-https://app/",
    );
  });

  it("es idempotente: una segunda corrida sin caches no falla", async () => {
    stubCaches(["api-cache", "photos-cache"]);
    await clearPrivateCaches();

    caches.keys.mockResolvedValue([]);
    const removed = await clearPrivateCaches();

    expect(removed).toEqual([]);
  });

  it("resuelve sin error en un navegador sin Cache API", async () => {
    vi.stubGlobal("caches", undefined);

    await expect(clearPrivateCaches()).resolves.toEqual([]);
  });

  it("no rechaza si caches.keys() falla", async () => {
    vi.stubGlobal("caches", {
      keys: vi.fn(async () => {
        throw new Error("CacheStorage roto");
      }),
      delete: vi.fn(),
    });

    await expect(clearPrivateCaches()).resolves.toEqual([]);
  });

  it("no rechaza si caches.delete() falla en un nombre", async () => {
    stubCaches(["api-cache", "photos-cache"], {
      deleteImpl: vi.fn(async (name) => {
        if (name === "api-cache") throw new Error("bloqueada");
        return true;
      }),
    });

    await expect(clearPrivateCaches()).resolves.toEqual(["photos-cache"]);
  });

  it("la lista de nombres es cerrada y sólo incluye las legacy conocidas", () => {
    expect(PRIVATE_CACHE_NAMES).toEqual(["api-cache", "photos-cache"]);
  });
});
