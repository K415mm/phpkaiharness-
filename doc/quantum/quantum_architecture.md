# Quantum & Dirac Specifications: Mathematical and Physics Formulation

This document provides a rigorous mathematical and structural description of the quantum-inspired and Dirac-inspired features implemented in the `phpkaiharness` package.

---

## 1. Dirac-Inspired Dynamic Complexity Routing

In classical AI agents, routing incoming prompts is often done using heuristics or strict classifier boundaries. `phpkaiharness` models prompt complexity as a state vector $| \psi \rangle$ in a 3-dimensional Hilbert space spanned by the orthonormal complexity bases: $\{| \text{Simple} \rangle, | \text{Complicated} \rangle, | \text{Complex} \rangle\}$.

### Mathematical Formulation

The complexity state vector $| \psi \rangle$ is defined as:

$$| \psi \rangle = c_s | \text{Simple} \rangle + c_d | \text{Complicated} \rangle + c_x | \text{Complex} \rangle$$

where $c_s, c_d, c_x \in \mathbb{C}$ are the probability amplitudes representing the query's alignment with each domain. The coefficients are computed dynamically by projecting the query's token length ($L_t$), vocabulary entropy ($H_v$), and tool density requirement ($D_{tool}$) onto the bases:

$$c_s = \sqrt{1 - \tanh(\gamma \cdot L_t)}$$
$$c_d = \tanh(\gamma \cdot L_t) \cdot (1 - D_{tool})$$
$$c_x = \tanh(\gamma \cdot L_t) \cdot D_{tool}$$

To satisfy the normalization condition:

$$\langle \psi | \psi \rangle = |c_s|^2 + |c_d|^2 + |c_x|^2 = 1$$

The probability of collapsing into a specific complexity routing path $i$ during measurement is:

$$P_i = |c_i|^2$$

### Spontaneous State Collapse (Measurement)
When a prompt enters the loop, a measurement operator $\hat{M}$ acts on $| \psi \rangle$. This collapses the superposition into a single eigenstate:

* **Collapse to $| \text{Simple} \rangle$ ($P_s$)**: Bypasses all middleware. Executes a direct, single-pass LLM call without tool execution loops or cache matches.
* **Collapse to $| \text{Complicated} \rangle$ ($P_d$)**: Activates the Ontological Context Injector (RAG) to hydrate the system prompt with DB entities, executing a single-pass LLM call without multi-turn tool loops.
* **Collapse to $| \text{Complex} \rangle$ ($P_x$)**: Triggers the full Agent Executor, allowing multi-turn tool execution, semantic caching, guardrails, and ontological verification passes.

---

## 2. Quantum Field Theory Ontological Memory

The **Quantum Memory Harness** models episodic memories as quantum nodes with phase states. Rather than storing static embeddings, memories exist in semantic superposition and undergo interference when queried.

### Cosine + Phase Interference Scoring

Standard vector retrieval calculates flat cosine similarity $S_{cos}$ between a query vector $\vec{q}$ and memory vector $\vec{m}$:

$$S_{cos} = \frac{\vec{q} \cdot \vec{m}}{\|\vec{q}\| \|\vec{m}\|}$$

The Quantum Memory Harness introduces **contextual phase angles** ($\theta \in [0, 2\pi]$) assigned to nodes based on operational domains (e.g., system errors, sizing data, or user queries). The wave interference score $S_{interfere}$ is:

$$S_{interfere} = \cos(\theta_q - \theta_m)$$

The final **Fused Ontological Score** ($S_{fused}$) is calculated using the superposition of these scores:

$$S_{fused} = \alpha \cdot S_{cos} + \beta \cdot S_{interfere}$$

where $\alpha$ (semantic weight, default: `0.7`) and $\beta$ (phase coherence weight, default: `0.3`) govern the quantum-classical transition.

### Entanglement Matrix Propagation

When two memories $A$ and $B$ are highly correlated (e.g., a sizing request and a corresponding server benchmark result), they become **entangled** with an entanglement force $F_{ent} \in (0, 1]$.

If Node $A$ is successfully retrieved (its $S_{fused} \ge \text{threshold}$), the entanglement operator $\hat{E}$ propagates state information instantly to Node $B$:

$$S_{fused}'(B) = \max(S_{fused}(B), S_{fused}(A) \cdot F_{ent})$$

This pulls Node $B$ into the active context window, bypassing its direct vector similarity score and preserving vital context.

---

## 3. Quantum-Inspired Semantic Cache

The semantic cache mitigates repeated LLM costs by storing prompt-response pairs. To account for context uncertainty, prompts are represented as **density matrices** ($\rho$) rather than flat strings:

$$\rho = \sum_k p_k |\phi_k\rangle\langle\phi_k|$$

### Dissipative Quantum Decay

To prevent stale cache hits in a dynamically changing database, cache entries undergo **Dissipative Quantum Decay** representing the loss of coherence over time:

$$\rho(t) = e^{-\Gamma t} \rho(0)$$

where $\Gamma$ is the decay rate parameter (adjustable in the config UI). 

* **Dissipative Mode**: Cache similarity thresholds decay exponentially over time: $T(t) = T_0 + (1 - T_0)(1 - e^{-\Gamma t})$, requiring progressively stricter matches as the cache ages.
* **Step Mode**: Simple hard expiration boundaries.
* **None Mode**: Cache values remain fully coherent indefinitely.

---

## 4. QFT-Inspired Cache Verification Loop

To prevent leaking stale or wrong data (e.g., returning details of client ID `3` when querying client ID `78455`), the cache employs a **QFT-Inspired Verification Loop** that operates before a cache hit is returned:

```
[Cache Candidate Match] ──> [Extract Entity IDs] ──> [Database Existence Check] ──> [LLM Semantic Verification]
```

1. **Numeric ID Extraction**: The cache matches the prompt and extracts any numeric identifiers.
2. **State Verification**: Queries the host database models (e.g., `Client::exists($id)`) to check if the referred entity exists. If the entity has been deleted or does not exist, the cache state is considered decayed and a **Cache Miss** is forced.
3. **LLM Verification Pass**: A fast, low-cost verification call (using `qwen-turbo`) validates if the cached response is semantically and logically correct for the current prompt.

---

## 5. Ontological Context Injector (RAG)

The Ontological Injector translates structural app data into system-prompt embeddings. It queries the host database models, calculates cosine similarities against the query state, and injects context envelopes dynamically at the prefix level:

$$\text{Prompt}_{\text{enriched}} = \text{Context}_{\text{RAG}} \oplus \text{Prompt}_{\text{original}}$$

---

## 6. Cognitive Graph Memory

The Cognitive Graph Memory builds a persistent knowledge graph across agent execution turns. 

### Fact Extraction & Deduplication
1. **Extraction**: Tool execution outputs are analyzed to extract entities and relations:
   $$\text{Edge} = (\text{Entity}_A) \xrightarrow{\text{Relation}} (\text{Entity}_B)$$
2. **Deduplication**: Facts are deduplicated using cosine similarity checks. If a matching relationship exists, its coherence factor (edge weight) is amplified; otherwise, a new edge is spawned.
3. **Graph Decay**: Unreferenced edges decay over time, allowing the agent to gradually "forget" outdated connections.
