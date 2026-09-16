<?php
// API backend for Web Alias MX OVH — config + signed OVH email proxy.
// Served directly by PHP-FPM (no long-running process). Routed via query string:
//   GET  ?action=config                       → { "domains": { ... } }
//   GET  ?action=redirections&domain=<domain> → { "items": [ { id, from, to }, ... ], "unread": 0 }
//   *    ?ovh=<domain>/redirection[/<id>]     → signed proxy to /email/domain/<same path>
//
// Both ?action= routes are read-only and check their domain against the config allowlist;
// OVH_PATH_RE guards the ?ovh= proxy only.

declare(strict_types=1);

const JSON_CT = 'application/json; charset=utf-8';
const MAX_BODY = 4096;
const TIME_DELTA_TTL = 3600;
const LIST_TTL_DEFAULT = 10;
const MAX_CONCURRENCY = 12;
const OVH_TIMEOUT = 30;
const OVH_PROBE_TIMEOUT = 10;
const OVH_CONNECT_TIMEOUT = 5;

// The only sub-path the proxy will forward. An allowlist, not a denylist: neither a
// traversal (plain or double-encoded) nor a smuggled query string can widen the
// slice of the OVH API reachable through this file.
const OVH_PATH_RE = '~^(?<domain>[A-Za-z0-9.-]+)/redirection(?:/(?<id>\d+))?$~D';

// --- Helpers -----------------------------------------------------------------

function send(int $status, string $contentType, string $body): void {
  http_response_code($status);
  header('Content-Type: ' . $contentType);
  header('Cache-Control: no-store');
  header('X-Content-Type-Options: nosniff');
  echo $body;
  exit;
}

function json_body(array $data): string {
  return (string) json_encode($data, JSON_UNESCAPED_SLASHES);
}

/** Error response, shaped like OVH's own ({"message": ...}) so the UI has one path. */
function fail(int $status, string $message): void {
  send($status, JSON_CT, json_body(['message' => $message]));
}

// An endpoint that always answers JSON must never let a diagnostic into the body: a stray
// warning printed before the payload makes the response unparseable, and the caller sees
// "Error 500" instead of the real cause. Diagnostics go to the error log instead, and
// anything thrown becomes a clean 500.
ini_set('display_errors', '0');
ini_set('log_errors', '1');
set_error_handler(static function (int $no, string $message, string $file, int $line): bool {
  error_log(sprintf('wamx: %s in %s:%d', $message, $file, $line));
  return true;
});
set_exception_handler(static function (Throwable $e): void {
  error_log('wamx: uncaught ' . get_class($e) . ': ' . $e->getMessage());
  fail(500, 'Internal error — see the server error log');
});

/**
 * Reject writes a cross-site page could forge. Basic auth is replayed automatically by the
 * browser, so this cannot rest on a credential the caller would have to know.
 *
 * Sec-Fetch-Site settles it outright when present (Chrome 76+, Firefox 90+, Safari 16.4+).
 * Older browsers omit it, so fall back to matching Origin against the requested host rather
 * than presuming same-origin — scheme excluded, since a TLS-terminating proxy routinely
 * leaves PHP believing the request arrived over plain HTTP.
 *
 * The JSON content type on POST is the third layer, and the one that holds when a client
 * sends neither header: a cross-site <form> can only emit urlencoded, multipart or
 * text/plain, while any fetch carrying a JSON type — or a DELETE — preflights first, and
 * this file answers no preflight.
 */
function guard_write(string $method): void {
  $site = (string) ($_SERVER['HTTP_SEC_FETCH_SITE'] ?? '');
  if ($site !== '') {
    if ($site !== 'same-origin' && $site !== 'none') fail(403, 'Cross-site request blocked');
  } else {
    $origin = (string) ($_SERVER['HTTP_ORIGIN'] ?? '');
    if ($origin !== '' && !origin_matches_host($origin)) fail(403, 'Cross-site request blocked');
  }
  if ($method === 'POST' && stripos((string) ($_SERVER['CONTENT_TYPE'] ?? ''), 'application/json') !== 0) {
    fail(415, 'Expected Content-Type: application/json');
  }
}

