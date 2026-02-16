#!/bin/bash
# scripts/check.sh — Boot probe: verify the compiled application loads correctly

set -e

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT_DIR="$(dirname "$SCRIPT_DIR")"

echo "Running boot probe..."
timeout 15 php "$SCRIPT_DIR/check.php"

if [ $? -eq 0 ]; then
    echo "✓ Boot probe passed!"
    exit 0
else
    echo "✗ Boot probe failed!"
    exit 1
fi
