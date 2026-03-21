# Buddy Agent Protocol

This document is intended for LLMs and coding agents that interact with Buddy as a tool. It defines when to escalate, how to submit problems, how to interpret responses, and what to avoid.

Buddy is available via two transports: **MCP** (stdio JSON-RPC) and **REST API** (HTTP JSON). Both expose the same capabilities. Use whichever transport your runtime supports.

---

## 1. Purpose

Buddy is an evaluator-optimizer sidecar. You call Buddy when:
- Your work is taking too long
- You have failed multiple times
- You are uncertain about root cause
- Your evidence conflicts
- Tests keep failing despite attempts

Buddy receives your problem, searches its episodic memory for similar past episodes, evaluates solution hypotheses, and returns a structured recommendation. Buddy prefers concrete plans over clarifying questions.

Buddy does NOT:
- Execute code
- Modify files
- Run tests
- Make commits
- Spawn other agents

Buddy evaluates and recommends. You execute.

---

## 2. Escalation Policy

Escalate to Buddy when **at least two** of these conditions are true:

| # | Condition                | Test                          |
|---|--------------------------|-------------------------------|
| 1 | Time elapsed             | > 300 seconds (5 minutes)     |
| 2 | Failed attempts          | > 2                           |
| 3 | Repeated test failure    | Same test(s) failing again    |
| 4 | Low confidence           | You are not confident in your approach |
| 5 | Ambiguous root cause     | Cannot identify a single root cause |
| 6 | Conflicting evidence     | Logs/tests/code disagree      |

**Decision logic:**
```
active_conditions = count(conditions that are true)
should_escalate = active_conditions >= 2
```

Do NOT escalate for:
- Simple, well-understood tasks
- Tasks where you have high confidence
- Tasks you have not yet attempted

---

## 3. Interaction Protocol

Follow these steps in order:

### Step 1: Submit Problem

Call `buddy.submit_problem` (MCP) or `POST /api/buddy/tasks` (REST).

Provide as much structured information as possible. The more evidence and context you include, the better Buddy's evaluation will be.

### Step 2: Attach Additional Evidence (optional)

If you have logs, test output, stack traces, or code snippets that did not fit in the initial submission, attach them:

Call `buddy.attach_artifact` (MCP) or `POST /api/buddy/tasks/{id}/artifacts` (REST).

You can attach multiple artifacts. Do this BEFORE requesting evaluation.

### Step 3: Request Evaluation

Call `buddy.get_recommendation` (MCP) or `POST /api/buddy/tasks/{id}/evaluate` (REST).

This triggers Buddy's evaluator-optimizer agent. Buddy will:
1. Search episodic memory for similar past problems
2. Build solution hypotheses
3. Evaluate each against evidence, constraints, blast radius, and confidence
4. Return a structured recommendation

### Step 4: Interpret Response

The response has this structure:

```json
{
  "accepted": true,
  "confidence": "high",
  "summary": "...",
  "recommended_plan": ["Step 1", "Step 2", "..."],
  "rejected_reasons": [],
  "required_followups": [],
  "risks": ["..."],
  "next_actions": ["..."],
  "memory_hits": ["..."]
}
```

**If `accepted` is `true`:**
- Follow `recommended_plan` in order
- Consider `risks` before executing
- Execute `next_actions`

**If `accepted` is `false`:**
- Read `rejected_reasons` to understand why
- Gather `required_followups` (specific evidence Buddy needs)
- Submit the additional evidence and re-evaluate

### Step 5: Close Task

