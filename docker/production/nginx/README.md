# Production server setup

MolMeDB runs as one Docker Compose stack (`docker-compose-production.yaml`). Its `web` service (nginx, this directory) is the only entry point. It decides by domain and path where every request goes.

The web server on the host (Apache) only terminates TLS. It passes every MolMeDB domain unchanged to that nginx and has no routing rules of its own. All routing lives in this repository, so moving to another server needs only the steps below.

```
client ──HTTPS──> Apache (host, TLS only) ──HTTP──> 127.0.0.1:8443 ──> nginx (web)
                                                                        ├─> frontend:3000   Next.js website
                                                                        ├─> app:9000        Laravel (PHP-FPM)
                                                                        └─> cdkdepict:8080  2D depictions
```

## Routing (`templates/molmedb.conf.template`)

| Domain | Path | Served by |
|---|---|---|
| `molmedb.upol.cz` (`MOLMEDB_SITE_HOST`) | `/api/v1/…` (public REST API, its docs, OpenAPI, MCP server at `/api/v1/mcp`) | Laravel |
| | `/depict/…` | CDK Depict |
| | `/sitemap*.xml` | files in `storage/app/sitemaps` |
| | everything else, including the frontend's own `/api/…` routes | Next.js frontend |
| `admin.molmedb.upol.cz` (`MOLMEDB_ADMIN_HOST`) | `/depict/…` | CDK Depict |
| | everything else (administration, API for the frontend, `/api/v1/…`) | Laravel |
| `rdf.molmedb.upol.cz` (`MOLMEDB_RDF_HOST`) | `/` | redirect to the RDF documentation |
| | `/{path}` (RDF IRIs) | Laravel at `/api/rdf/{path}`; the IRI stays in the address bar |
| any other host, e.g. `web` inside Docker | | same as the admin domain (default server) |

Inside Docker, the frontend calls the backend at `http://web` (`NEXT_BACKEND_URL`).

## 1. The stack

```bash
cp docker-compose-production.yaml docker-compose.yaml
```

Set these values in `.env`:

```bash
APP_ENV=production
APP_DEBUG=false
APP_URL=https://admin.molmedb.upol.cz
FRONTEND_URL=https://molmedb.upol.cz
FAIR_SITE_URL=https://molmedb.upol.cz            # links of the public API point here
CDK_DEPICT_URL=https://admin.molmedb.upol.cz     # browsers load depictions from /depict/
CDK_DEPICT_INTERNAL_URL=http://cdkdepict:8080

# Only if the domains differ from the defaults:
MOLMEDB_SITE_HOST=molmedb.upol.cz
MOLMEDB_ADMIN_HOST=admin.molmedb.upol.cz
MOLMEDB_RDF_HOST=rdf.molmedb.upol.cz
```

The frontend reads `frontend/.env`. Then start the stack:

```bash
docker compose build
docker compose up -d
```

Only nginx is meant to be reached from outside, and only through Apache. Its port `8443` and the debugging ports of the databases, RDKit and CDK Depict are published on `127.0.0.1` only.

## 2. Apache

Enable the modules once:

```bash
sudo a2enmod ssl proxy proxy_http headers
```

Every domain gets the same virtual host; only `ServerName` and the certificate differ. For example, `/etc/apache2/sites-available/molmedb.upol.cz.conf`:

```apache
<VirtualHost *:80>
    ServerName molmedb.upol.cz
    Redirect permanent / https://molmedb.upol.cz/
</VirtualHost>

<VirtualHost *:443>
    ServerName molmedb.upol.cz

    SSLEngine on
    SSLCertificateFile    /etc/letsencrypt/live/molmedb.upol.cz/fullchain.pem
    SSLCertificateKeyFile /etc/letsencrypt/live/molmedb.upol.cz/privkey.pem

    # Pass the domain and the original scheme on; nginx routes by the domain
    # and Laravel builds its URLs from them. X-Forwarded-For (the client's
    # address, counted by the API rate limits) is added by mod_proxy.
    ProxyRequests Off
    ProxyPreserveHost On
    RequestHeader set X-Forwarded-Proto "https"
    RequestHeader set X-Forwarded-Port "443"
    ProxyTimeout 300

    # The MCP server may stream its responses (server-sent events): no
    # buffering and no compression.
    SetEnvIf Request_URI "^/api/v1/mcp" no-gzip=1
    ProxyPass        /api/v1/mcp http://127.0.0.1:8443/api/v1/mcp flushpackets=on
    ProxyPassReverse /api/v1/mcp http://127.0.0.1:8443/api/v1/mcp

    ProxyPass        / http://127.0.0.1:8443/
    ProxyPassReverse / http://127.0.0.1:8443/
</VirtualHost>
```

