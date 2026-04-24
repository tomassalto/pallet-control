/**
 * Scraper de imágenes de productos — comerciante.carrefour.com.ar (Maxi Pedido)
 *
 * Flujo de login:
 *  1. Ir a la home
 *  2. Click "Ingresar" (arriba a la derecha)
 *  3. Click "Comercio o Emprendimiento"
 *  4. Seleccionar Provincia → Sucursal (dropdowns)
 *  5. Completar Nombre, CUIT/DNI, Teléfono, Email → "Ingresar"
 *
 * Flujo de scraping:
 *  6. Para cada EAN navegar a /p/{EAN}
 *  7. Extraer URL de imagen principal
 *  8. Acumular en batch → POST al backend cada BATCH_SIZE productos
 *  9. Guardar progress.json para reanudar si se interrumpe
 *
 * Modo test: TEST_LIMIT=20 en .env solo procesa los primeros 20 EANs
 *
 * Uso:
 *   cp .env.example .env   # completar credenciales
 *   npm install
 *   npx playwright install chromium
 *   node scrape.js
 */

require("dotenv").config();
const { chromium } = require("playwright");
const fs   = require("fs");
const path = require("path");
const { parse } = require("csv-parse/sync");

// ── Config ────────────────────────────────────────────────────────────────────
const {
  CARREFOUR_EMAIL,
  CARREFOUR_DNI,
  CARREFOUR_PHONE,
  CARREFOUR_NAME,
  CARREFOUR_PROVINCIA = "NEUQUEN",
  CARREFOUR_SUCURSAL  = "Neuquén II - Antártida Argentina 3600",
  API_BASE_URL,
  BOT_SECRET,
  CSV_PATH   = "../pallet-backend/storage/app/imports/listado.csv",
  DELAY_MS   = "1500",
  BATCH_SIZE = "20",
  TEST_LIMIT = "",          // Si está seteado, solo procesa esa cantidad de EANs
  HEADLESS   = "true",      // Poner "false" para ver el browser
} = process.env;

const DELAY         = parseInt(DELAY_MS, 10);
const BATCH         = parseInt(BATCH_SIZE, 10);
const TEST_N        = TEST_LIMIT ? parseInt(TEST_LIMIT, 10) : null;
const PROGRESS_FILE = path.join(__dirname, "progress.json");
const BASE_URL      = "https://comerciante.carrefour.com.ar";

// ── Helpers ───────────────────────────────────────────────────────────────────
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

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
  const done = new Set();
  for (let i = 0; i < eans.length; i += 500) {
    const slice = eans.slice(i, i + 500);
    try {
      const res = await fetch(`${API_BASE_URL}/scraper/images/check-existing`, {
        method: "POST",
        headers: { "Content-Type": "application/json", "X-Bot-Secret": BOT_SECRET },
        body: JSON.stringify({ eans: slice }),
      });
      if (res.ok) {
        const data = await res.json();
        (data.done || []).forEach((e) => done.add(e));
      }
    } catch (_) {}
  }
  return done;
}

