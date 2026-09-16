<?php
// API backend for Web Alias MX OVH — config + signed OVH email proxy.
// Served directly by PHP-FPM (no long-running process). Routed via query string:
//   GET  ?action=config                       → { "domains": { ... } }
//   GET  ?action=redirections&domain=<domain> → [ { id, from, to }, ... ] sorted by "from"
//   *    ?ovh=<domain>/redirection[/<id>]     → signed proxy to /email/domain/<same path>

declare(strict_types=1);

const JSON_CT = 'application/json; charset=utf-8';
const MAX_BODY = 65536;
const TIME_DELTA_TTL = 3600;
const MAX_CONCURRENCY = 12;

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

/** Error response, shaped like OVH's own ({"message": ...}) so the UI has one path. */
function fail(int $status, string $message): void {
  send($status, JSON_CT, (string) json_encode(['message' => $message]));
}

/**
 * Reject writes a cross-site page could forge. Basic auth is replayed automatically by
 * the browser, and a cross-site <form enctype="text/plain"> can emit a valid JSON body
 * without triggering a CORS preflight — so check the origin relationship and the type.
 */
function guard_write(string $method): void {
  $site = $_SERVER['HTTP_SEC_FETCH_SITE'] ?? 'same-origin';
  if ($site !== 'same-origin' && $site !== 'none') fail(403, 'Cross-site request blocked');
  if ($method === 'POST' && stripos((string) ($_SERVER['CONTENT_TYPE'] ?? ''), 'application/json') !== 0) {
    fail(415, 'Expected Content-Type: application/json');
  }
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
if (!is_array($config) || !isset($config['ovh'], $config['domains']) || !is_array($config['domains'])) {
  fail(500, 'Config file must return an array with "ovh" and "domains" keys');
}
$ovh = $config['ovh'];

// --- OVH client --------------------------------------------------------------

/** OVH request signature: $1$ + sha1(secret+consumer+method+url+body+timestamp). */
function ovh_sign(array $ovh, string $method, string $url, string $body, int $ts): string {
  $s = implode('+', [$ovh['applicationSecret'], $ovh['consumerKey'], $method, $url, $body, (string) $ts]);
  return '$1$' . sha1($s);
}

/**
 * Clock delta with OVH (server_time - local_time). Memoised per request, then cached in
 * APCu, then on disk — without a shared cache every proxied call would probe /auth/time
 * first, doubling the round trips. A failed probe is never cached: storing the fallback
 * zero would keep signing with a wrong clock for the whole TTL.
 */
function ovh_time_delta(array $ovh): int {
  static $memo = null;
  if ($memo !== null) return $memo;

  $key = 'ovh_time_delta';
  $file = sys_get_temp_dir() . '/wamx-ovh-time-delta';
  $hasApcu = function_exists('apcu_fetch');
  if ($hasApcu) {
    $cached = apcu_fetch($key, $ok);
    if ($ok) return $memo = (int) $cached;
  } elseif (is_file($file) && time() - (int) filemtime($file) < TIME_DELTA_TTL) {
    $raw = trim((string) file_get_contents($file));
    if (is_numeric($raw)) return $memo = (int) $raw;
  }

  $ch = curl_init($ovh['endpoint'] . '/auth/time');
  curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_CONNECTTIMEOUT => 5,
    CURLOPT_TIMEOUT => 10,
  ]);
  $resp = curl_exec($ch);
  curl_close($ch);
  if ($resp === false || !is_numeric(trim((string) $resp))) return $memo = 0;

  $memo = (int) trim((string) $resp) - time();
  if ($hasApcu) apcu_store($key, $memo, TIME_DELTA_TTL);
  else @file_put_contents($file, (string) $memo, LOCK_EX);
  return $memo;
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
  curl_setopt_array($ch, [
    CURLOPT_CUSTOMREQUEST => $method,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_CONNECTTIMEOUT => 5,
    CURLOPT_TIMEOUT => 30,
    CURLOPT_HTTPHEADER => $headers,
  ]);
  if ($body !== '') curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
  return $ch;
}

/** Forward a signed request to the OVH API, returning [status, contentType, body]. */
function ovh_request(array $ovh, string $method, string $apiPath, string $body): array {
  $ch = ovh_handle($ovh, $method, $apiPath, $body);
  $resp = curl_exec($ch);
  if ($resp === false) {
    $err = curl_error($ch);
    curl_close($ch);
    return [502, JSON_CT, (string) json_encode(['message' => $err])];
  }
  $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
  $ct = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
  curl_close($ch);
  return [$status, $ct !== '' ? $ct : JSON_CT, $resp];
}

