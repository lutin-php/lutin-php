#!/bin/bash
# scripts/lint.sh — Syntax lint all PHP files

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT_DIR="$(dirname "$SCRIPT_DIR")"

cd "$ROOT_DIR"

echo "Linting source files..."
errors=0

for f in src/classes/*.php src/agents/*.php src/agent_providers/*.php src/index.php scripts/build.php scripts/check.php scripts/test.php; do
    if [ -f "$f" ]; then
        if php -l "$f" > /dev/null 2>&1; then
            echo "  ✓ $f"
        else
            echo "  ✗ $f"
            php -l "$f"
            ((errors++))
        fi
    fi
done

echo ""
if [ -f "dist/lutin.php" ]; then
    echo "Linting compiled file..."
    if php -l dist/lutin.php > /dev/null 2>&1; then
        echo "  ✓ dist/lutin.php"
    else
        echo "  ✗ dist/lutin.php"
        php -l dist/lutin.php
        ((errors++))
    fi
    echo ""
fi

if [ $errors -eq 0 ]; then
    echo "✓ All files passed syntax check!"
    exit 0
else
    echo "✗ $errors file(s) failed syntax check!"
    exit 1
fi
