# CLAUDE.md — Lutin.php Project

## Project Overview

**Lutin.php** is a self-hosted, single-file PHP development environment.
It lets users build and edit websites through an AI-powered chat interface or a manual code editor.
When `lutin.php` and the lutin directory are removed, the managed website remains 100% functional.

### Architecture

Lutin.php follows a **two-directory architecture** for security:

- **Project Root** (configurable): The root directory of the project containing all source code, 
  configuration files, and website files. The AI agent can read/write anywhere in this directory.
- **Web Root** (`public/`, `www/`, etc.): A subdirectory of the project root where `lutin.php` lives 
  and where public-facing website files are served from.
- **Lutin Directory** (`lutin/` inside project root): Stores:
  - `config.json` — configuration including password hash, API key, provider settings
  - `backups/` — timestamped backups of all file changes
  - `temp/` — temporary files

This separation ensures that sensitive configuration data is never accessible via web requests, even if `.htaccess` protection fails.

---

## Repository Layout

```
lutin/
├── src/                        # Source files (developed here)
│   ├── classes/                # Core PHP classes
│   │   ├── LutinConfig.php     # Config management + project root handling
│   │   ├── LutinAuth.php
│   │   ├── LutinFileManager.php # Project root + lutin dir file operations
│   │   ├── LutinRouter.php
│   │   └── LutinView.php
│   ├── agents/                 # AI Agent classes
│   │   ├── AbstractLutinAgent.php  # Base Agent class (abstract)
│   │   └── LutinChatAgent.php      # Chat Agent with file tools
│   ├── agent_providers/        # AI Provider adapters
│   │   ├── LutinProviderAdapter.php  # Interface
│   │   ├── AnthropicAdapter.php      # Anthropic (Claude) adapter
│   │   └── OpenAIAdapter.php         # OpenAI (GPT) adapter
│   ├── views/                  # HTML view partials
│   │   ├── layout.php          # Outer HTML shell (head, tabs, script tags)
│   │   ├── setup_wizard.php    # First-run setup form (includes project root config)
│   │   ├── login.php           # Login form
│   │   ├── tab_chat.php        # Chat tab markup
│   │   ├── tab_editor.php      # Manual editor tab markup
│   │   └── tab_config.php      # Config & Backups tab markup
│   ├── assets/
│   │   └── app.js              # All vanilla JS (bundled into single file)
│   └── index.php               # Entry point (bootstrap and dispatch)
├── dist/
│   └── lutin.php               # Compiled single-file output (do not edit manually)
├── scripts/                    # Development and build scripts
│   ├── build.sh                # Bash wrapper: build the application
│   ├── build.php               # PHP script: concatenate src/ into dist/lutin.php
│   ├── test.sh                 # Bash wrapper: run unit tests
│   ├── test.php                # PHP script: unit test suite
│   ├── check.sh                # Bash wrapper: run boot probe
│   ├── check.php               # PHP script: boot probe (HTTP + render test)
│   ├── lint.sh                 # Bash wrapper: syntax check all files
│   ├── qa.sh                   # Bash wrapper: run all quality gates
│   ├── start.sh                # Bash wrapper: start compiled server (run/public + run/lutin)
│   └── dev.sh                  # Bash wrapper: start dev server from src/
├── run/                        # Runtime directory (created by start.sh)
│   ├── public/                 # Web root (lutin.php copied here)
│   └── lutin/                  # Lutin directory (stores config, backups)
├── tests/                      # Unit tests
├── docs/
│   ├── STARTER_SPECS.md
│   └── DEV_PLAN_V1.md
├── VERSION                     # Version file (used by build.php)
└── CLAUDE.md                   # This file
```

---

## Development Workflow

### Quick Start

All scripts are in the `scripts/` directory and can be run from the repo root:

```bash
# Build the application
./scripts/build.sh

# Run unit tests
./scripts/test.sh

# Syntax check all files
./scripts/lint.sh

# Run all quality gates at once
./scripts/qa.sh

# Start development server (creates run/public + run/lutin)
# Website at http://localhost:8000/, Lutin at http://localhost:8000/lutin.php
./scripts/start.sh [port=8000]

# Start development server (directly from src/, no build needed)
./scripts/dev.sh [port=8000]
```

### Quality Gates

Run `./scripts/qa.sh` to execute all gates. Individual gates:

| Gate | Command | Purpose |
|------|---------|---------|
| Syntax lint | `./scripts/lint.sh` | Check all PHP files for syntax errors |
| Build | `./scripts/build.sh` | Compile src/ into dist/lutin.php |
| Unit tests | `./scripts/test.sh` | Run test suite |
| Boot probe | `./scripts/check.sh` | Start server and verify it renders |

**All gates must pass before committing changes.**

---

## Build System

The compiled file `dist/lutin.php` is produced by running:

```bash
./scripts/build.sh    # Recommended: uses bash wrapper
# or
php scripts/build.php # Direct PHP invocation
```

`scripts/build.php` reads all `src/classes/*.php` files, `src/views/*.php`, inlines `src/assets/app.js`,
and writes a single self-contained `dist/lutin.php`.

**Important:**
- **Never edit `dist/lutin.php` directly** — always modify files in `src/` and rebuild
- Always run `./scripts/lint.sh` before and after changes
- Run `./scripts/qa.sh` to verify all quality gates pass before committing

---

## Coding Standards

