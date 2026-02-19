# Swordfish — Agent Memory

## Repository Overview
Secret-sharing web application with a PHP/Amp HTTP server backend and a PHP Symfony Console CLI client.

## Structure
- `server/` — Amp HTTP Server (PHP). Entry point: `server.php`. Source classes in `src/`.
- `cli/` — Symfony Console CLI. Entry point: `cli.php`. Source classes in `src/`.
- `swagger.yml` — OpenAPI spec (canonical API definition).
- `helm/` — Kubernetes Helm chart for deployment.
- `Makefile` — Docker-based build/run targets (`server-build`, `server-up`, `server-down`, `server-install`, `cli-install`).

## Key Source Files
| File | Purpose |
|------|---------|
| `server/server.php` | Registers all HTTP routes via `Amp\Http\Server\Router` |
| `server/src/ServerRoutes.php` | Static factory methods returning `CallableRequestHandler` instances |
| `server/src/CreateRequest.php` | Deserializes and validates secret-creation payloads |
| `server/src/RetrievalRequest.php` | Deserializes and validates secret-retrieval payloads |
| `cli/src/CreateSecretCommand.php` | `secret:create` CLI command; POSTs to `/api/create` |
| `cli/src/RetrieveSecretCommand.php` | `secret:retrieve` CLI command; POSTs to `/api/retrieve` |

## API Routes (current)
| Method | Path | Handler |
|--------|------|---------|
| GET | `/` | `ServerRoutes::mainContent()` |
| GET | `/secret` | `ServerRoutes::secretRetrieval()` |
| GET | `/secret/{secretId}` | `ServerRoutes::secretRetrieval()` |
| POST | `/api/create` | `ServerRoutes::createSecret()` — canonical |
| POST | `/api/retrieve` | `ServerRoutes::retrieveSecret()` — canonical |
| POST | `/create` | `ServerRoutes::redirect('/api/create')` — 307 compat |
| POST | `/retrieve` | `ServerRoutes::redirect('/api/retrieve')` — 307 compat |

## Linting / Testing
- No phpcs/phpstan/phpunit config present. Use `php -l <file>` for syntax checking.
- No automated test suite; verify logic manually or via integration tests against a running server.

## Dependencies
- Server: `amphp/http-server`, `amphp/http-server-router`, `amphp/http-server-static-content`, `predis/predis`, `amphp/log`
- CLI: `symfony/console`, `ext-sodium`, `ext-curl`
- Install via Docker: `make server-install` / `make cli-install`

## Git / PR Workflow
- Feature branches pushed to `origin` (GitHub: `DisplaceTech/swordfish`).
- PRs opened against `main`. Title must start with the ticket ID.
- Use GitHub REST API (`/repos/DisplaceTech/swordfish/pulls`) if `gh pr create` stalls.
- Existing git credentials: Eric Mann <eric@eamann.com>; add `Co-authored-by: openhands <openhands@all-hands.dev>` to commits.
