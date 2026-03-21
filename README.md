# Buddy

An evaluator-optimizer sidecar agent for engineering workflows. Buddy helps primary coding agents when their work becomes slow, ambiguous, or repeatedly unsuccessful.

## What is Buddy?

Buddy is not a generic chatbot. It is a specialized engineering evaluator that receives structured problem packets from primary coding agents, searches its episodic memory for similar past problems, generates and evaluates solution hypotheses, and returns either a concrete recommendation or a rejection with actionable feedback.

```
Primary Agent                          Buddy
     |                                   |
     |  1. Submit problem packet  -----> |
     |                                   |  2. Search episodic memory (Qdrant)
     |                                   |  3. Build solution hypotheses
     |                                   |  4. Evaluate against constraints
     |                                   |  5. Score confidence + risks
     |  <---- 6. Return recommendation   |
     |                                   |
     |  7. Close task + store learnings  |
```

## Architecture

```
┌──────────────────────────────────────────────────────┐
│                  External Agents                     │
│           (Claude, Cursor, Copilot, etc.)            │
└──────────┬──────────────────────┬────────────────────┘
           │ REST API              │ MCP (stdio)
           ▼                       ▼
┌────────────────────┐   ┌─────────────────────┐
│   API Controllers  │   │   MCP Tool Server   │
│   (5 endpoints)    │   │   (7 tools)         │
└────────┬───────────┘   └────────┬────────────┘
         │                         │
         ▼                         ▼
┌────────────────────────────────────────────┐
│          Application Services              │
│  EvaluatorOptimizerService                 │
│  EscalationService                         │
│  QdrantMemoryService                       │
└────────┬──────────────────┬────────────────┘
         │                  │
         ▼                  ▼
┌──────────────┐   ┌───────────────────┐
│   Database   │   │  External APIs    │
│   (SQLite)   │   │  OpenAI (GPT-5.4) │
│              │   │  Qdrant (vectors) │
└──────────────┘   └───────────────────┘
```

## Tech Stack

