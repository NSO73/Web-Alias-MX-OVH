<div align="center">
<h1>Web Alias MX OVH</h1>
A lightweight web UI to manage OVH email redirections.

*Une interface web légère pour gérer les redirections email OVH.*

No npm &middot; No build step &middot; No framework &middot; Pure PHP

<a href="screenshot.png"><img src="screenshot.png" alt="Screenshot"></a>
</div>

## About
OVH email plans don't support `user+tag@domain.tld` aliases.
Every time you sign up for a new service, you need a new redirection like `you.service@domain.tld` → `you@domain.tld`, and the OVH admin panel is painfully slow for this.
This tool lets you add and remove redirections in seconds.

### Features
- Multi-domain support with domain selector
- Source field auto-fills `@domain.tld`
- Remembers last selected domain
- Alphabetically sorted redirection list
- Add / delete in one click
- Light and dark theme, mobile responsive

### Requirements
- PHP >= 7.4 with the `curl` extension (any PHP hosting works — shared hosting, Apache/mod_php, nginx/Caddy + PHP-FPM)
- OVH API credentials → [create a token](https://eu.api.ovh.com/createToken/) with these rights:
  - GET on `/email/domain/*`
  - POST on `/email/domain/*`
  - DELETE on `/email/domain/*`

## Setup
### 1. Clone
```bash
git clone https://github.com/NSO73/Web-Alias-MX-OVH.git
```

### 2. Configure
```bash
cp config.example.php config.php
```
Fill in your OVH credentials, then list the domains you want to manage. Each domain key maps to a default destination email, pre-filled in the form:

```php
'domains' => [
  'domain.tld' => 'you@domain.tld',
  'other.com'  => 'you@other.com',
],
```

`config.php` is ignored by Git and holds your API secrets, so `chmod 600` it. Better still, keep it out of the document root entirely and point `WAMX_CONFIG` at it:

```bash
install -m 600 config.php /etc/wamx/config.php
```

### 3. Serve
Serve the project folder with any PHP-capable web server: it serves the static files (`index.html`, `style.css`, `app.js`) and runs `api.php`. No process to keep running, no port to manage.

For a quick local test, use PHP's built-in server:
```bash
php -S localhost:8080
```
Open <http://localhost:8080>.

## Deployment
### Caddy + PHP-FPM
The app has no built-in authentication. Add `basic_auth` (or any other auth mechanism) at the web server level to protect access.

```
domain.tld {
    basic_auth {
        user hash_bcrypt
    }
    root * /path/to/Web-Alias-MX-OVH
    php_fastcgi unix//run/php/php-fpm.sock {
        env WAMX_CONFIG /etc/wamx/config.php
    }
    file_server
    header {
        Content-Security-Policy "default-src 'self'; img-src 'self' data:; base-uri 'none'; form-action 'none'"
        X-Content-Type-Options nosniff
        Referrer-Policy no-referrer
    }
}
```

Generate `hash_bcrypt` with:
```bash
caddy hash-password --plaintext "password"
```

### Apache / nginx
Any standard PHP setup works too — point the document root at the project folder so `index.html` is served and `.php` files are executed by PHP-FPM or mod_php. Pass `WAMX_CONFIG` through `SetEnv` (Apache) or `fastcgi_param` (nginx), or drop `config.php` next to `api.php` and skip it.

## Security notes
- The OVH proxy only forwards `/email/domain/<configured domain>/redirection[/<id>]`. Nothing else in the OVH API is reachable through it, whatever the caller sends.
- Writes require a same-origin request and a JSON content type, so a third-party page cannot forge one against your session.
- Keep the API token restricted to `/email/domain/*`, and `config.php` out of the document root.
- Authentication is the web server's job — see above.

## Legacy Node.js version
This project used to run as a single `node server.js`. That version is preserved at the [`v1.1-node`](../../releases/tag/v1.1-node) tag if you need it.

## License
[WTFPL](LICENSE)
