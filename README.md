# Project Swordfish

A secure, anonymous secret-sharing application. Secrets are encrypted entirely in the browser (or CLI) before reaching the server — the server stores only ciphertext and never sees a plaintext secret or passphrase.

## v2 Features

- **Preact SPA** — dark-themed single-page application served by the PHP server; no separate static host required
- **JSON API** — `POST /api/create` and `POST /api/retrieve` with structured JSON request/response bodies
- **Configurable TTL** — secrets expire after 1 hour, 6 hours, 24 hours, 3 days, or 7 days
- **View limits** — optionally restrict a secret to 1, 3, 5, or 10 retrievals; enforced atomically via a Redis Lua script
- **Health endpoint** — `GET /health` checks Redis connectivity; used by Kubernetes liveness and readiness probes
- **Rate limiting** — `/api/retrieve` is limited to 10 requests per minute per IP; responses include `X-RateLimit-*` headers
- **Metrics** — creation and retrieval counts and byte totals are recorded per hour in Redis (90-day retention)
- **Legacy redirects** — `POST /create` and `POST /retrieve` issue `308 Permanent Redirect` to the `/api/*` equivalents

## Security

All cryptography runs client-side using the Web Crypto API (browser) or libsodium (CLI):

| Primitive | Usage |
|---|---|
| AES-GCM-256 | Secret encryption/decryption |
| PBKDF2-SHA-256 (10 000 iterations) | Key derivation from passphrase + random salt |
| PBKDF2-SHA-256 (10 000 iterations, fixed pepper) | Verifier derivation for server-side authentication |
| bcrypt | Server-side hashing of the verifier before storage |

The server stores only the encrypted payload and a bcrypt-hashed verifier. The plaintext secret and the passphrase never leave the client.

## Prerequisites

| Tool | Purpose |
|---|---|
| Docker + Docker Compose | Running the server and Redis locally |
| Make | Convenience targets |
| PHP 8.4+ | Running the server or CLI outside Docker |
| Composer | Installing PHP dependencies |
| Node.js 20+ | Building the frontend SPA |
| Kubernetes 1.19+ | Production deployment |
| Helm 3.0+ | Kubernetes chart management |

## Development Setup

### Quick start (Docker)

```bash
# Build the server image and start the server + Redis
make server-up

# Stop all containers
make server-down
```

The server listens on `http://localhost:8080` by default.

### Server (PHP)

```bash
# Install Composer dependencies locally (via Docker, no local PHP required)
make server-install

# Build the server container image only
make server-build
```

### Frontend (Node.js)

The Preact SPA lives in `frontend/` and is built with Vite. Build output lands in `server/static/dist/` and is committed to the repository.

```bash
cd frontend

# Install dependencies
npm install

# Start the Vite dev server (proxies API calls to the PHP server)
npm run dev

# Build for production (output → server/static/dist/)
npm run build

# Run ESLint
npm run lint

# Run Vitest unit tests
npm test
```

> **Note:** Always run `npm run build` after frontend changes and commit the updated `server/static/dist/` directory.

### CLI

```bash
# Install Composer dependencies locally (via Docker, no local PHP required)
make cli-install
```

### Running tests

**PHP (server):**
```bash
cd server
composer test
```

**JavaScript (frontend):**
```bash
cd frontend
npm test
```

## API Reference

The full OpenAPI 2.0 specification is in [`swagger.yml`](swagger.yml).

### Web interface

| Method | Path | Description |
|---|---|---|
| `GET` | `/` | Secret creation page (SPA) |
| `GET` | `/secret` | Secret retrieval page (SPA) |
| `GET` | `/secret/{secretId}` | Pre-populated retrieval page (SPA) |

### Backend API

#### `GET /health`

Returns Redis connectivity status. Used by Kubernetes probes.

**200 OK — Redis reachable:**
```json
{ "status": "ok" }
```

**503 Service Unavailable — Redis unreachable:**
```json
{ "status": "error", "message": "Connection refused [tcp://redis:6379]" }
```

---

#### `POST /api/create`

Create a new secret. The request body must be JSON.

**Request:**
```json
{
  "encrypted_secret": "<hex(salt)>$<hex(verifier)>$<hex(nonce+ciphertext)>",
  "ttl": 86400,
  "max_views": 1
}
```

