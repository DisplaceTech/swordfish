# Swordfish — Agent Memory

## Repository Overview
PHP secret-sharing application. Two components:
- **`server/`** — Amp async HTTP server (PHP 8+), Redis-backed storage
- **`cli/`** — Symfony Console CLI for creating/retrieving secrets

## Key Architecture
- Routes defined in `server/src/ServerRoutes.php` (static factory methods returning `CallableRequestHandler`)
- Route handlers use Amp coroutines (`yield $request->getBody()->read()`)
- `server/src/CreateRequest.php` — legacy text/plain create request parser
- `server/src/RetrievalRequest.php` — legacy text/plain retrieval request parser

## API Endpoints
| Method | Path | Formats |
|--------|------|---------|
| POST | `/create` | `application/json` (new) + `text/plain` (legacy) |
| POST | `/retrieve` | `application/json` (new) + `text/plain` (legacy) |

### JSON Create (`POST /create`)
- Request: `{"encrypted_secret": "...", "ttl": 86400, "max_views": 1}`
- Response: `{"id": "...", "expires_at": 1234567890, "max_views": 1}`
- Redis keys: `json_secret:{id}`, `json_views:{id}`, `json_expires:{id}`

### JSON Retrieve (`POST /retrieve`)
- Request: `{"id": "..."}`
- Response: `{"encrypted_secret": "...", "views_remaining": 0, "expires_at": 1234567890}`
- Decrements view counter; deletes all keys when exhausted

### Legacy text/plain Create
- Request: `{hex_salt}${hex_verifier}${hex_secret}`
- Response: plain-text secret ID
- Redis keys: `secret:{id}`, `verifier:{id}`

### Legacy text/plain Retrieve
- Request: `{secretID}${hex_verifier}`
- Response: plain-text hex-encoded secret

## Testing
- Framework: PHPUnit 11 (`server/vendor/bin/phpunit`)
- Config: `server/phpunit.xml`
- Tests: `server/tests/`
- Run: `cd server && composer test` or `./vendor/bin/phpunit --testdox`
- 23 tests, 35 assertions (all pass)

## Build / Install
```bash
# Install server deps locally (requires Docker)
make server-install

# Or directly with composer (PHP 8+ required)
cd server && composer install --ignore-platform-reqs

# Run server (Docker)
make server-up
```

## Git / GitHub
- Remote: `origin` → `git@github.com:DisplaceTech/swordfish.git`
- Remote: `fj` → internal Forgejo instance
- Git user: Eric Mann <eric@eamann.com>
- Use `Co-authored-by: openhands <openhands@all-hands.dev>` in commits
- Use `gh pr create` (GitHub CLI) to open PRs — `GITHUB_TOKEN` is set

## Linting
No dedicated linter configured. Use `php -l` for syntax checking:
```bash
find server/src server/tests -name '*.php' | xargs php -l
```

## Notes
- `ServerRoutes` route handlers cannot be easily unit-tested directly (Amp coroutines)
- Extract pure parsing logic into static helpers (e.g., `parseJsonCreateBody`) for testability
- `feature/setup-phpunit` branch on origin has the PHPUnit infrastructure (merged into this work)
- `.gitignore` at root covers `**/vendor/` and `**/.phpunit.result.cache`