function origin_matches_host(string $origin): bool {
  $host = (string) parse_url($origin, PHP_URL_HOST);
  if ($host === '') return false;
  $port = parse_url($origin, PHP_URL_PORT);
  $expected = (string) ($_SERVER['HTTP_HOST'] ?? '');
  return strcasecmp($port ? $host . ':' . $port : $host, $expected) === 0;
}

// --- Cache -------------------------------------------------------------------
// APCu when the extension is enabled. Falling back to a file is opt-in per entry, because
// the fallback directory is shared ground: the clock offset may go there (it is public data
// from an unauthenticated OVH endpoint), a redirection listing may not. Both layers are
// best-effort by design: every caller has to work when a read comes back null.

function cache_apcu(): bool {
  static $ok = null;
  if ($ok === null) $ok = function_exists('apcu_enabled') && apcu_enabled();
  return $ok;
}

function cache_dir(): string {
  return rtrim((string) (getenv('WAMX_CACHE_DIR') ?: sys_get_temp_dir()), "/\\");
}

/** Salted with the install path so two vhosts on one host never share an entry. */
function cache_key(string $name): string {
  return 'wamx-' . substr(hash('sha256', __DIR__), 0, 16) . '-' . $name;
}

function cache_get(string $name, bool $diskFallback = false): ?string {
  $key = cache_key($name);
  if (cache_apcu()) {
    $value = apcu_fetch($key, $ok);
    return $ok ? (string) $value : null;
  }
  if (!$diskFallback) return null;

  $file = cache_dir() . '/' . $key;
  // Refuse a symlink, or a file another local user owns: a poisoned clock offset would make
  // OVH reject every signed call until the entry expires.
  if (!is_file($file) || is_link($file)) return null;
  $stat = stat($file);
  if (!$stat || (function_exists('posix_getuid') && $stat['uid'] !== posix_getuid())) return null;
  $raw = file_get_contents($file);
  if ($raw === false) return null;

  [$expiry, $value] = array_pad(explode("\n", $raw, 2), 2, '');
  return is_numeric($expiry) && (int) $expiry > time() ? $value : null;
}

function cache_set(string $name, string $value, int $ttl, bool $diskFallback = false): void {
  $key = cache_key($name);
  if (cache_apcu()) {
    apcu_store($key, $value, $ttl);
    return;
  }
  if (!$diskFallback) return;

  // Write under a fresh name, then rename over the target. The target itself could be a
  // symlink someone planted; a name drawn at random a moment ago cannot be.
  $file = cache_dir() . '/' . $key;
  $tmp = $file . '.' . bin2hex(random_bytes(6));
  if (file_put_contents($tmp, (time() + $ttl) . "\n" . $value) === false) return;
  chmod($tmp, 0600);
  if (!rename($tmp, $file)) unlink($tmp);
}

/** Clears both layers unconditionally: dropping an entry is never the unsafe direction. */
function cache_delete(string $name): void {
  $key = cache_key($name);
  if (cache_apcu()) {
    apcu_delete($key);
    return;
  }
  $file = cache_dir() . '/' . $key;
  if (is_file($file)) unlink($file);
}

// --- Configuration -----------------------------------------------------------

// Kept overridable so config.php can live outside the document root: if PHP ever stops
// executing (FPM down, vhost mistake), a file inside the root is served as plain text.
$configFile = (string) (getenv('WAMX_CONFIG') ?: ($_SERVER['WAMX_CONFIG'] ?? ''));
if ($configFile === '') $configFile = __DIR__ . '/config.php';
if (!is_file($configFile)) {
  fail(500, 'Config file not found — copy config.example.php to config.php');
}
$config = require $configFile;
if (!is_array($config)) fail(500, 'Config file must return an array');

// Validated up front rather than at first use: a key missing here used to be signed as an
// empty string and came back from OVH as an opaque 403.
$ovh = $config['ovh'] ?? null;
if (!is_array($ovh)) fail(500, 'Config key "ovh" must be an array');
foreach (['endpoint', 'applicationKey', 'applicationSecret', 'consumerKey'] as $key) {
  if (!isset($ovh[$key]) || !is_string($ovh[$key]) || trim($ovh[$key]) === '') {
    fail(500, 'Config key "ovh.' . $key . '" must be a non-empty string');
  }
}
$ovh['endpoint'] = rtrim(trim($ovh['endpoint']), '/');
if (stripos($ovh['endpoint'], 'https://') !== 0) {
  fail(500, 'Config key "ovh.endpoint" must be an https:// URL');
}