/**
 * Run several signed GETs through a sliding window, returning [path => [status, body]].
 * The OVH collection endpoint only yields ids, so one detail call per id is unavoidable --
 * running them in parallel server-side turns N browser round trips into one.
 *
 * One multi handle for the whole run, on purpose: its connection cache is what lets the
 * later calls skip the TLS handshake. Refilling the window as each transfer completes,
 * rather than in fixed batches, also keeps the slowest call of a batch from stalling the
 * eleven others behind it.
 */
function ovh_get_many(array $ovh, array $apiPaths): array {
  $results = [];
  $paths = array_values($apiPaths);
  $total = count($paths);
  $next = 0;
  $active = [];
  $multi = curl_multi_init();

  while ($next < $total || $active) {
    while ($next < $total && count($active) < MAX_CONCURRENCY) {
      $ch = ovh_handle($ovh, 'GET', $paths[$next], '');
      curl_multi_add_handle($multi, $ch);
      $active[] = [$ch, $paths[$next]];
      $next++;
    }
    curl_multi_exec($multi, $running);

    while ($done = curl_multi_info_read($multi)) {
      $ch = $done['handle'];
      foreach ($active as $i => $pair) {
        if ($pair[0] !== $ch) continue;
        $results[$pair[1]] = [(int) curl_getinfo($ch, CURLINFO_HTTP_CODE), (string) curl_multi_getcontent($ch)];
        unset($active[$i]);
        break;
      }
      curl_multi_remove_handle($multi, $ch);
      curl_close($ch);
    }
    $active = array_values($active);

    // -1 means curl has no socket to wait on yet; a short sleep avoids a spin.
    if ($active && curl_multi_select($multi, 1.0) === -1) usleep(1000);
  }

  curl_multi_close($multi);
  return $results;
}

// --- Routing -----------------------------------------------------------------

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if (($_GET['action'] ?? '') === 'config') {
  if ($method !== 'GET') fail(405, 'Method not allowed');
  send(200, JSON_CT, (string) json_encode(['domains' => $config['domains']]));
}

if (($_GET['action'] ?? '') === 'redirections') {
  if ($method !== 'GET') fail(405, 'Method not allowed');
  $domain = (string) ($_GET['domain'] ?? '');
  if (!isset($config['domains'][$domain])) fail(403, 'Domain not allowed');

  $base = '/email/domain/' . $domain . '/redirection';
  [$status, , $listBody] = ovh_request($ovh, 'GET', $base, '');
  if ($status !== 200) send($status, JSON_CT, $listBody);
  $ids = json_decode($listBody, true);
  if (!is_array($ids)) fail(502, 'Unexpected response from OVH');

  $items = [];
  $paths = array_map(static fn($id) => $base . '/' . rawurlencode((string) $id), $ids);
  foreach (ovh_get_many($ovh, $paths) as [$itemStatus, $itemBody]) {
    // Skip anything deleted between the two calls rather than failing the whole list.
    if ($itemStatus !== 200) continue;
    $item = json_decode($itemBody, true);
    if (!is_array($item)) continue;
    $items[] = [
      'id'   => $item['id'] ?? null,
      'from' => (string) ($item['from'] ?? ''),
      'to'   => (string) ($item['to'] ?? ''),
    ];
  }
  usort($items, static fn($a, $b) => strcasecmp($a['from'], $b['from']));
  send(200, JSON_CT, (string) json_encode($items));
}

if (isset($_GET['ovh'])) {
  if (!in_array($method, ['GET', 'POST', 'DELETE'], true)) fail(405, 'Method not allowed');
  if ($method !== 'GET') guard_write($method);

  // Sub-path after /email/domain/ — e.g. "nsoffice.fr/redirection/42".
  $sub = ltrim((string) $_GET['ovh'], '/');
  if (!preg_match(OVH_PATH_RE, $sub, $m)) fail(400, 'Bad path');
  if (!isset($config['domains'][$m['domain']])) fail(403, 'Domain not allowed');

  // Reject oversized bodies outright: truncating them yielded a misleading "Invalid JSON".
  if ((int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > MAX_BODY) fail(413, 'Payload too large');
  $body = (string) file_get_contents('php://input', false, null, 0, MAX_BODY + 1);
  if (strlen($body) > MAX_BODY) fail(413, 'Payload too large');
  if ($body !== '') {
    json_decode($body);
    if (json_last_error() !== JSON_ERROR_NONE) fail(400, 'Invalid JSON');
  }

  [$status, $ct, $out] = ovh_request($ovh, $method, '/email/domain/' . $sub, $body);
  send($status, $ct, $out);
}

fail(404, 'Not found');
