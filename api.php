<?php
// API backend for Web Alias MX OVH — config + the OVH calls the UI needs.
// Served directly by PHP-FPM (no long-running process). Routed via query string:
//   GET  ?action=config                → { "domains": { "<domain>": "<default destination>", ... } }
//   GET  ?action=list&domain=<domain>  → { "items": [ { id, from, to }, ... ], "unread": <int> }
//   POST ?action=add     { domain, from, to } → 204
//   POST ?action=delete  { domain, id }       → 204
// Every domain is checked against the config allowlist, and every OVH request is built here:
// the caller supplies values, never a path or a body to forward. Errors are { "message": ... }.

declare(strict_types=1);

const MAX_BODY = 4096;
const TIME_DELTA_TTL = 3600;
const LIST_TTL_DEFAULT = 10;
const MAX_CONCURRENCY = 12;
const OVH_TIMEOUT = 30;
const OVH_PROBE_TIMEOUT = 10;
const OVH_CONNECT_TIMEOUT = 5;

// --- Helpers -----------------------------------------------------------------

function send(int $status, string $body = ''): never {
  http_response_code($status);
  // An empty body carries no type; header_remove() cannot stop PHP's own text/html default.
  if ($body === '') ini_set('default_mimetype', '');
  else header('Content-Type: application/json; charset=utf-8');
  header('Cache-Control: no-store');
  header('X-Content-Type-Options: nosniff');
  echo $body;
  exit;
}

