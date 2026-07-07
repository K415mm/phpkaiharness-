# High-Level Architecture (HLA)

This document provides a comprehensive view of the `phpkaiharness` architecture — how framework modules, external LLM endpoints, middleware layers, and the host application interact to run autonomous agent loops securely.

---

## 1. System Context Diagram

```mermaid
flowchart TD
    User([End User]) <-->|Accesses Dashboard & Traces| LaravelHost[Laravel 13 Host App]
    LaravelHost <-->|Binds & Boots Service Provider| Package[phpkaiharness Package]

    subgraph Middleware Pipeline
        MW1[EnvironmentBootstrapMiddleware]
        MW2[PolicyGuardrailMiddleware]
        MW3[CompressContextMiddleware]
    end

    subgraph Package Core
        Loop[AgentLoop Runtime] <-->|Resolves Tools| Registry[ToolRegistry]
        Loop <-->|Queries LLM| LlmClient[LlmClientInterface]
        Loop --> |Dispatches Traces| Store[(SqliteMonitorStore)]
    end

    subgraph LLM Client Stack
        LlmClient --> Failover[FailoverLlmClient]
        Failover --> PII[PiiMaskingLlmClient]
        PII --> RateLimit[RateLimitedLlmClient]
        RateLimit --> Thinking[ThinkingBudgetLlmClient]
    end

    subgraph LLM Providers
        Thinking <-->|HTTP POST| QwenCloud[Qwen Cloud - DashScope]
        Thinking <-->|HTTP POST| Ollama[Ollama - Local]
        Thinking <-->|HTTP POST| LMStudio[LM Studio - Local]
        Thinking <-->|HTTP POST| OpenRouter[OpenRouter - Cloud]
        Thinking <-->|Laravel Ai Driver| LaravelAi[laravel/ai Connection]
    end

    subgraph Optimization Layer
        Loop --> SemanticCache[SemanticCache]
        Loop --> Compact[ContextCompactor]
        Loop --> Optimizer[ModelPromptOptimizer]
        Loop --> Ontology[OntologicalContextInjector]
        Loop --> GraphMem[CognitiveGraphMemory]
        Loop --> DraftVerify[DraftVerificationOrchestration]
    end

    subgraph Tool Execution
        Registry <-->|proc_open| WSL[Kali WSL Terminal]
        Registry <-->|guzzle/http| Microservices[External HTTP Microservices]
        Registry <-->|Native PHP| PHPClass[Native PHP Tools]
        Registry <-->|Webhook| AsyncTool[AsynchronousWebhookTool]
        Registry <-->|Child Loop| SubAgent[AgentDelegationTool]
    end

    Package <--> Middleware Pipeline
    Store --> Controller[HarnessConfigController]
    Controller --> |Renders Blade + AJAX JSON| LaravelHost
```

---

## 2. Middleware Pipeline

Requests to the harness pass through three HTTP middleware layers before reaching agent logic:

| Middleware | Responsibility |
|---|---|
| `EnvironmentBootstrapMiddleware` | Validates environment, loads harness config, bootstraps SQLite store |
| `PolicyGuardrailMiddleware` | Enforces scope-level access policies before tool execution |
| `CompressContextMiddleware` | Compresses large context payloads before passing them to the AgentLoop |

Each middleware respects the feature toggle in `config/harness.php` and is bypassed (no-op pass-through) when disabled. The dashboard trace viewer shows middleware nodes as **green (ACTIVE)** or **red (DEACTIVATED)** based on live config state.

---

## 3. Full Execution Flow

The following sequence tracks the lifecycle of a single prompt from entry to final response:

