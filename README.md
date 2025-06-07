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
- GitHub Personal Access Token with `read:packages` scope (for pulling images)

#### Installation

1. Create the namespace:
```bash
kubectl create namespace swordfish
```

2. Configure GitHub Container Registry Authentication:

The server image is hosted on GitHub Container Registry (ghcr.io) and requires authentication to pull. You have two options:

Option 1: Let Helm create the pull secret (recommended):
```bash
helm install swordfish ./helm/swordfish -n swordfish \
  --set server.imagePullSecrets.create=true \
  --set server.imagePullSecrets.github.username=YOUR_GITHUB_USERNAME \
  --set server.imagePullSecrets.github.token=YOUR_GITHUB_PAT
```

Option 2: Create a pull secret manually and reference it:
```bash
# Create the secret manually
kubectl create secret docker-registry ghcr-auth \
  --docker-server=ghcr.io \
  --docker-username=YOUR_GITHUB_USERNAME \
  --docker-password=YOUR_GITHUB_PAT \
  -n swordfish

# Use the existing secret in Helm
helm install swordfish ./helm/swordfish -n swordfish \
  --set server.imagePullSecrets.name=ghcr-auth
```

For production deployments, you can include the authentication in your values file:
```yaml
# values.yaml
server:
  imagePullSecrets:
    create: true
    github:
      username: YOUR_GITHUB_USERNAME
      token: YOUR_GITHUB_PAT
  
  # Other configuration...
  image:
    repository: ghcr.io/displacetech/swordfish/server
    tag: "latest"    # or specific SHA
```

Then install with:
```bash
helm install swordfish ./helm/swordfish -n swordfish -f values.yaml
```

#### Configuration

The following table lists the configurable parameters for the Helm chart:

| Parameter | Description | Default |
|-----------|-------------|---------|
| `server.replicaCount` | Number of server replicas | `1` |
| `server.image.repository` | Server image repository | `ghcr.io/displacetech/swordfish/server` |
| `server.image.tag` | Server image tag | `latest` |
| `server.image.sha` | Optional SHA override for the tag | `""` |
| `server.imagePullSecrets.create` | Create a new pull secret | `false` |
| `server.imagePullSecrets.name` | Name of existing pull secret to use | `""` |
| `server.imagePullSecrets.github.username` | GitHub username for pull secret | `""` |
| `server.imagePullSecrets.github.token` | GitHub PAT for pull secret | `""` |
| `server.service.type` | Kubernetes service type | `ClusterIP` |
| `server.service.port` | Service port | `8080` |
| `redis.architecture` | Redis architecture | `standalone` |
| `redis.auth.enabled` | Enable Redis authentication | `false` |
| `ingress.enabled` | Enable ingress | `false` |
| `ingress.className` | Ingress class name | `""` |
| `ingress.hosts` | Ingress hosts configuration | `[{host: swordfish.local, paths: [{path: /, pathType: Prefix}]}]` |

Example configuration with custom values and authentication:

```yaml
# values.yaml
server:
  replicaCount: 2
  image:
    repository: ghcr.io/displacetech/swordfish/server
    tag: "latest"
    # sha: "a1b2c3d"  # Optional: Use specific commit
  
  # GitHub Container Registry authentication
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