// Keys are lowercased so a request for "Domain.TLD" still matches: DNS is case-insensitive.
$domains = [];
foreach (is_array($config['domains'] ?? null) ? $config['domains'] : [] as $domain => $destination) {
  $name = strtolower(trim((string) $domain));
  if ($name !== '') $domains[$name] = (string) $destination;
}
if (!$domains) fail(500, 'Config key "domains" must list at least one domain');

// Seconds a complete listing may be reused for. Kept configurable because it trades freshness
// for speed: a change made in the OVH panel takes this long to surface here. 0 disables it.
$listCacheTtl = max(0, (int) ($config['list_cache_ttl'] ?? LIST_TTL_DEFAULT));

// Off by default. Authentication is the web server's job, and a vhost that forgets it would
// otherwise expose the app silently — this makes that mistake fail closed instead.
if (($config['require_auth'] ?? false) === true) {
  $user = (string) ($_SERVER['REMOTE_USER']
    ?? $_SERVER['REDIRECT_REMOTE_USER']
    ?? $_SERVER['PHP_AUTH_USER']
    ?? '');
  if ($user === '') fail(401, 'Authentication required');
}

// --- OVH client --------------------------------------------------------------

/** OVH request signature: $1$ + sha1(secret+consumer+method+url+body+timestamp). */
function ovh_sign(array $ovh, string $method, string $url, string $body, int $ts): string {
  $s = implode('+', [$ovh['applicationSecret'], $ovh['consumerKey'], $method, $url, $body, (string) $ts]);
  return '$1$' . sha1($s);
}

/** Transport options shared by every call — pinned here rather than inherited from php.ini. */
function curl_base_opts(int $timeout = OVH_TIMEOUT): array {
  $opts = [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_CONNECTTIMEOUT => OVH_CONNECT_TIMEOUT,
    CURLOPT_TIMEOUT => $timeout,
    CURLOPT_FOLLOWLOCATION => false,
    CURLOPT_SSL_VERIFYPEER => true,
    CURLOPT_SSL_VERIFYHOST => 2,
  ];
  // Keep the handle on HTTPS whatever the endpoint turns out to be. The string form is the
  // current one; the bitmask is there for the libcurl builds that predate it.
  if (defined('CURLOPT_PROTOCOLS_STR')) $opts[CURLOPT_PROTOCOLS_STR] = 'https';
  elseif (defined('CURLPROTO_HTTPS')) $opts[CURLOPT_PROTOCOLS] = CURLPROTO_HTTPS;
  return $opts;
}

/**
 * Clock delta with OVH (server_time - local_time). Memoised per request, then cached —
 * without a shared cache every proxied call would probe /auth/time first, doubling the
 * round trips. A failed probe is never cached: storing the fallback zero would keep signing
 * with a wrong clock for the whole TTL.
 */
function ovh_time_delta(array $ovh): int {
  static $memo = null;
  if ($memo !== null) return $memo;

  $cached = cache_get('time-delta', true);
  if ($cached !== null && is_numeric($cached)) return $memo = (int) $cached;

  $ch = curl_init($ovh['endpoint'] . '/auth/time');
  curl_setopt_array($ch, curl_base_opts(OVH_PROBE_TIMEOUT));
  $resp = curl_exec($ch);
  if ($resp === false) error_log('wamx: OVH /auth/time probe failed: ' . curl_error($ch));
  curl_close($ch);
  if ($resp === false || !is_numeric(trim((string) $resp))) return $memo = 0;

  $memo = (int) trim((string) $resp) - time();
  cache_set('time-delta', (string) $memo, TIME_DELTA_TTL, true);
  return $memo;
}

/** Stable per-handle key: curl handles are objects since PHP 8.0 and resources before it. */
function handle_key($ch): int {
  return is_object($ch) ? spl_object_id($ch) : (int) $ch;
}

