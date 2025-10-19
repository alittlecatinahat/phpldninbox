#!/bin/bash

# LDN Inbox Docker Run Script

cd "$(dirname "$0")/.."

echo "======================================"
echo "  LDN Inbox - Docker Compose Startup"
echo "======================================"
echo ""

# Check if Docker is running
if ! docker info > /dev/null 2>&1; then
    echo "[Error] : Docker is not running. Please start Docker first."
    exit 1
fi

# Use docker compose (v2) instead of docker-compose (v1)
COMPOSE_CMD="docker compose"

echo "[Info] Building and starting containers..."
$COMPOSE_CMD up -d --build

# Wait for MySQL to be ready
echo ""
echo "[Info] Waiting for MySQL to be ready..."
sleep 10

# Check container status
echo ""
echo "[Info] Container Status:"
$COMPOSE_CMD ps

echo ""
echo "======================================"
echo "       LDN Inbox is now running!"
echo "======================================"
echo ""
echo "Application URL: http://localhost:8081"
echo "Public Pages:    http://localhost:8081/public/"
echo "MySQL Port:      3307"
echo ""
echo "Useful commands:"
echo "View logs:       docker compose logs -f"
echo "Stop:            docker compose down"
echo "Restart:         docker compose restart"
echo "Shell (PHP):     docker exec -it ldn_php bash"
echo "Shell (MySQL):   docker exec -it ldn_mysql mysql -uldn_user -pldn_password ldn_inbox"
echo ""
