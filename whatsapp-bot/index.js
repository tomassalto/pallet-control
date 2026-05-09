import makeWASocket, {
  DisconnectReason,
  fetchLatestBaileysVersion,
  proto,
  initAuthCreds,
  BufferJSON,
  downloadMediaMessage,
} from '@whiskeysockets/baileys';
import { Boom } from '@hapi/boom';
import express from 'express';
import qrcode from 'qrcode';
import pkg from 'pg';
import pino from 'pino';

const { Pool } = pkg;

// ─────────────────────────────────────────────────────────
// Config
// ─────────────────────────────────────────────────────────
const API_SECRET   = process.env.BOT_API_SECRET   || 'changeme';
const PORT         = parseInt(process.env.PORT     || '3001', 10);
const WA_GROUP_ID  = process.env.WHATSAPP_GROUP_ID || '';   // e.g. "120363xxxxxxx@g.us"
const SESSION_ID   = process.env.SESSION_ID        || 'pallet-bot';
const LARAVEL_URL  = (process.env.LARAVEL_URL      || '').replace(/\/$/, '');

const pool = new Pool({
  connectionString: process.env.DATABASE_URL,
  ssl: process.env.DATABASE_URL?.includes('render.com') || process.env.DATABASE_URL?.includes('amazonaws')
    ? { rejectUnauthorized: false }
    : false,
});

const logger = pino({ level: 'warn' });

// ─────────────────────────────────────────────────────────
// PostgreSQL Auth State (persists session across restarts)
// ─────────────────────────────────────────────────────────
async function usePGAuthState(sessionId) {
  await pool.query(`
    CREATE TABLE IF NOT EXISTS whatsapp_sessions (
      session_id TEXT NOT NULL,
      key        TEXT NOT NULL,
      value      TEXT NOT NULL,
      PRIMARY KEY (session_id, key)
    )
  `);

  const write = async (data, file) => {
    const value = JSON.stringify(data, BufferJSON.replacer);
    await pool.query(
      `INSERT INTO whatsapp_sessions (session_id, key, value)
       VALUES ($1, $2, $3)
       ON CONFLICT (session_id, key) DO UPDATE SET value = EXCLUDED.value`,
      [sessionId, file, value]
    );
  };

  const read = async (file) => {
    const { rows } = await pool.query(
      `SELECT value FROM whatsapp_sessions WHERE session_id = $1 AND key = $2`,
      [sessionId, file]
    );
    if (!rows.length) return null;
    return JSON.parse(rows[0].value, BufferJSON.reviver);
  };

  const remove = async (file) => {
    await pool.query(
      `DELETE FROM whatsapp_sessions WHERE session_id = $1 AND key = $2`,
      [sessionId, file]
    );
  };

  const creds = (await read('creds')) ?? initAuthCreds();

  return {
    state: {
      creds,
      keys: {
        get: async (type, ids) => {
          const data = {};
          await Promise.all(
            ids.map(async (id) => {
              let value = await read(`${type}-${id}`);
              if (type === 'app-state-sync-key' && value) {
                value = proto.Message.AppStateSyncKeyData.fromObject(value);
              }
              data[id] = value;
            })
          );
          return data;
        },
        set: async (data) => {
          await Promise.all(
            Object.keys(data).flatMap((category) =>
              Object.keys(data[category]).map(async (id) => {
                const value = data[category][id];
                if (value) {
                  await write(value, `${category}-${id}`);
                } else {
                  await remove(`${category}-${id}`);
                }
              })
            )
          );
        },
      },
    },
    saveCreds: () => write(creds, 'creds'),
  };
}

// ─────────────────────────────────────────────────────────
// Ayuda
// ─────────────────────────────────────────────────────────
const HELP_TEXT = `🤖 *PalletBot — Comandos*

Mandá una foto con el texto (caption):

📦 *p* — último pallet abierto
📦 *p 2* — segundo pallet más reciente
🗂️ *b* — última base del último pallet
🗂️ *b A1* — base "A1" del último pallet
🗂️ *b 2 A1* — base "A1" del segundo pallet
🎫 *t* — último pedido abierto
🎫 *t 12345* — pedido específico

*📋 vermas* — Ver lista reciente de pallets y pedidos
_Escribí *ayuda* (sin foto) para ver esto._`;

