# Web Alias MX OVH
A lightweight web UI to manage OVH email redirections. Zero dependencies, single `node server.js`.  
Une interface web legere pour gerer les redirections email OVH. Zero dependance, un simple `node server.js`.

<p align="center"><a href="screenshot.png"><img src="screenshot.png" alt="Screenshot" width="500"></a></p>

## Why?
OVH email plans don't support `user+tag@domain` aliases. Every time you sign up for a new service, you need a new redirection like `service@domain` → `you@gmail.com`.  
The OVH admin panel is painfully slow for this. This tool lets you add/remove redirections in seconds.


## Features
- Multi-domain support with domain selector
- Source field auto-fills `@domain`
- Alphabetically sorted redirection list
- Add / delete in one click
- Remembers last selected domain
- Mobile responsive
- Reverse proxy friendly (Caddy, nginx)
- No npm, no build step, no dependencies

## Requirements
- Node.js >= 14
- OVH API credentials ([create an app](https://eu.api.ovh.com/createToken/) with rights on `/email/domain/*`)

## Setup
```bash
git clone https://github.com/NSO73/Web-Alias-MX-OVH.git
```

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
    "example.com": "default@destination.com",
    "other.com": "other@destination.com"
  }
}
```
Each domain key maps to a default destination email (pre-filled in the form).

## Run
```bash
node server.js
```
Open http://localhost:8080

## Reverse proxy (Caddy)
```
example.com {
    basic_auth {
        user $2a$14$hashedpassword
    }
    reverse_proxy localhost:8080
}
```
The app has no built-in authentication. You should add `basic_auth` (or any other auth mechanism) at the reverse proxy level to protect access.

## Security notes
- The server binds to `127.0.0.1` only (not exposed to the network)
- The API proxy is restricted to `/email/domain/` endpoints and configured domains only
- `config.json` contains secrets and is gitignored — protect it accordingly
- Add authentication at the reverse proxy level (basic_auth, SSO, etc.)

## License
[WTFPL](LICENSE)
