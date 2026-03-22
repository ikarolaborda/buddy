# Buddy — Claude Code Project Instructions

## What This Is

Buddy is an evaluator-optimizer sidecar agent for engineering workflows. It is called by primary coding agents (Claude, Cursor, Copilot, etc.) when work becomes slow, ambiguous, or repeatedly unsuccessful.

Buddy exposes 8 MCP tools and 6 REST API endpoints. It runs inside Docker (PHP 8.5) and uses GPT-5.4 via laravel/ai for AI evaluation.

## Stack

- Laravel 13.x, PHP 8.5+
- laravel/ai v0.3.2 (agents, structured output, tools, embeddings, testing fakes)
- Qdrant (episodic memory, semantic search)
- SQLite (dev) / PostgreSQL (prod)
- Docker (PHP 8.5 + Qdrant on qdrant-memory_default network)

## Commands

- `composer dev` — Start dev server + queue + logs + vite
- `php artisan test` — Run test suite (30 tests, 130 assertions)
- `./vendor/bin/pint` — Format code
- `php artisan buddy:mcp-server` — Start MCP stdio server
- `php artisan migrate:fresh` — Reset database
- `docker compose build` — Build Docker image
- `docker compose up -d` — Start app + queue worker

## Code Conventions

- PSR-12, single quotes, trailing commas in multiline arrays
- Laravel 13 PHP attributes: `#[Middleware]`, `#[Tries]`, `#[Timeout]`, `#[FailOnTimeout]`
- Typed properties and return types everywhere
- Early returns, happy-path-last
- DTOs as readonly classes in `app/DTOs/`
- Enums as backed enums in `app/Enums/`
- AI agents in `app/Ai/Agents/`, tools in `app/Ai/Tools/`
- Services in `app/Services/`, MCP tools in `app/Mcp/Tools/`
- Form requests for validation, API resources for response formatting
- Laravel Pint for formatting — run before committing

## Testing

- PHPUnit 12.x with `laravel/ai` fakes
- `Agent::fake()` requires PHP **arrays** (not JSON strings) for structured output agents
- All OpenAI structured output schema fields must have `->required()` (OpenAI strict mode)
- Tests run against SQLite with no external service dependencies

## Key Architecture Decisions

- Two AI agents: `EvaluatorOptimizerAgent` (code evaluation) and `PromptRefinementAgent` (task refinement)
- Single escalation hop only — Buddy never spawns other Buddy instances
- Qdrant via Http facade (no extra client package)
- MCP server as Artisan command implementing JSON-RPC 2.0 stdio transport
- Secrets stay in `.env`, never in Claude config — mount `.env` as Docker volume

## Docker MCP Configuration

When configuring Buddy as an MCP server in Claude Code settings:

```json
"buddy": {
  "command": "docker",
  "args": [
    "run", "--rm", "-i",
    "--network", "qdrant-memory_default",
    "-e", "QDRANT_HOST=http://qdrant-memory-db",
    "-v", "/path/to/buddy/.env:/var/www/html/.env",
    "-v", "/path/to/buddy/database:/var/www/html/database",
    "buddy-app",
    "php", "artisan", "buddy:mcp-server"
  ]
}
```

## Git

- Author: configured per-repo (not global)
- `.serena/` excluded via `.git/info/exclude`
- `.agent/` excluded via `.gitignore`
- Never commit `.env` or `database/database.sqlite`