// ─────────────────────────────────────────────────────────
// Helpers de parsing
// ─────────────────────────────────────────────────────────

// Devuelve true si el string es un índice corto: 1, 2, ... 99
const isIndex = (s) => s && /^\d{1,2}$/.test(s);

function parseCommand(caption) {
  const parts = caption.trim().toLowerCase().split(/\s+/);
  const cmd   = parts[0];

  // Normalizar alias p/b/t → pallet/base/ticket
  const typeMap = { p: 'pallet', pallet: 'pallet', b: 'base', base: 'base', t: 'ticket', ticket: 'ticket' };
  const type = typeMap[cmd];
  if (!type) return null;

  const payload = { type };

  if (type === 'pallet') {
    // p | p 2 | p PAL-xxx
    const arg = parts[1];
    if (!arg || isIndex(arg)) {
      payload.pallet_index = parseInt(arg || '1', 10);
    } else {
      payload.pallet_code = arg;
    }

  } else if (type === 'base') {
    // b | b A1 | b 2 A1
    const arg1 = parts[1];
    const rest  = parts.slice(2).join(' ');

    if (!arg1) {
      // solo "b" → último pallet, última base
      payload.pallet_index = 1;
    } else if (isIndex(arg1) && rest) {
      // "b 2 A1" → pallet index 2, base "A1"
      payload.pallet_index = parseInt(arg1, 10);
      payload.base_name    = rest;
    } else {
      // "b A1" → último pallet, base "A1"
      payload.pallet_index = 1;
      payload.base_name    = parts.slice(1).join(' ');
    }

  } else if (type === 'ticket') {
    // t | t 12345
    const arg = parts[1];
    if (!arg || isIndex(arg)) {
      payload.order_index = parseInt(arg || '1', 10);
    } else {
      payload.order_code = arg;
    }
  }

  return payload;
}

// ─────────────────────────────────────────────────────────
// Handler de fotos recibidas en el grupo
// ─────────────────────────────────────────────────────────
async function handlePhotoMessage(msg) {
  const imageMsg = msg.message?.imageMessage;
  if (!imageMsg) return;

  const caption = (imageMsg.caption || '').trim();

  if (!caption) {
    await sock.sendMessage(WA_GROUP_ID, { text: HELP_TEXT, quoted: msg });
    return;
  }

  const payload = parseCommand(caption);

  if (!payload) {
    await sock.sendMessage(WA_GROUP_ID, {
      text: `❓ Comando no reconocido.\n\n${HELP_TEXT}`,
      quoted: msg,
    });
    return;
  }

  if (!LARAVEL_URL) {
    await sock.sendMessage(WA_GROUP_ID, { text: '⚠️ Bot mal configurado (LARAVEL_URL vacío)', quoted: msg });
    return;
  }

  try {
    const buffer = await downloadMediaMessage(
      msg, 'buffer', {},
      { logger, reuploadRequest: sock.updateMediaMessage }
    );

    const form = new FormData();
    form.append('photo', new Blob([buffer], { type: 'image/jpeg' }), 'photo.jpg');

    for (const [key, val] of Object.entries(payload)) {
      form.append(key, String(val));
    }

    const res = await fetch(`${LARAVEL_URL}/api/v1/bot/upload`, {
      method:  'POST',
      headers: { 'X-Bot-Secret': API_SECRET },
      body:    form,
    });

    const json = await res.json().catch(() => ({}));

    if (!res.ok) throw new Error(json.error || json.message || `HTTP ${res.status}`);

    await sock.sendMessage(WA_GROUP_ID, {
      text: json.msg || '✅ Foto guardada',
      quoted: msg,
    });

  } catch (err) {
    console.error('[Bot] handlePhotoMessage error:', err.message);
    await sock.sendMessage(WA_GROUP_ID, {
      text: `❌ Error: ${err.message}`,
      quoted: msg,
    });
  }
}

