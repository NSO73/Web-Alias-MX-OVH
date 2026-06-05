<div align="center">
<h1>Web Alias MX OVH</h1>
A lightweight web UI to manage OVH email redirections.

*Une interface web légère pour gérer les redirections email OVH.*

No npm &middot; No build step &middot; No framework &middot; Pure PHP

<a href="screenshot.png"><img src="screenshot.png" alt="Screenshot"></a>
</div>

# About
OVH email plans don't support `user+tag@domain.tld` aliases.  
Every time you sign up for a new service, you need a new redirection like `you.service@domain.tld` → `you@domain.tld` and the OVH admin panel is painfully slow for this.  
This tool lets you add/remove redirections in seconds.

## Features
- Multi-domain support with domain selector
- Source field auto-fills `@domain.tld`
- Remembers last selected domain
- Alphabetically sorted redirection list
- Add / delete in one click
- Mobile responsive

## Requirements
- PHP >= 7.4 with the `curl` extension (any PHP hosting works — shared hosting, Apache/mod_php, nginx/Caddy + PHP-FPM)
- OVH API credentials → [Create API Keys](https://eu.api.ovh.com/createToken/) with rights :
  - GET on `/email/domain/*`
  - POST on `/email/domain/*`
  - DELETE on `/email/domain/*`

# Setup
## Clone repo
```bash
git clone https://github.com/NSO73/Web-Alias-MX-OVH.git
```

## Configuration
Copy the example and fill in your values:
```bash
cp config.example.php config.php
```
```php
<?php
return [
  'ovh' => [
    'endpoint'          => 'https://eu.api.ovh.com/1.0',
    'applicationKey'    => 'your_app_key',
    'applicationSecret' => 'your_app_secret',
    'consumerKey'       => 'your_consumer_key',
  ],
  'domains' => [
    'domain.tld' => 'you@domain.tld',
    'other.com'  => 'you@other.com',
  ],
];
```
Each domain key maps to a default destination email (pre-filled in the form).
`config.php` is executed by PHP (never served as plain text), so your secrets stay private. Keep it out of version control (a `.gitignore` rule is included) and `chmod 600` it on the server.

## Run
Serve the project folder with any PHP-capable web server. The web server serves the static files (`index.html`, `style.css`, `app.js`) and runs `api.php`.

For a quick local test, use PHP's built-in server:
```bash
php -S localhost:8080
```
Open http://localhost:8080

# More
## Caddy + PHP-FPM
The app has no built-in authentication. You should add `basic_auth` (or any other auth mechanism) at the web server level to protect access.

```
domain.tld {
    basic_auth {
        user hash_bcrypt
    }
    root * /path/to/Web-Alias-MX-OVH
    php_fastcgi unix//run/php/php-fpm.sock
    file_server
}
```
Generate hash_bcrypt with:
```bash
caddy hash-password --plaintext "password"
```

## Apache / nginx
Any standard PHP setup works too — just point the document root at the project folder so `index.html` is served and `.php` files are executed by PHP-FPM/mod_php. No process to keep running, no port to manage.

# Notes
- The OVH proxy is restricted to `/email/domain/` endpoints and configured domains only
- `config.php` is never exposed (executed by PHP, not served as a static file)
- Use [DarkReader](https://darkreader.org/) for dark theme

## Legacy Node.js version
This project used to run as a single `node server.js`. That version is preserved at the [`v1.1-node`](../../releases/tag/v1.1-node) tag if you need it.

# License
[WTFPL](LICENSE)
