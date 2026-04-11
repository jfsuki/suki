---
name: suki-router-analyzer
description: Analyze intent routing path, trace deterministic flow (Cache→Rules→RAG→LLM), and diagnose routing failures
---

# SUKI Router Analyzer Skill

## Purpose
Debug and analyze the deterministic router to ensure intents follow the correct path and guards are enforced.

## When to Use
- Chat not responding as expected
- Executable action blocked unexpectedly
- Informative response not returned
- Tenant isolation violated
- Router policy not applied
- Gate enforcement questions

## Router Order (Canonical)
```
Input Message
    ↓
[1] Cache (exact intent + policy fingerprint match)
    ↓ (if miss)
[2] Rules/DSL (deterministic rule match)
    ↓ (if no match)
[3] RAG (Qdrant semantic retrieval)
    ↓ (if no evidence)
[4] LLM (last resort only, strict JSON)
    ↓
Enforce Gates (mode, role, schema, evidence, tenant, auth)
    ↓
Execute or Return
```

## Key Gates (Hard Stops)
- `auth_gate` — User must be authenticated for executable actions
- `tenant_gate` — All queries must be tenant-scoped
- `schema_gate` — Action payload must match contract schema
- `evidence_gate` — Minimum evidence required for execution
- `mode_gate` — BUILD vs APP mode boundaries enforced

## Workflow

### 1. Trace Intent Path
```
suki-router-analyzer trace <message> [--tenant=default] [--mode=app|builder]
```

Outputs:
```
Message: "crear tabla productos"
Tenant: default | Mode: builder | Role: admin

Route Path:
  [1] Cache     → MISS (no exact match)
  [2] Rules     → HIT (matches "crear tabla" rule)
  [3] RAG       → (skipped, rules resolved)
  [4] LLM       → (not needed)

Intent: CREATE_ENTITY
Type: EXECUTABLE
Required Evidence: ✅ PRESENT

Gates Applied:
  ✅ auth_gate       → PASS (user authenticated)
  ✅ mode_gate       → PASS (mode=builder allows creation)
  ✅ schema_gate     → PASS (payload matches entity contract)
  ✅ evidence_gate   → PASS (source=rule_id:rule_123)
  ✅ tenant_gate     → PASS (tenant_id=default scoped)
  ✅ idempotency_gate → PASS (idempotency_key provided)

Result: EXECUTION_PLAN_VALID
Command: CreateEntityCommand(entity_name="productos", ...)
```

### 2. Debug Cache Misses
```
suki-router-analyzer cache-miss <message>
```

Outputs:
- Cache key that was looked up
- Policy/version fingerprint used
- Why it didn't match
- Suggestion: update training data or add rule

### 3. Audit Router Policy
```
suki-router-analyzer policy-audit
```

Validates:
- `router_policy.json` schema compliance
- All action names in policy exist in `action_catalog.json`
- `minimum_evidence` and `resolve_criteria` are consistent
- `gates_required` are valid gate names
- Intent classifications are complete

### 4. Analyze Gate Failures
```
suki-router-analyzer gate-failure <intent_name> [--reason]
```

Outputs:
- Why gate was triggered
- Which user/tenant/mode caused it
- Suggestion: is it a bug or expected behavior?

Example:
```
Intent: DELETE_ENTITY
Gate Failure: auth_gate
Reason: User not authenticated
Tenant: default
Mode: app
Expected: ✅ CORRECT (delete requires auth)

Suggestion: This is expected behavior.
If user should have access, check:
  1. Session is valid
  2. User role has "delete" permission
  3. No IP-based blocking
```

### 5. Tenant Isolation Check
```
suki-router-analyzer tenant-check <message> [--tenant=X] [--cross-tenant-test]
```

Validates:
- Query filters by correct tenant_id
- No data leakage between tenants
- Cross-tenant access properly denied

## Common Issues & Fixes

| Issue | Cause | Fix |
|-------|-------|-----|
| Executable blocked unexpectedly | Gate failed (auth/schema/mode) | Check gate audit output |
| Cache miss, fallback to LLM | No rule match, RAG no evidence | Add rule or RAG data |
| Tenant isolation violated | Query missing tenant_id filter | Check Repository implementation |
| Role access denied | User role lacks permission | Grant permission or check `tenant_check_permission` |
| Wrong intent resolved | Rule too broad | Refine rule in router_policy.json |

## Output Format
```json
{
  "message": "...",
  "tenant": "default",
  "mode": "app",
  "route_path": ["cache", "rules", "rag", "llm"],
  "stage_resolved": "rules",
  "intent": {
    "name": "CREATE_ENTITY",
    "type": "EXECUTABLE",
    "confidence": 0.95
  },
  "gates": [
    {"name": "auth_gate", "result": "PASS", "reason": "user authenticated"},
    ...
  ],
  "final_status": "EXECUTION_PLAN_VALID|INFORMATIVE_RESPONSE_VALID|FORBIDDEN_RESPONSE_VALID|NEEDS_CLARIFICATION",
  "command": "...",
  "fallback_reason": null,
  "audit_event_id": "..."
}
```

## Non-Negotiables
- **LLM last resort only**: If earlier stages resolve, LLM is not called
- **Gates are hard stops**: Failing gate blocks execution regardless of confidence
- **Tenant scope mandatory**: Every query must include tenant_id
- **Evidence required**: No execution without minimum evidence
- **Deterministic first**: Probabilistic routing only if deterministic fails

## Integration with QA Gates
Router analysis included in `chat_golden.php` golden suite validation.