// ─────────────────────────────────────────────────────────
// WhatsApp state
// ─────────────────────────────────────────────────────────
let sock         = null;
let currentQR    = null;
let isConnected  = false;
let isConnecting = false;
let reconnTimer  = null;

async function connect() {
  if (isConnecting) return;
  isConnecting = true;
  console.log('[WA] Connecting…');

  try {
    const { state, saveCreds } = await usePGAuthState(SESSION_ID);
    const { version }          = await fetchLatestBaileysVersion();

    sock = makeWASocket({
      version,
      auth:               state,
      logger,
      printQRInTerminal:  true,
      browser:            ['PalletBot', 'Chrome', '23.0.0'],
      connectTimeoutMs:   60_000,
      defaultQueryTimeoutMs: 30_000,
      markOnlineOnConnect: false,
    });

    sock.ev.on('creds.update', saveCreds);

    // Escuchar mensajes entrantes del grupo
    sock.ev.on('messages.upsert', async ({ messages, type }) => {
      if (type !== 'notify') return;
      for (const msg of messages) {
        if (msg.key.remoteJid !== WA_GROUP_ID) continue;
        if (msg.key.fromMe) continue;

        // Comando de texto: "ayuda" o "?"
        const text = (
          msg.message?.conversation ||
          msg.message?.extendedTextMessage?.text || ''
        ).trim().toLowerCase();

        if (text === 'ayuda' || text === '?') {
          await sock.sendMessage(WA_GROUP_ID, { text: HELP_TEXT, quoted: msg });
          continue;
        }

        if (text === 'vermas') {
          const recentItems = await getRecentItemsMessage();
          await sock.sendMessage(WA_GROUP_ID, { text: recentItems, quoted: msg });
          continue;
        }

        // Foto con caption → subir imagen
        if (msg.message?.imageMessage) {
          handlePhotoMessage(msg).catch(e => console.error('[Bot] unhandled:', e.message));
        }
      }
    });

    sock.ev.on('connection.update', async (update) => {
      const { connection, lastDisconnect, qr } = update;

      if (qr) {
        currentQR = qr;
        console.log('[WA] QR available — open GET /qr to scan');
      }

      if (connection === 'open') {
        console.log('[WA] ✅ Connected to WhatsApp');
        isConnected  = true;
        isConnecting = false;
        currentQR    = null;
      }

      if (connection === 'close') {
        isConnected  = false;
        isConnecting = false;

        const statusCode = (lastDisconnect?.error instanceof Boom)
          ? lastDisconnect.error.output?.statusCode
          : null;

        const shouldReconnect = statusCode !== DisconnectReason.loggedOut;
        console.log(`[WA] Connection closed (code ${statusCode}) — reconnect: ${shouldReconnect}`);

        if (shouldReconnect) {
          clearTimeout(reconnTimer);
          reconnTimer = setTimeout(connect, 8_000);
        } else {
          console.log('[WA] Logged out — scan QR again at GET /qr');
          // Clear stored creds so next connect shows a fresh QR
          await pool.query(
            `DELETE FROM whatsapp_sessions WHERE session_id = $1`,
            [SESSION_ID]
          );
        }
      }
    });

  } catch (err) {
    console.error('[WA] connect() error:', err.message);
    isConnecting = false;
    clearTimeout(reconnTimer);
    reconnTimer  = setTimeout(connect, 10_000);
  }
}

// ─────────────────────────────────────────────────────────
// Express API
// ─────────────────────────────────────────────────────────
const app = express();
app.use(express.json());

function requireKey(req, res, next) {
  if (req.headers['x-api-key'] !== API_SECRET) {
    return res.status(401).json({ error: 'Unauthorized' });
  }
  next();
}

