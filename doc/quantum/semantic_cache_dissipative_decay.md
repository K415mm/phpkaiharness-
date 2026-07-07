# Quantum-Inspired Semantic Cache & Dissipative Decay

## 1. Concept & Rationale
In environments where database records, pricing configurations, or deployment states shift constantly, a standard semantic cache can result in stale cache hits.

`phpkaiharness` treats prompt entries as **concept density matrices** ($\rho$) rather than raw text hashes. Over time, these entries undergo **Dissipative Quantum Decay**, representing the decay of semantic coherence.

---

## 2. Mathematical Formulation

A cached prompt state is represented as a density matrix $\rho$:

$$\rho = \sum_k p_k |\phi_k\rangle\langle\phi_k|$$

As time ($t$) progresses, the coherence of the cached response decays exponentially:

$$\rho(t) = e^{-\Gamma t} \rho(0)$$

where $\Gamma$ is the decay rate parameter configured in the admin panel.

---

## 3. Dissipative Decay Modes

The harness supports three modes to translate this decay into cache retrieval logic:

### A. Dissipative Threshold Shift
The similarity threshold $T(t)$ required for a cache match grows over time:

$$T(t) = T_0 + (1 - T_0) \cdot (1 - e^{-\Gamma t})$$

where $T_0$ is the base similarity threshold (e.g., `0.88`). As the cache entry ages, a query must match the cached prompt with increasingly strict similarity to trigger a cache hit.

### B. Step Function Decay
A hard expiration threshold where entries remain fully valid ($100\%$ coherence) until $t = \text{TTL}$, after which coherence drops instantly to zero.

### C. None
No decay is applied. Cache matches remain fully active indefinitely.
