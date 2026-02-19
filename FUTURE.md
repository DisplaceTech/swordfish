# v3 Migration: Cloudflare Workers

This document outlines the considerations for migrating Swordfish from its current
PHP/Redis/Kubernetes stack to a Cloudflare Workers-based edge deployment.

---

## What Changes

### Runtime: PHP → Workers (JavaScript/TypeScript)

The current server is a long-running PHP process built on [Amphp](https://amphp.org/)
with coroutine-based async I/O. Cloudflare Workers uses a V8 isolate model: each
request is handled by a short-lived JavaScript/TypeScript function with no persistent
process state between invocations.

- All server-side logic (`SecretService`, route handlers) must be rewritten in
  JavaScript or TypeScript.
- Amphp coroutines (`yield`) are replaced by native `async`/`await`.
- PHP's `password_hash`/`password_verify` (bcrypt) is not available in the Workers
  runtime. The verifier hashing strategy must be replaced with a Web Crypto API
  primitive (e.g., HMAC-SHA-256 or PBKDF2-derived comparison key).
- `bin2hex`/`hex2bin` serialization in `CreateRequest` and `RetrievalRequest` maps
  directly to `TextEncoder`/`Uint8Array` operations in JavaScript.

### Storage: Redis → Cloudflare KV

Redis (`Predis\Client`) is used today for all secret storage with `SETEX` for TTL
management. In v3, [Cloudflare KV](https://developers.cloudflare.com/kv/) replaces
Redis as the persistence layer.

Key differences to account for:

| Concern | Redis (current) | Cloudflare KV (v3) |
|---|---|---|
| TTL | `SETEX key ttl value` | `put(key, value, { expirationTtl: ttl })` |
| Atomic multi-key ops | `DEL key1 key2 ...` in one call | Multiple `delete()` calls (no transactions) |
| Read consistency | Strong (single node) | Eventually consistent globally; read-after-write consistent within the same Worker invocation |
| Max value size | Configurable (default 512 MB) | 25 MB per value |
| Key naming | `secret:{id}`, `verifier:{id}`, etc. | Same naming scheme can be preserved |
| View counter decrement | `DECR json_views:{id}` (atomic) | No atomic decrement; use [Durable Objects](https://developers.cloudflare.com/durable-objects/) for atomic view-count management |

The eventual-consistency model of KV is acceptable for secret payloads and verifier
hashes (write-once, read-once-then-delete). However, the view-counter logic in
`SecretService::retrieveJson` relies on an atomic `DECR` to prevent over-delivery.
This must be moved to a **Durable Object** to preserve correctness under concurrent
requests.

### Deployment: Docker/Kubernetes → Wrangler

The current deployment pipeline builds a Docker image, pushes it to GHCR, and
deploys via Helm to a Kubernetes cluster. In v3:

- The server is deployed with [Wrangler](https://developers.cloudflare.com/workers/wrangler/)
  (`wrangler deploy`).
- There is no container image, no Helm chart, and no Redis sidecar.
- Environment variables become [Workers secrets](https://developers.cloudflare.com/workers/configuration/secrets/)
  (`wrangler secret put`) or `[vars]` in `wrangler.toml`.
- The KV namespace is declared as a binding in `wrangler.toml` and injected into the
  Worker as `env.KV`.

### Static Assets

Today the PHP server reads `static/index.html` from disk and serves it directly, with
a `DocumentRoot` fallback for Vite build artifacts. In v3, static assets are served
via [Cloudflare Pages](https://pages.cloudflare.com/) or
[Workers Static Assets](https://developers.cloudflare.com/workers/static-assets/).
The Vite build output (`server/static/dist/`) can be deployed as-is.

### Health Check

The `/health` endpoint currently pings Redis to confirm connectivity. In v3, the
equivalent check would verify KV binding availability. Because Workers are stateless
and KV is a managed service, a simple `200 OK` response (or a lightweight KV read)
is sufficient.

---

## What Stays the Same

### Client-Side Cryptography

All encryption and decryption happens in the browser (and CLI) before any data
reaches the server. This is the core security property of Swordfish and is
**unchanged** in v3:

- **AES-256-GCM** for secret encryption — already uses the Web Crypto API
  (`SubtleCrypto.encrypt`) in the frontend, which is natively available in Workers.
- **PBKDF2** key derivation — likewise available via `SubtleCrypto.deriveKey`.
- The server never sees plaintext secrets; it stores and returns opaque ciphertext.

### API Contract

The HTTP API surface exposed to clients remains the same:

| Method | Path | Purpose |
|---|---|---|
| `POST` | `/api/create` | Store a new encrypted secret |
| `POST` | `/api/retrieve` | Retrieve and (optionally) delete a secret |
| `GET` | `/health` | Service health check |

Request and response shapes (the `$`-delimited wire format for v1, and the JSON
envelope for the current API) are preserved to maintain CLI and frontend
compatibility.

### Secret Lifecycle Model

- Secrets are stored with a caller-specified TTL (default 24 hours, max 7 days).
- Secrets are never stored in plaintext.
- A bcrypt-equivalent verifier hash gates retrieval.
- Secrets are deleted on view exhaustion (for view-limited secrets).

---

## Edge Encryption Considerations

Running at the edge introduces some nuances worth noting:

- **Web Crypto API is available** in the Workers runtime (`globalThis.crypto`), so
  server-side hashing (verifier storage) can use `SubtleCrypto` directly without
  any third-party library.
- **No `bcrypt` in Workers.** The current verifier hashing uses PHP's `password_hash`
  (bcrypt). The replacement should use `PBKDF2-HMAC-SHA-256` via `SubtleCrypto`,
  which is both Workers-compatible and appropriate for this use case.
- **Secrets in transit** are already protected by Cloudflare's TLS termination at
  the edge — no change needed.
- **KV encryption at rest** is handled transparently by Cloudflare; no additional
  application-layer encryption of the stored ciphertext is required (though it is
  already encrypted by the client before storage).
- **Side-channel timing** for verifier comparison should use `crypto.subtle`-based
  constant-time comparison rather than string equality.

---

## Migration Checklist (High-Level)

- [ ] Rewrite `SecretService` in TypeScript with KV bindings
- [ ] Replace bcrypt verifier hashing with PBKDF2-HMAC-SHA-256 via `SubtleCrypto`
- [ ] Implement atomic view-counter using a Durable Object
- [ ] Port route handlers to Workers `fetch` handler (or use Hono/itty-router)
- [ ] Configure `wrangler.toml` with KV namespace binding and secrets
- [ ] Update GitHub Actions workflow to run `wrangler deploy` instead of building a Docker image
- [ ] Deploy frontend via Cloudflare Pages (Vite build output is already compatible)
- [ ] Remove Helm chart and Docker Compose files (or archive them)
- [ ] Update `swagger.yml` if any API shapes change
