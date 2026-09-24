// End-to-end tests for api.php: a fake OVH API over HTTPS, the real api.php behind `php -S`.
// Needs php (with curl) and openssl on the PATH. Run from the repository root: node --test
import { test, before, after } from 'node:test';
import assert from 'node:assert/strict';
import { spawn, execFileSync } from 'node:child_process';
import { createHash } from 'node:crypto';
import { mkdtempSync, readFileSync, writeFileSync } from 'node:fs';
import { createServer as createHttpsServer } from 'node:https';
import { createServer } from 'node:net';
import { tmpdir } from 'node:os';
import { join } from 'node:path';

const ROOT = join(import.meta.dirname, '..');
const TMP = mkdtempSync(join(tmpdir(), 'wamx-test-'));
const CERT = join(TMP, 'cert.pem');
const KEY = join(TMP, 'key.pem');
const ERROR_LOG = join(TMP, 'php-error.log');
const OVH = { applicationKey: 'ak', applicationSecret: 'as', consumerKey: 'ck' };
// Seconds OVH is ahead of this host; a test moves it to play a host clock being stepped.
let clockSkew = 7;

// --- Fake OVH ----------------------------------------------------------------

const redirections = {
  'acme.test': new Map([[1, 'zed'], [2, 'alpha'], [3, 'Mike']].map(([id, user]) => [id, { from: `${user}@acme.test`, to: 'me@mail.test' }])),
  // 10 fails once and must come back through the retry, 11 never answers usefully.
  'flaky.test': new Map([10, 11, 12].map(id => [id, { from: `u${id}@flaky.test`, to: 'me@mail.test' }])),
};
const failures = new Map([[10, 1], [11, Infinity]]);
const calls = [];
let nextId = 100;

function ovhHandler(port) {
  return (req, res) => {
    let body = '';
    req.on('data', chunk => body += chunk).on('end', () => {
      const reply = (status, data) => {
        res.writeHead(status, { 'content-type': 'application/json; charset=utf-8' });
        res.end(JSON.stringify(data));
      };
      if (req.url === '/1.0/auth/time') return reply(200, Math.floor(Date.now() / 1000) + clockSkew);

      const ts = Number(req.headers['x-ovh-timestamp']);
      const expected = '$1$' + createHash('sha1')
        .update([OVH.applicationSecret, OVH.consumerKey, req.method, `https://localhost:${port}${req.url}`, body, ts].join('+'))
        .digest('hex');
      if (req.headers['x-ovh-signature'] !== expected) return reply(403, { message: 'Invalid signature' });
      if (Math.abs(ts - (Date.now() / 1000 + clockSkew)) > 2) return reply(400, { message: 'Invalid timestamp' });

      calls.push({ method: req.method, path: req.url, body });
      const m = req.url.match(/^\/1\.0\/email\/domain\/([^/]+)\/redirection(?:\/(\d+))?$/);
      const items = m && redirections[m[1]];
      if (!items) return reply(404, { message: 'This service does not exist' });
      const id = m[2] && Number(m[2]);

      if (req.method === 'GET' && !id) return reply(200, [...items.keys()].map(String));
      if (req.method === 'GET') {
        if (failures.get(id) > 0) {
          failures.set(id, failures.get(id) - 1);
          return reply(503, { message: 'Busy' });
        }
        return items.has(id) ? reply(200, { id: String(id), ...items.get(id) }) : reply(404, { message: 'Not found' });
      }
      if (req.method === 'POST' && !id) {
        const { from, to } = JSON.parse(body);
        if ([...items.values()].some(item => item.from === from)) return reply(409, { message: `${from} already exists` });
        items.set(nextId, { from, to });
        return reply(200, { id: String(nextId++), action: 'add' });
      }
      if (req.method === 'DELETE' && id) {
        return items.delete(id) ? reply(200, { action: 'delete' }) : reply(404, { message: 'This redirection does not exist' });
      }
      reply(400, { message: 'Unexpected call' });
    });
  };
}

// --- Harness -----------------------------------------------------------------

