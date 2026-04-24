/**
 * Scraper de imágenes de productos — comerciante.carrefour.com.ar
 *
 * Flujo:
 *  1. Login al sitio con las credenciales del .env
 *  2. Lee el CSV de EANs
 *  3. Pregunta al backend qué EANs ya tienen imagen (para saltear)
 *  4. Para cada EAN pendiente:
 *       a. Navega a /p/{EAN}
 *       b. Extrae la URL de imagen principal
 *       c. Acumula en batch
 *  5. Cada BATCH_SIZE productos → POST al backend → guarda en progress.json
 *  6. Al terminar muestra el resumen
 *
 * Uso:
 *   cp .env.example .env   # completar credenciales
 *   npm install
 *   npx playwright install chromium
 *   node scrape.js
 */

require("dotenv").config();
const { chromium } = require("playwright");
const fs = require("fs");
const path = require("path");
const { parse } = require("csv-parse/sync");

// ── Config ────────────────────────────────────────────────────────────────────
const {
  CARREFOUR_EMAIL,
  CARREFOUR_DNI,
  CARREFOUR_PHONE,
  CARREFOUR_NAME,
  API_BASE_URL,
  BOT_SECRET,
  CSV_PATH = "../pallet-backend/storage/app/imports/listado.csv",
  DELAY_MS = "1500",
  BATCH_SIZE = "20",
} = process.env;

const DELAY      = parseInt(DELAY_MS, 10);
const BATCH      = parseInt(BATCH_SIZE, 10);
const PROGRESS_FILE = path.join(__dirname, "progress.json");
const BASE_URL   = "https://comerciante.carrefour.com.ar";

// ── Helpers ───────────────────────────────────────────────────────────────────
function sleep(ms) {
  return new Promise((r) => setTimeout(r, ms));
}

function loadProgress() {
  if (fs.existsSync(PROGRESS_FILE)) {
    return JSON.parse(fs.readFileSync(PROGRESS_FILE, "utf8"));
  }
  return { done: [], failed: [] };
}

function saveProgress(progress) {
  fs.writeFileSync(PROGRESS_FILE, JSON.stringify(progress, null, 2));
}

function readEans() {
  const csvPath = path.resolve(__dirname, CSV_PATH);
  const content = fs.readFileSync(csvPath, "utf8");
  const rows    = parse(content, { skip_empty_lines: true, relax_quotes: true });
  // Col 0 = EAN (puede venir con comillas)
  return rows
    .map((r) => String(r[0] || "").replace(/\D/g, "").trim())
    .filter((e) => e.length >= 7);
}

async function pushBatchToApi(batch) {
  const res = await fetch(`${API_BASE_URL}/scraper/images/bulk-update`, {
    method: "POST",
    headers: {
      "Content-Type": "application/json",
      "X-Bot-Secret": BOT_SECRET,
    },
    body: JSON.stringify({ products: batch }),
  });

  if (!res.ok) {
    const text = await res.text();
    throw new Error(`API error ${res.status}: ${text}`);
  }

  return res.json();
}

async function getAlreadyDoneFromApi(eans) {
  // Lote de 500 para no superar tamaños de request
  const done = new Set();
  for (let i = 0; i < eans.length; i += 500) {
    const slice = eans.slice(i, i + 500);
    const res = await fetch(`${API_BASE_URL}/scraper/images/check-existing`, {
      method: "POST",
      headers: {
        "Content-Type": "application/json",
        "X-Bot-Secret": BOT_SECRET,
      },
      body: JSON.stringify({ eans: slice }),
    });
    if (res.ok) {
      const data = await res.json();
      (data.done || []).forEach((e) => done.add(e));
    }
  }
  return done;
}

