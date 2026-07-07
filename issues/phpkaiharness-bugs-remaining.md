# phpkaiharness — Remaining Bugs (Follow-up #3)

**Date:** 2026-06-21
**Package version:** dev-main @ `ba3d5b6`
**Previous reports:** Bug Report #1 (resolved), Bug Report #2 (`phpkaiharness-bugs-report-2.md` — **none fixed**)
**Environment:** PHP 8.5.5, Windows, Laravel 13.16.1, LM Studio (qwen/qwen3.5-9b)

---

None of the 9 bugs from Report #2 were addressed in `ba3d5b6`. The 3 Critical bugs make the harness **completely non-functional** with OpenAI-compatible APIs (LM Studio, Ollama, OpenRouter). We are re-patching the vendor files locally after every `composer update`, which is unsustainable.

Please fix at minimum the 3 Critical bugs before the next release.

---

## CRITICAL (blocking — harness crashes on every LLM call)

### 1. `tool_calls: null` sent in every message

**Files:** `src/Llm/LmStudioClient.php:60`, `src/Llm/OllamaClient.php:62`, `src/Llm/OpenRouterClient.php:70`

All three LLM clients send `"tool_calls": null` in every message even when there are no tool calls. OpenAI-compatible APIs reject this with `400 Bad Request: Invalid 'messages' in payload`.

**Current code (all 3 files):**
```php
foreach ($messages as $msg) {
    $compiledMessages[] = [
        'role' => $msg['role'],
        'content' => $msg['content'] ?? '',
        'tool_calls' => $msg['tool_calls'] ?? null,  // ← BUG
    ];
}
```

**Fix:**
```php
foreach ($messages as $msg) {
    $compiledMsg = [
        'role' => $msg['role'],
        'content' => $msg['content'] ?? '',
    ];
    if (isset($msg['tool_calls']) && $msg['tool_calls'] !== null) {
        $compiledMsg['tool_calls'] = $msg['tool_calls'];
    }
    $compiledMessages[] = $compiledMsg;
}
```

---

### 2. Non-standard `tool_calls` format in assistant messages

**Files:** `src/Llm/LmStudioClient.php`, `src/Llm/OllamaClient.php`, `src/Llm/OpenRouterClient.php`

When the agent loop sends assistant messages with tool calls back to the LLM (iteration 2+), the harness internal format is passed through as-is. The OpenAI API expects a different structure.

