# ROUTER_CANON
Status: CANONICAL  
Version: 1.0.0  
Date: 2026-03-02  
Scope: Deterministic routing authority and fallback conditions.

## 1) Canonical Router Order (Mandatory)
The router SHALL execute in this exact order:
1. Cache
2. Rules/DSL
3. RAG
4. LLM (last resort)

No stage may be skipped unless a signed policy explicitly disables it.

## 2) What "Resolve" Means
A request is resolved only when the router returns one of:
- `EXECUTION_PLAN_VALID` (executable path, all guards satisfied)
- `INFORMATIVE_RESPONSE_VALID` (no side effects, evidence present if required)
- `FORBIDDEN_RESPONSE_VALID` (policy denial with reason code)

Any other state is unresolved and MUST continue to the next stage or return clarification.

## 3) Stage Contracts
### 3.1 Cache Stage
Resolve condition:
- Exact intent/action cache hit with valid policy/version fingerprint.

Output:
- deterministic response or plan
- evidence: `cache_key`, `fingerprint`, `created_at`

### 3.2 Rules/DSL Stage
Resolve condition:
- Deterministic rule match produces valid classified action.

Output:
- rule result and normalization
- evidence: `rule_id`, `rule_version`

### 3.3 RAG Stage (Qdrant + AKP)
Resolve condition:
- Retrieval returns evidence meeting threshold and contract constraints.

Canonical retrieval profile:
- embedding model: `gemini-embedding-001`
- output dimensionality: `768`
- distance metric: `Cosine`

Output:
- answer/plan candidate
- evidence: `akp_id`, `akp_version`, `source_id`, `chunk_id`, `score`

### 3.4 LLM Stage (Last Resort)
LLM is allowed only when stages 1-3 cannot resolve with minimum evidence.

LLM output MUST be strict JSON and MUST include:
- `intent_name`
- `type`
- `confidence`
- `required_missing_data` (if any)
- `proposed_action` (if executable candidate)

LLM SHALL NOT execute side effects directly.

## 4) Minimum Evidence Definition
### 4.1 For EXECUTABLE
Minimum evidence:
- one deterministic authority source (`rule_id` OR contract reference),
- plus one integrity proof (schema pass and mode/role pass).

Without this, executable action is not allowed.

### 4.2 For INFORMATIVE
Minimum evidence:
- at least one source reference (`akp_ref`, registry ref, or rule ref).

### 4.3 For FORBIDDEN
Minimum evidence:
- policy reference (`policy_id` or guard code).

## 5) What To Do If There Is No Evidence
If no minimum evidence is available:
1. Return `NEEDS_CLARIFICATION` with one critical missing question, or
2. Return `CANNOT_EXECUTE_SAFELY` when action risk is high/critical.

The router MUST NOT guess data and MUST NOT fabricate evidence.

## 6) LLM Permission Conditions
LLM stage is permitted only if all are true:
- cache miss,
- no deterministic rules resolve,
- RAG lacks minimum evidence,
- request is not classified as FORBIDDEN by earlier guards.

If any condition is false, LLM call is blocked.

## 7) Deterministic Pseudoflow
```text
classify -> cache -> rules -> rag -> llm
if resolved: enforce gates -> return
if unresolved: NEEDS_CLARIFICATION
if forbidden: deny + audit
```

## 8) Audit Requirement
Router SHALL emit one structured routing event per message with:
- stage path taken
- resolution status
- evidence references
- fallback usage flag

## 9) Non-Goal
This canon defines routing behavior only. It does not implement cache stores, vector indexes, or LLM clients.
