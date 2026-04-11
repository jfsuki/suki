---
name: suki-contract-guardian
description: Validate JSON contracts against schema, detect breaking changes, and ensure backward compatibility before changes
---

# SUKI Contract Guardian Skill

## Purpose
Protect SUKI's contract layer (source of truth) from breaking changes. Enforce immutability of existing keys and schema compliance.

## When to Use
- Before modifying any contract JSON file
- When adding new actions, skills, or entities
- To validate contract consistency across the system
- When reviewing changes to critical contracts

## Critical Contracts
- `docs/contracts/action_catalog.json` — Action whitelist
- `docs/contracts/skills_catalog.json` — Skill registry
- `project/contracts/entities/*.json` — Entity schemas
- `project/contracts/invoices/*.json` — Fiscal contracts
- `docs/contracts/router_policy.json` — Routing rules
- `docs/contracts/agentops_metrics_contract.json` — Telemetry schema

## Validation Rules

### Rule 1: Key Immutability
- ✅ Add new keys
- ❌ Remove existing keys
- ❌ Rename existing keys
- ✅ Update values only

### Rule 2: Schema Compliance
- All action definitions must have: `name`, `type`, `required_role`, `allowed_tools`, `gates_required`
- All skill definitions must reference valid actions in `action_catalog.json`
- All entity contracts must include: `table_name`, `fields`, `tenant_id` requirement

### Rule 3: Cross-contract References
- `skills_catalog.json` → must reference actions in `action_catalog.json`
- `router_policy.json` → must reference valid action names
- Entity contracts → must respect multi-tenancy laws

### Rule 4: Backward Compatibility
- Existing clients must continue working
- New fields should have default values
- Deprecated fields must remain readable

## Workflow

### 1. Before Editing Contract
```
suki-contract-guardian validate-pre <contract_path>
```
- Loads contract baseline
- Checks schema validity
- Reports current key structure

### 2. After Editing Contract
```
suki-contract-guardian validate-post <contract_path>
```
- Compares against baseline
- Detects breaking changes
- Lists new/modified/removed keys
- Validates schema compliance

### 3. Full Audit
```
suki-contract-guardian audit-all
```
- Validates all contracts in `docs/contracts/` and `project/contracts/`
- Detects orphaned action references
- Reports schema drift
- Returns consolidated report

## Remediation Examples

**❌ Removed key (BREAKING)**
```json
// Before
{"name": "CREATE_CLIENT", "type": "EXECUTABLE", ...}

// After
{"type": "EXECUTABLE", ...}  // ❌ BREAKING: name removed
```
**Fix**: Keep `name` key, modify only the value if needed.

**✅ New key (OK)**
```json
// Before
{"name": "CREATE_CLIENT", "type": "EXECUTABLE"}

// After
{"name": "CREATE_CLIENT", "type": "EXECUTABLE", "description": "Create new client"}  // ✅ OK
```

**❌ Key rename (BREAKING)**
```json
// Before: "required_role"
// After: "role_required"  // ❌ BREAKING
```
**Fix**: Keep `required_role`, add `role_required` as alias if needed for new code.

## Output Format
```json
{
  "contract": "docs/contracts/action_catalog.json",
  "status": "VALID|INVALID|BREAKING",
  "schema_valid": true|false,
  "changes": {
    "new_keys": [...],
    "modified_keys": [...],
    "removed_keys": [...],
    "renames": [...]
  },
  "breaking_changes": [],
  "recommendations": []
}
```

## Non-Negotiables
- **Contracts are immutable**: Never remove keys
- **Schema is law**: All contracts must validate against their schema
- **Cross-refs must exist**: Every referenced action/skill must exist
- **Multi-tenancy**: All entity contracts must include `tenant_id`

## Integration with QA Gates
Contract validation runs as part of `suki-qa-gate post`.
