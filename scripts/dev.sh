#!/bin/bash
# scripts/dev.sh — Start development server using src/ directly (without building)

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT_DIR="$(dirname "$SCRIPT_DIR")"

cd "$ROOT_DIR"

PORT="${1:-8000}"

echo "Starting development server (from src/)..."
echo "URL: http://localhost:$PORT"
echo "Document root: src"
echo ""
echo "Note: Running directly from src/index.php without compilation"
echo "Press Ctrl+C to stop the server"
echo ""

php -S localhost:$PORT -t src src/index.php
