# ROOT CAUSE - chat/message executable bypass (closed)

Cut date: 2026-03-04

## PoC that was failing before
1. `POST /api.php?route=chat/message` without session/auth:
   - `mode=builder`
   - `message="quiero crear una tabla status_redteam_p0"`
2. Follow-up confirmation:
   - `message="si"`

Observed pre-fix behavior: command was executed and returned `Tabla creada`.

## Root cause
1. Auth/RBAC violations were detected but, in `ENFORCEMENT_MODE=warn`, executable actions could still continue.
2. Chat executable path did not enforce a hard stop for unauthenticated requests before command execution.
3. Hard gates (`auth/tenant/allowlist/schema`) could still be treated as degradable/deferred in some flows.

## Fix implemented
1. Added executable hard-stop in chat layer:
   - `framework/app/Core/ChatAgent.php`
   - `isExecutableIntentOrAction(...)`
   - `enforceExecutableChatSecurity(...)`
   - `denyExecutableChat(...)`
2. Added hard-gate override in router evaluator:
   - `framework/app/Core/RouterPolicyEvaluator.php`
   - hard gates: `allowlist_gate`, `schema_gate`, `auth_rbac_gate`, `tenant_scope_gate`
   - hard gate failure now blocks regardless of `off|warn|strict`.
3. Added auth context binding in API entrypoint:
   - `project/public/api.php`
   - explicit `is_authenticated`, `auth_*`, `chat_exec_auth_required=true`
   - unauthenticated chat forced to `role=guest`.

## Regression protection tests
1. `framework/tests/chat_exec_requires_auth_test.php`
2. `framework/tests/chat_exec_no_default_admin_test.php`
3. `framework/tests/chat_exec_tenant_binding_test.php`
4. `framework/tests/chat_informational_without_auth_ok_test.php`
5. `framework/tests/chat_warn_mode_does_not_allow_exec_when_auth_fails_test.php`
6. `framework/tests/redteam_poc_chat_exec_test.php`

Current status: PoC converted to automated test and passing.
