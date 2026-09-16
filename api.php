<?php
// API backend for Web Alias MX OVH — config + signed OVH email proxy.
// Served directly by PHP-FPM (no long-running process). Routed via query string:
//   GET  ?action=config                       → { "domains": { ... } }
//   *    ?ovh=<domain>/redirection[/<id>]     → signed proxy to /email/domain/<same path>

declare(strict_types=1);

const JSON_CT = 'application/json; charset=utf-8';

// The only sub-path the proxy will forward. An allowlist, not a denylist: neither a
// traversal (plain or double-encoded) nor a smuggled query string can widen the
// slice of the OVH API reachable through this file.
const OVH_PATH_RE = '~^(?<domain>[A-Za-z0-9.-]+)/redirection(?:/(?<id>\d+))?$~D';

// --- Helpers -----------------------------------------------------------------

function send(int $status, string $contentType, string $body): void {
  http_response_code($status);
  header('Content-Type: ' . $contentType);
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

$configFile = __DIR__ . '/config.php';
if (!is_file($configFile)) {
  fail(500, 'config.php not found — copy config.example.php to config.php');
}
$config = require $configFile;
$ovh = $config['ovh'];

// --- OVH client --------------------------------------------------------------

/** OVH request signature: $1$ + sha1(secret+consumer+method+url+body+timestamp). */
function ovh_sign(array $ovh, string $method, string $url, string $body, int $ts): string {
  $s = implode('+', [$ovh['applicationSecret'], $ovh['consumerKey'], $method, $url, $body, (string) $ts]);
  return '$1$' . sha1($s);
}

/** Clock delta with OVH (server_time - local_time), cached ~5 min via APCu when available. */
function ovh_time_delta(array $ovh): int {
  $key = 'ovh_time_delta';
  if (function_exists('apcu_fetch')) {
    $cached = apcu_fetch($key, $ok);
    if ($ok) return (int) $cached;
  }
  $ch = curl_init($ovh['endpoint'] . '/auth/time');
  curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10]);
  $resp = curl_exec($ch);
  curl_close($ch);
  $delta = ($resp !== false && is_numeric(trim($resp))) ? ((int) trim($resp) - time()) : 0;
  if (function_exists('apcu_store')) apcu_store($key, $delta, 300);
  return $delta;
}

/** Forward a signed request to the OVH API, returning [status, contentType, body]. */
function ovh_request(array $ovh, string $method, string $apiPath, string $body): array {
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
    CURLOPT_TIMEOUT => 30,
    CURLOPT_HTTPHEADER => $headers,
  ]);
  if ($body !== '') curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
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

// --- Routing -----------------------------------------------------------------

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if (($_GET['action'] ?? '') === 'config') {
  if ($method !== 'GET') fail(405, 'Method not allowed');
  send(200, JSON_CT, (string) json_encode(['domains' => $config['domains']]));
}

if (isset($_GET['ovh'])) {
  if (!in_array($method, ['GET', 'POST', 'DELETE'], true)) fail(405, 'Method not allowed');
  if ($method !== 'GET') guard_write($method);

  // Sub-path after /email/domain/ — e.g. "nsoffice.fr/redirection/42".
  $sub = ltrim((string) $_GET['ovh'], '/');
  if (!preg_match(OVH_PATH_RE, $sub, $m)) fail(400, 'Bad path');
  if (!isset($config['domains'][$m['domain']])) fail(403, 'Domain not allowed');

  $body = file_get_contents('php://input', false, null, 0, 64 * 1024);
  if ($body === false) $body = '';
  if ($body !== '') {
    json_decode($body);
    if (json_last_error() !== JSON_ERROR_NONE) fail(400, 'Invalid JSON');
  }

  [$status, $ct, $out] = ovh_request($ovh, $method, '/email/domain/' . $sub, $body);
  send($status, $ct, $out);
}

fail(404, 'Not found');
