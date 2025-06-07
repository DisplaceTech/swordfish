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

- Docker and Docker Compose (for local development)
- Make
- PHP 8.4+ (for local development)
- Composer (for local development)
- Kubernetes 1.19+ (for production deployment)
- Helm 3.0+ (for production deployment)

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

## Deployment Options

### Docker Configuration

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

#### Development Mode

For development, the server component includes a `docker-compose.dev.yml` that:
- Mounts the local `/server` directory into the container
- Enables hot-reloading of PHP files
- Uses the `swordfish:local` image tag

### Kubernetes Deployment

The application can be deployed to Kubernetes using the provided Helm chart.

#### Prerequisites
- Kubernetes cluster 1.19+
- Helm 3.0+
- Ingress controller (optional, for ingress support)

#### Installation

1. Create the namespace:
```bash
kubectl create namespace swordfish
```

2. Install the chart:
```bash
helm install swordfish ./helm/swordfish -n swordfish
```

#### Configuration

The following table lists the configurable parameters for the Helm chart:

| Parameter | Description | Default |
|-----------|-------------|---------|
| `server.replicaCount` | Number of server replicas | `1` |
| `server.image.repository` | Server image repository | `swordfish` |
| `server.image.tag` | Server image tag | `latest` |
| `server.service.type` | Kubernetes service type | `ClusterIP` |
| `server.service.port` | Service port | `8080` |
| `redis.architecture` | Redis architecture | `standalone` |
| `redis.auth.enabled` | Enable Redis authentication | `false` |
| `ingress.enabled` | Enable ingress | `false` |
| `ingress.className` | Ingress class name | `""` |
| `ingress.hosts` | Ingress hosts configuration | `[{host: swordfish.local, paths: [{path: /, pathType: Prefix}]}]` |

Example configuration with custom values:

```yaml
# values.yaml
server:
  replicaCount: 2
  image:
    repository: your-registry/swordfish
    tag: v1.0.0
  
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

Install with custom values:
```bash
helm install swordfish ./helm/swordfish -n swordfish -f values.yaml
```

#### Upgrading

To upgrade an existing deployment:
```bash
helm upgrade swordfish ./helm/swordfish -n swordfish
```

#### Uninstalling

To remove the deployment:
```bash
helm uninstall swordfish -n swordfish
```

### CI/CD Pipeline

The project uses GitHub Actions to automatically build and push the server container image to GitHub Container Registry (ghcr.io).

#### Pipeline Behavior

- On Branch Pushes:
  - Builds the server image
  - Tags with commit SHA (e.g., `sha-a1b2c3d`)
  - Pushes to GitHub Container Registry

- On Main Branch:
  - Builds the server image
  - Tags with both commit SHA and `latest`
  - Pushes to GitHub Container Registry
  - Images are available at `ghcr.io/<org>/<repo>/server:<tag>`

- On Pull Requests from Forks:
  - Builds the server image
  - Runs tests and verifies build
  - Does not push to registry (for security)

#### Container Registry Cleanup

GitHub Container Registry provides automatic cleanup of untagged container images after 30 days. The `latest` tag and any specific version tags are preserved indefinitely.

#### Required Setup

1. Ensure your repository has GitHub Actions enabled
2. The workflow uses `GITHUB_TOKEN` which is automatically provided
3. GitHub Container Registry permissions are automatically handled by the workflow

#### Using the Container Images

In your Kubernetes deployment:
```yaml
# values.yaml
server:
  image:
    repository: ghcr.io/<org>/<repo>/server
    # Use latest for production
    tag: "latest"  
    # Or use a specific commit for immutable deployments
    # tag: "sha-a1b2c3d"
```

For local development, you can still use:
```bash
make server-build
```

This will build the image locally without pushing to the registry.

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
