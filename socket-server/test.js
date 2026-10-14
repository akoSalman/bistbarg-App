'use strict';
// End-to-end check: starts the server, connects three phones (guest, member 5, member 7),
// publishes to each audience and checks who was notified. Run with: npm test
const { spawn } = require('child_process');
const crypto = require('crypto');
const WebSocket = require('ws');
const assert = require('assert');

const SECRET = 'test-secret-0123456789abcdef';
const PORT = 18090;
const base = `http://127.0.0.1:${PORT}`;

const b64url = (b) => Buffer.from(b).toString('base64').replace(/=+$/, '').replace(/\+/g, '-').replace(/\//g, '_');
function token(u, d, ttl = 3600) {
  // Same format the WordPress plugin produces.
  const body = b64url(JSON.stringify({ u, d, e: Math.floor(Date.now() / 1000) + ttl }));
  return body + '.' + b64url(crypto.createHmac('sha256', SECRET).update(body).digest());
}
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

function connect(tok) {
  return new Promise((resolve, reject) => {
    const ws = new WebSocket(`ws://127.0.0.1:${PORT}/?token=${encodeURIComponent(tok)}`);
    ws.got = [];
    ws.on('message', (m) => {
      const msg = JSON.parse(m);
      if (msg.type === 'hello') resolve(ws);
      if (msg.type === 'notification') ws.got.push(msg.id);
    });
    ws.on('unexpected-response', (req, res) => reject(new Error('HTTP ' + res.statusCode)));
    ws.on('error', reject);
  });
}

async function publish(body, secret = SECRET) {
  const res = await fetch(base + '/publish', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', 'X-Bistbarg-Secret': secret },
    body: JSON.stringify(body),
  });
  return { status: res.status, json: await res.json() };
}

(async () => {
  const server = spawn(process.execPath, [__dirname + '/server.js'], {
    env: { ...process.env, BISTBARG_SECRET: SECRET, PORT: String(PORT) },
    stdio: ['ignore', 'pipe', 'inherit'],
  });
  await new Promise((r) => server.stdout.once('data', r));
  try {
    const guest = await connect(token(0, 'device-guest'));
    const five = await connect(token(5, 'device-five'));
    const seven = await connect(token(7, 'device-seven'));

    assert.strictEqual((await publish({ id: 1, audience: 'all' })).json.delivered, 3);
    assert.strictEqual((await publish({ id: 2, audience: 'members' })).json.delivered, 2);
    assert.strictEqual((await publish({ id: 3, audience: 'user', user_id: 7 })).json.delivered, 1);
    assert.strictEqual((await publish({ id: 4 }, 'wrong-secret-wrong-secret')).status, 403);
    await sleep(200);
    assert.deepStrictEqual(guest.got, [1]);
    assert.deepStrictEqual(five.got, [1, 2]);
    assert.deepStrictEqual(seven.got, [1, 2, 3]);

    await assert.rejects(connect(token(5, 'x', -10)), /401/, 'expired token must be refused');
    await assert.rejects(connect(token(5, 'x').slice(0, -2) + 'AA'), /401/, 'tampered token must be refused');

    const health = await (await fetch(base + '/health', { headers: { 'X-Bistbarg-Secret': SECRET } })).json();
    assert.strictEqual(health.clients, 3);
    assert.strictEqual(health.members, 2);

    [guest, five, seven].forEach((w) => w.close());
    console.log('All socket server tests passed ✔');
  } finally {
    server.kill('SIGTERM');
  }
})().catch((e) => {
  console.error(e);
  process.exit(1);
});
