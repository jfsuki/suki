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

## 3. Vector Collection Strategy
Three canonical collections are mandatory by memory domain:
- `agent_training`: framework knowledge, architecture canon, contracts/policies used to ground agent behavior.
- `sector_knowledge`: sector datasets, FAQs, and business evidence for RAG retrieval.
- `user_memory`: conversation/session memory scoped by tenant/user/app.

Automatic mapping is resolved by `memory_type`:
- `agent_training` -> collection `agent_training`
- `sector_knowledge` -> collection `sector_knowledge`
- `user_memory` -> collection `user_memory`

Compatibility rule:
- If `memory_type` is missing/unknown, runtime falls back to `QDRANT_COLLECTION`.

## 4. Canonical flow
1. `ingest`
2. `hygiene`
3. `embedding(768)`
4. `upsert Qdrant`
5. `retrieval cosine`

## 5. Minimum indexed payload
Required fields:
- `memory_type` (`agent_training|sector_knowledge|user_memory`)
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

## 6. Runtime enforcement
- Mixed vector dimensions are forbidden.
- Non-Cosine distance is forbidden.
- Retrieval without tenant filter is forbidden.
- Retrieval result metadata must expose evidence identifiers (`source_id`, `chunk_id`) for AgentOps traceability.

## 7. AgentOps minimum when retrieval is used
- `route_path`
- `gate_decision`
- `contract_versions`
- retrieval summary:
  - `retrieval_attempted`
  - `retrieval_result_count`
  - `collection`
  - `tenant_id`
  - `app_id`

## 8. Contract reference
- `docs/contracts/semantic_memory_payload.json`