/** A signed, ready-to-run curl handle for one OVH call. */
function ovh_handle(array $ovh, string $method, string $apiPath, string $body) {
  $url = $ovh['endpoint'] . $apiPath;
  $ts = time() + ovh_time_delta($ovh);
  $headers = [
    'X-Ovh-Application: ' . $ovh['applicationKey'],
    'X-Ovh-Timestamp: ' . $ts,
    'X-Ovh-Signature: ' . ovh_sign($ovh, $method, $url, $body, $ts),
    'X-Ovh-Consumer: ' . $ovh['consumerKey'],
  ];
  if ($body !== '') $headers[] = 'Content-Type: application/json';

  $ch = curl_init($url);
  curl_setopt_array($ch, array_replace(curl_base_opts(), [
    CURLOPT_CUSTOMREQUEST => $method,
    CURLOPT_HTTPHEADER => $headers,
  ]));
  if ($body !== '') curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
  return $ch;
}

/** The one place a /email/domain/ path is assembled. */
function ovh_path(string $domain, ?string $id = null): string {
  return '/email/domain/' . $domain . '/redirection' . ($id !== null && $id !== '' ? '/' . $id : '');
}

/** Forward a signed request to the OVH API, returning [status, contentType, body]. */
function ovh_request(array $ovh, string $method, string $apiPath, string $body): array {
  $ch = ovh_handle($ovh, $method, $apiPath, $body);
  $resp = curl_exec($ch);
  if ($resp === false) {
    // A curl message can carry the resolved IP, a proxy host or certificate internals, so
    // it goes to the log and the caller gets a generic failure.
    error_log('wamx: OVH ' . $method . ' ' . $apiPath . ' failed: ' . curl_error($ch));
    curl_close($ch);
    return [502, JSON_CT, json_body(['message' => 'Could not reach the OVH API'])];
  }
  $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
  $ct = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
  curl_close($ch);

  // Reflect the upstream type only while it stays JSON. Labelling anything else as text
  // keeps a direct navigation to this endpoint from rendering markup that came from OVH.
  $ct = stripos($ct, 'application/json') === 0 ? JSON_CT : 'text/plain; charset=utf-8';
  return [$status, $ct, $resp];
}

/**
 * Run several signed GETs through a sliding window, returning [path => [status, body]],
 * where a status of 0 marks a call that never completed. The OVH collection endpoint only
 * yields ids, so one detail call per id is unavoidable -- running them in parallel
 * server-side turns N browser round trips into one.
 *
 * One multi handle for the whole run, on purpose: its connection cache is what lets the
 * later calls skip the TLS handshake. Refilling the window as each transfer completes,
 * rather than in fixed batches, also keeps the slowest call of a batch from stalling the
 * eleven others behind it.
 */
function ovh_get_many(array $ovh, array $apiPaths): array {
  if (!$apiPaths) return [];

  $results = [];
  $paths = array_values($apiPaths);
  $total = count($paths);
  $next = 0;
  $active = [];  // handle key => path, so resolving a completed transfer stays O(1)
  $multi = curl_multi_init();

  while ($next < $total || $active) {
    while ($next < $total && count($active) < MAX_CONCURRENCY) {
      $ch = ovh_handle($ovh, 'GET', $paths[$next], '');
      curl_multi_add_handle($multi, $ch);
      $active[handle_key($ch)] = $paths[$next];
      $next++;
    }
    curl_multi_exec($multi, $running);

    while ($done = curl_multi_info_read($multi)) {
      $ch = $done['handle'];
      $key = handle_key($ch);
      $path = $active[$key] ?? null;
      unset($active[$key]);
      if ($path !== null) {
        if ($done['result'] === CURLE_OK) {
          $results[$path] = [(int) curl_getinfo($ch, CURLINFO_HTTP_CODE), (string) curl_multi_getcontent($ch)];
        } else {
          error_log('wamx: OVH GET ' . $path . ' failed: ' . curl_error($ch));
          $results[$path] = [0, ''];
        }
      }
      curl_multi_remove_handle($multi, $ch);
      curl_close($ch);
    }

    // -1 means curl has no socket to wait on yet; a short sleep avoids a spin.
    if ($active && curl_multi_select($multi, 1.0) === -1) usleep(1000);
  }

  curl_multi_close($multi);
  return $results;
}

// --- Redirections ------------------------------------------------------------

/**
 * The listing, as the JSON body to hand back: { items: [...], unread: <int> }.
 *
 * "unread" is what keeps the list honest. Every detail call that came back unusable is
 * counted rather than dropped, because a silently shortened list — and the count the UI
 * prints beside it — looks exactly like a correct one.
 *
 * A complete answer is cached for the configured TTL: long enough to make switching back and
 * forth between domains instant, short enough for a change made elsewhere to surface on the
 * next look, and dropped outright as soon as this app writes to the domain. The listing is the
 * user's alias list, so it is cached in memory only, never in the shared fallback directory.
 */