| Field | Type | Required | Description |
|---|---|---|---|
| `encrypted_secret` | string | ✓ | Client-side encrypted payload in `hex(salt)$hex(verifier)$hex(nonce+ciphertext)` format |
| `ttl` | integer | | Lifetime in seconds. Allowed: `3600`, `21600`, `86400`, `259200`, `604800`. Default: `86400` |
| `max_views` | integer | | Maximum retrieval count. Allowed: `0` (unlimited), `1`, `3`, `5`, `10`. Default: `0` |

**201 Created:**
```json
{
  "id": "a1b2c3d4e5f6",
  "expires_at": 1735689600,
  "max_views": 1
}
```

**400 Bad Request** — malformed JSON  
**413 Payload Too Large** — body exceeds 100 KB  
**422 Unprocessable Entity** — disallowed `ttl` or `max_views` value

---

#### `POST /api/retrieve`

Retrieve a secret. Rate-limited to **10 requests per minute per IP**. All responses include `X-RateLimit-Limit`, `X-RateLimit-Remaining`, and `X-RateLimit-Reset` headers.

**Request:**
```json
{
  "id": "a1b2c3d4e5f6",
  "verifier": "<hex-encoded PBKDF2 verifier>"
}
```

**200 OK:**
```json
{
  "encrypted_secret": "<hex(salt+nonce+ciphertext)>",
  "views_remaining": 0,
  "expires_at": 1735689600
}
```

`views_remaining` is `null` for unlimited secrets. When it reaches `0`, all keys for the secret are deleted atomically.

**400 Bad Request** — malformed JSON  
**401 Unauthorized** — verifier does not match  
**404 Not Found** — secret not found or expired  
**429 Too Many Requests** — rate limit exceeded

---

#### Legacy redirects

`POST /create` and `POST /retrieve` both respond with `308 Permanent Redirect` to `/api/create` and `/api/retrieve` respectively. Update any integrations to use the `/api/*` paths directly.

## CLI Usage

The CLI tool is a Symfony Console application located in `cli/`. It requires PHP 8.4+ with the `sodium` and `curl` extensions.

### `secret:create`

Encrypt a secret locally and store it on the server.

```bash
php cli/cli.php secret:create <secret> <password>
```

**Arguments:**

| Argument | Description |
|---|---|
| `secret` | Plaintext secret to protect |
| `password` | Passphrase used to encrypt the secret |

**Example:**
```bash
php cli/cli.php secret:create "my API key: sk-abc123" "correct horse battery staple"
```

```
Secret Created
==============
Secret ID: a1b2c3d4e5f6
Password:  correct horse battery staple

URL:       https://swordfish.displace.tech/secret/a1b2c3d4e5f6
```

### `secret:retrieve`

Authenticate with the server, retrieve the ciphertext, and decrypt it locally.

```bash
php cli/cli.php secret:retrieve <secret-id> <password>
```

**Arguments:**

| Argument | Description |
|---|---|
| `secret-id` | ID of the secret to retrieve |
| `password` | Passphrase used when the secret was created |

**Example:**
```bash
php cli/cli.php secret:retrieve a1b2c3d4e5f6 "correct horse battery staple"
```

```
Secret Decrypted!
==============
my API key: sk-abc123
```

### Environment variables

| Variable | Default | Description |
|---|---|---|
| `SWORDFISH_URL` | `https://swordfish.displace.tech` | Base URL of the Swordfish server |

## Environment Variables (server)

| Variable | Default | Description |
|---|---|---|
| `SERVER_PORT` | `8080` | HTTP listen port |
| `REDIS_HOST` | `redis` | Redis hostname |
| `REDIS_PORT` | `6379` | Redis port |

## Docker

The server image is a multi-stage build: Composer installs dependencies in a `composer:latest` builder stage, then the runtime stage uses `php:8.4-cli` with the `redis` PECL extension and `pcntl`.

```bash
# Build the image locally
make server-build

# Start server + Redis with dev volume mount (hot-reload)
make server-up

# Stop containers
make server-down
```

The `docker-compose.dev.yml` overlay mounts the local `server/` directory into the container so PHP file changes take effect without rebuilding the image.

## Helm Deployment

The application ships with a Helm chart at `helm/swordfish/`. It deploys the PHP server and a Redis subchart, with optional ingress and Keel-based auto-update support.

### Installation

```bash
kubectl create namespace swordfish

helm install swordfish ./helm/swordfish -n swordfish \
  --set server.imagePullSecrets.create=true \
  --set server.imagePullSecrets.github.username=YOUR_GITHUB_USERNAME \
  --set server.imagePullSecrets.github.token=YOUR_GITHUB_PAT
```

Alternatively, create the pull secret manually and reference it:

