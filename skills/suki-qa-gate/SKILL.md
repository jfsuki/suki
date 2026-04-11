---
name: suki-qa-gate
description: Run SUKI QA gates (pre/post checks, unit tests, chat golden, db health) with structured output and remediation suggestions
---

# SUKI QA Gate Skill

## Purpose
Enforce mandatory QA gates before/after changes to prevent regressions and ensure contract compliance.

## When to Use
- Before implementing any change: `suki-qa-gate pre`
- After code changes: `suki-qa-gate post`
- To validate entire test suite: `suki-qa-gate full`

## Workflow

### 1. Pre-Check (Before coding)
```bash
suki-qa-gate pre
```
Runs:
- `php framework/scripts/codex_self_check.php --strict`
- Validates `.env` is not tracked
- Checks database backup freshness

**Output**: ✅ OK or list of blockers with fixes

### 2. Post-Check (After coding)
```bash
suki-qa-gate post
```
Runs:
- `php framework/tests/run.php` (71 unit tests)
- `ENFORCEMENT_MODE=strict php framework/tests/chat_golden.php` (24 chat routes)
- `php framework/tests/db_health.php` (schema integrity)
- `php framework/scripts/qa_gate.php post`

**Output**: Pass/fail with affected test summary and remediation steps

### 3. Full Suite
```bash
suki-qa-gate full
```
Runs pre + post + database backup + llm_smoke diagnostics.

## Non-Negotiables
- **No blind pushes**: Evidence required before commit
- **Gates are mandatory**: Failing test = work incomplete
- **Strict mode**: ENFORCEMENT_MODE=strict for critical paths
- **Tenant isolation**: Check db_health for tenant_id index coverage

## Failure Remediation
If tests fail:
1. Read error output (tells exact blocker)
2. Check affected file in error message
3. Fix root cause (not workaround)
4. Re-run specific test
5. Re-run full post-check
6. Only then commit

## Output Format
```json
{
  "gate": "pre|post|full",
  "status": "PASS|FAIL",
  "timestamp": "2026-04-09T15:30:00Z",
  "checks": [
    {"name": "codex_self_check", "result": "PASS", "evidence": "..."},
    {"name": "unit_tests", "result": "PASS", "count": "71/71"},
    ...
  ],
  "blockers": [],
  "remediation": "..."
}
```
