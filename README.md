# Lutin.php

A self-hosted, single-file AI-powered website editor.

Drop `lutin.php` into any web public directory and get an AI assistant that helps you build and edit your website through natural conversation. Remove `lutin.php` and the data directory when you're done — your website remains 100% functional.

## Philosophy

- **Single file deployment**: One PHP file to rule them all
- **Security by design**: Sensitive data (config, backups) lives outside the web root
- **Zero dependencies**: No Composer, no build step, just PHP with curl
- **Portable**: Remove Lutin anytime, your website stays intact

## Prerequisites

- PHP 8.1+ with `curl` extension
- An API key from [Anthropic](https://www.anthropic.com) (Claude) or [OpenAI](https://openai.com) (GPT)

## Quick Start

```bash
# Copy lutin.php to your web root
cp lutin.php /var/www/html/  # or public/, www/, etc.

# Go to lutin.php in your browser
open http://localhost/lutin.php
```

On first run, you'll set up:
- Admin password
- AI provider (Anthropic/OpenAI) and API key
- Data directory location (default: `../lutin`, outside web root)

Then just chat with the AI to build your website:
- *"Create a landing page with a hero section"*
- *"Add a contact form"*
- *"Update the header on all pages"*

## Development

This repository contains the source code. The `dist/lutin.php` file is compiled from `src/`.

### Scripts

| Script | Purpose |
|--------|---------|
| `./scripts/build.sh` | Compile `src/` into `dist/lutin.php` |
| `./scripts/start.sh` | Start dev server at `run/public/` with data at `run/lutin/` |
| `./scripts/dev.sh` | Start dev server directly from `src/` (no build needed) |
| `./scripts/test.sh` | Run unit tests |
| `./scripts/lint.sh` | Syntax check all PHP files |
| `./scripts/qa.sh` | Run all quality gates |

## License

MIT