```bash
kubectl create secret docker-registry ghcr-auth \
  --docker-server=ghcr.io \
  --docker-username=YOUR_GITHUB_USERNAME \
  --docker-password=YOUR_GITHUB_PAT \
  -n swordfish

helm install swordfish ./helm/swordfish -n swordfish \
  --set server.imagePullSecrets.name=ghcr-auth
```

### Configuration

| Parameter | Description | Default |
|---|---|---|
| `server.replicaCount` | Number of server replicas | `1` |
| `server.image.repository` | Server image repository | `ghcr.io/displacetech/swordfish/server` |
| `server.image.tag` | Server image tag | `latest` |
| `server.image.sha` | Optional SHA override for the tag | `""` |
| `server.imagePullSecrets.create` | Create a pull secret from provided credentials | `false` |
| `server.imagePullSecrets.name` | Name of an existing pull secret to use | `""` |
| `server.imagePullSecrets.github.username` | GitHub username for the pull secret | `""` |
| `server.imagePullSecrets.github.token` | GitHub PAT for the pull secret | `""` |
| `server.service.type` | Kubernetes service type | `ClusterIP` |
| `server.service.port` | Service port | `8080` |
| `keel.policy` | Keel update policy (`force`, `semver`, `glob`, `none`) | `force` |
| `keel.trigger` | Keel trigger type (`poll`, `pubsub`) | `poll` |
| `keel.pollSchedule` | Poll schedule (cron or `@every` syntax) | `@every 5m` |
| `keel.matchTag` | Only update when the new image tag matches the deployed tag | `true` |
| `redis.architecture` | Redis architecture | `standalone` |
| `redis.auth.enabled` | Enable Redis authentication | `false` |
| `ingress.enabled` | Enable ingress | `false` |
| `ingress.className` | Ingress class name | `""` |
| `ingress.hosts` | Ingress hosts configuration | `[{host: swordfish.local, paths: [{path: /, pathType: Prefix}]}]` |

**Example `values.yaml` with ingress and TLS:**

```yaml
server:
  replicaCount: 2
  image:
    repository: ghcr.io/displacetech/swordfish/server
    tag: "latest"
  imagePullSecrets:
    create: true
    github:
      username: YOUR_GITHUB_USERNAME
      token: YOUR_GITHUB_PAT

ingress:
  enabled: true
  className: nginx
  hosts:
    - host: swordfish.example.com
      paths:
        - path: /
          pathType: Prefix
  tls:
    - secretName: swordfish-tls
      hosts:
        - swordfish.example.com
```

```bash
helm install swordfish ./helm/swordfish -n swordfish -f values.yaml
```

### Upgrading and uninstalling

```bash
helm upgrade swordfish ./helm/swordfish -n swordfish
helm uninstall swordfish -n swordfish
```

### Automated updates with Keel

The Helm chart annotates the Deployment for [Keel](https://keel.sh), which polls GHCR and triggers a rolling restart when a new image digest is detected. The default `force` policy is suitable for `latest`-tagged images.

| Policy | When to use |
|---|---|
| `force` | `latest` tag — re-pulls whenever a new digest is pushed |
| `semver` | Semantically versioned tags (e.g., `1.2.3`) |
| `glob` | Pattern-matched tags (e.g., `sha-*`) |
| `none` | Disable Keel automation entirely |

Override in `values.yaml`:

```yaml
keel:
  policy: "semver"
  trigger: "poll"
  pollSchedule: "@every 10m"
  matchTag: "true"
```

## CI/CD

GitHub Actions (`.github/workflows/build.yml`) runs on every push or pull request touching `server/**`, `frontend/**`, or the workflow file itself.

| Job | Steps |
|---|---|
| `test` | Sets up PHP 8.4 + Redis, installs Composer deps, runs PHPUnit with coverage |
| `js-test` | Sets up Node.js 20, installs npm deps, runs Vitest with coverage |
| `build` | Builds the SPA (`npm run build`), then builds and pushes the Docker image to GHCR |

Images are tagged with the commit SHA on every push. The `latest` tag is also applied on pushes to `main`. Pull requests from forks build the image but do not push it.

## Contributing

1. Fork the repository and create a feature branch.
2. Make your changes. Run `npm run lint` and `npm test` in `frontend/`, and `composer test` in `server/` before committing.
3. If you change the frontend, rebuild with `npm run build` and commit the updated `server/static/dist/`.
4. Open a pull request against `main`. Reference any related issue in the PR description.

Please read [CODE_OF_CONDUCT.md](CODE_OF_CONDUCT.md) before contributing.
