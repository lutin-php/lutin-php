#!/bin/bash
# scripts/test.sh — Run the unit test suite

set -e

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT_DIR="$(dirname "$SCRIPT_DIR")"

echo "Running unit tests..."
php "$SCRIPT_DIR/test.php"

if [ $? -eq 0 ]; then
    echo "✓ All tests passed!"
    exit 0
else
    echo "✗ Tests failed!"
    exit 1
fi
