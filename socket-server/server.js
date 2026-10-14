'use strict';
/**
 * Bistbarg real-time notification server.
 *
 *  - Phones connect with WebSocket:  wss://<site>/bistbarg-socket/?token=<token from WordPress>
 *    The token is signed by the WordPress plugin with the shared SECRET and says which customer
 *    (user id, 0 = guest) and which install (device id) is connecting.
 *  - WordPress tells this server about a new notification:
 *      POST /publish   (header X-Bistbarg-Secret: SECRET)
 *      {"id": 12, "audience": "all" | "members" | "user", "user_id": 5}
 *    and the matching phones get {"type":"notification","id":12}. They then fetch the content from
 *    WordPress with their own login, so nothing private travels over the socket.
 *  - GET /health (same header) returns how many phones are connected.
 *
 * Settings (environment variables):
 *   BISTBARG_SECRET  required, the "socket secret" shown in the WordPress plugin settings
 *   PORT             default 8090
 *   HOST             default 127.0.0.1 (keep it local and put nginx/Apache in front for TLS)
 */

const http = require('http');
const crypto = require('crypto');
const { WebSocketServer } = require('ws');

const SECRET = process.env.BISTBARG_SECRET || '';
const PORT = parseInt(process.env.PORT || '8090', 10);
const HOST = process.env.HOST || '127.0.0.1';
const HEARTBEAT_MS = 30000;

if (SECRET.length < 16) {
  console.error('BISTBARG_SECRET is missing or too short. Copy it from WordPress → اعلان‌های اپ → تنظیمات.');
  process.exit(1);
}

const log = (...args) => console.log(new Date().toISOString(), ...args);

function b64url(buf) {
  return Buffer.from(buf).toString('base64').replace(/=+$/, '').replace(/\+/g, '-').replace(/\//g, '_');
}

/** Token = base64url(JSON payload) + "." + base64url(HMAC-SHA256(payload part, SECRET)). */
function verifyToken(token) {
  if (typeof token !== 'string') return null;
  const [body, sig] = token.split('.');
  if (!body || !sig) return null;
  const expected = b64url(crypto.createHmac('sha256', SECRET).update(body).digest());
  const a = Buffer.from(sig);
  const b = Buffer.from(expected);
  if (a.length !== b.length || !crypto.timingSafeEqual(a, b)) return null;
  let payload;
  try {
    payload = JSON.parse(Buffer.from(body.replace(/-/g, '+').replace(/_/g, '/'), 'base64').toString('utf8'));
  } catch (e) {
    return null;
  }
  if (!payload || typeof payload.e !== 'number' || payload.e * 1000 < Date.now()) return null;
  return { userId: Number(payload.u) || 0, deviceId: String(payload.d || '') };
}

function secretOk(req) {
  const given = Buffer.from(String(req.headers['x-bistbarg-secret'] || ''));
  const want = Buffer.from(SECRET);
  return given.length === want.length && crypto.timingSafeEqual(given, want);
}

function sendJson(res, status, data) {
  res.writeHead(status, { 'Content-Type': 'application/json' });
  res.end(JSON.stringify(data));
}

function readBody(req, limit = 64 * 1024) {
  return new Promise((resolve, reject) => {
    let size = 0;
    const chunks = [];
    req.on('data', (c) => {
      size += c.length;
      if (size > limit) {
        reject(new Error('too large'));
        req.destroy();
      } else {
        chunks.push(c);
      }
    });
    req.on('end', () => resolve(Buffer.concat(chunks).toString('utf8')));
    req.on('error', reject);
  });
}

const wss = new WebSocketServer({ noServer: true, maxPayload: 4 * 1024 });

function matches(client, audience, userId) {
  if (audience === 'all') return true;
  if (audience === 'members') return client.userId > 0;
  if (audience === 'user') return userId > 0 && client.userId === userId;
  return false;
}

function publish(msg) {
  const audience = String(msg.audience || 'all');
  const userId = Number(msg.user_id) || 0;
  const payload = JSON.stringify({ type: 'notification', id: Number(msg.id) || 0 });
  let delivered = 0;
  for (const ws of wss.clients) {
    if (ws.readyState === ws.OPEN && matches(ws.client, audience, userId)) {
      ws.send(payload);
      delivered++;
    }
  }
  return delivered;
}

const server = http.createServer(async (req, res) => {
  const url = new URL(req.url, 'http://localhost');
  const path = url.pathname.replace(/\/+$/, '') || '/';

  if (req.method === 'POST' && path.endsWith('/publish')) {
    if (!secretOk(req)) return sendJson(res, 403, { error: 'forbidden' });
    try {
      const msg = JSON.parse(await readBody(req));
      const delivered = publish(msg);
      log(`publish #${msg.id} audience=${msg.audience} user=${msg.user_id || 0} → ${delivered} phones`);
      return sendJson(res, 200, { ok: true, delivered });
    } catch (e) {
      return sendJson(res, 400, { error: 'bad request' });
    }
  }

  if (req.method === 'GET' && path.endsWith('/health')) {
    if (!secretOk(req)) return sendJson(res, 403, { error: 'forbidden' });
    let members = 0;
    for (const ws of wss.clients) if (ws.client && ws.client.userId > 0) members++;
    return sendJson(res, 200, { ok: true, clients: wss.clients.size, members, uptime: Math.round(process.uptime()) });
  }

  sendJson(res, 404, { error: 'not found' });
});

server.on('upgrade', (req, socket, head) => {
  const url = new URL(req.url, 'http://localhost');
  const client = verifyToken(url.searchParams.get('token'));
  if (!client) {
    socket.write('HTTP/1.1 401 Unauthorized\r\nConnection: close\r\n\r\n');
    socket.destroy();
    return;
  }
  wss.handleUpgrade(req, socket, head, (ws) => {
    ws.client = client;
    ws.isAlive = true;
    wss.emit('connection', ws, req);
  });
});

wss.on('connection', (ws) => {
  ws.on('pong', () => {
    ws.isAlive = true;
  });
  ws.on('message', (data) => {
    // The app may send {"type":"ping"} to keep mobile networks from dropping the connection.
    if (String(data).includes('"ping"')) ws.send('{"type":"pong"}');
  });
  ws.on('error', () => {});
  ws.send(JSON.stringify({ type: 'hello', user: ws.client.userId > 0 }));
});

const heartbeat = setInterval(() => {
  for (const ws of wss.clients) {
    if (!ws.isAlive) {
      ws.terminate();
      continue;
    }
    ws.isAlive = false;
    ws.ping();
  }
}, HEARTBEAT_MS);

wss.on('close', () => clearInterval(heartbeat));

server.listen(PORT, HOST, () => log(`Bistbarg socket server listening on ${HOST}:${PORT}`));

function shutdown() {
  log('shutting down');
  for (const ws of wss.clients) ws.close(1001, 'server restart');
  server.close(() => process.exit(0));
  setTimeout(() => process.exit(0), 3000).unref();
}
process.on('SIGTERM', shutdown);
process.on('SIGINT', shutdown);

module.exports = { verifyToken, b64url };
