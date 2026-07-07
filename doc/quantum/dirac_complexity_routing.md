# Dirac-Inspired Dynamic Complexity Routing Specification

## 1. Concept & Rationale
Traditional AI agents use static rules or a single, resource-heavy model for all tasks. This results in either poor performance on complex issues or excessive API costs on simple greetings.

`phpkaiharness` models prompt complexity as a state vector $| \psi \rangle$ in a 3-dimensional Hilbert space spanned by the orthonormal complexity bases:
* $| \text{Simple} \rangle$: Direct response query.
* $| \text{Complicated} \rangle$: Queries requiring Domain RAG context.
* $| \text{Complex} \rangle$: Multi-step agentic reasoning with tool executions.

---

## 2. Mathematical Formulation

The complexity state vector $| \psi \rangle$ is defined as:

$$| \psi \rangle = c_s | \text{Simple} \rangle + c_d | \text{Complicated} \rangle + c_x | \text{Complex} \rangle$$

where $c_s, c_d, c_x \in \mathbb{C}$ are the probability amplitudes representing the query's alignment with each domain. 

### Amplitude Coefficients
The coefficients are computed dynamically by projecting the query's token length ($L_t$), vocabulary entropy ($H_v$), and tool density requirement ($D_{tool}$) onto the bases:

$$c_s = \sqrt{1 - \tanh(\gamma \cdot L_t)}$$
$$c_d = \tanh(\gamma \cdot L_t) \cdot (1 - D_{tool})$$
$$c_x = \tanh(\gamma \cdot L_t) \cdot D_{tool}$$

where $\gamma$ is a scaling constant (default: `0.005`).

### Normalization Condition
To satisfy quantum probability laws, the state vector must remain normalized:

$$\langle \psi | \psi \rangle = |c_s|^2 + |c_d|^2 + |c_x|^2 = 1$$

---

## 3. Spontaneous State Collapse (Measurement)

When a prompt enters the loop, a measurement operator $\hat{M}$ acts on $| \psi \rangle$. This collapses the superposition into a single eigenstate with probability $P_i = |c_i|^2$:

### A. Simple Domain ($P_s$)
* **Action**: Direct execution.
* **Routing**: The prompt is routed directly to a single-pass LLM call. All RAG engines, loops, caches, and verification checks are bypassed.
* **Purpose**: Minimum latency and zero token waste for trivial tasks.

### B. Complicated Domain ($P_d$)
* **Action**: RAG context injection.
* **Routing**: The Ontological Context Injector retrieves relevant Eloquent models from the host app database and prepends them into the system prompt before a single-pass LLM call. Tool execution loops are bypassed.
* **Purpose**: Fast answers grounded in database facts.

### C. Complex Domain ($P_x$)
* **Action**: Multi-turn agent loop.
* **Routing**: Triggers the full AgentLoop executor. The agent has access to tool registries, semantic caching, cognitive graph memories, and post-loop draft verification passes.
* **Purpose**: Solves open-ended, multi-step problem solving.
