#!/bin/bash
# scripts/build.sh — Build the compiled dist/lutin.php from source files

set -e

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT_DIR="$(dirname "$SCRIPT_DIR")"

echo "Building Lutin.php..."
php "$SCRIPT_DIR/build.php"

if [ $? -eq 0 ]; then
    echo "✓ Build successful!"
    exit 0
else
    echo "✗ Build failed!"
    exit 1
fi
