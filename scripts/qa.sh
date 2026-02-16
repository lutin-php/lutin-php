#!/bin/bash
# scripts/qa.sh — Run all quality assurance checks

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT_DIR="$(dirname "$SCRIPT_DIR")"

cd "$ROOT_DIR"

echo "╔════════════════════════════════════════════════════════════════════════════╗"
echo "║                        QUALITY ASSURANCE CHECKS                            ║"
echo "╚════════════════════════════════════════════════════════════════════════════╝"
echo ""

# Gate 1: Lint
echo "GATE 1: Syntax Lint..."
if bash "$SCRIPT_DIR/lint.sh"; then
    echo "✓ Gate 1 passed!"
else
    echo "✗ Gate 1 failed!"
    exit 1
fi
echo ""

# Gate 2: Build
echo "GATE 2: Building..."
if bash "$SCRIPT_DIR/build.sh"; then
    echo "✓ Gate 2 passed!"
else
    echo "✗ Gate 2 failed!"
    exit 1
fi
echo ""

# Gate 3: Lint compiled file
echo "GATE 3: Lint Compiled File..."
if php -l dist/lutin.php > /dev/null 2>&1; then
    echo "✓ dist/lutin.php passes syntax check"
else
    echo "✗ dist/lutin.php has syntax errors"
    php -l dist/lutin.php
    exit 1
fi
echo ""

# Gate 4: Unit Tests
echo "GATE 4: Unit Tests..."
if bash "$SCRIPT_DIR/test.sh"; then
    echo "✓ Gate 4 passed!"
else
    echo "✗ Gate 4 failed!"
    exit 1
fi
echo ""

# Gate 5: Boot Probe
echo "GATE 5: Boot Probe..."
if bash "$SCRIPT_DIR/check.sh"; then
    echo "✓ Gate 5 passed!"
else
    echo "✗ Gate 5 failed!"
    exit 1
fi
echo ""

echo "╔════════════════════════════════════════════════════════════════════════════╗"
echo "║                    ✅ ALL QUALITY GATES PASSED                             ║"
echo "╚════════════════════════════════════════════════════════════════════════════╝"
exit 0