// ── Login ─────────────────────────────────────────────────────────────────────
async function login(page) {
  console.log("🔐 Haciendo login...");
  await page.goto(`${BASE_URL}/login`, { waitUntil: "domcontentloaded" });

  // El sitio puede tener distintos selectores — intentamos los más comunes
  // Ajustá los selectores si el login falla (ver instrucciones en README)
  try {
    // Esperar que aparezca algún input de email/dni
    await page.waitForSelector('input[type="email"], input[name="email"], input[placeholder*="mail" i]', { timeout: 10000 });

    const emailInput = page.locator('input[type="email"], input[name="email"], input[placeholder*="mail" i]').first();
    await emailInput.fill(CARREFOUR_EMAIL);

    const dniInput = page.locator('input[name="dni"], input[placeholder*="dni" i], input[placeholder*="documento" i]').first();
    await dniInput.fill(CARREFOUR_DNI);

    const phoneInput = page.locator('input[name="phone"], input[name="telefono"], input[placeholder*="tel" i], input[placeholder*="cel" i]').first();
    await phoneInput.fill(CARREFOUR_PHONE);

    const nameInput = page.locator('input[name="name"], input[name="nombre"], input[placeholder*="nombre" i]').first();
    await nameInput.fill(CARREFOUR_NAME);

    // Submit
    await page.locator('button[type="submit"], input[type="submit"]').first().click();
    await page.waitForLoadState("networkidle", { timeout: 15000 });
    console.log("✅ Login OK — URL actual:", page.url());
  } catch (err) {
    console.error("❌ Error en login:", err.message);
    console.log("📸 Guardando screenshot de login para depurar...");
    await page.screenshot({ path: path.join(__dirname, "login-debug.png"), fullPage: true });
    throw err;
  }
}

// ── Extraer imagen de una página de producto ──────────────────────────────────
async function extractImageUrl(page, ean) {
  const productUrl = `${BASE_URL}/p/${ean}`;
  await page.goto(productUrl, { waitUntil: "domcontentloaded", timeout: 20000 });

  // Intentamos varios selectores comunes de imagen principal en VTex/Carrefour
  const selectors = [
    // VTex product image standard
    '.vtex-product-image img[src*="vtexassets"]',
    '.vtex-store-components-3-x-productImageTag',
    'img.vtex-product-image--main',
    // Genéricos de imagen principal grande
    '[data-testid="product-image"] img',
    '.product-image img',
    '.pdp-image img',
    'figure.vtex-store-components-3-x-figure img',
    // Fallback: primera imagen relevante
    'img[src*="vtexassets.com"]',
    'img[src*="carrefourassets"]',
  ];

  for (const sel of selectors) {
    try {
      const el = page.locator(sel).first();
      if (await el.count() > 0) {
        const src = await el.getAttribute("src");
        if (src && src.startsWith("http")) {
          return src;
        }
      }
    } catch (_) {
      // Intentar siguiente selector
    }
  }

  // Intentar vía JSON-LD (structured data)
  try {
    const jsonLd = await page.evaluate(() => {
      const el = document.querySelector('script[type="application/ld+json"]');
      if (!el) return null;
      const data = JSON.parse(el.textContent);
      return data?.image?.[0] || data?.image || null;
    });
    if (jsonLd && typeof jsonLd === "string" && jsonLd.startsWith("http")) {
      return jsonLd;
    }
  } catch (_) {}

  return null;
}