| Component       | Technology                                                   |
|-----------------|--------------------------------------------------------------|
| Framework       | Laravel 13.x                                                 |
| PHP             | 8.5+                                                         |
| AI SDK          | [laravel/ai](https://laravel.com/ai) v0.3.2                 |
| Evaluator Model | GPT-5.4 (configurable)                                       |
| Vector DB       | Qdrant (episodic memory + semantic search)                   |
| Database        | SQLite (dev) / PostgreSQL (production)                       |
| Queue           | Laravel database queue (dev) / Redis (production)            |
| MCP Transport   | stdio (JSON-RPC 2.0)                                         |
| Testing         | PHPUnit 12.x with Laravel AI SDK fakes                       |
| Formatting      | Laravel Pint                                                 |

## Quick Start

### Prerequisites

- PHP 8.5+
- Composer
- An OpenAI API key
- Qdrant running locally (or via Docker)

### Local Setup

```bash
# Clone and install
git clone <repo-url> buddy
cd buddy
composer install

# Configure environment
cp .env.example .env
php artisan key:generate

# Set your API keys in .env
# OPENAI_API_KEY=sk-...

# Create database and run migrations
touch database/database.sqlite
php artisan migrate

# Start development server
composer dev
```

### Docker Setup

```bash
docker compose up -d
```

This starts three services:
- **app** — Laravel HTTP server on port 8000
- **queue** — Background queue worker for async evaluations
- **qdrant** — Qdrant vector database on port 6333

## Configuration

All Buddy-specific configuration lives in `config/buddy.php` and `.env`:

| Variable                    | Default                   | Description                          |
|-----------------------------|---------------------------|--------------------------------------|
| `BUDDY_MODEL`              | `gpt-5.4`                | AI model for evaluation              |
| `BUDDY_EMBEDDING_MODEL`    | `text-embedding-3-small`  | Model for vector embeddings          |
| `BUDDY_MAX_EVALUATION_STEPS` | `10`                   | Max tool-use steps per evaluation    |
| `BUDDY_EVALUATION_TIMEOUT` | `120`                     | Timeout in seconds                   |
| `QDRANT_HOST`              | `http://localhost`        | Qdrant server host                   |
| `QDRANT_PORT`              | `6333`                    | Qdrant server port                   |
| `QDRANT_API_KEY`           | (empty)                   | Qdrant API key (optional)            |
| `QDRANT_COLLECTION`        | `buddy_episodes`          | Qdrant collection for episodes       |
| `QDRANT_KNOWLEDGE_COLLECTION` | `buddy_knowledge`      | Qdrant collection for distilled knowledge |
| `QDRANT_VECTOR_SIZE`       | `1536`                    | Embedding vector dimensions          |

AI provider keys are configured via Laravel AI SDK in `config/ai.php`:

| Variable           | Description            |
|--------------------|------------------------|
| `OPENAI_API_KEY`   | OpenAI API key         |

## REST API

All endpoints are prefixed with `/api/buddy`.

### Create Task

```
POST /api/buddy/tasks
```

Create a new evaluation task from a structured problem packet.

**Request:**
```json
{
  "source_agent": "claude",
  "task_summary": "Login page returns 500 after OAuth callback",
  "problem_type": "bug",
  "repo": "acme/webapp",
  "branch": "feature/oauth-fix",
  "constraints": ["preserve backward compatibility"],
  "evidence": [
    {"type": "error_log", "content": "NullPointerException at AuthController:42"}
  ],
  "artifacts": [
    {"type": "stacktrace", "content": "..."}
  ],
  "requested_outcome": "Fix the 500 error on OAuth callback"
}
```

**Response (201):**
```json
{
  "data": {
    "task_id": "01JDZK...",
    "source_agent": "claude",
    "task_summary": "Login page returns 500 after OAuth callback",
    "problem_type": "bug",
    "status": "pending",
    "attempt_count": 0,
    "created_at": "2026-03-21T20:00:00.000Z"
  }
}
```

### Get Task

```
GET /api/buddy/tasks/{ulid}
```

Returns the task with its latest recommendation (if evaluated).

### Attach Artifact

```
POST /api/buddy/tasks/{ulid}/artifacts
```

Attach evidence to an existing task before evaluation.

**Request:**
```json
{
  "type": "log",
  "content": "ERROR 2026-03-21 Auth failed at line 42",
  "metadata": {"file": "auth.log"}
}
```

Artifact types: `log`, `test_output`, `stacktrace`, `code_snippet`, `diff`, `config`, `screenshot`, `other`.

### Evaluate Task

```
POST /api/buddy/tasks/{ulid}/evaluate
POST /api/buddy/tasks/{ulid}/evaluate?async=1
```

Run the evaluator-optimizer agent on the task. Without `?async=1`, this runs synchronously and returns the recommendation directly. With `?async=1`, the evaluation is dispatched to a queue worker and returns immediately with status `202`.

**Synchronous Response (200):**
```json
{
  "task": { "task_id": "01JDZK...", "status": "completed", "..." : "..." },
  "evaluation": {
    "accepted": true,
    "confidence": "high",
    "summary": "The OAuth callback needs a null check on the user object.",
    "recommended_plan": [
      "Add null check at AuthController:42",
      "Add test coverage for null user case"
    ],
    "rejected_reasons": [],
    "required_followups": [],
    "risks": ["Minimal - isolated change"],
    "next_actions": ["Apply the fix", "Run test suite"],
    "memory_hits": ["Similar OAuth issue resolved 2 weeks ago"]
  }
}
```

### Close Task

```
POST /api/buddy/tasks/{ulid}/close
```

Close a completed task and optionally store durable learnings in Qdrant.

**Request:**
```json
{
  "learnings_summary": "OAuth callback must validate user object before redirect."
}
```

## MCP Server

Buddy exposes itself as an MCP server so external coding agents can use it as a tool.

### Starting the MCP Server

```bash
php artisan buddy:mcp-server
```

This starts a stdio-based JSON-RPC 2.0 server that reads from stdin and writes to stdout.

### Connecting from Claude Code

Add to your MCP configuration:

```json
{
  "mcpServers": {
    "buddy": {
      "command": "php",
      "args": ["artisan", "buddy:mcp-server"],
      "cwd": "/path/to/buddy"
    }
  }
}
```

### Available MCP Tools

| Tool                      | Description                                          |
|---------------------------|------------------------------------------------------|
| `buddy.submit_problem`    | Submit a structured problem packet for evaluation    |
| `buddy.get_task_status`   | Get the current status of a task                     |
| `buddy.get_recommendation`| Trigger evaluation and get the recommendation        |
| `buddy.search_memory`     | Search episodic memory for past problems/patterns    |
| `buddy.store_memory`      | Store distilled engineering knowledge                |
| `buddy.attach_artifact`   | Attach evidence to an existing task                  |
| `buddy.close_task`        | Close a task and optionally store learnings          |

## Escalation Trigger Policy

Buddy is designed to be called when a primary agent's work degrades. The recommended trigger policy escalates to Buddy when **at least two** of these conditions are true:

| Condition                  | Threshold         |
|----------------------------|-------------------|
| Time elapsed               | > 5 minutes       |
| Failed attempts            | > 2               |
| Repeated test failure      | persists          |
| Confidence                 | low               |
| Root cause                 | ambiguous         |
| Evidence                   | conflicts         |

The `EscalationTrigger` DTO and `EscalationService` implement this policy. See the agent-facing protocol document (`AGENT.md`) for integration details.

## Memory System

Buddy uses Qdrant for long-term episodic memory:

- **Storage**: Every closed task can store distilled learnings as vectors
- **Retrieval**: On every evaluation, Buddy searches memory for similar past episodes
- **Metadata Filtering**: Memories are tagged by `task_intent`, `stack`, `subsystem`, `symptom`, `root_cause`, `solution_pattern`, and `outcome`
- **Embeddings**: Generated via OpenAI's `text-embedding-3-small` model through `laravel/ai`

Only distilled, reusable knowledge is stored. Raw transcripts are not persisted unless distilled first.

### Initializing Qdrant Collections

```bash
# Ensure Qdrant is running, then use tinker to create collections:
php artisan tinker
>>> app(\App\Services\QdrantMemoryService::class)->ensureCollectionExists()
```

## Testing

```bash
# Run all tests
php artisan test

# Run specific test suites
php artisan test --filter=BuddyTaskApiTest
php artisan test --filter=EscalationTriggerTest
```

Tests use `laravel/ai`'s built-in `Agent::fake()` to mock AI interactions. The test suite runs entirely against SQLite with no external service dependencies.

## Project Structure

```
app/
├── Ai/
│   ├── Agents/
│   │   └── EvaluatorOptimizerAgent.php    # Core AI agent
│   └── Tools/
│       └── SearchMemoryTool.php            # Agent tool for memory search
├── Console/Commands/
│   └── McpServerCommand.php                # MCP stdio server
├── DTOs/
│   ├── ProblemPacket.php                   # Input contract
│   ├── EvaluationResult.php                # Output contract
│   ├── EscalationTrigger.php               # Escalation policy
│   └── MemorySearchResult.php              # Qdrant result wrapper
├── Enums/
│   ├── TaskStatus.php                      # pending/evaluating/completed/failed/closed
│   ├── ProblemType.php                     # bug/test_failure/performance/...
│   ├── RunStatus.php                       # started/completed/failed
│   ├── Confidence.php                      # high/medium/low/none
│   └── ArtifactType.php                    # log/test_output/stacktrace/...
├── Http/
│   ├── Controllers/Api/Buddy/
│   │   └── BuddyTaskController.php         # REST API (5 actions)
│   ├── Requests/Buddy/                     # Form request validation
│   └── Resources/Buddy/                    # API response formatting
├── Jobs/
│   └── EvaluateTaskJob.php                 # Async evaluation queue job
├── Mcp/
│   ├── McpTool.php                         # Tool interface
│   ├── BaseMcpTool.php                     # Base class
│   ├── McpToolRegistry.php                 # Tool registration
│   └── Tools/                              # 7 MCP tool implementations
├── Models/
│   ├── BuddyTask.php                       # Primary entity
│   ├── BuddyRun.php                        # Evaluation run
│   ├── BuddyArtifact.php                   # Evidence attachment
│   ├── BuddyRecommendation.php             # AI recommendation
│   ├── BuddyQuestion.php                   # Clarifying question
│   ├── BuddyMemoryReference.php            # Qdrant memory link
│   └── BuddyDecisionLog.php                # Audit trail
└── Services/
    ├── EvaluatorOptimizerService.php        # Orchestration
    ├── QdrantMemoryService.php              # Qdrant HTTP client
    └── EscalationService.php                # Trigger evaluation
```

## Design Decisions

- **Single escalation hop**: Buddy never spawns other Buddy instances. This prevents unbounded agent loops.
- **Qdrant via HTTP facade**: No extra PHP client package. The Qdrant REST API is simple enough that `Http::baseUrl()` is sufficient.
- **Laravel 13 PHP attributes**: Controller middleware (`#[Middleware]`) and queue job configuration (`#[Tries]`, `#[Timeout]`, `#[FailOnTimeout]`) are declared via attributes.
- **laravel/ai structured output**: The `HasStructuredOutput` interface with `JsonSchema` builder guarantees the AI returns data matching the `EvaluationResult` shape.
- **Auditable AI**: Every evaluation creates a `BuddyRun` (with model and token usage), a `BuddyRecommendation`, and a `BuddyDecisionLog`.

## License

MIT