let base, ovhServer, php;

const freePort = () => new Promise(resolve => {
  const server = createServer().listen(0, '127.0.0.1', () => {
    const { port } = server.address();
    server.close(() => resolve(port));
  });
});

const api = (query, init) => fetch(`${base}/api.php?${query}`, init);
const post = (action, body, headers = {}) => api(`action=${action}`, {
  method: 'POST',
  headers: { 'content-type': 'application/json', ...headers },
  body: typeof body === 'string' ? body : JSON.stringify(body),
});

async function expectError(response, status, message) {
  assert.equal(response.status, status);
  assert.deepEqual(await response.json(), { message });
}

async function list(domain) {
  const response = await api(`action=list&domain=${domain}`);
  assert.equal(response.status, 200);
  return response.json();
}

before(async () => {
  execFileSync('openssl', ['req', '-x509', '-newkey', 'rsa:2048', '-nodes', '-days', '1', '-keyout', KEY, '-out', CERT,
    '-subj', '/CN=localhost', '-addext', 'subjectAltName=DNS:localhost'], { stdio: 'ignore' });

  const ovhPort = await freePort();
  ovhServer = createHttpsServer({ key: readFileSync(KEY), cert: readFileSync(CERT) }, ovhHandler(ovhPort));
  await new Promise(resolve => ovhServer.listen(ovhPort, 'localhost', resolve));

  writeFileSync(join(TMP, 'config.json'), JSON.stringify({
    ovh: { endpoint: `https://localhost:${ovhPort}/1.0/`, ...OVH },
    domains: { 'acme.test': 'me@mail.test', 'Flaky.Test': 'me@mail.test', 'gone.test': 'me@mail.test' },
  }));
  writeFileSync(join(TMP, 'config.php'), "<?php return json_decode(file_get_contents(__DIR__ . '/config.json'), true);\n");

  const phpPort = await freePort();
  base = `http://127.0.0.1:${phpPort}`;
  // Both CA settings: which one the curl extension honours depends on the platform build.
  php = spawn('php', ['-d', `curl.cainfo=${CERT}`, '-d', `openssl.cafile=${CERT}`, '-d', `error_log=${ERROR_LOG}`,
    '-S', `127.0.0.1:${phpPort}`, '-t', ROOT], {
    env: { ...process.env, WAMX_CONFIG: join(TMP, 'config.php'), WAMX_CACHE_DIR: TMP },
    stdio: 'ignore',
  });
  for (let i = 0; i < 50; i++) {
    try { await fetch(base); return; } catch { await new Promise(r => setTimeout(r, 100)); }
  }
  throw new Error('php -S did not start');
});

after(() => {
  php?.kill();
  ovhServer?.close();
});

// --- Reads -------------------------------------------------------------------

test('config lists the domains, lowercased', async () => {
  const response = await api('action=config');
  assert.equal(response.status, 200);
  assert.equal(response.headers.get('cache-control'), 'no-store');
  assert.deepEqual(Object.keys((await response.json()).domains), ['acme.test', 'flaky.test', 'gone.test']);
});

test('list is signed, complete and sorted case-insensitively', async () => {
  assert.deepEqual(await list('acme.test'), {
    items: [
      { id: 2, from: 'alpha@acme.test', to: 'me@mail.test' },
      { id: 3, from: 'Mike@acme.test', to: 'me@mail.test' },
      { id: 1, from: 'zed@acme.test', to: 'me@mail.test' },
    ],
    unread: 0,
  });
});

test('list retries once, then counts what it could not read', async () => {
  const { items, unread } = await list('FLAKY.test');
  assert.deepEqual(items.map(item => item.id), [10, 12]);
  assert.equal(unread, 1);
});

test('list relays an OVH error with its own status and message', async () => {
  await expectError(await api('action=list&domain=gone.test'), 404, 'This service does not exist');
});