- **PHP 8.1+** — use typed properties, enums, named arguments, fibers if needed.
- **Zero Composer dependencies.** Use only PHP built-ins and `curl`.
- One class per file in `src/classes/`.
- Class names are prefixed with `Lutin` (e.g. `LutinAgent`).
- All file paths passed to `LutinFileManager` must be validated through
  `LutinFileManager::safePath()` before use — never build paths manually elsewhere.
- HTTP responses are sent only through `LutinRouter` or `LutinAgent`; no stray `echo`s.
- SSE output uses `Content-Type: text/event-stream` with explicit `ob_end_clean()` before output.
  Note: Current implementation uses synchronous API calls and yields events after the full response
  is received (pseudo-streaming). True streaming may be added later if timeout issues arise.

---

## Security Rules (Hard Constraints)

1. `LutinFileManager::write()` and `LutinFileManager::read()` must refuse any path that
   resolves to `lutin.php`.
2. The lutin directory (`config.json`, `backups/`, `temp/`) lives inside the project root
   but is never directly accessible via HTTP.
3. Before every write, `LutinFileManager` creates a timestamped backup in the lutin directory's
   `backups/YYYY-MM-DD_HH-II-SS_<basename>`.
4. All AI tool-call arguments are re-validated server-side; the AI's output is never trusted directly.
5. The password hash is stored in `config.json` as a `password_hash()` bcrypt string.
   Plain-text passwords are never stored or logged.
6. CSRF: every non-GET AJAX endpoint checks a session token sent as `X-Lutin-Token` header.

---

## AI Provider Abstraction

Provider adapters are in `src/agent_providers/` and implement the `LutinProviderAdapter` interface.
`AbstractLutinAgent` (base class) communicates with a single provider configured globally in the lutin directory's `config.json`.
Supported providers in v1: **Anthropic** (Claude) and **OpenAI** (GPT).
The provider adapter is selected at runtime based on `config.provider` — no code changes needed to switch.

Each adapter implements `formatTools()` to convert provider-agnostic tool definitions to the provider-specific format:
- **Anthropic**: Uses tools directly (no transformation)
- **OpenAI**: Wraps each tool in `{type: 'function', function: {...}}` structure

Tool calling follows each provider's native format:
- Anthropic: `tool_use` / `tool_result` content blocks.
- OpenAI: `tools` array with `function` type, `tool_calls` in response.

Available AI tools (PHP-side functions the agent can invoke):
- `list_files(path)` — returns directory listing.
- `read_file(path)` — returns file content.
- `write_file(path, content)` — writes/creates a file (triggers backup).

## Agent Architecture

The base `AbstractLutinAgent` class (in `src/agents/AbstractLutinAgent.php`) provides:
- Provider adapter management and API communication
- The agentic loop (`runLoop()`) for multi-turn tool interactions
- SSE output handling (`sseFlush()`)
- Helper method `addFileContentToPrompt()` for including file content in prompts
- `COMMON_AVAILABLE_TOOLS` constant — the full list of all available tools (list_files, read_file, write_file)
- `buildToolDefinitions()` — returns all tools by default
- `selectTools(array $toolNames)` — helper to select specific tools from COMMON_AVAILABLE_TOOLS
- `executeTool()` — executes file management tools via LutinFileManager
- `executeCustomTool()` — hook for subclasses to handle custom tools

Subclasses must:
- Implement `buildSystemPrompt()` — Builds and returns the complete system prompt
- Override `buildToolDefinitions()` and return `$this->selectTools(['tool1', 'tool2'])` to select wanted tools
- Optionally override `executeCustomTool()` for custom tool handling

Current agent implementations:
- `LutinChatAgent` — File management agent
  - Overrides `buildToolDefinitions()` to return `$this->selectTools(['list_files', 'read_file', 'write_file'])`
  - Uses `addFileContentToPrompt()` to inject AGENTS.md content
  - Inherits tool execution from base class
- Future: `LutinEditorAgent` — Would select a different subset of tools and optionally override `executeCustomTool()`

---

## Key Implementation Notes for Haiku

- **Startup flow:** On first visit (no config), Lutin renders a setup wizard (password + provider + API key + optional project root).
  On subsequent visits, `LutinAuth` checks the session; if unauthenticated, shows login form.
- **Three-directory architecture:**
  - `LutinConfig` manages three directories:
    - `projectRootDir` — the project root (configurable, asked in setup)
    - `webRootDir` — where lutin.php lives and public files are served from (configurable, asked in setup)
    - `lutinDir` — internal files (auto-created as `projectRoot/lutin`)
  - Defaults: project root = parent of lutin.php, web root = directory containing lutin.php
  - Configurable via `LUTIN_PROJECT_ROOT` environment variable or setup wizard
- **Accessing Lutin:** Lutin is accessed explicitly via `/lutin.php`. The web root (`/`) serves the managed website files (index.html, etc.).
- **SSE streaming (emulated):** The agent makes synchronous API calls, then yields SSE `data:` 
  events to the frontend. This keeps the connection alive on slow hosts while keeping the 
  implementation simple. The JS consumes these events via `fetch()` with `response.body.getReader()`.
- **Tab routing:** All navigation is client-side (hash-based). The PHP backend is API-only after
  the initial page load.
- **Edit by URL:** `LutinFileManager::urlToFile(string $url): array` returns a ranked list of
  candidate file paths using heuristics; the JS shows a picker if more than one candidate exists.
- **One-click restore:** `LutinFileManager::restore(string $backupPath)` writes the backup content
  back to the original file, first creating a new backup of the current state.
