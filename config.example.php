<?php
// Copy to config.php and fill in your values:  cp config.example.php config.php
// Best practice: keep this file outside the document root and point WAMX_CONFIG at it,
// so the secrets are unreachable even if PHP stops executing.
return [
  'ovh' => [
    'endpoint'          => 'https://eu.api.ovh.com/1.0',
    'applicationKey'    => 'your_app_key',
    'applicationSecret' => 'your_app_secret',
    'consumerKey'       => 'your_consumer_key',
  ],
  // Each domain maps to a default destination (pre-filled in the form).
  'domains' => [
    'domain.tld' => 'you@domain.tld',
    'other.com'  => 'you@other.com',
  ],
  // Seconds a complete listing may be reused for, which makes switching between domains
  // instant. The trade-off is freshness: a change made in the OVH panel takes this long to
  // show up here. Set 0 to always ask OVH. Needs apcu; without it there is no list cache.
  'list_cache_ttl' => 10,
  // Fail-safe, off by default. Authentication is the web server's job (see the README), and
  // turning this on makes a vhost that forgot it answer 401 instead of serving the app to
  // anyone. It needs the server to pass the authenticated user through as REMOTE_USER.
  'require_auth' => false,
];
