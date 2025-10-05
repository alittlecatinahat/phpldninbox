#!/usr/bin/env bash
set -euo pipefail

echo "Rebuilding MySQL from schema.sql (fresh volume)..."

# Change to the root directory where docker-compose.yml is located
cd "$(dirname "$0")/.."

# Bring the stack down and remove named volumes for this project
docker compose down -v

# Start only MySQL first; on a fresh datadir, it will run /docker-entrypoint-initdb.d/schema.sql
echo "Starting MySQL container with fresh volume..."
docker compose up -d ldn_mysql

# Wait for MySQL to initialize and be ready
echo "Waiting for MySQL to initialize database (this may take 5 seconds)..."
sleep 5

echo "Checking MySQL health status..."
docker compose ps ldn_mysql

echo "MySQL is ready. Starting remaining containers..."

# Start all remaining containers
docker compose up -d

echo ""
echo "Database rebuilt successfully!"
echo "Application URL: http://localhost:8081"
