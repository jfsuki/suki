# QA Gate Reference

## Pre-check (fast)
Command:
`php framework/scripts/qa_gate.php pre`

Checks:
- `framework/tests/run.php`
- `framework/tests/db_health.php`

Goal:
- detect basic runtime/db regressions before touching large code areas.

## Post-check (full)
Command:
`php framework/scripts/qa_gate.php post`

Checks:
- `framework/tests/run.php`
- `framework/tests/chat_acid.php`
- `framework/tests/chat_golden.php`
- `framework/tests/db_health.php`

Goal:
- block delivery when chat quality or DB health regresses.

## Cleanup
If tests generated temporary contracts/state:
`php framework/tests/reset_test_project.php`
