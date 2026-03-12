const http = require('http');
const https = require('https');
const crypto = require('crypto');
const fs = require('fs');
const path = require('path');

const config = JSON.parse(fs.readFileSync(path.join(__dirname, 'config.json'), 'utf8'));
const { applicationKey, applicationSecret, consumerKey, endpoint } = config.ovh;
let timeDelta = 0;

function sign(method, url, body, timestamp) {
  const s = [applicationSecret, consumerKey, method, url, body, timestamp].join('+');
  return '$1$' + crypto.createHash('sha1').update(s).digest('hex');
}

function ovhRequest(method, apiPath, body) {
  return new Promise((resolve, reject) => {
    const url = endpoint + apiPath;
    const ts = Math.round(Date.now() / 1000) + timeDelta;
    const bodyStr = body ? JSON.stringify(body) : '';
    const sig = sign(method, url, bodyStr, ts);

    const parsed = new URL(url);
    const opts = {
      hostname: parsed.hostname,
      path: parsed.pathname + parsed.search,
      method,
      headers: {
        'X-Ovh-Application': applicationKey,
        'X-Ovh-Timestamp': String(ts),
        'X-Ovh-Signature': sig,
        'X-Ovh-Consumer': consumerKey,
      }
    };

    if (bodyStr) {
      opts.headers['Content-Type'] = 'application/json';
      opts.headers['Content-Length'] = Buffer.byteLength(bodyStr);
    }

    const req = https.request(opts, res => {
      let data = '';
      res.on('data', chunk => data += chunk);
      res.on('end', () => resolve({ status: res.statusCode, body: data }));
    });

    req.on('error', reject);
    if (bodyStr) req.write(bodyStr);
    req.end();
  });
}

function fetchTimeDelta() {
  return new Promise((resolve, reject) => {
    https.get(endpoint + '/auth/time', res => {
      let data = '';
      res.on('data', chunk => data += chunk);
      res.on('end', () => {
        timeDelta = parseInt(data, 10) - Math.round(Date.now() / 1000);
        console.log(`OVH time delta: ${timeDelta}s`);
        resolve();
      });
    }).on('error', reject);
  });
}

function readBody(req, limit = 64 * 1024) {
  return new Promise((resolve, reject) => {
    let body = '', size = 0;
    req.on('data', chunk => {
      size += chunk.length;
      if (size > limit) { req.destroy(); reject(new Error('Body too large')); }
      else body += chunk;
    });
    req.on('end', () => resolve(body));
  });
}

const MIME = {
  '.html': 'text/html; charset=utf-8',
  '.css': 'text/css; charset=utf-8',
  '.js': 'application/javascript; charset=utf-8',
};
const STATIC_FILES = new Set(['/', '/index.html', '/style.css', '/app.js']);
const OVH_PREFIX = '/api/ovh/email/domain/';
const ALLOWED_METHODS = new Set(['GET', 'POST', 'DELETE']);
const allowedDomains = new Set(Object.keys(config.domains));

const server = http.createServer(async (req, res) => {
  const urlPath = req.url.split('?')[0];
  try {
    if (req.method === 'GET' && STATIC_FILES.has(urlPath)) {
      const filePath = urlPath === '/' ? '/index.html' : urlPath;
      const ext = path.extname(filePath);
      const content = fs.readFileSync(path.join(__dirname, filePath), 'utf8');
      res.writeHead(200, { 'Content-Type': MIME[ext] || 'text/plain' });
      return res.end(content);
    }

    if (req.method === 'GET' && urlPath === '/api/config') {
      res.writeHead(200, { 'Content-Type': 'application/json; charset=utf-8' });
      return res.end(JSON.stringify({ domains: config.domains }));
    }

    if (req.url.startsWith(OVH_PREFIX)) {
      if (!ALLOWED_METHODS.has(req.method)) { res.writeHead(405); return res.end('Method not allowed'); }
      const rest = req.url.slice(OVH_PREFIX.length);
      const domain = rest.split('/')[0];
      if (!allowedDomains.has(domain)) { res.writeHead(403); return res.end('Domain not allowed'); }
      const apiPath = '/email/domain/' + rest;
      const raw = await readBody(req);
      let parsed = null;
      try { if (raw) parsed = JSON.parse(raw); }
      catch { res.writeHead(400, { 'Content-Type': 'application/json' }); return res.end('{"error":"Invalid JSON"}'); }
      const result = await ovhRequest(req.method, apiPath, parsed);
      res.writeHead(result.status, { 'Content-Type': 'application/json; charset=utf-8' });
      return res.end(result.body);
    }

    res.writeHead(404);
    res.end('Not found');
  } catch (err) {
    console.error(err);
    res.writeHead(500, { 'Content-Type': 'application/json; charset=utf-8' });
    res.end(JSON.stringify({ error: err.message }));
  }
});

fetchTimeDelta().then(() => {
  const port = config.port || 8080;
  server.listen(port, '127.0.0.1', () => console.log(`OVHMail → http://localhost:${port}`));
}).catch(err => {
  console.error('OVH time sync failed:', err.message);
  process.exit(1);
});