After completing work (whether you followed Buddy's recommendation or not), close the task:

Call `buddy.close_task` (MCP) or `POST /api/buddy/tasks/{id}/close` (REST).

Include a `learnings_summary` so Buddy stores durable knowledge for future episodes:

```json
{
  "task_id": "01JDZK...",
  "learnings_summary": "OAuth callback must validate user object before redirect. Null user occurs when the provider returns an error response that is not caught by the exception handler."
}
```

---

## 4. Input Contract

When submitting a problem, provide these fields:

| Field              | Type       | Required | Description                              |
|--------------------|------------|----------|------------------------------------------|
| `source_agent`     | string     | yes      | Your identifier (e.g., `"claude"`, `"cursor"`) |
| `task_summary`     | string     | yes      | Natural language description of the problem |
| `problem_type`     | string     | yes      | One of the values below                  |
| `repo`             | string     | no       | Repository identifier (e.g., `"org/project"`) |
| `branch`           | string     | no       | Branch name                              |
| `constraints`      | string[]   | no       | Constraints on the solution              |
| `evidence`         | object[]   | no       | Structured evidence objects              |
| `artifacts`        | object[]   | no       | Inline artifacts (logs, traces, etc.)    |
| `requested_outcome`| string     | no       | What you want Buddy to help achieve      |

### Problem Types

Use the most specific type that applies:

| Value            | When to use                                       |
|------------------|---------------------------------------------------|
| `bug`            | Something is broken that was working before        |
| `test_failure`   | Tests are failing                                  |
| `performance`    | Slowness, timeouts, resource exhaustion            |
| `architecture`   | Design uncertainty, structural decisions           |
| `integration`    | Issues at system boundaries (APIs, services)       |
| `configuration`  | Environment, config, deployment issues             |
| `security`       | Security-related concerns                          |
| `ambiguous`      | You cannot categorize the problem                  |
| `other`          | None of the above                                  |

### Artifact Types

When attaching evidence, use these types:

| Value          | Description                |
|----------------|----------------------------|
| `log`          | Application or system logs |
| `test_output`  | Test runner output         |
| `stacktrace`   | Exception stack trace      |
| `code_snippet` | Relevant code fragment     |
| `diff`         | Code diff or patch         |
| `config`       | Configuration file content |
| `screenshot`   | Visual evidence            |
| `other`        | Anything else              |

---

## 5. Output Contract

Buddy's evaluation response always has this shape:

| Field                | Type     | Description                                     |
|----------------------|----------|-------------------------------------------------|
| `accepted`           | boolean  | `true` = recommendation provided; `false` = needs more evidence |
| `confidence`         | string   | `"high"`, `"medium"`, `"low"`, or `"none"`      |
| `summary`            | string   | Concise explanation of the evaluation            |
| `recommended_plan`   | string[] | Ordered steps to implement the solution (empty if rejected) |
| `rejected_reasons`   | string[] | Why the problem cannot be resolved yet (empty if accepted) |
| `required_followups` | string[] | What evidence or information is needed next      |
| `risks`              | string[] | Potential risks or side effects                  |
| `next_actions`       | string[] | Immediate next actions for you                   |
| `memory_hits`        | string[] | Summaries of relevant past episodes found        |

---

## 6. MCP Tools Reference

Buddy exposes 7 tools via MCP. Connect using stdio transport:

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

### buddy.submit_problem

Submit a structured problem packet. Returns a task ID.

**Input:**
```json
{
  "source_agent": "claude",
  "task_summary": "Login returns 500 after OAuth callback",
  "problem_type": "bug",
  "repo": "acme/webapp",
  "branch": "feature/oauth-fix",
  "constraints": ["preserve backward compatibility"],
  "evidence": [{"type": "log", "content": "NullPointerException at line 42"}],
  "requested_outcome": "Fix the 500 error"
}
```
Required: `source_agent`, `task_summary`, `problem_type`

**Output:**
```json
{
  "task_id": "01JDZK...",
  "status": "pending",
  "message": "Problem submitted. Use buddy.get_task_status to check or buddy.get_recommendation after evaluation."
}
```

### buddy.get_task_status

Check the current state of a task.

**Input:**
```json
{
  "task_id": "01JDZK..."
}
```
Required: `task_id`

**Output:**
```json
{
  "task_id": "01JDZK...",
  "status": "completed",
  "problem_type": "bug",
  "runs_count": 1,
  "has_recommendation": true,
  "created_at": "2026-03-21T20:00:00.000Z"
}
```

Task statuses: `pending`, `evaluating`, `completed`, `failed`, `closed`

### buddy.get_recommendation

Trigger evaluation (or retrieve existing recommendation) for a task.

**Input:**
```json
{
  "task_id": "01JDZK..."
}
```
Required: `task_id`

**Output:** Returns the full evaluation result (see Output Contract above).

### buddy.search_memory

Search Buddy's episodic memory without creating a task.

**Input:**
```json
{
  "query": "OAuth callback null user error after provider redirect",
  "limit": 5,
  "tags": ["bug", "oauth"]
}
```
Required: `query`

**Output:**
```json
[
  {
    "score": 0.892,
    "summary": "OAuth callback must validate user object before redirect...",
    "tags": ["bug", "oauth", "authentication"]
  }
]
```

### buddy.store_memory

Store distilled engineering knowledge for future retrieval.

**Input:**
```json
{
  "summary": "OAuth callback must validate user object before redirect. Null user occurs when provider returns error.",
  "tags": ["oauth", "authentication", "null-check"],
  "task_intent": "bugfix",
  "stack": "laravel",
  "subsystem": "auth",
  "symptom": "500 on OAuth callback",
  "root_cause": "Missing null check on user object",
  "solution_pattern": "Guard clause before redirect",
  "outcome": "resolved"
}
```
Required: `summary`

**Output:**
```json
{
  "stored": true,
  "point_id": "a2615296-..."
}
```

### buddy.attach_artifact

Attach evidence to an existing task.

**Input:**
```json
{
  "task_id": "01JDZK...",
  "artifact_type": "stacktrace",
  "content": "Exception: NullPointerException at AuthController.php:42...",
  "metadata": {"file": "AuthController.php", "line": 42}
}
```
Required: `task_id`, `artifact_type`, `content`

Artifact types: `log`, `test_output`, `stacktrace`, `code_snippet`, `diff`, `config`, `screenshot`, `other`

**Output:**
```json
{
  "attached": true,
  "artifact_id": 7
}
```

### buddy.close_task

Close a task and optionally store learnings.

**Input:**
```json
{
  "task_id": "01JDZK...",
  "learnings_summary": "OAuth callback must validate user object before redirect."
}
```
Required: `task_id`

**Output:**
```json
{
  "closed": true,
  "task_id": "01JDZK..."
}
```

---

## 7. REST API Reference

Base URL: `http://localhost:8000/api/buddy`

| Method | Path                          | Description                    |
|--------|-------------------------------|--------------------------------|
| POST   | `/tasks`                      | Create task                    |
| GET    | `/tasks/{ulid}`               | Get task + recommendation      |
| POST   | `/tasks/{ulid}/artifacts`     | Attach artifact                |
| POST   | `/tasks/{ulid}/evaluate`      | Run evaluation (sync)          |
| POST   | `/tasks/{ulid}/evaluate?async=1` | Run evaluation (async, 202) |
| POST   | `/tasks/{ulid}/close`         | Close task + store learnings   |

The `{ulid}` parameter is the ULID returned when creating a task (`task_id` field).

---

## 8. Error Handling

| HTTP Status | Meaning                                    | Action                         |
|-------------|--------------------------------------------|--------------------------------|
| 201         | Created successfully                       | Proceed with task_id           |
| 200         | Success                                    | Parse response                 |
| 202         | Accepted (async)                           | Poll GET /tasks/{id} for status |
| 404         | Task not found                             | Check task_id is correct       |
| 422         | Validation error or invalid state          | Read error message, fix input  |
| 500         | Evaluation failed                          | Retry or attach more evidence  |

MCP errors follow JSON-RPC 2.0 conventions:
- `-32700`: Parse error (malformed JSON)
- `-32601`: Method not found
- `-32602`: Invalid params (unknown tool)
- Tool execution errors return `isError: true` in the result

---

## 9. Anti-Patterns

Do NOT do any of the following:

1. **Do not call Buddy recursively.** Buddy must not be used to spawn another Buddy instance. Single escalation hop only.

2. **Do not poll in a tight loop.** When using async evaluation, poll `buddy.get_task_status` at reasonable intervals (5-10 seconds), not continuously.

3. **Do not skip closing tasks.** Always close tasks after work is complete. This is how Buddy stores durable learnings.

4. **Do not submit without evidence.** The more evidence you provide (logs, test output, stack traces, code), the better the evaluation. A bare `task_summary` with no evidence produces low-confidence results.

5. **Do not ignore rejected recommendations.** If `accepted` is `false`, Buddy is telling you it needs specific information listed in `required_followups`. Gather that information and re-evaluate.

6. **Do not re-evaluate completed tasks.** Once a task has a recommendation, `buddy.get_recommendation` returns the existing one. Create a new task for a new problem.

7. **Do not treat Buddy as a chatbot.** Buddy is not conversational. It receives structured input and returns structured output. Do not send freeform questions as `task_summary`.

8. **Do not escalate prematurely.** Follow the escalation policy (Section 2). Buddy adds value when you have genuinely hit a wall, not on every task.

---

## 10. Complete Interaction Example

Here is a full interaction flow using MCP tools:

### 1. Check escalation conditions
```
elapsed_seconds: 420    (> 300 -> TRUE)
failed_attempts: 3      (> 2   -> TRUE)
repeated_test_failure: true
conditions_met: 3 >= 2 -> ESCALATE
```

### 2. Submit problem
```json
-> buddy.submit_problem({
  "source_agent": "claude",
  "task_summary": "POST /api/users returns 422 with valid payload. Error: 'The email field is required.' but email is present in request body. Started after merging PR #247.",
  "problem_type": "bug",
  "repo": "acme/api",
  "branch": "main",
  "constraints": ["no schema changes", "preserve backward compatibility"],
  "evidence": [
    {"type": "request", "content": "{\"name\": \"John\", \"email\": \"john@example.com\"}"},
    {"type": "response", "content": "{\"errors\": {\"email\": [\"The email field is required.\"]}}"},
    {"type": "pr", "content": "PR #247 refactored UserRequest validation"}
  ],
  "requested_outcome": "Identify why email validation fails and provide a fix"
})

<- {"task_id": "01JDZK4X...", "status": "pending", ...}
```

### 3. Attach additional evidence
```json
-> buddy.attach_artifact({
  "task_id": "01JDZK4X...",
  "artifact_type": "code_snippet",
  "content": "class StoreUserRequest extends FormRequest\n{\n    public function rules(): array\n    {\n        return [\n            'email_address' => ['required', 'email'],\n            'name' => ['required', 'string'],\n        ];\n    }\n}"
})

<- {"attached": true, "artifact_id": 1}
```

### 4. Request evaluation
```json
-> buddy.get_recommendation({"task_id": "01JDZK4X..."})

<- {
  "accepted": true,
  "confidence": "high",
  "summary": "PR #247 renamed the validation rule from 'email' to 'email_address' but the request payload still sends 'email'. The validation rule expects 'email_address' which is not present in the request.",
  "recommended_plan": [
    "Option A: Rename the validation rule back to 'email' in StoreUserRequest",
    "Option B: Update all API consumers to send 'email_address' instead of 'email'",
    "Recommendation: Option A is safer (backward compatible). Change 'email_address' to 'email' in StoreUserRequest::rules()"
  ],
  "rejected_reasons": [],
  "required_followups": [],
  "risks": [
    "If other endpoints already adopted 'email_address', this creates inconsistency",
    "Check if the migration also renamed the column"
  ],
  "next_actions": [
    "Change 'email_address' to 'email' in StoreUserRequest::rules()",
    "Run the test suite",
    "Verify POST /api/users with the original payload"
  ],
  "memory_hits": [
    "Similar issue 2 weeks ago: validation rule rename broke API consumers in billing module"
  ]
}
```

### 5. Execute the fix (you do this, not Buddy)

### 6. Close task with learnings
```json
-> buddy.close_task({
  "task_id": "01JDZK4X...",
  "learnings_summary": "PR #247 renamed 'email' to 'email_address' in validation rules but did not update API consumers. Validation rule renames are breaking changes for API consumers. Always check request payload field names match validation rule keys after refactoring FormRequest classes."
})

<- {"closed": true, "task_id": "01JDZK4X..."}
```

---

## 11. Memory Tags Reference

When storing memories via `buddy.store_memory`, use these tag categories for optimal retrieval:

| Category           | Example values                                      |
|--------------------|-----------------------------------------------------|
| `task_intent`      | `bugfix`, `feature`, `refactor`, `testing`, `infra`  |
| `stack`            | `laravel`, `react`, `python`, `go`, `node`           |
| `subsystem`        | `auth`, `billing`, `api`, `queue`, `database`        |
| `symptom`          | `500-error`, `timeout`, `validation-failure`          |
| `root_cause`       | `null-check`, `race-condition`, `config-mismatch`     |
| `solution_pattern` | `guard-clause`, `retry-logic`, `schema-migration`     |
| `outcome`          | `resolved`, `partial`, `failed`, `workaround`         |
