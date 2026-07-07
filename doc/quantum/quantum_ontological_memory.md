# Quantum-Inspired Ontological Memory Specification

## 1. Concept & Rationale
Traditional vector databases retrieve context using a flat cosine similarity search. This ignores the temporal context, the cognitive domain, or relation strengths between concepts.

The **Quantum Memory Harness** models episodic memories as quantum states with phase angles ($\theta \in [0, 2\pi]$) representing operational contexts. When memories are queried, the harness simulates wave interference to constructively amplify or destructively suppress retrieval candidates.

---

## 2. Cosine + Phase Interference Scoring

Standard vector retrieval calculates flat cosine similarity $S_{cos}$ between a query vector $\vec{q}$ and memory vector $\vec{m}$:

$$S_{cos} = \frac{\vec{q} \cdot \vec{m}}{\|\vec{q}\| \|\vec{m}\|}$$

In `phpkaiharness`, every memory node $m$ has an associated phase angle $\theta_m$ mapping its domain. The incoming query is assigned a query phase angle $\theta_q$:
* $\theta = 0$: System errors and log traces.
* $\theta = \pi/2$: Sizing configurations and metrics.
* $\theta = \pi$: Pricing benchmarks and proposals.
* $\theta = 3\pi/2$: General chat or historical prompts.

The **Wave Interference Score** ($S_{interfere}$) is calculated as:

$$S_{interfere} = \cos(\theta_q - \theta_m)$$

The final **Fused Ontological Score** ($S_{fused}$) combines both vectors:

$$S_{fused} = \alpha \cdot S_{cos} + \beta \cdot S_{interfere}$$

where:
* $\alpha$: Semantic weight (Default: `0.7`).
* $\beta$: Phase weight (Default: `0.3`).

*Constructive interference* occurs when the query and memory phase angles are closely aligned ($\theta_q \approx \theta_m$), raising $S_{fused}$. *Destructive interference* suppresses nodes from unrelated domains even if they share similar words.

---

## 3. Semantic Entanglement Propagation

When two memories $A$ and $B$ are strongly associated, they are linked in the `entanglement_pairs` matrix with a force $F_{ent} \in (0, 1]$.

If Node $A$ is retrieved with a high score (exceeding similarity threshold), the entanglement operator propagates state collapse to Node $B$:

$$S_{fused}'(B) = \max(S_{fused}(B), S_{fused}(A) \cdot F_{ent})$$

This pulls Node $B$ into the active context window instantly, ensuring highly coupled dependencies are not lost during retrieval.
