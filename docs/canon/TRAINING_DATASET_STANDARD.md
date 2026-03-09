# Training Dataset Standard

Version: 1.0.0  
Effective date: 2026-03-07

## 1) Objective
Define a clean, low-noise, and verifiable sector dataset standard compatible with:
- retrieval/RAG
- operational intents
- support/FAQ
- sector knowledge packs

## 2) Canonical separation of layers

### 2.1 knowledge_stable
- Stable sector facts and operational references.
- Vectorizable: yes.
- Must not contain execution policy or prompt internals.

### 2.2 intents_operatives
- Intent/action behavior, slots, and one-question policy.
- Vectorizable: no (structured/runtime logic).
- Used for deterministic routing and action behavior.

### 2.3 support_faq
- Support and FAQ entries.
- Vectorizable: yes.
- Short, concrete, and non-promotional.

### 2.4 policy_constraints
- Governance restrictions and no-go rules.
- Vectorizable: no (structured constraints).
- Must be explicit and auditable.

### 2.5 utterances
- User language coverage by intent:
  - explicit
  - implicit
  - hard negatives
- Vectorizable: yes, after hygiene and dedupe.

## 3) Mandatory quality rules
- No overlap between positive utterances and hard negatives.
- One-question policy must cover required slots.
- Minimum coverage thresholds:
  - explicit >= 40 per intent
  - implicit >= 40 per intent
  - hard negatives >= 40 per intent
  - dialogues >= 10 per batch
  - qa cases >= 10 per batch

## 4) Anti-noise rules
- Forbid placeholder text (`lorem`, `dummy`, `xxx`, etc.).
- Forbid marketing copy (`compra ahora`, `oferta`, etc.).
- Remove channel prefixes in training phrases (`web:`, `telegram:`, `whatsapp:`).
- Deduplicate with normalized fingerprint:
  - lowercase
  - trim
  - collapse whitespace
  - remove common diacritics

## 5) Canonical pipeline
1. `ingest`
2. `hygiene`
3. `validation`
4. `quality_score`
5. `publication`

## 6) Publication states
- `draft`
- `validated`
- `published`
- `deprecated`

## 7) Contract reference
- `docs/contracts/sector_training_dataset_standard.json`

## 8) Runtime compatibility notes
- Keep `intents_expansion`, `multi_turn_dialogues`, `emotion_cases`, and `qa_cases` unchanged.
- Add new layers as optional additive blocks to preserve existing datasets.

