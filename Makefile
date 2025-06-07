.PHONY: server-build server-up server-down server-install cli-install

# Default target
.DEFAULT_GOAL := server-up

# Build the server container image
server-build:
	cd server && docker compose build
	docker tag server-server:latest swordfish:local

# Run the server with local development overrides
server-up: server-build
	cd server && docker compose -f docker-compose.yml -f docker-compose.dev.yml up -d

# Stop all server containers
server-down:
	cd server && docker compose -f docker-compose.yml -f docker-compose.dev.yml down

# Install Composer dependencies locally for server
server-install:
	docker run --rm -v $(PWD)/server:/app composer:latest composer install --ignore-platform-reqs

# Install Composer dependencies locally for CLI
cli-install:
	docker run --rm -v $(PWD)/cli:/app composer:latest composer install --ignore-platform-reqs 