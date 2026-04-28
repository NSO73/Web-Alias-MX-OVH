<div align="center">
<h1>Web Alias MX OVH</h1>
A lightweight web UI to manage OVH email redirections.

*Une interface web légère pour gérer les redirections email OVH.*

No npm &middot; No build step &middot; No dependencies &middot; Single `node server.js`

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
- Node.js >= 14
- OVH API credentials → [Create API Keys](https://eu.api.ovh.com/createToken/) with rights :
  - GET on `/email/domain/*`
  - POSTE on `/email/domain/*`
  - DELETE on `/email/domain/*`

# Setup
## Clone repo
```bash
git clone https://github.com/NSO73/Web-Alias-MX-OVH.git
```

## Configuration
Edit `config.json`:
```json
{
  "port": 8080,
  "ovh": {
    "endpoint": "https://eu.api.ovh.com/1.0",
    "applicationKey": "your_app_key",
    "applicationSecret": "your_app_secret",
    "consumerKey": "your_consumer_key"
  },
  "domains": {
    "domain.tld": "you@domain.tld",
    "other.com": "you@other.com"
  }
}
```
Each domain key maps to a default destination email (pre-filled in the form).

## Run
```bash
node server.js
```
Open http://localhost:8080

# More
## Caddy - Reverse proxy
The app has no built-in authentication. You should add `basic_auth` (or any other auth mechanism) at the reverse proxy level to protect access.

```
domain.tld {
    basic_auth {
        user hash_bcrypt
    }
    reverse_proxy localhost:8080
}
```
Generate hash_bcrypt with:
```bash
caddy hash-password --plaintext "password"
```

## Service systemd
```
cat > /etc/systemd/system/web-alias-mx-ovh.service <<'EOF'
[Unit]
Description=Web Alias MX OVH
After=network.target

[Service]
Type=simple
User=user
Group=group
WorkingDirectory=/folder_path/Web-Alias-MX-OVH
ExecStart=/usr/bin/node server.js
Restart=on-failure
RestartSec=10

[Install]
WantedBy=multi-user.target
EOF
```
Edit user, group and /folder_path/Web-Alias-MX-OVH.
```bash
systemctl daemon-reload
systemctl enable --now web-alias-mx-ovh
systemctl status web-alias-mx-ovh
```

# Notes
- The server binds to `127.0.0.1` only (not exposed to the network)
- The API proxy is restricted to `/email/domain/` endpoints and configured domains only
- Use [DarkReader](https://darkreader.org/) for dark theme

# License
[WTFPL](LICENSE)
