# Project Swordfish

A secure, anonymous secret sharing application that allows users to share sensitive information through time-limited, encrypted secrets. The project consists of two main components:

1. A PHP-based server application that:
   - Provides a web interface for creating and retrieving secrets
   - Handles encryption/decryption of secrets
   - Uses Redis for temporary secret storage (24-hour expiration)
   - Built with Amphp for async PHP processing

2. A CLI tool for programmatic interaction with the server that:
   - Creates encrypted secrets
   - Retrieves and decrypts secrets
   - Uses local encryption/decryption for enhanced security

## Security Features

- Client-side encryption/decryption (both in browser and CLI)
- AES-256-GCM encryption
- PBKDF2 key derivation
- 24-hour secret expiration
- Password verification without storing the actual password
- Secrets are never stored in plaintext

## Prerequisites

- Docker and Docker Compose
- Make
- PHP 8.4+ (for local development)
- Composer (for local development)

## Development Setup

The project uses Make for common development tasks. Here are the available commands:

### Server Component

```bash
# Install server dependencies locally
make server-install

# Build the server container
make server-build

# Start the server (builds if needed)
make server-up

# Stop the server
make server-down
```

### CLI Component

```bash
# Install CLI dependencies locally
make cli-install
```

## Docker Configuration

### Server Component

The server runs two containers:
1. PHP Server (Amphp-based HTTP server)
   - Exposes port 8080
   - Handles web interface and API endpoints
   - Configurable through environment variables:
     - `SERVER_PORT`: HTTP server port (default: 8080)
     - `REDIS_HOST`: Redis server hostname
     - `REDIS_PORT`: Redis server port

2. Redis Server
   - Stores encrypted secrets
   - Handles automatic expiration
   - Exposes port 6379 (for internal use)

### Development Mode

For development, the server component includes a `docker-compose.dev.yml` that:
- Mounts the local `/server` directory into the container
- Enables hot-reloading of PHP files
- Uses the `swordfish:local` image tag

## API Endpoints

### Web Interface
- `GET /` - Secret creation page
- `GET /secret` - Secret retrieval page
- `GET /secret/{secretId}` - Pre-populated secret retrieval page

### Backend API
- `POST /create` - Create a new secret
- `POST /retrieve` - Retrieve an existing secret

## CLI Usage

The CLI tool provides two main commands:

```bash
# Create a new secret
./cli/cli.php secret:create "your secret" "your password"

# Retrieve a secret
./cli/cli.php secret:retrieve "secret-id" "password"
```

## Environment Variables

- `SWORDFISH_URL`: API server URL (CLI only, defaults to https://swordfish.displace.tech)
- `SERVER_PORT`: Server listening port (default: 8080)
- `REDIS_HOST`: Redis server hostname
- `REDIS_PORT`: Redis server port (default: 6379)
