<?php
// Copy to config.php and fill in your values:  cp config.example.php config.php
// config.php is executed by PHP (never served as plain text), so secrets stay private.
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
];
