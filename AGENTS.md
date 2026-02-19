# Swordfish — Agent Memory

## Repository Overview
PHP secret-sharing application using the Amphp async HTTP server framework. Secrets are stored in Redis via Predis.

## Structure
- `server/` — Main PHP HTTP server (Amphp v2, PHP 8.4)
  - `server.php` — Entry point: wires up sockets, Redis client, and routes
  - `src/ServerRoutes.php` — All route handlers as static factory methods returning `CallableRequestHandler`
  - `src/CreateRequest.php` / `src/RetrievalRequest.php` — Request value objects
  - `docker-compose.yml` — Production compose (server + redis)
  - `docker-compose.dev.yml` — Dev overrides
  - `Dockerfile` — Multi-stage: composer builder → php:8.4-cli runtime
  - `static/` — Static assets served by PHP server; Vite build output lands here
- `frontend/` — Preact SPA (Vite + Preact 10 + TailwindCSS 3 + preact-router)
  - `vite.config.js` — Build output: `../server/static/dist/`, `emptyOutDir: true`; uses `@preact/preset-vite`
  - `tailwind.config.js` — `darkMode: 'class'`; `index.html` has `<html class="dark">` for default dark theme; extended with semantic design tokens (see Design System below)
  - `src/main.jsx` — Preact entry point using `render()`; `src/App.jsx` — root component with Router
  - `src/App.jsx` — Uses `preact-router`; routes: `/` (Create), `/secret` (Retrieve), `/secret/:id` (Retrieve), `/about` (About); `NavLink` uses `useRouter()` for active state
  - `src/Create.jsx` — Secret creation form (textarea + passphrase input + submit button + security card)
  - `src/Retrieve.jsx` — Secret retrieval form (ID input + passphrase input + submit button); accepts `id` prop from router
  - `src/About.jsx` — How It Works page (`/about`) with step cards and security guarantees
  - `src/api.js` — `createSecret()` and `retrieveSecret()` fetch helpers
  - `src/index.css` — Tailwind directives + `@layer base` (html defaults) + `@layer components` (design system classes)
  - `eslint.config.js` — ESLint flat config with react-hooks (Preact-compatible)
- `cli/` — PHP CLI tool for interacting with the server
- `helm/` — Helm chart for Kubernetes deployment
- `swagger.yml` — OpenAPI 2.0 spec (update when adding routes)

## Design System (DIS-887)

Dark theme is default via `<html class="dark">` + `darkMode: 'class'` in Tailwind.

### Semantic color tokens (use these, not raw gray-*/indigo-*)
| Token | Value | Use |
|---|---|---|
| `surface-base` | #030712 | Page background |
| `surface-raised` | #111827 | Cards, nav |
| `surface-overlay` | #1f2937 | Input backgrounds, hover surfaces |
| `border` | #1f2937 | Default borders |
| `border-subtle` | #374151 | Hover borders |
| `content-primary` | #f3f4f6 | Headings, primary text |
| `content-secondary` | #9ca3af | Body text, labels |
| `content-muted` | #6b7280 | Hints, captions |
| `accent` | #6366f1 | Buttons, interactive |
| `accent-muted` | #818cf8 | Icons, step numbers, active nav |
| `accent-surface` | #1e1b4b | Accent card backgrounds |
| `accent-border` | #4338ca | Accent card borders |
| `accent-text` | #a5b4fc | Accent headings |
| `danger` / `danger-surface` / `danger-border` / `danger-text` | red scale | Error states |
| `success` / `success-surface` / `success-border` / `success-text` | green scale | Success states |

### Component classes (defined in `src/index.css` `@layer components`)
- `.card` — surface-raised card with border and shadow
- `.card-accent` — indigo-tinted accent card
- `.label` — form field label
- `.input` — text/password input
- `.textarea` — resizable textarea (extends `.input`)
- `.btn` — base button (use with modifier)
- `.btn-primary` — indigo filled button
- `.btn-secondary` — gray outlined button
- `.btn-ghost` — transparent button
- `.field` — flex column wrapper for label + input + hint
- `.hint` — small muted helper text
- `.banner-danger` / `.banner-success` — status banners

## Key Patterns
- **Route registration**: Add `$router->addRoute(METHOD, PATH, ServerRoutes::handlerName(...))` in `server.php`
- **Handler factory**: Add a `public static function handlerName(Logger, Client): CallableRequestHandler` to `ServerRoutes.php`
- **Async**: Amphp coroutines — use `yield` for async I/O inside handlers; Predis calls are synchronous
- **Redis errors**: Catch `\Exception` around Predis calls; `ConnectionException` is thrown when Redis is unreachable
- **HTTP status codes**: Use `Amp\Http\Status` constants (e.g., `Status::OK`, `Status::SERVICE_UNAVAILABLE`)
- **JSON responses**: Set `content-type: application/json` header and use `json_encode()`

## Build & Run
```bash
make server-up        # build image and start containers (requires Docker)
make server-down      # stop containers
make server-install   # install Composer deps locally via Docker
```

### Frontend (from `frontend/`)
```bash
npm install           # install dependencies
npm run dev           # start Vite dev server
npm run build         # build to server/static/
npm run lint          # run ESLint
```

## Environment Variables
| Variable | Default | Description |
|---|---|---|
| `REDIS_HOST` | redis | Redis hostname |
| `REDIS_PORT` | 6379 | Redis port |
| `SERVER_PORT` | 8080 | HTTP listen port |

## CI/CD
- GitHub Actions workflow at `.github/workflows/build.yml` builds and pushes the Docker image to GHCR on pushes to `main` or PRs touching `server/**`
- Git remotes: `origin` → GitHub (`DisplaceTech/swordfish`), `fj` → internal Forgejo instance

## Testing
- **PHP**: No phpunit/phpcs configuration; linting/testing done by building and running the Docker image
- **Frontend**: Vitest test suite at `frontend/src/__tests__/`; run with `npm test` from `frontend/`; also run `npm run lint` (ESLint) and `npm run build` (Vite) to verify
- **`server/static/dist/`** is committed to the repo — always rebuild with `npm run build` after frontend changes
