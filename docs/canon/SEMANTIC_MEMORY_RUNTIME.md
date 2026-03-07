# Semantic Memory Runtime Canon

Version: 1.0.0  
Effective date: 2026-03-07

## 1. Objective
Formalize the runtime base for SUKI semantic memory under canonical constraints.

## 2. Non-negotiable profile
- Vector DB: `Qdrant`
- Embedding model: `gemini-embedding-001`
- Output dimensionality: `768`
- Distance metric: `Cosine`
- Multi-tenant scope: `tenant_id` required in every indexed payload and retrieval filter
- App scope: `app_id` nullable, filterable when applicable

## 3. Canonical flow
1. `ingest`
2. `hygiene`
3. `embedding(768)`
4. `upsert Qdrant`
5. `retrieval cosine`

## 4. Minimum indexed payload
Required fields:
- `tenant_id`
- `app_id` (nullable)
- `source_type`
- `source_id`
- `chunk_id`
- `type`
- `tags`
- `version`
- `quality_score`
- `created_at`

Operational extension allowed:
- `content`
- `content_hash`

## 5. Runtime enforcement
- Mixed vector dimensions are forbidden.
- Non-Cosine distance is forbidden.
- Retrieval without tenant filter is forbidden.
- Retrieval result metadata must expose evidence identifiers (`source_id`, `chunk_id`) for AgentOps traceability.

## 6. AgentOps minimum when retrieval is used
- `route_path`
- `gate_decision`
- `contract_versions`
- retrieval summary:
  - `retrieval_attempted`
  - `retrieval_result_count`
  - `collection`
  - `tenant_id`
  - `app_id`

## 7. Contract reference
- `docs/contracts/semantic_memory_payload.json`