/** GET /health — comprobación de estado */
app.get('/health', (_req, res) => {
  res.json({
    ok:        true,
    connected: isConnected,
    hasQR:     !!currentQR,
  });
});

/** GET /qr — muestra el QR para escanear desde el celular */
app.get('/qr', async (_req, res) => {
  if (isConnected) {
    return res.send(`
      <!DOCTYPE html><html><head><meta charset="utf-8">
      <title>WhatsApp Bot</title></head>
      <body style="font-family:sans-serif;display:flex;align-items:center;justify-content:center;height:100vh;margin:0">
        <div style="text-align:center">
          <div style="font-size:48px">✅</div>
          <h2>WhatsApp conectado</h2>
          <p style="color:#888">El bot está funcionando correctamente.</p>
        </div>
      </body></html>
    `);
  }

  if (!currentQR) {
    return res.send(`
      <!DOCTYPE html><html><head><meta charset="utf-8">
      <title>WhatsApp Bot</title>
      <meta http-equiv="refresh" content="3">
      </head>
      <body style="font-family:sans-serif;display:flex;align-items:center;justify-content:center;height:100vh;margin:0">
        <div style="text-align:center">
          <div style="font-size:48px">⏳</div>
          <h2>Generando QR…</h2>
          <p style="color:#888">La página se actualiza sola cada 3 segundos.</p>
        </div>
      </body></html>
    `);
  }

  try {
    const img = await qrcode.toDataURL(currentQR);
    res.send(`
      <!DOCTYPE html><html><head><meta charset="utf-8">
      <title>Escanear QR</title>
      <meta http-equiv="refresh" content="30">
      </head>
      <body style="font-family:sans-serif;display:flex;align-items:center;justify-content:center;height:100vh;margin:0">
        <div style="text-align:center">
          <h2>Escaneá con WhatsApp</h2>
          <p style="color:#555">WhatsApp → ⋮ → Dispositivos vinculados → Vincular dispositivo</p>
          <img src="${img}" style="width:280px;height:280px;border:1px solid #ddd;border-radius:8px" />
          <p style="color:#aaa;font-size:13px">El QR expira en ~30 seg — la página se refresca sola</p>
        </div>
      </body></html>
    `);
  } catch (err) {
    res.status(500).json({ error: err.message });
  }
});

/** POST /send — envía un mensaje al grupo
 *  Body: { message: "texto", group_id?: "120363xxx@g.us" }
 *  Header: x-api-key: <BOT_API_SECRET>
 */
app.post('/send', requireKey, async (req, res) => {
  if (!isConnected) {
    return res.status(503).json({ error: 'WhatsApp not connected' });
  }

  const { message, group_id } = req.body ?? {};
  if (!message) return res.status(400).json({ error: 'message is required' });

  const target = group_id || WA_GROUP_ID;
  if (!target)  return res.status(400).json({ error: 'WHATSAPP_GROUP_ID not configured' });

  try {
    await sock.sendMessage(target, { text: message });
    res.json({ ok: true });
  } catch (err) {
    console.error('[WA] sendMessage error:', err.message);
    res.status(500).json({ error: err.message });
  }
});

/** GET /groups — lista todos los grupos (útil para encontrar el WHATSAPP_GROUP_ID) */
app.get('/groups', requireKey, async (_req, res) => {
  if (!isConnected) {
    return res.status(503).json({ error: 'WhatsApp not connected' });
  }
  try {
    const groups = await sock.groupFetchAllParticipating();
    const list = Object.entries(groups).map(([id, g]) => ({
      id,
      name: g.subject,
      participants: g.participants?.length ?? 0,
    }));
    res.json(list);
  } catch (err) {
    res.status(500).json({ error: err.message });
  }
});