Create the same file for `admin.molmedb.upol.cz` and `rdf.molmedb.upol.cz`, replacing the domain in `ServerName`, `Redirect` and the certificate paths. Then enable the sites, get the certificates and reload Apache:

```bash
sudo a2ensite molmedb.upol.cz admin.molmedb.upol.cz rdf.molmedb.upol.cz
sudo certbot --apache -d molmedb.upol.cz -d admin.molmedb.upol.cz -d rdf.molmedb.upol.cz
sudo apachectl configtest && sudo systemctl reload apache2
```

Do not add other rules (`ProxyPass` to other ports, `Redirect`, `Header` for CORS) to these virtual hosts. Routing, CORS and caching belong to nginx and Laravel; an Apache rule would silently override them. Apache must not add or answer CORS `OPTIONS` requests either: the API and the MCP server answer them themselves.

### Moving from the old setup

The old Apache configuration routed some paths itself:
- the site domain went to the frontend on port `3001`;
- `admin…/depict/` went to CDK Depict on port `1661`;
- the RDF domain was redirected to `molmedb.upol.cz/api/rdf/…`.

Remove these rules and replace the virtual hosts with the one above. The frontend no longer publishes a port of its own.

## 3. Checks

Run them from any machine after a deployment:

```bash
# Website, public API and its documentation
curl -sI https://molmedb.upol.cz/ | grep -i x-powered-by                 # X-Powered-By: Next.js
curl -s  https://molmedb.upol.cz/api/v1/about | head -c 200               # JSON about the dataset
curl -sI https://molmedb.upol.cz/api/v1/docs | head -1                    # HTTP/1.1 200

# MCP server: the preflight and a call
curl -si -X OPTIONS https://molmedb.upol.cz/api/v1/mcp | grep -i access-control-allow-methods   # POST, OPTIONS
curl -s -X POST https://molmedb.upol.cz/api/v1/mcp \
  -H 'Content-Type: application/json' -H 'Accept: application/json, text/event-stream' \
  -d '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2025-06-18","capabilities":{},"clientInfo":{"name":"check","version":"1"}}}'

# Depictions, RDF IRIs, sitemap, administration
curl -sI 'https://admin.molmedb.upol.cz/depict/cot/svg?smi=CCO' | grep -i content-type        # image/svg+xml
curl -s -H 'Accept: text/turtle' https://rdf.molmedb.upol.cz/vocabulary | head -3              # Turtle
curl -sI https://molmedb.upol.cz/sitemap.xml | head -1                                          # HTTP/1.1 200
curl -sI https://admin.molmedb.upol.cz/login | head -1                                          # HTTP/1.1 200

# The client's address reaches Laravel: the remaining limit decreases per client
curl -sI https://molmedb.upol.cz/api/v1/about | grep -i x-ratelimit-remaining
```

## Troubleshooting

- **502 Bad Gateway or 504 Gateway Timeout** comes from nginx when a service behind it is down. Check `docker compose ps` and `docker compose logs web frontend app`. Upstreams are resolved per request, so nginx starts and keeps running without them.
- **The API answers 429 to everybody** when the client's address is lost and all requests share one. Apache must pass `X-Forwarded-For` (it does by default) and connect to `127.0.0.1:8443`. nginx and Laravel trust only private addresses as proxies (`set_real_ip_from` in `nginx.conf`, `trustProxies` in `bootstrap/app.php`).
- **Links of the API point to `http://` or to `127.0.0.1`** when `ProxyPreserveHost On` or `X-Forwarded-Proto` is missing in Apache.
- **MCP clients hang** when a response is buffered or compressed on the way. Check the `/api/v1/mcp` rules in Apache and `fastcgi_buffering off` in `snippets/shared-locations.conf`.
- **Images of structures are missing** when `CDK_DEPICT_URL` is not the admin domain, or the `cdkdepict` service is down.

## Files

- `nginx.conf`: shared settings (trusted proxies, forwarded headers, caches, limits).
- `templates/molmedb.conf.template`: the server of every domain. The nginx image renders it with the `MOLMEDB_*_HOST` variables into `/etc/nginx/conf.d` on start.
- `snippets/`: how requests are passed to Laravel and to the frontend, and the locations shared by the site and the admin domain.
- `Dockerfile`: the image of the `web` service.
