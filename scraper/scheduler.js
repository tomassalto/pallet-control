/**
 * Scheduler — corre el scraper automáticamente en el horario configurado.
 *
 * Uso:
 *   node scheduler.js
 *
 * Dejarlo corriendo en segundo plano (dejar la terminal abierta o usar pm2).
 * El scraper ya salta los EANs que tienen imagen — solo procesa los pendientes.
 *
 * Configuración en .env:
 *   SCHEDULE_CRON=0 2 * * 4   → jueves 02:00 AM (default)
 *   SCHEDULE_CRON=0 3 * * 3   → miércoles 03:00 AM
 *   SCHEDULE_CRON=0 2 * * 1,4 → lunes y jueves 02:00 AM
 *   SCHEDULE_CRON=0 2 * * *   → todos los días 02:00 AM
 *
 * Formato cron: minuto hora día-del-mes mes día-de-la-semana
 *   Día de la semana: 0=domingo 1=lunes ... 4=jueves ... 6=sábado
 */

require("dotenv").config();
const cron  = require("node-cron");
const { spawn } = require("child_process");
const path  = require("path");

const SCHEDULE = process.env.SCHEDULE_CRON || "0 2 * * 4"; // jueves 02:00 AM

function runScraper() {
  const now = new Date().toLocaleString("es-AR", { timeZone: "America/Argentina/Buenos_Aires" });
  console.log(`\n[${now}] 🚀 Iniciando corrida programada del scraper...\n`);

  const child = spawn("node", [path.join(__dirname, "scrape.js")], {
    stdio: "inherit",
    env: process.env,
  });

  child.on("close", (code) => {
    const fin = new Date().toLocaleString("es-AR", { timeZone: "America/Argentina/Buenos_Aires" });
    if (code === 0) {
      console.log(`\n[${fin}] ✅ Corrida finalizada correctamente.`);
    } else {
      console.log(`\n[${fin}] ❌ El scraper terminó con código ${code}.`);
    }
  });
}

if (!cron.validate(SCHEDULE)) {
  console.error(`❌ SCHEDULE_CRON inválido: "${SCHEDULE}"`);
  console.error('   Ejemplo válido: "0 2 * * 4" (jueves 02:00 AM)');
  process.exit(1);
}

console.log("⏰ Scheduler activo");
console.log(`   Cron:    ${SCHEDULE}`);
console.log(`   Próxima corrida: ver tabla abajo`);
console.log("");
console.log("   Día  │ Cron example");
console.log("   ─────┼───────────────────");
console.log("   Lun  │ 0 2 * * 1");
console.log("   Mié  │ 0 2 * * 3");
console.log("   Jue  │ 0 2 * * 4");
console.log("   Vie  │ 0 2 * * 5");
console.log("   Diario│ 0 2 * * *");
console.log("");
console.log("   Ctrl+C para detener.");

cron.schedule(SCHEDULE, runScraper, {
  timezone: "America/Argentina/Buenos_Aires",
});
