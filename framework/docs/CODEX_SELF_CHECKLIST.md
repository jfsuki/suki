# CODEX_SELF_CHECKLIST

Mandatory checklist before any code/doc change in SUKI.

## A) Context and scope
- [ ] Read `framework/docs/INDEX.md`.
- [ ] Read only target docs needed for this task.
- [ ] Confirm source of truth:
  - contracts in `project/contracts/*`
  - registry in `project/storage/meta/project_registry.sqlite`
- [ ] Confirm current mode impact: BUILD, USE, or both.

## B) Guardrails and compatibility
- [ ] No contract keys are renamed or removed.
- [ ] No direct SQL is introduced in app/business logic.
- [ ] APP/BUILDER boundaries remain enforced.
- [ ] Entity existence is validated before CRUD.
- [ ] One critical missing question per turn in conversation flows.

## C) Security and reliability
- [ ] No eval introduced.
- [ ] Permission checks remain contract-driven.
- [ ] Audit trail still records critical actions.
- [ ] Multi-tenant context preserved (`tenant + project + mode + user`).

## D) Pre-check command (mandatory)
- [ ] Run: `php framework/scripts/codex_self_check.php --strict`
- [ ] If it fails, fix root cause before implementing further.

## E) QA gate after implementation
- [ ] `php framework/tests/run.php`
- [ ] `php framework/tests/chat_acid.php`
- [ ] `php framework/tests/chat_golden.php`
- [ ] `php framework/tests/db_health.php`

## F) Temporary artifacts policy
- [ ] Testing artifacts are written only under `framework/tests/tmp/`.
- [ ] No temp artifact is committed outside `framework/tests/tmp/`.

## G) Git discipline (mandatory)
- [ ] `git add` only intended files.
- [ ] Commit message describes intent + scope.
- [ ] Push only when QA gate passes.

## H) Report to user
- [ ] What changed.
- [ ] Test evidence (pass/fail).
- [ ] Pending risks or gaps.