// ── Main ──────────────────────────────────────────────────────────────────────
(async () => {
  // Validar config
  const missing = ["CARREFOUR_EMAIL", "CARREFOUR_DNI", "CARREFOUR_PHONE", "CARREFOUR_NAME", "API_BASE_URL", "BOT_SECRET"]
    .filter((k) => !process.env[k]);
  if (missing.length) {
    console.error("❌ Faltan variables de entorno:", missing.join(", "));
    console.error("   Copiá .env.example a .env y completá los valores.");
    process.exit(1);
  }

  // Leer EANs del CSV
  console.log("📂 Leyendo CSV...");
  const allEans = readEans();
  console.log(`   ${allEans.length} EANs encontrados en el CSV`);

  // Cargar progreso local
  const progress = loadProgress();
  const localDone = new Set(progress.done);
  const localFailed = new Set(progress.failed);

  // Preguntar al backend cuáles ya tienen imagen
  console.log("🌐 Verificando EANs ya procesados en el backend...");
  const apiDone = await getAlreadyDoneFromApi(allEans);
  console.log(`   ${apiDone.size} ya tienen imagen en la DB`);

  // EANs pendientes
  const pending = allEans.filter((e) => !localDone.has(e) && !apiDone.has(e));
  console.log(`   ${pending.length} EANs pendientes de scrapear\n`);

  if (pending.length === 0) {
    console.log("✅ No hay nada que scrapear. ¡Todo al día!");
    return;
  }

  // Iniciar browser
  const browser = await chromium.launch({
    headless: true,   // cambiar a false para ver qué pasa si hay problemas
  });
  const context = await browser.newContext({
    userAgent: "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36",
    viewport: { width: 1280, height: 800 },
  });
  const page = await context.newPage();

  // Login
  await login(page);

  let batch       = [];
  let scraped     = 0;
  let notFound    = 0;
  let apiErrors   = 0;

  const total = pending.length;
  const startTime = Date.now();

  for (let i = 0; i < pending.length; i++) {
    const ean = pending[i];

    try {
      const imageUrl = await extractImageUrl(page, ean);

      if (imageUrl) {
        batch.push({ ean, image_url: imageUrl });
        localDone.add(ean);
        scraped++;
      } else {
        // Producto no encontrado o sin imagen
        localFailed.add(ean);
        notFound++;
      }
    } catch (err) {
      console.error(`  ⚠️  Error scrapeando EAN ${ean}:`, err.message);
      localFailed.add(ean);
      notFound++;
    }

    // Enviar batch al backend
    if (batch.length >= BATCH) {
      try {
        const result = await pushBatchToApi(batch);
        console.log(`  📤 Batch enviado: ${result.updated}/${result.sent} actualizados`);
        batch = [];
      } catch (err) {
        console.error("  ❌ Error enviando batch a la API:", err.message);
        apiErrors++;
      }
    }

    // Guardar progreso local periódicamente
    if (i % 50 === 0) {
      progress.done    = [...localDone];
      progress.failed  = [...localFailed];
      saveProgress(progress);

      const elapsed  = ((Date.now() - startTime) / 1000 / 60).toFixed(1);
      const eta      = ((Date.now() - startTime) / (i + 1)) * (total - i - 1) / 1000 / 60;
      console.log(`[${i + 1}/${total}] ✅ ${scraped} encontrados | ⚠️ ${notFound} sin imagen | ⏱ ${elapsed}min transcurridos | ETA ~${eta.toFixed(0)}min`);
    }

    await sleep(DELAY);
  }

  // Enviar el batch final
  if (batch.length > 0) {
    try {
      const result = await pushBatchToApi(batch);
      console.log(`  📤 Batch final enviado: ${result.updated}/${result.sent} actualizados`);
    } catch (err) {
      console.error("  ❌ Error enviando batch final:", err.message);
    }
  }

  // Guardar progreso final
  progress.done   = [...localDone];
  progress.failed = [...localFailed];
  saveProgress(progress);

  await browser.close();

  // Resumen
  const totalMin = ((Date.now() - startTime) / 1000 / 60).toFixed(1);
  console.log("\n════════════════════════════════");
  console.log("✅ Scraping finalizado");
  console.log(`   Procesados:  ${scraped + notFound} / ${total}`);
  console.log(`   Con imagen:  ${scraped}`);
  console.log(`   Sin imagen:  ${notFound}`);
  console.log(`   Errores API: ${apiErrors}`);
  console.log(`   Tiempo:      ${totalMin} minutos`);
  console.log("════════════════════════════════");
  console.log("\nSi hay EANs fallidos podés revisarlos en progress.json → 'failed'");
})();