**Harness internal format (what's being sent):**
```json
{"id": "abc", "name": "list_campaigns", "arguments": []}
```

**OpenAI API format (what's expected):**
```json
{"id": "abc", "type": "function", "function": {"name": "list_campaigns", "arguments": "[]"}}
```

Three differences:
1. Missing `"type": "function"` field
2. `name` and `arguments` must be nested under `"function"`
3. `arguments` must be a **JSON string**, not an array

Additionally, `tool` role messages are missing the required `tool_call_id` field (the LLM clients strip it — they only copy `role`, `content`, `tool_calls`).

**Fix (apply to all 3 LLM clients):**
```php
foreach ($messages as $msg) {
    $compiledMsg = [
        'role' => $msg['role'],
        'content' => $msg['content'] ?? '',
    ];
    if (isset($msg['tool_calls']) && $msg['tool_calls'] !== null) {
        $formattedToolCalls = [];
        foreach ($msg['tool_calls'] as $tc) {
            $args = $tc['arguments'] ?? [];
            if (is_array($args)) {
                $args = json_encode($args, JSON_UNESCAPED_UNICODE);
            }
            $formattedToolCalls[] = [
                'id' => $tc['id'] ?? uniqid('call_'),
                'type' => 'function',
                'function' => [
                    'name' => $tc['name'] ?? '',
                    'arguments' => $args,
                ],
            ];
        }
        $compiledMsg['tool_calls'] = $formattedToolCalls;
    }
    if (isset($msg['tool_call_id'])) {
        $compiledMsg['tool_call_id'] = $msg['tool_call_id'];
    }
    $compiledMessages[] = $compiledMsg;
}
```

---

### 3. `recordEvent()` called before `startSession()` — FK constraint failure

**File:** `src/Core/AgentLoop.php` — `recordEvent` at line 412, `startSession` at line 543

The optimizer event is recorded **before** the session row is created. The `harness_details` table has a foreign key `session_id → harness_sessions.id`, so this throws:
```
SQLSTATE[23000]: Integrity constraint violation: 19 FOREIGN KEY constraint failed
```

This crashes the entire harness run. Host apps that catch the exception silently will see no telemetry — the harness appears broken with no error message.

**Fix (option A — move startSession before optimizer):**
Move `$collector->startSession()` to before the optimizer block (~line 408).

**Fix (option B — make recordEvent resilient):**
In `SqliteMonitorStore::recordEvent()`, auto-create the session if missing:
```php
$stmt = $this->pdo->prepare(
    "INSERT OR IGNORE INTO harness_sessions (id, prompt, method, settings, created_at, updated_at)
     VALUES (:sid, '', 'auto', '{}', datetime('now'), datetime('now'))"
);
$stmt->execute([':sid' => $sessionId]);
```

---

## HIGH

### 4. `config_overrides.json` persists across test runs — disables telemetry

**File:** `src/PhpkaiharnessServiceProvider.php:35`

The override file is loaded on every request including tests. If a test calls `POST /harness/config`, it writes `telemetry.enabled: false` to the override file, which persists after the test suite and breaks the real app.

**Fix:** Skip loading overrides in testing env:
```php
if (File::exists($overridePath) && !app()->environment('testing')) {
```

---

### 5. Test-only imports in production code

**File:** `src/Llm/LaravelAiClient.php:14,17`

```php
use Mockery\MockInterface;           // dev dependency, not in production
use PHPUnit\Framework\MockObject\MockObject;  // dev dependency, not in production
```

Used at line 65-67 to detect mocks. Will cause issues in production where these packages aren't installed.

**Fix:** Remove `use` statements, use string-based class checks:
```php
$isMockedTest = (interface_exists('Mockery\MockInterface') && $manager instanceof \Mockery\MockInterface)
    || (class_exists('PHPUnit\Framework\MockObject\MockObject') && $manager instanceof \PHPUnit\Framework\MockObject\MockObject);
```

---

### 6. Double-wrapping of LLM clients

**File:** `src/Core/AgentLoop.php:764-813` (`decorateLlmClient`)

`AgentLoop::decorateLlmClient()` automatically wraps the client with failover, rate limiting, PII masking, and budget gating. When the host app **also** wraps the client (the natural integration pattern), everything gets double-wrapped: rate limiting applied twice, PII masking double-escaped, 3+ failover clients where 2 suffice.

**Fix:** Add a config flag to disable auto-decoration:
```php
if (config('harness.auto_decorate', true)) {
    // ... existing decoration logic
}
```

---

## MEDIUM

### 7. Silent `catch (\Throwable)` blocks swallow errors

**File:** `src/Core/AgentLoop.php` — 13 catch blocks, most with empty bodies or no logging

Example (line 251):
```php
} catch (\Throwable) {
}
```

When the optimizer, compactor, or cache fails, the error is silently lost. The agent continues with degraded behavior and the user can't debug.

**Fix:** Log errors:
```php
} catch (\Throwable $e) {
    $this->logger?->warning('Operation failed: ' . $e->getMessage());
}
```

---

### 8. `FailoverLlmClient` records events before session exists

**File:** `src/Llm/FailoverLlmClient.php:62`

Same FK issue as bug #3. When the first client fails, `recordEvent` is called before `startSession`, throwing an FK violation that masks the original error.

**Fix:** Same as bug #3.

---

## LOW

### 9. Config key inconsistency: `rate_limiting` vs `rate_limits`

**Files:** `config/harness.php` uses `rate_limits`, `src/Core/AgentLoop.php:772-777` checks both keys with fallback

The config key was renamed but backward-compat fallbacks remain, creating confusion.

**Fix:** Pick one name and use it everywhere. Remove dual-key fallbacks.

---

## Priority

| Priority | Bugs | Impact |
|----------|------|--------|
| **Fix immediately** | #1, #2, #3 | Harness is completely non-functional without these |
| **Fix next release** | #4, #5, #6 | Breaks tests, production safety, integration patterns |
| **Fix when convenient** | #7, #8, #9 | Debugging difficulty, minor inconsistencies |
