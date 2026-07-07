# Ontological Context Injector (RAG) Specification

## 1. Concept & Rationale
An agent operating without local context suffers from context deprivation and hallucinations. 

The **Ontological Context Injector** is a domain-level Retrieval-Augmented Generation (RAG) engine. It converts query vectors, compares them against model database record embeddings, and injects context envelopes dynamically before sending queries to Qwen Cloud.

---

## 2. Technical Pipeline

```
[Prompt Input]
      │
      ▼
[Generate Embeddings via Client]
      │
      ▼
[Calculate Cosine Similarity against Model Tables]
      │
      ▼
[Filter & Sort by Similarity Score]
      │
      ▼
[Inject Context Envelope into System Prompt]
```

---

## 3. Mathematical Execution

1. **Embedding Generation**: The query prompt $P$ is converted to vector $\vec{q}$:

   $$\vec{q} = \text{Embed}(P)$$

2. **Similarity Evaluation**: Performs similarity lookups against target model tables (using the configurable `ontology.embedding_column` columns):

   $$S_{cos} = \frac{\vec{q} \cdot \vec{e}_m}{\|\vec{q}\| \|\vec{e}_m\|}$$

3. **Hydration**: Elements exceeding the `ontology.similarity_threshold` are retrieved (up to `ontology.max_records`), serialized to text, and prepended:

   $$\text{Prompt}_{\text{enriched}} = \text{Context}_{\text{RAG}} \oplus \text{Prompt}_{\text{original}}$$