// ── Login ─────────────────────────────────────────────────────────────────────
async function login(page) {
  console.log("🔐 Iniciando login en Maxi Pedido...");

  // Paso 1: ir a la home
  await page.goto(BASE_URL, { waitUntil: "domcontentloaded", timeout: 30000 });
  await sleep(1500);

  // Paso 2: click "Ingresar" (top right)
  console.log("   → Click 'Ingresar'");
  await page.locator('text=Ingresar').first().click();
  await sleep(1000);

  // Screenshot para debug
  await page.screenshot({ path: path.join(__dirname, "debug-step1.png") });

  // Paso 3: click "Comercio o Emprendimiento"
  console.log("   → Click 'Comercio o Emprendimiento'");
  await page.locator('text=COMERCIO').first().click();
  await sleep(1500);

  await page.screenshot({ path: path.join(__dirname, "debug-step2.png") });

  // Paso 4: seleccionar Provincia
  console.log(`   → Provincia: ${CARREFOUR_PROVINCIA}`);
  // Esperar que aparezca el dropdown de provincia
  await page.waitForSelector('select', { timeout: 10000 });

  // Primer select = provincia
  const selects = page.locator('select');
  await selects.first().selectOption({ label: CARREFOUR_PROVINCIA });
  await sleep(1500); // Esperar que carguen las sucursales

  await page.screenshot({ path: path.join(__dirname, "debug-step3.png") });

  // Paso 5: seleccionar Sucursal (segundo select, que se carga dinámicamente)
  console.log(`   → Sucursal: ${CARREFOUR_SUCURSAL}`);
  const sucursalSelect = selects.nth(1);
  // Intentar por label exacto primero, luego por texto parcial
  try {
    await sucursalSelect.selectOption({ label: CARREFOUR_SUCURSAL });
  } catch (_) {
    // Si el label exacto no funciona, buscar por valor que contenga "Antartida" o "3600"
    const options = await sucursalSelect.locator('option').all();
    let found = false;
    for (const opt of options) {
      const text = (await opt.textContent() || "").trim();
      if (text.toLowerCase().includes("antartida") || text.includes("3600") || text.toLowerCase().includes("neuquen ii")) {
        const val = await opt.getAttribute("value");
        await sucursalSelect.selectOption({ value: val });
        console.log(`   → Sucursal seleccionada: "${text}"`);
        found = true;
        break;
      }
    }
    if (!found) {
      // Listar opciones disponibles para debug
      const opts = [];
      for (const opt of options) opts.push(await opt.textContent());
      console.log("   Opciones disponibles en Sucursal:", opts.join(" | "));
      // Tomar la primera que no sea placeholder
      const firstValid = options[1];
      if (firstValid) await sucursalSelect.selectOption({ index: 1 });
    }
  }
  await sleep(500);

  await page.screenshot({ path: path.join(__dirname, "debug-step4.png") });

  // Paso 6: completar datos de contacto
  console.log("   → Completando datos de contacto...");

  // Nombre y Apellido (placeholder "Ej: Juan Perez")
  await page.locator('input[placeholder*="Juan" i], input[placeholder*="nombre" i]').first().fill(CARREFOUR_NAME);

  // CUIT / DNI (placeholder "Ej: 30112233440")
  await page.locator('input[placeholder*="30112" i], input[placeholder*="cuit" i], input[placeholder*="dni" i]').first().fill(CARREFOUR_DNI);

  // Teléfono
  await page.locator('input[placeholder*="011" i], input[placeholder*="tel" i], input[placeholder*="cel" i]').first().fill(CARREFOUR_PHONE);

  // Email
  await page.locator('input[placeholder*="email" i], input[placeholder*="mail" i], input[type="email"]').first().fill(CARREFOUR_EMAIL);

  await page.screenshot({ path: path.join(__dirname, "debug-step5.png") });

  // Paso 7: click "Ingresar" (el botón del formulario)
  console.log("   → Click 'Ingresar' (submit)");
  await page.locator('button:has-text("Ingresar"), input[type="submit"]').last().click();
  await page.waitForLoadState("networkidle", { timeout: 20000 });

  await page.screenshot({ path: path.join(__dirname, "debug-step6-after-login.png") });

  const currentUrl = page.url();
  console.log(`✅ Login completado — URL: ${currentUrl}`);
}

// ── Extraer imagen de una página de producto ──────────────────────────────────
async function extractImageUrl(page, ean) {
  const productUrl = `${BASE_URL}/p/${ean}`;

  try {
    await page.goto(productUrl, { waitUntil: "domcontentloaded", timeout: 20000 });
  } catch (_) {
    // timeout parcial — intentar igual
  }

  await sleep(800); // Dar tiempo a que carguen las imágenes lazy

  // Selectores comunes en VTex (plataforma que usa Carrefour)
  const selectors = [
    'img[src*="vtexassets.com"]',
    'img[src*="carrefourassets"]',
    '.vtex-store-components-3-x-productImageTag--main',
    '.vtex-store-components-3-x-productImageTag',
    '[class*="productImageTag"] img',
    '[class*="productImage"] img',
    'figure img[src^="https"]',
  ];

  for (const sel of selectors) {
    try {
      const el = page.locator(sel).first();
      if (await el.count() > 0) {
        // Esperar que tenga src cargado
        await el.waitFor({ state: "attached", timeout: 3000 }).catch(() => {});
        const src = await el.getAttribute("src");
        if (src && src.startsWith("http") && !src.includes("placeholder")) {
          // Obtener la imagen en máxima calidad eliminando parámetros de resize
          return src.replace(/\?.*$/, "") + "?width=400&height=400&aspect=true";
        }
      }
    } catch (_) {}
  }

  // Fallback: JSON-LD structured data
  try {
    const jsonLd = await page.evaluate(() => {
      const scripts = document.querySelectorAll('script[type="application/ld+json"]');
      for (const s of scripts) {
        try {
          const d = JSON.parse(s.textContent);
          const img = d?.image?.[0] || d?.image;
          if (typeof img === "string" && img.startsWith("http")) return img;
        } catch (_) {}
      }
      return null;
    });
    if (jsonLd) return jsonLd;
  } catch (_) {}

  return null;
}