test('list refuses a domain outside the config, a missing one and a POST', async () => {
  await expectError(await api('action=list&domain=evil.test'), 403, 'Domain not allowed');
  await expectError(await api('action=list'), 400, 'Field "domain" is required');
  await expectError(await api('action=list&domain[]=acme.test'), 400, 'Field "domain" is required');
  await expectError(await api('action=list&domain=acme.test', { method: 'POST' }), 405, 'Method not allowed');
});

test('an unknown action is a 404', async () => {
  await expectError(await api('action=nope'), 404, 'Not found');
  await expectError(await api('ovh=acme.test/redirection'), 404, 'Not found');
});

// --- Writes ------------------------------------------------------------------

test('writes are refused from a cross-site context, before OVH is called', async () => {
  const before = calls.length;
  const body = { domain: 'acme.test', from: 'x@acme.test', to: 'me@mail.test' };
  await expectError(await post('add', body, { 'sec-fetch-site': 'cross-site' }), 403, 'Cross-site request blocked');
  await expectError(await post('add', body, { origin: 'https://evil.test' }), 403, 'Cross-site request blocked');
  await expectError(await post('add', body, { 'content-type': 'text/plain' }), 415, 'Expected Content-Type: application/json');
  await expectError(await api('action=add'), 405, 'Method not allowed');
  assert.equal(calls.length, before);
});

test('writes validate their body, before OVH is called', async () => {
  const before = calls.length;
  const add = (from, to) => post('add', { domain: 'acme.test', from, to });
  await expectError(await post('add', '{nope'), 400, 'Invalid JSON');
  await expectError(await post('add', { a: 'x'.repeat(5000) }), 413, 'Payload too large');
  await expectError(await add('x@acme.test'), 400, 'Field "to" is required');
  await expectError(await post('add', { domain: 'evil.test', from: 'x@evil.test', to: 'a@b.test' }), 403, 'Domain not allowed');
  for (const from of ['x', '@acme.test', 'x@y@acme.test', 'x y@acme.test']) {
    await expectError(await add(from, 'me@mail.test'), 400, 'Field "from" is not a valid address');
  }
  await expectError(await add('x@other.test', 'me@mail.test'), 400, 'Field "from" must be an address on acme.test');
  await expectError(await add('x@acme.test', 'nope'), 400, 'Field "to" is not a valid address');
  for (const id of ['12abc', 0, -1, 1.5, null]) {
    await expectError(await post('delete', { domain: 'acme.test', id }), 400, 'Field "id" must be a positive integer');
  }
  assert.equal(calls.length, before);
});

test('add builds the OVH body itself, answers a bare 204, and the entry shows up at once', async () => {
  const response = await post('add', { domain: 'acme.test', from: 'new@ACME.test', to: 'me@mail.test', localCopy: true },
    { 'sec-fetch-site': 'same-origin', origin: base });
  assert.equal(response.status, 204);
  assert.equal(response.headers.get('content-type'), null);
  assert.deepEqual(JSON.parse(calls.at(-1).body), { from: 'new@ACME.test', to: 'me@mail.test', localCopy: false });
  assert.ok((await list('acme.test')).items.some(item => item.from === 'new@ACME.test'));
});

test('add relays the OVH refusal', async () => {
  await expectError(await post('add', { domain: 'acme.test', from: 'zed@acme.test', to: 'me@mail.test' }), 409, 'zed@acme.test already exists');
});

test('delete removes the entry, and relays OVH when it is already gone', async () => {
  assert.equal((await post('delete', { domain: 'acme.test', id: 2 })).status, 204);
  assert.ok(!(await list('acme.test')).items.some(item => item.id === 2));
  await expectError(await post('delete', { domain: 'acme.test', id: '2' }), 404, 'This redirection does not exist');
});

test('a host clock stepped since the offset was cached is caught up with, invisibly', async () => {
  clockSkew = 60;
  assert.equal((await post('delete', { domain: 'acme.test', id: 3 })).status, 204);
  assert.ok(!(await list('acme.test')).items.some(item => item.id === 3));
});

test('nothing reached the PHP error log', () => {
  let log = '';
  try { log = readFileSync(ERROR_LOG, 'utf8'); } catch { /* never written */ }
  assert.equal(log, '');
});
