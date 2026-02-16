#!/bin/bash
# scripts/start.sh — Start PHP development server

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT_DIR="$(dirname "$SCRIPT_DIR")"

cd "$ROOT_DIR"

PORT="${1:-8000}"
RUNDIR="$ROOT_DIR/run"
WEBROOT="$RUNDIR/public"
DATADIR="$RUNDIR/lutin"

# Create directory structure
mkdir -p "$WEBROOT"
mkdir -p "$DATADIR"

# Copy/update lutin.php to web root (without removing other files)
cp "dist/lutin.php" "$WEBROOT/lutin.php"

echo "Starting development server..."
echo "Website: http://localhost:$PORT/"
echo "Lutin:   http://localhost:$PORT/lutin.php"
echo "Web root: $WEBROOT"
echo "Data directory: $DATADIR"
echo ""
echo "Press Ctrl+C to stop the server"
echo ""

# Set the data directory environment variable and start server
# This ensures the data directory is outside the web root
export LUTIN_DATA_DIR="$DATADIR"
php -S localhost:$PORT -t "$WEBROOT"