// ─────────────────────────────────────────────────────────
// Helper: obtener lista de pallets y pedidos recientes
// ─────────────────────────────────────────────────────────
async function getRecentItemsMessage() {
  try {
    // Obtener pallets recientes con pedidos y bases
    const palletsRes = await pool.query(`
      SELECT p.id, p.code, p.status, p.note, p.created_at,
             COALESCE(json_agg(DISTINCT jsonb_build_object('name', c.name)) FILTER (WHERE c.name IS NOT NULL), '[]') as customers,
             (SELECT COUNT(*) FROM pallet_base_order_items pboi
              JOIN pallet_bases pb ON pb.id = pboi.base_id
              WHERE pb.pallet_id = p.id) as product_count,
             (SELECT COUNT(*) FROM pallet_bases WHERE pallet_id = p.id) as base_count
      FROM pallets p
      LEFT JOIN order_pallet po ON po.pallet_id = p.id
      LEFT JOIN orders o ON o.id = po.order_id
      LEFT JOIN customers c ON c.id = o.customer_id
      GROUP BY p.id
      ORDER BY p.id DESC
      LIMIT 5
    `);

    // Obtener pedidos recientes
    const ordersRes = await pool.query(`
      SELECT o.id, o.code, o.status, o.created_at, c.name as customer_name,
             (SELECT COUNT(*) FROM order_items WHERE order_id = o.id) as item_count,
             (SELECT array_agg(p.code) FROM order_pallet po JOIN pallets p ON p.id = po.pallet_id WHERE po.order_id = o.id) as pallets
      FROM orders o
      LEFT JOIN customers c ON c.id = o.customer_id
      ORDER BY o.id DESC
      LIMIT 5
    `);

    const pallets = palletsRes.rows;
    const orders = ordersRes.rows;

    if (pallets.length === 0 && orders.length === 0) {
      return "📋 *Lista reciente*\n\nNo hay pallets ni pedidos registrados.";
    }

    let msg = "📋 *Lista reciente*\n\n";

    msg += "*PALLETS:*\n";
    for (const p of pallets) {
      const status = p.status === 'done' ? '✅' : '🔵';
      const customers = p.customers?.filter(c => c.name).map(c => c.name).join(', ') || '';
      const productCount = parseInt(p.product_count) || 0;
      const baseCount = parseInt(p.base_count) || 0;

      msg += `${status} *${p.code}*\n`;
      if (p.note) msg += `   📝 ${p.note}\n`;
      if (customers) msg += `   👤 ${customers}\n`;
      msg += `   📦 ${productCount} productos · ${baseCount} bases\n\n`;
    }

    msg += "*PEDIDOS:*\n";
    for (const o of orders) {
      const status = o.status === 'done' ? '✅' : (o.status === 'paused' ? '⏸️' : '🔵');
      const itemsCount = parseInt(o.item_count) || 0;
      const palletsList = o.pallets?.filter(Boolean).join(', ') || '';

      msg += `${status} *#${o.code}*\n`;
      if (o.customer_name) msg += `   👤 ${o.customer_name}\n`;
      msg += `   📦 ${itemsCount} productos`;
      if (palletsList) msg += ` · Pallets: ${palletsList}`;
      msg += `\n\n`;
    }

    msg += "_Escribí *ayuda* para ver comandos_";
    return msg;
  } catch (err) {
    console.error('[Bot] getRecentItemsMessage:', err.message);
    return "❌ Error al obtener la lista. Intentalo más tarde.";
  }
}

/** POST /logout — cierra sesión y limpia la DB */
app.post('/logout', requireKey, async (_req, res) => {
  try {
    if (sock) await sock.logout();
    await pool.query(
      `DELETE FROM whatsapp_sessions WHERE session_id = $1`,
      [SESSION_ID]
    );
    isConnected = false;
    res.json({ ok: true, message: 'Sesión cerrada. Escaneá /qr para reconectar.' });
  } catch (err) {
    res.status(500).json({ error: err.message });
  }
});

// ─────────────────────────────────────────────────────────
// Start
// ─────────────────────────────────────────────────────────
app.listen(PORT, () => console.log(`[Bot] HTTP server on :${PORT}`));
connect();