// ── Main ──────────────────────────────────────────────────────────────────────
(async () => {
  // Validar config mínima
  const required = ["CARREFOUR_EMAIL", "CARREFOUR_DNI", "CARREFOUR_PHONE", "CARREFOUR_NAME", "API_BASE_URL", "BOT_SECRET"];
  const missing  = required.filter((k) => !process.env[k]);
  if (missing.length) {
    console.error("❌ Faltan variables en .env:", missing.join(", "));
    process.exit(1);
  }

  // Leer CSV
  console.log("📂 Leyendo CSV de EANs...");
  let allEans = readEans();
  console.log(`   ${allEans.length} EANs en el CSV`);

  if (TEST_N) {
    allEans = allEans.slice(0, TEST_N);
    console.log(`   ⚠️  MODO TEST: procesando solo los primeros ${TEST_N} EANs`);
  }

  // Progreso local
  const progress = loadProgress();
  const localDone   = new Set(progress.done);
  const localFailed = new Set(progress.failed);

  // Preguntar al backend
  if (!TEST_N) {
    console.log("🌐 Consultando backend — EANs ya con imagen...");
    const apiDone = await getAlreadyDoneFromApi(allEans);
    console.log(`   ${apiDone.size} ya tienen imagen en la DB`);
    apiDone.forEach((e) => localDone.add(e));
  }

  // Pendientes
  const pending = allEans.filter((e) => !localDone.has(e));
  console.log(`   ${pending.length} EANs a procesar\n`);

  if (pending.length === 0) {
    console.log("✅ No hay nada que scrapear.");
    return;
  }

  // Lanzar browser
  const browser = await chromium.launch({
    headless: HEADLESS !== "false",
  });
  const context = await browser.newContext({
    userAgent: "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36",
    viewport: { width: 1280, height: 800 },
  });
  const page = await context.newPage();

  // Login
  try {
    await login(page);
  } catch (err) {
    console.error("❌ Login falló:", err.message);
    console.log("   Revisá los screenshots debug-step*.png para ver qué pasó");
    await browser.close();
    process.exit(1);
  }

  let batch     = [];
  let scraped   = 0;
  let notFound  = 0;
  let apiErrors = 0;
  const total     = pending.length;
  const startTime = Date.now();

  console.log(`\n🚀 Iniciando scraping de ${total} productos...\n`);

  for (let i = 0; i < pending.length; i++) {
    const ean = pending[i];

    try {
      const imageUrl = await extractImageUrl(page, ean);

      if (imageUrl) {
        batch.push({ ean, image_url: imageUrl });
        localDone.add(ean);
        scraped++;
        if (TEST_N) console.log(`  ✅ [${i + 1}/${total}] EAN ${ean} → ${imageUrl.slice(0, 80)}...`);
      } else {
        localFailed.add(ean);
        notFound++;
        if (TEST_N) console.log(`  ⚠️  [${i + 1}/${total}] EAN ${ean} — sin imagen`);
      }
    } catch (err) {
      localFailed.add(ean);
      notFound++;
      if (TEST_N) console.log(`  ❌ [${i + 1}/${total}] EAN ${ean} — error: ${err.message}`);
    }

    // Enviar batch
    if (batch.length >= BATCH) {
      try {
        const result = await pushBatchToApi(batch);
        console.log(`  📤 Batch enviado: ${result.updated}/${result.sent} actualizados en DB`);
        batch = [];
      } catch (err) {
        console.error("  ❌ Error enviando batch a la API:", err.message);
        apiErrors++;
      }
    }

    // Guardar progreso + log periódico (cada 50, o cada 5 en test)
    const logEvery = TEST_N ? 5 : 50;
    if ((i + 1) % logEvery === 0 || i === pending.length - 1) {
      progress.done   = [...localDone];
      progress.failed = [...localFailed];
      saveProgress(progress);

      if (!TEST_N) {
        const elapsed = ((Date.now() - startTime) / 1000 / 60).toFixed(1);
        const eta     = i > 0
          ? (((Date.now() - startTime) / (i + 1)) * (total - i - 1) / 1000 / 60).toFixed(0)
          : "?";
        console.log(`[${i + 1}/${total}] ✅ ${scraped} con imagen | ⚠️ ${notFound} sin imagen | ⏱ ${elapsed}min | ETA ~${eta}min`);
      }
    }

    await sleep(DELAY);
  }

  // Batch final
  if (batch.length > 0) {
    try {
      const result = await pushBatchToApi(batch);
      console.log(`  📤 Batch final: ${result.updated}/${result.sent} actualizados`);
    } catch (err) {
      console.error("  ❌ Error batch final:", err.message);
    }
  }

  progress.done   = [...localDone];
  progress.failed = [...localFailed];
  saveProgress(progress);

  await browser.close();

  const totalMin = ((Date.now() - startTime) / 1000 / 60).toFixed(1);
  console.log("\n════════════════════════════════");
  console.log(TEST_N ? "✅ Test finalizado" : "✅ Scraping finalizado");
  console.log(`   Procesados:  ${scraped + notFound} / ${total}`);
  console.log(`   Con imagen:  ${scraped}`);
  console.log(`   Sin imagen:  ${notFound}`);
  console.log(`   Errores API: ${apiErrors}`);
  console.log(`   Tiempo:      ${totalMin} minutos`);
  console.log("════════════════════════════════");
})();
