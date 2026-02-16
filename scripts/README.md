# Lutin.php Development Scripts

This directory contains all development and deployment scripts for Lutin.php.

## Bash Wrapper Scripts

These are the primary interface for managing the project. Run from the repo root:

### `build.sh`
Compiles the application from `src/` into a single-file `dist/lutin.php`.

```bash
./scripts/build.sh
```

- Reads all PHP classes, views, and assets from `src/`
- Inlines everything into `dist/lutin.php`
- Output: ~65 KB single-file PHP application

### `test.sh`
Runs the unit test suite (7 tests covering core functionality).

```bash
./scripts/test.sh
```

Tests:
- Config loading/saving and first-run detection
- File path security (escape prevention, protected paths)
- File writing with automatic backups
- URL-to-file heuristic mapping

### `lint.sh`
Syntax checks all PHP source files and the compiled file.

```bash
./scripts/lint.sh
```

Checks:
- All individual source files in `src/classes/`, `src/index.php`
- All PHP scripts in `scripts/`
- Compiled `dist/lutin.php`

### `check.sh`
Boot probe — starts the PHP dev server and verifies the app renders correctly.

```bash
./scripts/check.sh
```

Verification:
- Starts built-in PHP server on random port
- Requests `http://localhost:<port>/`
- Confirms HTTP 200 response with `lutin-token` marker
- Kills server and exits

### `qa.sh`
Runs ALL quality gates in sequence (the primary development workflow).

```bash
./scripts/qa.sh
```

Sequence:
1. **GATE 1**: Syntax lint
2. **GATE 2**: Build
3. **GATE 3**: Lint compiled file
4. **GATE 4**: Unit tests
5. **GATE 5**: Boot probe

All gates must pass. Exit code is 0 on success, 1 on failure.

### `start.sh`
Starts the development server from the compiled `dist/` (production-like).

```bash
./scripts/start.sh [port=8000]
```

Options:
- `port` (default: 8000) — TCP port to listen on

Access: `http://localhost:8000`

**Note:** For first run, you'll be prompted to complete the setup wizard.

### `dev.sh`
Starts the development server directly from `src/index.php` (no build needed).

```bash
./scripts/dev.sh [port=8000]
```

Options:
- `port` (default: 8000) — TCP port to listen on

**Use this during development to test changes without rebuilding.**

## PHP Scripts

These are called by the bash wrappers but can also be invoked directly:

### `build.php`
Core build script: concatenates all source files into `dist/lutin.php`.

```bash
php scripts/build.php
```

Algorithm:
1. Reads `src/classes/*.php` in dependency order
2. Strips opening `<?php` tags and `declare(strict_types=1)` from subsequent files
3. Inlines all view files as PHP constants
4. Inlines `src/assets/app.js` as a PHP constant
5. Appends `src/index.php` entry point
6. Writes complete compiled output to `dist/lutin.php`

### `check.php`
Boot probe: starts server, makes HTTP request, validates response.

```bash
php scripts/check.php
```

Behavior:
- Starts `php -S localhost:<random-port>`
- Waits 0.5s for server startup
- Requests `/` via curl (or fallback to stream wrapper)
- Asserts HTTP 200 and presence of `lutin-token` string
- Terminates server
- Exit code: 0 = pass, 1 = fail

### `test.php`
Unit test runner (no Composer required).

```bash
php scripts/test.php
```

Features:
- Zero dependencies — pure PHP
- 7 tests covering core functionality
- Helper functions: `assert_eq()`, `assert_true()`, `assert_throws()`
- Temporary scratch directory for isolation
- Exit code: 0 = all pass, 1 = any fail

## Typical Workflow

1. **Make changes** to source files in `src/`

2. **Test locally** (optional, for quick feedback):
   ```bash
   ./scripts/dev.sh 8000
   # Visit http://localhost:8000 and test manually
   # Press Ctrl+C to stop
   ```

3. **Run QA** before committing:
   ```bash
   ./scripts/qa.sh
   ```

4. **If all pass**: commit your changes

5. **To deploy**: copy `dist/lutin.php` to your web server

## Common Issues

**Build fails:**
- Check syntax with `./scripts/lint.sh`
- Ensure all source files are present
- Review error message from `scripts/build.php`

**Tests fail:**
- Run individual test: `php scripts/test.php`
- Check test assertions for specifics
- Ensure temp directory is writable

**Boot probe fails:**
- Check if port is already in use
- Ensure `dist/lutin.php` is built: `./scripts/build.sh`
- Check server logs for PHP errors

**Dev server crashes:**
- Check for PHP syntax errors: `./scripts/lint.sh`
- Ensure API key is configured (if you've completed setup)
- Check for unhandled exceptions in custom code

## Directory Structure

```
scripts/
├── README.md          # This file
├── build.sh           # Build wrapper
├── build.php          # Core build logic
├── test.sh            # Test wrapper
├── test.php           # Test suite
├── check.sh           # Boot probe wrapper
├── check.php          # Boot probe logic
├── lint.sh            # Syntax check wrapper
├── qa.sh              # Quality assurance orchestrator
├── start.sh           # Production server starter
└── dev.sh             # Development server starter
```

## Requirements

- PHP 8.1+ (CLI)
- Bash 4+
- `curl` (optional, for boot probe; falls back to stream wrapper)
- `posix_kill` function enabled in PHP

All scripts are pure Bash and PHP — no external dependencies like npm or Composer.
