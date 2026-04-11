---
name: suki-architecture-review
description: Review architectural changes for SUKI laws compliance, compatibility risks, and design patterns alignment
---

# SUKI Architecture Review Skill

## Purpose
Validate that changes align with SUKI canonical laws, maintain backward compatibility, and follow established patterns before implementation.

## When to Use
- Designing new feature or module
- Adding new entity type or contract
- Changing router logic or message flow
- Refactoring core services
- Integrating third-party systems
- Planning multi-turn architectural shifts

## SUKI Canonical Laws

### Law 1: Execution Boundary
- ✅ LLM interprets, guides, proposes
- ✅ PHP kernel validates, persists, executes
- ❌ LLM SHALL NOT perform business math or direct persistence
- ✅ All side effects must go through CommandBus

### Law 2: Multi-Tenant Mandatory
- ✅ Every table must have `tenant_id`
- ✅ All queries automatically tenant-scoped
- ✅ Cross-tenant reads/writes forbidden unless marked system-global read-only
- ✅ Indices on `(tenant_id, created_at)` and relevant business keys

### Law 3: Contract-Driven
- ✅ JSON contracts are source of truth
- ❌ Never remove or rename contract keys
- ✅ All new entities/forms must have JSON schema first
- ✅ Runtime must validate against contracts

### Law 4: Router Deterministic
- ✅ Cache → Rules → RAG → LLM (in order)
- ✅ LLM only when earlier stages cannot resolve
- ✅ No stage may be skipped without policy signature
- ✅ All routing decisions must be auditable

### Law 5: Agent Controlled Copilot
- ✅ Agents interpret, normalize, delegate
- ❌ Agents SHALL NOT execute directly
- ✅ Real execution goes through skills/CommandBus/PHP kernel
- ✅ Agents must stay within registry/contracts

### Law 6: Additive Evolution Only
- ✅ New features add to codebase
- ❌ Never rewrite existing modules
- ✅ Preserve backward compatibility always
- ✅ Deprecate gradually, never rip out

### Law 7: Security by Design
- ✅ Deny by default for unknown/unsafe actions
- ✅ Hard gates for critical operations (auth/tenant/schema)
- ✅ All mutations require explicit session auth
- ✅ Audit log every side effect

## Review Checklist

### Phase 1: Scope & Risk (Before Design)
- [ ] Is this additive or rewrite? (must be additive)
- [ ] Does it affect multi-tenancy? (if yes, extra validation needed)
- [ ] Does it require new table? (justify: shared core → custom_fields → JSON → new table only as last resort)
- [ ] Does it touch router? (if yes, read ROUTER_CANON.md)
- [ ] Does it touch contracts? (if yes, apply contract-guardian skill)
- [ ] New executable action? (must define gates_required)
- [ ] New integration? (must use CommandBus, not direct API)

### Phase 2: Design Validation
```
suki-architecture-review design <description>
```

Checks:
- ✅ Follows "execute boundary law" (LLM vs PHP)
- ✅ Respects "multi-tenant mandatory" law
- ✅ Uses contracts correctly (contract-driven)
- ✅ Maintains deterministic routing
- ✅ Follows "controlled copilot" pattern
- ✅ Is additive only (no rewrites)
- ✅ Applies security by design

### Phase 3: File Impact Analysis
```
suki-architecture-review impact <files>
```

Reports:
- Files affected by change
- Impact on other modules
- Contract compatibility
- DB schema implications
- Test coverage needed
- Backward compatibility risk

### Phase 4: Pattern Alignment
```
suki-architecture-review pattern <pattern_name>
```

Validates against established patterns:

**Pattern: New Entity CRUD**
- ✅ Entity contract in `project/contracts/entities/`
- ✅ Repository + Service + CommandHandler triplet
- ✅ Skills registered in `docs/contracts/skills_catalog.json`
- ✅ Actions registered in `docs/contracts/action_catalog.json`
- ✅ Tests for CRUD, tenant isolation, validation
- ✅ Router rules if commonly used

**Pattern: New Integration**
- ✅ Adapter pattern in `framework/app/Core/*Adapter.php`
- ✅ Credentials encrypted + masked in logs
- ✅ Webhook signature validation (if incoming)
- ✅ Idempotency key tracking
- ✅ Audit log for all external calls
- ✅ Sandbox/production support

**Pattern: New Module**
- ✅ Module directory: `framework/app/Modules/<Name>/AGENTS.md`
- ✅ Repository/Service/CommandHandler triplet
- ✅ All entities in module have `tenant_id`
- ✅ Public API through CommandBus only
- ✅ AgentOps telemetry registered
- ✅ Tests: unit + integration + E2E

**Pattern: New Router Rule**
- ✅ Rule in `docs/contracts/router_policy.json`
- ✅ Action must exist in `action_catalog.json`
- ✅ Intent classification is deterministic
- ✅ Evidence minimum defined
- ✅ Gates required listed
- ✅ Test coverage for both match and non-match cases

### Phase 5: Final Sign-Off
```
suki-architecture-review sign-off <files> --approved
```

Outputs:
```json
{
  "review_id": "ARV-2026-04-09-001",
  "status": "APPROVED|BLOCKED",
  "laws_compliant": true,
  "backward_compatible": true,
  "required_tests": [...],
  "risk_level": "LOW|MEDIUM|HIGH",
  "blockers": [],
  "recommendations": [],
  "next_steps": "Proceed to implementation with suki-qa-gate"
}
```

## Common Patterns to Reuse

### Adding a CRUD Entity
1. Create contract: `project/contracts/entities/my_entity.json`
2. Create Repository: `framework/app/Core/MyEntityRepository.php`
3. Create Service: `framework/app/Core/MyEntityService.php`
4. Create Handler: `framework/app/Core/MyEntityCommandHandler.php`
5. Register in Action Catalog
6. Register in Skills Catalog
7. Add tests: CRUD, tenant isolation, validation
8. (Optional) Add router rule if commonly used

### Adding an Integration
1. Create Adapter: `framework/app/Core/MyIntegrationAdapter.php`
2. Implement `IntegrationAdapterInterface`
3. Add credential encryption in `.env`
4. Implement webhook signature validation
5. Log all external calls (audit)
6. Add idempotency tracking
7. Register in Action Catalog
8. Add tests: connectivity, credential masking, idempotency, tenant isolation

### Adding a Router Rule
1. Define intent in `docs/contracts/router_policy.json`
2. Add action to `docs/contracts/action_catalog.json`
3. Define gates_required
4. Define minimum_evidence
5. Test: both matching and non-matching cases
6. Test: with different users/tenants/modes

## Non-Negotiables
- **Laws are immutable**: Never violate canonical laws
- **Additive only**: No rewrites, no removals
- **Contracts first**: Design contracts before code
- **Backward compat**: All changes must support existing clients
- **Security by design**: Apply gates to all executables
- **Multi-tenant always**: Every query must respect tenant boundaries

## Output Format
```json
{
  "type": "design|impact|pattern|sign-off",
  "status": "APPROVED|NEEDS_REVISION|BLOCKED",
  "review_date": "2026-04-09",
  "laws_validation": {
    "execution_boundary": "PASS",
    "multitenant_mandatory": "PASS",
    "contract_driven": "PASS",
    "router_deterministic": "PASS",
    "agent_copilot": "PASS",
    "additive_evolution": "PASS",
    "security_by_design": "PASS"
  },
  "findings": [],
  "risks": [],
  "recommendations": [],
  "files_affected": [],
  "tests_required": []
}
```

## Integration with QA Gates
Architecture review not run automatically, but highly recommended before implementing significant changes. Use skill judgment.