```mermaid
sequenceDiagram
    autonumber
    actor App as Host App / Client
    participant Router as ComplexityRouter
    participant Cache as L1/L2 Cache (Redis+SQLite)
    participant PII as PiiMaskingClient
    participant Rate as RateLimitedClient
    participant Ontology as OntologicalRAG
    participant Optimizer as ModelPromptOptimizer
    participant Loop as AgentLoop (Executor)
    participant LLM as Qwen Cloud (DashScope)
    participant Guard as PolicyGuardrails
    participant Tools as ToolRegistry
    participant Quantum as QuantumMemory
    participant Store as SqliteMonitorStore

    App->>Router: handle($prompt, $history)
    Router->>LLM: Project query state in Hilbert space (using qwen-turbo)
    LLM-->>Router: Project density matrix → collapses (Simple/Complicated/Complex)
    
    alt Simple Domain
        Router->>LLM: Direct LLM generation
        LLM-->>Router: Final Response
        Router-->>App: Direct response (bypass all pipeline features)
    else Complicated Domain
        Router->>Ontology: Inject Ontological context (RAG)
        Router->>LLM: LLM generation with context
        LLM-->>Router: Final Response
        Router-->>App: RAG response (bypass loop iteration)
    else Complex Domain
        Router->>Cache: lookup($prompt)
        Cache->>Cache: Redis L1 match + SQLite model verification + LLM verification pass
        Cache-->>Router: Hit → return cached response (exit early)
        
        Router->>PII: mask($prompt)
        PII-->>Router: sanitized prompt
        Router->>Rate: checkBucket()
        Rate-->>Router: OK or throttle exception

        loop Max Iterations
            Router->>Loop: runAgentLoop()
            Loop->>Ontology: injectContext($history)
            Ontology-->>Loop: enriched history with RAG records
            Loop->>Optimizer: rewriteSystemPrompt($model)
            Optimizer-->>Loop: model-tuned prompt
            Loop->>LLM: chat() via QwenClient
            LLM-->>Loop: text + tool_calls[]
            Loop->>Store: recordLlmTrace(tokens, duration)

            alt No Tool Calls
                Loop->>Quantum: ingestEpisodicMemory($response)
                Quantum->>Quantum: Calculate phase interference & entanglement pairing
                Loop-->>Router: Final response (exit loop)
            end

            loop For each tool_call
                Loop->>Guard: validate($toolName, $args)
                Guard-->>Loop: Allowed / Blocked
                alt Allowed
                    Loop->>Tools: execute($toolName, $args)
                    Tools-->>Loop: result string
                    Loop->>Store: recordToolTrace(duration)
                    Loop->>Loop: append tool result to history
                else Blocked
                    Loop->>Loop: append blocked status to history
                end
            end
        end
        Router-->>App: Final Response
    end
```

---

## 4. Telemetry & Analytics Pipeline

`phpkaiharness` uses a self-contained SQLite database — never writing to the host application's primary database.

```mermaid
flowchart LR
    subgraph Agent Runtime
        Loop[AgentLoop Executing] -->|Fires Events / PSR-14| Events{Agent Events}
    end

    subgraph Data Persistence
        Events -->|SqliteMonitorStore| SQLite[(harness.sqlite)]
    end

    subgraph HUD Dashboard
        SQLite -->|Session query| Controller[HarnessConfigController]
        Controller -->|AJAX JSON| TraceViewer[Animated Workflow Trace Viewer]
        Controller -->|AJAX JSON| ConfigPanel[Category Config Panel]
        TraceViewer -->|Live badges| StatusNodes[ACTIVE / DEACTIVATED Nodes]
    end
```

### PSR-14 Event Hooks

The `AgentLoop` fires the following event objects at each stage:

| Event | Trigger |
|---|---|
| `AgentStarted` | Loop begins processing a prompt |
| `LlmCallStarted` / `LlmCallFinished` | Before/after each LLM HTTP request |
| `LlmStreamChunkReceived` | Per-token during streaming mode |
| `ToolCallStarted` / `ToolCallFinished` | Before/after each tool execution |
| `AgentFinished` | Loop halts (success, error, iteration limit) |

---

## 5. Configuration Persistence & UI State

The dashboard Configuration Panel persists feature toggles to `config/harness.php` (standalone) or via `HarnessConfigController` (Laravel). Each feature node in the Execution Trace Viewer reflects the saved state:

- 🟢 **ACTIVE** — feature is enabled and running
- 🔴 **DEACTIVATED** — feature is disabled; the loop bypasses it
