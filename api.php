<?php
// API backend for Web Alias MX OVH — config + signed OVH email proxy.
// Served directly by PHP-FPM (no long-running process). Routed via query string:
//   GET  ?action=config           → { "domains": { ... } }
//   *    ?ovh=<domain>/<subpath>   → signed proxy to /email/domain/<domain>/<subpath>

declare(strict_types=1);

$configFile = __DIR__ . '/config.php';
if (!is_file($configFile)) {
  http_response_code(500);
  header('Content-Type: application/json; charset=utf-8');
  exit('{"error":"config.php not found — copy config.example.php to config.php"}');
}
$config = require $configFile;
$ovh = $config['ovh'];

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
    return [502, 'application/json; charset=utf-8', json_encode(['error' => $err])];
  }
  $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
  $ct = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
  curl_close($ch);
  return [$status, $ct !== '' ? $ct : 'application/json; charset=utf-8', $resp];
}

function send(int $status, string $contentType, string $body) {
  http_response_code($status);
  header('Content-Type: ' . $contentType);
  echo $body;
  exit;
}

// --- Routing -----------------------------------------------------------------

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if (($_GET['action'] ?? '') === 'config') {
  if ($method !== 'GET') send(405, 'text/plain', 'Method not allowed');
  send(200, 'application/json; charset=utf-8', json_encode(['domains' => $config['domains']]));
}

if (isset($_GET['ovh'])) {
  $allowedMethods = ['GET', 'POST', 'DELETE'];
  if (!in_array($method, $allowedMethods, true)) send(405, 'text/plain', 'Method not allowed');

  // Sub-path after /email/domain/ — e.g. "nsoffice.fr/redirection/42".
  $sub = ltrim((string) $_GET['ovh'], '/');
  if (strpos($sub, '..') !== false) send(400, 'text/plain', 'Bad path');
  $domain = explode('/', $sub)[0];
  if (!isset($config['domains'][$domain])) send(403, 'text/plain', 'Domain not allowed');

  $body = file_get_contents('php://input', false, null, 0, 64 * 1024);
  if ($body === false) $body = '';
  if ($body !== '') {
    json_decode($body);
    if (json_last_error() !== JSON_ERROR_NONE) send(400, 'application/json', '{"error":"Invalid JSON"}');
  }

  [$status, $ct, $out] = ovh_request($ovh, $method, '/email/domain/' . $sub, $body);
  send($status, $ct, $out);
}

send(404, 'text/plain', 'Not found');
