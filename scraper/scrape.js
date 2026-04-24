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
  FAST_MODE  = "true",      // Solo usa URL directa (sin browser). "false" para forzar scraping
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
  // Volcar el HTML de la página para debug
  fs.writeFileSync(path.join(__dirname, "debug-page0.html"), await page.content());

  // Intentar distintos selectores para el botón "Ingresar" del header
  const ingresarSelectors = [
    'a:has-text("Ingresar")',
    'button:has-text("Ingresar")',
    'span:has-text("Ingresar")',
    '[class*="login"]:has-text("Ingresar")',
    '[class*="ingresar"]',
    '[href*="login"]',
  ];
  let clickedIngresar = false;
  for (const sel of ingresarSelectors) {
    try {
      const el = page.locator(sel).first();
      if (await el.count() > 0) {
        await el.click({ timeout: 5000 });
        console.log(`   → 'Ingresar' clickeado con selector: ${sel}`);
        clickedIngresar = true;
        break;
      }
    } catch (_) {}
  }
  if (!clickedIngresar) {
    console.log("   ⚠️  No se encontró el botón Ingresar — intentando con click en coordenadas...");
    await page.mouse.click(1450, 55); // posición aproximada del botón en 1280x800
  }
  await sleep(2000);

  // Screenshot + HTML tras click en Ingresar
  await page.screenshot({ path: path.join(__dirname, "debug-step1.png"), fullPage: false });
  fs.writeFileSync(path.join(__dirname, "debug-page1.html"), await page.content());
  console.log("   📸 debug-step1.png guardado");

  // Paso 3: click "Comercio o Emprendimiento"
  // El botón es una imagen: <img src="...comerciante.png" alt="Icon">
  console.log("   → Click 'Comercio o Emprendimiento'");
  await page.waitForSelector('img[src*="comerciante.png"]', { timeout: 10000 });
  await page.locator('img[src*="comerciante.png"]').first().click();
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

  await page.locator('#user-name,  input[name="name"]').first().fill(CARREFOUR_NAME);
  await page.locator('#user-cuit,  input[name="numberId"]').first().fill(CARREFOUR_DNI);
  await page.locator('#user-phone, input[name="phone"]').first().fill(CARREFOUR_PHONE);
  await page.locator('#user-email, input[name="email"]').first().fill(CARREFOUR_EMAIL);

  await page.screenshot({ path: path.join(__dirname, "debug-step5.png") });

  // Paso 7: click "Ingresar" (botón submit del formulario)
  console.log("   → Click 'Ingresar' (submit)");
  await page.locator('#btn_step2').click();
  await page.waitForLoadState("networkidle", { timeout: 20000 });

  await page.screenshot({ path: path.join(__dirname, "debug-step6-after-login.png") });

  const currentUrl = page.url();
  console.log(`✅ Login completado — URL: ${currentUrl}`);
}

// ── Verificar si una URL de imagen existe (HEAD request) ─────────────────────
const NO_IMAGE_MARKERS = ["noimage", "no-image", "placeholder"];

async function checkImageUrl(url) {
  try {
    const res = await fetch(url, { method: "HEAD", signal: AbortSignal.timeout(5000) });
    if (!res.ok) return false;
    const ct = res.headers.get("content-type") || "";
    return ct.startsWith("image/") && !NO_IMAGE_MARKERS.some((m) => url.includes(m));
  } catch (_) {
    return false;
  }
}

// ── Extraer imagen de una página de producto ──────────────────────────────────
async function extractImageUrl(page, ean) {
  // Estrategia 1: URL directa por EAN (sin navegar, rapidísimo)
  // El sitio expone las imágenes en: tupedido.carrefour.com.ar/imagenesPDA/{EAN}.jpg
  const directUrl = `https://tupedido.carrefour.com.ar/imagenesPDA/${ean}.jpg`;
  if (await checkImageUrl(directUrl)) {
    return directUrl;
  }

  // Estrategia 2: navegar a la página del producto y leer img.p_principal_img
  const productUrl = `${BASE_URL}/p/${ean}`;
  try {
    await page.goto(productUrl, { waitUntil: "domcontentloaded", timeout: 20000 });
  } catch (_) {}

  await sleep(500);

  try {
    const el = page.locator("img.p_principal_img").first();
    if (await el.count() > 0) {
      const src = await el.getAttribute("src");
      if (src && src.startsWith("http") && !NO_IMAGE_MARKERS.some((m) => src.includes(m))) {
        return src;
      }
    }
  } catch (_) {}

  return null;
}

// ── Main ──────────────────────────────────────────────────────────────────────
(async () => {
  const isFast = FAST_MODE !== "false";

  // En fast mode solo necesitamos API_BASE_URL y BOT_SECRET
  const required = isFast
    ? ["API_BASE_URL", "BOT_SECRET"]
    : ["CARREFOUR_EMAIL", "CARREFOUR_DNI", "CARREFOUR_PHONE", "CARREFOUR_NAME", "API_BASE_URL", "BOT_SECRET"];
  const missing = required.filter((k) => !process.env[k]);
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
  console.log(`   ${pending.length} EANs a procesar`);

  if (pending.length === 0) {
    console.log("✅ No hay nada que scrapear.");
    return;
  }

  // Modo rápido: solo HTTP, sin browser (usa URL directa por EAN)
  // Modo completo: Playwright con login para páginas que no tienen URL directa
  let browser = null;
  let page    = null;

  if (isFast) {
    console.log("⚡ FAST MODE: usando URL directa (sin browser)\n");
  } else {
    console.log("🌐 Modo completo: iniciando browser con login\n");
    browser = await chromium.launch({ headless: HEADLESS !== "false" });
    const context = await browser.newContext({
      userAgent: "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36",
      viewport: { width: 1280, height: 800 },
    });
    page = await context.newPage();
    try {
      await login(page);
    } catch (err) {
      console.error("❌ Login falló:", err.message);
      await browser.close();
      process.exit(1);
    }
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
      // En fast mode usamos solo la URL directa (sin browser)
      const imageUrl = isFast
        ? (await checkImageUrl(`https://tupedido.carrefour.com.ar/imagenesPDA/${ean}.jpg`)
            ? `https://tupedido.carrefour.com.ar/imagenesPDA/${ean}.jpg`
            : null)
        : await extractImageUrl(page, ean);

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

    // Fast mode: delay corto (solo HEAD requests), modo completo: delay más largo
    await sleep(isFast ? 200 : DELAY);
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

  if (browser) await browser.close();

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
