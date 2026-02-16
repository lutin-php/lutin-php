#!/bin/bash
# scripts/test.sh — Run all test suites

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT_DIR="$(dirname "$SCRIPT_DIR")"
TESTS_DIR="$ROOT_DIR/tests"

FAILED=0
PASSED=0

echo "Running test suites..."
echo ""

# Run all test_*.php files in tests/ directory
for test_file in "$TESTS_DIR"/test_*.php; do
    if [ -f "$test_file" ]; then
        test_name=$(basename "$test_file")
        echo "→ Running $test_name..."
        if php "$test_file"; then
            PASSED=$((PASSED + 1))
        else
            FAILED=$((FAILED + 1))
        fi
        echo ""
    fi
done

# Summary
echo "═══════════════════════════════════════════════════"
if [ $FAILED -eq 0 ]; then
    echo "✓ All $PASSED test suite(s) passed!"
    exit 0
else
    echo "✗ $FAILED test suite(s) FAILED, $PASSED passed."
    exit 1
fi
