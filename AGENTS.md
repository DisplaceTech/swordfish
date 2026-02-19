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
  - `tailwind.config.js` — `darkMode: 'class'`; `index.html` has `<html class="dark">` for default dark theme
  - `src/main.jsx` — Preact entry point using `render()`; `src/App.jsx` — root component with Router
  - `src/App.jsx` — Uses `preact-router`; routes: `/` (Create), `/secret` (Retrieve), `/secret/:id` (Retrieve), `/about` (About); `NavLink` uses `useRouter()` for active state
  - `src/Create.jsx` — Stub page for secret creation (`/`)
  - `src/Retrieve.jsx` — Stub page for secret retrieval (`/secret`, `/secret/:id`)
  - `src/About.jsx` — How It Works page (`/about`)
  - `src/api.js` — `createSecret()` and `retrieveSecret()` fetch helpers
  - `src/index.css` — Tailwind directives (`@tailwind base/components/utilities`)
  - `eslint.config.js` — ESLint flat config with react-hooks (Preact-compatible)
- `cli/` — PHP CLI tool for interacting with the server
- `helm/` — Helm chart for Kubernetes deployment
- `swagger.yml` — OpenAPI 2.0 spec (update when adding routes)

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

### Frontend Testing Patterns
- **Test deps**: `@testing-library/preact`, `jsdom`, `@testing-library/jest-dom` (all devDependencies)
- **Environment**: Default is `node` (for crypto tests using Web Crypto API); component tests use `// @vitest-environment jsdom` per-file annotation
- **Globals**: `globals: true` in `vite.config.js` test config — required for `@testing-library/jest-dom` to extend `expect` in `setupFiles`
- **Setup file**: `src/__tests__/setup.js` imports `@testing-library/jest-dom` and stubs `window.matchMedia` (guarded with `typeof window !== 'undefined'` for node-env tests); referenced via `setupFiles` in `vite.config.js`
- **matchMedia**: jsdom does not implement `window.matchMedia`; always add a stub in `setup.js` when components use it (e.g., `EncryptionFlow.jsx`)
- **Mocking**: Use `vi.mock('../api', () => ({ fn: vi.fn() }))` and `vi.mock('../crypto', ...)` at module level; import mocked functions after `vi.mock()` calls; reset with `vi.clearAllMocks()` in `beforeEach`
- **Preact events**: Use `fireEvent.input` for `<textarea>` and `<input>` (Preact uses `onInput`); use `fireEvent.change` for `<select>` (uses `onChange`); use `fireEvent.submit` on the `<form>` element
- **Async state**: Use `screen.findBy*` or `waitFor` after async operations (form submit, API calls)
- **Clipboard**: Mock `navigator.clipboard` via `Object.defineProperty(navigator, 'clipboard', { value: { writeText: vi.fn() }, writable: true, configurable: true })` in `beforeEach`
- **Coverage**: `include: ['src/**/*.{js,jsx}']`, excludes `src/main.jsx` and `src/__tests__/**`; page components target ≥70%
