# Cognitive Graph Memory Specification

## 1. Concept & Rationale
An agent needs to accumulate knowledge and relationships dynamically as it performs tasks. Single-turn conversation history forgets details across sessions.

The **Cognitive Graph Memory** maintains an ontological network of entities and relationships extracted dynamically from tool execution outputs and environment observations.

---

## 2. Graph Database Schema

Stored in the SQLite monitor database, the graph represents relationships using two primary tables:
* `graph_nodes`: Entities (e.g. `ServerConfig`, `PricingTier`) with their types and properties (JSON).
* `graph_edges`: Directed edges representing relationships (e.g., `ServerConfig` $\xrightarrow{\text{INFLUENCES}}$ `SizingOutcome`) with an associated coherence edge weight.

---

## 3. Operations & Deduplication

### A. Fact Extraction
After successful tool execution, the agent loops and runs an extraction pass on the tool output to identify triplets:

$$\text{Triplet} = (\text{Subject}, \text{Relationship}, \text{Object})$$

### B. Deduplication & Coherence Weighting
To prevent graph explosion, new edges are deduplicated:
* If relationship already exists, its edge weight (coherence factor) is amplified:

  $$W_{new} = \min(1.0, W_{old} + 0.10)$$

* If it does not exist, a new edge is initialized with a base coherence weight (default: `1.0`).

### C. Temporal Graph Decay
To support "forgetting" outdated information, edge weights decay over time:

$$W(t) = W_{initial} \cdot e^{-\lambda t}$$

Edges whose weight falls below the coherence threshold are pruned, keeping the knowledge graph focused on active, relevant contexts.
