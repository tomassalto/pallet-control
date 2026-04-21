import makeWASocket, {
  DisconnectReason,
  fetchLatestBaileysVersion,
  proto,
  initAuthCreds,
  BufferJSON,
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
