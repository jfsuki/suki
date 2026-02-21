---
name: software-engineering-senior
description: Senior software engineering workflow for SUKI. Use when implementing or refactoring backend/chat/db logic, designing architecture changes, or diagnosing regressions. Enforces retrieval-led analysis, compatibility checks, and QA gates before and after changes.
---

# Software Engineering Senior (SUKI)

## Purpose
Apply a disciplined engineering workflow for SUKI changes:
- analyze before coding,
- implement minimal compatible changes,
- validate with pre-check and post-check gates,
- report root cause and evidence.

## Mandatory workflow
1. Read context with retrieval-led protocol:
   - `framework/docs/INDEX.md`
   - only relevant docs for the task
2. Define:
   - problem statement,
   - root cause hypothesis,
   - minimal patch plan,
   - compatibility risks.
3. Implement incrementally (no rewrite).
4. Run pre-check and post-check using `framework/scripts/qa_gate.php`.
5. Report:
   - files changed,
   - test evidence,
   - pending risks.

## Non-negotiables
- Do not break existing contract keys.
- Do not bypass CommandLayer/QueryBuilder with raw SQL in app code.
- Keep APP mode and BUILDER mode separated.
- Keep one critical missing question per conversation turn.
- Do not claim success without test evidence.

## QA gate usage
- Pre-check:
  - `php framework/scripts/qa_gate.php pre`
- Post-check:
  - `php framework/scripts/qa_gate.php post`

See `references/qa-gate.md` for details.

## When debugging regressions
1. Reproduce issue with smallest deterministic case.
2. Capture failing output first.
3. Patch the smallest scope.
4. Re-run post-check.
5. Update docs if behavior changed.

## Output format
Always provide:
1. Root cause,
2. Fix applied,
3. Verification results,
4. Remaining gap (if any).
