<div align="center">
<h1>Web Alias MX OVH</h1>
Une interface web légère pour gérer les redirections email OVH.

*A lightweight web UI to manage OVH email redirections.*

Pas de npm &middot; Pas de build &middot; Pas de framework &middot; Du PHP pur

<a href="docs/screenshot.png"><img src="docs/screenshot.png" alt="Capture d'écran, thèmes clair et sombre"></a>
</div>

## Présentation
Les offres email OVH ne gèrent pas les alias `user+tag@domain.tld`.
Chaque inscription à un nouveau service demande donc une nouvelle redirection, du genre `you.service@domain.tld` → `you@domain.tld`, et le panneau d'administration OVH est terriblement lent pour ça.
Cet outil ajoute et supprime des redirections en quelques secondes.

### Fonctionnalités
- Plusieurs domaines, avec un sélecteur
- Le champ source complète tout seul `@domain.tld`
- Le dernier domaine choisi est mémorisé
- Liste des redirections triée par ordre alphabétique
- Ajout et suppression en un clic
- Thème clair et sombre natifs, interface adaptée au mobile

### Prérequis
- PHP >= 8.2 avec l'extension `curl` (n'importe quel hébergement PHP convient : mutualisé, Apache/mod_php, nginx/Caddy + PHP-FPM)
- `apcu` est facultatif : s'il est présent, il met en cache le décalage d'horloge avec l'API et la dernière liste de chaque domaine. Sans lui, seul le décalage d'horloge est mis en cache, dans un fichier temporaire privé ; une liste de redirections ne quitte jamais la mémoire, car le répertoire de repli est partagé avec tout ce qui tourne sur la machine
- Des identifiants API OVH → [créer un jeton](https://eu.api.ovh.com/createToken/) avec ces droits :
  - GET sur `/email/domain/*`
  - POST sur `/email/domain/*`
  - DELETE sur `/email/domain/*`

## Installation
### 1. Cloner
```bash
git clone https://github.com/NSO73/Web-Alias-MX-OVH.git
```

### 2. Configurer
```bash
cp config.example.php config.php
```
Renseigner les identifiants OVH, puis lister les domaines à gérer. Chaque domaine est associé à une adresse de destination par défaut, pré-remplie dans le formulaire :

```php
'domains' => [
  'domain.tld' => 'you@domain.tld',
  'other.com'  => 'you@other.com',
],
```

`config.php` est ignoré par Git et contient les secrets de l'API : le passer en `chmod 600`. Mieux encore, le sortir complètement de la racine web et y faire pointer `WAMX_CONFIG` :

```bash
install -m 600 config.php /etc/wamx/config.php
```

Deux clés facultatives complètent la configuration. `list_cache_ttl` (`10` par défaut) est le nombre de secondes pendant lesquelles une liste complète peut être réutilisée : le passage d'un domaine à l'autre devient instantané, mais une modification faite dans le panneau OVH met autant de temps à apparaître ici. `0` interroge OVH à chaque fois. `require_auth` (`false` par défaut) est décrit dans [Déploiement](#déploiement).

Deux variables d'environnement sont lues, toutes deux facultatives :

| Variable | Par défaut | Rôle |
|---|---|---|
| `WAMX_CONFIG` | `config.php` à côté de `api.php` | Emplacement de la configuration |
| `WAMX_CACHE_DIR` | le répertoire temporaire du système | Emplacement du cache fichier quand `apcu` est absent |

### 3. Servir
Servir le dossier du projet avec n'importe quel serveur web capable d'exécuter PHP : il sert les fichiers statiques (`index.html`, `style.css`, `app.js`) et exécute `api.php`. Aucun processus à maintenir, aucun port à gérer.

Pour un test local rapide, le serveur intégré de PHP suffit :
```bash
php -S localhost:8080
```
Ouvrir <http://localhost:8080>.

### Tests
```bash
node --test
```
Lancés depuis la racine du dépôt, ils démarrent un faux OVH en HTTPS et le vrai `api.php` derrière `php -S`, puis vérifient chaque route : signature, relance, gardes cross-site, validation, relais des erreurs OVH. Il faut `php` (avec `curl`), `node` et `openssl` dans le PATH. La CI les exécute sur la plus ancienne version de PHP supportée et sur la plus récente.

## Déploiement
### Caddy + PHP-FPM
L'application n'a pas d'authentification intégrée. Ajouter `basic_auth` (ou tout autre mécanisme d'authentification) au niveau du serveur web pour en protéger l'accès.

```
domain.tld {
    basic_auth {
        user hash_bcrypt
    }
    root * /path/to/Web-Alias-MX-OVH
    php_fastcgi unix//run/php/php-fpm.sock {
        env WAMX_CONFIG /etc/wamx/config.php
        env REMOTE_USER {http.auth.user.id}
    }
    file_server

    # The assets carry no version in their name, so they must revalidate: otherwise a
    # browser keeps yesterday's app.js against today's api.php after a deploy.
    @assets path *.html *.css *.js
    header @assets Cache-Control "no-cache"

    header {
        Content-Security-Policy "default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data:; base-uri 'none'; form-action 'none'; frame-ancestors 'none'; object-src 'none'"
        X-Content-Type-Options nosniff
        Referrer-Policy no-referrer
    }
}
```

Générer `hash_bcrypt` avec :
```bash
caddy hash-password --plaintext "password"
```

`env REMOTE_USER` permet à PHP de savoir qui s'est authentifié. Une fois ce bloc en place, passer `'require_auth' => true` dans `config.php` : l'application répond alors `401` à toute requête arrivée sans utilisateur authentifié. Un vhost qui perd son bloc `basic_auth` échoue ainsi fermé au lieu d'exposer l'outil sur internet.

### Apache / nginx
Toute configuration PHP standard convient : faire pointer la racine web sur le dossier du projet, pour que `index.html` soit servi et que les fichiers `.php` soient exécutés par PHP-FPM ou mod_php. Transmettre `WAMX_CONFIG` via `SetEnv` (Apache) ou `fastcgi_param` (nginx), ou déposer `config.php` à côté de `api.php` et s'en passer. Les en-têtes `Cache-Control`, `Content-Security-Policy`, `X-Content-Type-Options` et `Referrer-Policy` valent la peine d'être posés là aussi.

## Sécurité
- Le navigateur n'envoie que des valeurs (un domaine, deux adresses, un identifiant), toutes validées par le serveur, jamais un chemin ni un corps de requête à transmettre tel quel. `api.php` construit lui-même chaque appel OVH, et le seul chemin qu'il sait construire est `/email/domain/<domaine configuré>/redirection[/<id>]`. Le reste de l'API OVH est hors d'atteinte, quoi que l'appelant envoie.
- Les écritures (`add`, `delete`) sont protégées de trois façons contre un contexte cross-site : `Sec-Fetch-Site` quand le navigateur l'envoie, `Origin` comparé à l'hôte demandé sinon, et un type de contenu JSON obligatoire, qu'un `<form>` cross-site ne peut jamais produire sans une requête préalable (preflight) que l'application n'approuve jamais.
- Restreindre le jeton API à `/email/domain/*`, et garder `config.php` hors de la racine web.
- L'authentification est l'affaire du serveur web (voir plus haut) ; activer `require_auth` pour qu'une erreur de configuration se voie.

## Ancienne version Node.js
Ce projet tournait autrefois avec un simple `node server.js`. Cette version est conservée au tag [`v1.1-node`](../../releases/tag/v1.1-node).

## Licence
[WTFPL](LICENSE)
