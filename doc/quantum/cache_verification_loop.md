# QFT-Inspired Cache Verification Loop Specification

## 1. Concept & Rationale
A major failure mode in semantic caching is returning cached data for an entity that no longer exists or returning details of a different entity that matches the same prompt structure (e.g. returning sizing for client ID `3` when the user asks for client ID `78455`).

The **QFT-Inspired Cache Verification Loop** acts as an ontological filter. It extracts entity signatures, runs database existence validation checks, and performs a fast LLM verification pass before returning a cache hit.

---

## 2. Verification Architecture

The verification pipeline operates as follows:

```
[Cache Candidate Match]
          │
          ▼
[Extract Entity Signatures]
          │
          ▼
[Database Existence Validation] ──(Not Found)──> [Force Cache Miss & Evict]
          │
      (Exists)
          ▼
[LLM Semantic Verification]     ──(Fails)─────> [Force Cache Miss & Evict]
          │
      (Passes)
          ▼
[Return Cache Hit to Loop]
```

---

## 3. Pipeline Stages

### A. Extract Entity Signatures
The cache parser scans the prompt for numeric parameters and UUID patterns:
* Numeric client IDs (e.g. `client_id=123`, `client 456`).
* Resource codes (e.g., scenario templates, asset configurations).

### B. Database Existence Validation
The harness maps extracted IDs to host application Eloquent models (e.g., `App\Models\Client`). 
* Checks if the entity exists in the primary database: `Client::where('id', $extractedId)->exists()`.
* If the record does not exist or has been deleted, a **Cache Miss** is immediately forced, and the stale cache entry is evicted.

### C. LLM Semantic Verification
A lightweight call (using `qwen-turbo`) is fired to evaluate the candidate cached response against the query:
* **Prompt**: `Assess if the following cached response accurately and factually answers this user request without reference to stale IDs.`
* If the verification response is negative, the cache hit is rejected, forcing a **Cache Miss**.