function list_redirections(array $ovh, string $domain, int $ttl): string {
  $cached = $ttl > 0 ? cache_get('list-' . $domain) : null;
  if ($cached !== null) return $cached;

  $base = ovh_path($domain);
  [$status, , $listBody] = ovh_request($ovh, 'GET', $base, '');
  if ($status !== 200) send($status, JSON_CT, $listBody);
  $ids = json_decode($listBody, true);
  if (!is_array($ids)) fail(502, 'Unexpected response from OVH');

  $paths = array_map(static fn($id) => ovh_path($domain, rawurlencode((string) $id)), $ids);
  $responses = ovh_get_many($ovh, $paths);

  // One retry for the calls that failed in a way a retry can fix. A throttled or briefly
  // unavailable detail call is the usual reason a list comes back short, and firing a dozen
  // of them at once is what provokes it.
  $retry = [];
  foreach ($responses as $path => [$itemStatus]) {
    if ($itemStatus !== 200 && $itemStatus !== 404) $retry[] = $path;
  }
  if ($retry) $responses = array_replace($responses, ovh_get_many($ovh, $retry));

  $items = [];
  $unread = 0;
  foreach ($responses as [$itemStatus, $itemBody]) {
    // A 404 is a redirection deleted between the two calls: expected, and skipping it is
    // right. An id we cannot address is not — it would render as a dead delete button.
    if ($itemStatus === 404) continue;
    $item = $itemStatus === 200 ? json_decode($itemBody, true) : null;
    if (!is_array($item) || !isset($item['id']) || !is_numeric($item['id'])) {
      $unread++;
      continue;
    }
    $items[] = [
      'id'   => (int) $item['id'],
      'from' => (string) ($item['from'] ?? ''),
      'to'   => (string) ($item['to'] ?? ''),
    ];
  }
  usort($items, static fn($a, $b) => strcasecmp($a['from'], $b['from']));

  $body = json_body(['items' => $items, 'unread' => $unread]);
  if ($unread === 0 && $ttl > 0) cache_set('list-' . $domain, $body, $ttl);
  return $body;
}

// --- Routing -----------------------------------------------------------------

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$action = (string) ($_GET['action'] ?? '');

if ($action !== '') {
  if ($method !== 'GET') fail(405, 'Method not allowed');

  if ($action === 'config') send(200, JSON_CT, json_body(['domains' => $domains]));

  if ($action === 'redirections') {
    $domain = strtolower(trim((string) ($_GET['domain'] ?? '')));
    if (!isset($domains[$domain])) fail(403, 'Domain not allowed');
    send(200, JSON_CT, list_redirections($ovh, $domain, $listCacheTtl));
  }

  fail(404, 'Not found');
}

if (isset($_GET['ovh'])) {
  if (!in_array($method, ['GET', 'POST', 'DELETE'], true)) fail(405, 'Method not allowed');
  if ($method !== 'GET') guard_write($method);

  // Sub-path after /email/domain/ — e.g. "nsoffice.fr/redirection/42".
  $sub = ltrim((string) $_GET['ovh'], '/');
  if (!preg_match(OVH_PATH_RE, $sub, $m)) fail(400, 'Bad path');
  $domain = strtolower($m['domain']);
  if (!isset($domains[$domain])) fail(403, 'Domain not allowed');

  // Reject oversized bodies outright: truncating them yielded a misleading "Invalid JSON".
  if ((int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > MAX_BODY) fail(413, 'Payload too large');
  $body = (string) file_get_contents('php://input', false, null, 0, MAX_BODY + 1);
  if (strlen($body) > MAX_BODY) fail(413, 'Payload too large');
  if ($body !== '') {
    json_decode($body);
    if (json_last_error() !== JSON_ERROR_NONE) fail(400, 'Invalid JSON');
  }

  [$status, $ct, $out] = ovh_request($ovh, $method, ovh_path($domain, $m['id'] ?? null), $body);
  // Any write drops the burst cache, so the reload right behind it shows the new state.
  if ($method !== 'GET') cache_delete('list-' . $domain);
  send($status, $ct, $out);
}

fail(404, 'Not found');
