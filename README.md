# Lutin.php

A self-hosted, single-file AI-powered website editor.

Drop `lutin.php` into any web public directory and get an AI assistant that helps you build and edit your website through natural conversation. Remove `lutin.php` and the `lutin/` directory when you're done — your website remains 100% functional.

## Quick Start

* Download `lutin.php` from [here](https://raw.githubusercontent.com/lutin-php/lutin-php/refs/heads/main/dist/lutin.php)

* Copy in your server web root directory (public/, or www/, etc...)

* Go visit this new page `http://www.yoursite.com/lutin.php`

On first run, you'll set up:
- Admin password
- AI provider (Anthropic/OpenAI) and API key
- Project root directory (the AI can access all files here except `lutin/`)

Then just chat with the AI to build your website:
- *"Create a landing page with a hero section"*
- *"Add a contact form"*
- *"Update the header on all pages"*

## Philosophy

- **Single file deployment**: One PHP file to rule them all
- **Security by design**: Sensitive data (config, backups) lives in a protected `lutin/` directory
- **Zero dependencies**: No Composer, no build step, just PHP with curl
- **Portable**: Remove Lutin anytime, your website stays intact

## Prerequisites

- PHP 8.1+ with `curl` extension
- An API key from [Anthropic](https://www.anthropic.com) (Claude) or [OpenAI](https://openai.com) (GPT)

## Development

This repository contains the source code. The `dist/lutin.php` file is compiled from `src/`.

### Scripts

| Script | Purpose |
|--------|---------|
| `./scripts/build.sh` | Compile `src/` into `dist/lutin.php` |
| `./scripts/start.sh` | Start dev server at `run/public/` with lutin at `run/lutin/` |
| `./scripts/dev.sh` | Start dev server directly from `src/` (no build needed) |
| `./scripts/test.sh` | Run unit tests |
| `./scripts/lint.sh` | Syntax check all PHP files |
| `./scripts/qa.sh` | Run all quality gates |

## License

MIT