function json_body(array $data): string {
  return (string) json_encode($data, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
}

/** Error response, shaped like OVH's own ({"message": ...}) so the UI has one path. */
function fail(int $status, string $message): never {
  send($status, json_body(['message' => $message]));
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

/** SetEnv and fastcgi_param land in $_SERVER, a process environment in getenv(): read both. */
function env(string $name): string {
  return (string) (getenv($name) ?: ($_SERVER[$name] ?? ''));
}

/**
 * Reject writes a cross-site page could forge. Basic auth is replayed automatically by the
 * browser, so this cannot rest on a credential the caller would have to know.
 *
 * Sec-Fetch-Site settles it outright when present (Chrome 76+, Firefox 90+, Safari 16.4+).
 * Older browsers omit it, so fall back to matching Origin against the requested host rather
 * than presuming same-origin — scheme excluded, since a TLS-terminating proxy routinely
 * leaves PHP believing the request arrived over plain HTTP.
 *
 * The JSON content type is the third layer, and the one that holds when a client sends
 * neither header: a cross-site <form> can only emit urlencoded, multipart or text/plain,
 * while any fetch carrying a JSON type preflights first, and this file answers no preflight.
 */
function guard_write(): void {
  $site = (string) ($_SERVER['HTTP_SEC_FETCH_SITE'] ?? '');
  if ($site !== '') {
    if ($site !== 'same-origin' && $site !== 'none') fail(403, 'Cross-site request blocked');
  } else {
    $origin = (string) ($_SERVER['HTTP_ORIGIN'] ?? '');
    if ($origin !== '' && !origin_matches_host($origin)) fail(403, 'Cross-site request blocked');
  }
  if (stripos((string) ($_SERVER['CONTENT_TYPE'] ?? ''), 'application/json') !== 0) {
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

/** The request body as a JSON object. Oversized bodies are refused, never truncated. */
function read_json(): array {
  $raw = (string) file_get_contents('php://input', false, null, 0, MAX_BODY + 1);
  if (strlen($raw) > MAX_BODY) fail(413, 'Payload too large');
  $data = json_decode($raw, true);
  if (!is_array($data)) fail(400, 'Invalid JSON');
  return $data;
}

/** A required, non-empty string field of a query or a JSON body, trimmed. */
function field(array $input, string $key): string {
  $value = $input[$key] ?? null;
  if (!is_string($value) || trim($value) === '') fail(400, 'Field "' . $key . '" is required');
  return trim($value);
}

/** A required address field: one "@", nothing blank on either side. The rest is OVH's call. */
function address(array $input, string $key): string {
  $value = field($input, $key);
  if (!preg_match('/^[^@\s]+@[^@\s]+$/D', $value)) fail(400, 'Field "' . $key . '" is not a valid address');
  return $value;
}

/** A redirection id, from OVH or from the caller: a positive integer, or null. */
function redirection_id(mixed $value): ?int {
  $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
  return $id === false ? null : $id;
}

// --- Cache -------------------------------------------------------------------
// APCu when the extension is enabled. Falling back to a file is opt-in per entry, because
// the fallback directory is shared ground: the clock offset may go there (it is public data
// from an unauthenticated OVH endpoint), a redirection listing may not. Both layers are
// best-effort by design: every caller has to work when a read comes back null.

function cache_apcu(): bool {
  static $ok = null;
  return $ok ??= function_exists('apcu_enabled') && apcu_enabled();
}

/** Salted with the install path so two vhosts on one host never share an entry. */
function cache_key(string $name): string {
  return 'wamx-' . substr(hash('sha256', __DIR__), 0, 16) . '-' . $name;
}

function cache_file(string $name): string {
  return rtrim(env('WAMX_CACHE_DIR') ?: sys_get_temp_dir(), '/\\') . '/' . cache_key($name);
}

function cache_get(string $name, bool $diskFallback = false): ?string {
  if (cache_apcu()) {
    $value = apcu_fetch(cache_key($name), $ok);
    return $ok ? (string) $value : null;
  }
  if (!$diskFallback) return null;

  $file = cache_file($name);
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
  if (cache_apcu()) {
    apcu_store(cache_key($name), $value, $ttl);
    return;
  }
  if (!$diskFallback) return;

  // Write under a fresh name, then rename over the target. The target itself could be a
  // symlink someone planted; a name drawn at random a moment ago cannot be.
  $file = cache_file($name);
  $tmp = $file . '.' . bin2hex(random_bytes(6));
  if (file_put_contents($tmp, (time() + $ttl) . "\n" . $value) === false) return;
  chmod($tmp, 0600);
  if (!rename($tmp, $file)) unlink($tmp);
}

/** Listings are the only entries ever dropped, and they never leave APCu. */
function cache_delete(string $name): void {
  if (cache_apcu()) apcu_delete(cache_key($name));
}

// --- Configuration -----------------------------------------------------------

// Kept overridable so config.php can live outside the document root: if PHP ever stops
// executing (FPM down, vhost mistake), a file inside the root is served as plain text.
$configFile = env('WAMX_CONFIG') ?: __DIR__ . '/config.php';
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
// The same pattern that holds for a hostname keeps a config typo from ever reaching a path.
$domains = [];
foreach (is_array($config['domains'] ?? null) ? $config['domains'] : [] as $domain => $destination) {
  $name = strtolower(trim((string) $domain));
  if (!preg_match('/^[a-z0-9.-]+$/D', $name)) fail(500, 'Config key "domains" has an invalid domain: "' . $name . '"');
  $domains[$name] = (string) $destination;
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

function allowed_domain(string $name, array $domains): string {
  $name = strtolower($name);
  if (!isset($domains[$name])) fail(403, 'Domain not allowed');
  return $name;
}

// --- OVH client --------------------------------------------------------------

/** OVH request signature: $1$ + sha1(secret+consumer+method+url+body+timestamp). */
function ovh_sign(array $ovh, string $method, string $url, string $body, int $ts): string {
  $s = implode('+', [$ovh['applicationSecret'], $ovh['consumerKey'], $method, $url, $body, (string) $ts]);
  return '$1$' . sha1($s);
}

/**
 * Transport options shared by every call. TLS verification is libcurl's default and redirects
 * are off by default, so with the endpoint checked for https:// above, only timeouts remain.
 */
function curl_opts(int $timeout): array {
  return [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_CONNECTTIMEOUT => OVH_CONNECT_TIMEOUT,
    CURLOPT_TIMEOUT => $timeout,
  ];
}

/**
 * Clock delta with OVH (server_time - local_time). Memoised per request, then cached —
 * without a shared cache every OVH call would probe /auth/time first, doubling the
 * round trips. A failed probe is never cached: storing the fallback zero would keep signing
 * with a wrong clock for the whole TTL. $refresh skips both layers and probes again.
 */
function ovh_time_delta(array $ovh, bool $refresh = false): int {
  static $memo = null;
  if ($memo !== null && !$refresh) return $memo;

  $cached = $refresh ? null : cache_get('time-delta', true);
  if ($cached !== null && is_numeric($cached)) return $memo = (int) $cached;

  $ch = curl_init($ovh['endpoint'] . '/auth/time');
  curl_setopt_array($ch, curl_opts(OVH_PROBE_TIMEOUT));
  $resp = curl_exec($ch);
  if ($resp === false) error_log('wamx: OVH /auth/time probe failed: ' . curl_error($ch));
  if ($resp === false || !is_numeric(trim($resp))) return $memo = 0;

  $memo = (int) trim($resp) - time();
  cache_set('time-delta', (string) $memo, TIME_DELTA_TTL, true);
  return $memo;
}

/** The one place an OVH path is assembled. */
function ovh_path(string $domain, ?int $id = null): string {
  return '/email/domain/' . $domain . '/redirection' . ($id === null ? '' : '/' . $id);
}

/** A signed, ready-to-run curl handle for one OVH call. */
function ovh_handle(array $ovh, string $method, string $path, string $body): CurlHandle {
  $url = $ovh['endpoint'] . $path;
  $ts = time() + ovh_time_delta($ovh);
  $headers = [
    'X-Ovh-Application: ' . $ovh['applicationKey'],
    'X-Ovh-Timestamp: ' . $ts,
    'X-Ovh-Signature: ' . ovh_sign($ovh, $method, $url, $body, $ts),
    'X-Ovh-Consumer: ' . $ovh['consumerKey'],
  ];
  if ($body !== '') $headers[] = 'Content-Type: application/json';

  $ch = curl_init($url);
  curl_setopt_array($ch, curl_opts(OVH_TIMEOUT) + [
    CURLOPT_CUSTOMREQUEST => $method,
    CURLOPT_HTTPHEADER => $headers,
  ]);
  if ($body !== '') curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
  return $ch;
}

/**
 * One signed call, as [status, decoded body]. A status of 0 marks a call that never completed:
 * a curl message can carry the resolved IP, a proxy host or certificate internals, so it goes
 * to the log and the caller only learns that OVH was out of reach.
 */
function ovh_call(array $ovh, string $method, string $path, ?array $payload = null): array {
  $body = $payload === null ? '' : json_body($payload);
  $send = static function () use ($ovh, $method, $path, $body): array {
    $ch = ovh_handle($ovh, $method, $path, $body);
    $resp = curl_exec($ch);
    if ($resp === false) {
      error_log('wamx: OVH ' . $method . ' ' . $path . ' failed: ' . curl_error($ch));
      return [0, null];
    }
    return [(int) curl_getinfo($ch, CURLINFO_HTTP_CODE), json_decode($resp, true)];
  };

  // A refused call may be signed with a clock offset the cache kept after the host clock was
  // stepped. Probe again, and replay the call once if the offset moved: a refused call was
  // never applied, so replaying a write is safe.
  $result = $send();
  if (in_array($result[0], [400, 401, 403], true) && ovh_time_delta($ovh) !== ovh_time_delta($ovh, true)) {
    $result = $send();
  }
  return $result;
}

/** Relay a failed OVH call with OVH's own message, which is what the UI shows. */
function fail_ovh(int $status, mixed $data): never {
  if ($status === 0) fail(502, 'Could not reach the OVH API');
  $message = is_array($data) && is_string($data['message'] ?? null) ? $data['message'] : 'Unexpected response from OVH';
  fail($status >= 400 ? $status : 502, $message);
}

/**
 * Run several signed GETs through a sliding window, returning [path => [status, decoded body]]
 * with the same status 0 convention as ovh_call(). The OVH collection endpoint only yields
 * ids, so one detail call per id is unavoidable -- running them in parallel server-side turns
 * N browser round trips into one.
 *
 * One multi handle for the whole run, on purpose: its connection cache is what lets the
 * later calls skip the TLS handshake. Refilling the window as each transfer completes,
 * rather than in fixed batches, also keeps the slowest call of a batch from stalling the
 * rest behind it.
 */
function ovh_get_many(array $ovh, array $paths): array {
  $results = [];
  $paths = array_values($paths);
  $total = count($paths);
  $next = 0;
  $active = [];  // handle id => path, so resolving a completed transfer stays O(1)
  $multi = curl_multi_init();

  while ($next < $total || $active) {
    while ($next < $total && count($active) < MAX_CONCURRENCY) {
      $ch = ovh_handle($ovh, 'GET', $paths[$next], '');
      curl_multi_add_handle($multi, $ch);
      $active[spl_object_id($ch)] = $paths[$next++];
    }
    curl_multi_exec($multi, $running);

    while ($done = curl_multi_info_read($multi)) {
      $ch = $done['handle'];
      $path = $active[spl_object_id($ch)];
      unset($active[spl_object_id($ch)]);
      if ($done['result'] === CURLE_OK) {
        $results[$path] = [(int) curl_getinfo($ch, CURLINFO_HTTP_CODE), json_decode((string) curl_multi_getcontent($ch), true)];
      } else {
        error_log('wamx: OVH GET ' . $path . ' failed: ' . curl_error($ch));
        $results[$path] = [0, null];
      }
      curl_multi_remove_handle($multi, $ch);
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
 * "unread" is what keeps the list honest. Every redirection that came back unusable is
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

  [$status, $ids] = ovh_call($ovh, 'GET', ovh_path($domain));
  if ($status !== 200) fail_ovh($status, $ids);
  if (!is_array($ids)) fail(502, 'Unexpected response from OVH');

  $unread = 0;
  $paths = [];
  foreach ($ids as $raw) {
    $id = redirection_id($raw);
    if ($id === null) $unread++;
    else $paths[] = ovh_path($domain, $id);
  }
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
  foreach ($responses as [$itemStatus, $item]) {
    // A 404 is a redirection deleted between the two calls: expected, and skipping it is
    // right. An id we cannot address is not — it would render as a dead delete button.
    if ($itemStatus === 404) continue;
    $id = $itemStatus === 200 && is_array($item) ? redirection_id($item['id'] ?? null) : null;
    if ($id === null) {
      $unread++;
      continue;
    }
    $items[] = [
      'id'   => $id,
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
$action = $_GET['action'] ?? '';

if ($action === 'config' || $action === 'list') {
  if ($method !== 'GET') fail(405, 'Method not allowed');
  if ($action === 'config') send(200, json_body(['domains' => $domains]));
  send(200, list_redirections($ovh, allowed_domain(field($_GET, 'domain'), $domains), $listCacheTtl));
}

if ($action === 'add' || $action === 'delete') {
  if ($method !== 'POST') fail(405, 'Method not allowed');
  guard_write();
  $input = read_json();
  $domain = allowed_domain(field($input, 'domain'), $domains);

  if ($action === 'add') {
    $from = address($input, 'from');
    if (strcasecmp(substr($from, strpos($from, '@') + 1), $domain) !== 0) {
      fail(400, 'Field "from" must be an address on ' . $domain);
    }
    $payload = ['from' => $from, 'to' => address($input, 'to'), 'localCopy' => false];
    [$status, $data] = ovh_call($ovh, 'POST', ovh_path($domain), $payload);
  } else {
    $id = redirection_id($input['id'] ?? null);
    if ($id === null) fail(400, 'Field "id" must be a positive integer');
    [$status, $data] = ovh_call($ovh, 'DELETE', ovh_path($domain, $id));
  }

  // Dropped whatever the outcome: a write that timed out may still have gone through, and
  // the reload right behind it has to show the real state.
  cache_delete('list-' . $domain);
  if ($status < 200 || $status >= 300) fail_ovh($status, $data);
  send(204);
}

fail(404, 'Not found');
