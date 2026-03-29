# Implementation Gap Matrix (2026-02-20)

## Objective
Give a single, evidence-based view of what is solid, what is partial, and what is missing to close the app generator + chat assistant without hidden gaps.

## Current quality gate (evidence)
- `php framework/tests/run.php` -> **7 pass / 0 fail**
- `php framework/tests/chat_acid.php` -> **26 pass / 0 fail**
- `php framework/tests/chat_api_single_demo.php` -> **6 pass / 1 fail** (conversation quality gap in builder guidance)

## Global status (realistic)
- Core contracts + runtime: **70%**
- DB kernel + CRUD API: **75%**
- Chat assistant (builder + app): **58%**
- Multiuser/auth + tenancy isolation: **62%**
- UX no-tecnica (chat guidance + visual clarity): **45%**
- Electronic invoicing integration (Alanube): **40%**
- Security hardening + ops: **55%**
- End-to-end QA automation: **60%**
- **Overall delivery status:** **58%**

## Matrix (what is missing and how to implement)

| Area | Current status | Evidence (files/tests) | Main gap | Implementation needed (no rewrite) | Priority |
|---|---|---|---|---|---|
| Contracts (manifest/form/entity) | Stable | `contracts/schemas/*.json`, `ManifestValidator.php`, `EntityRegistry.php` | Build/use capabilities are not fully exposed as one canonical graph | Add one read-only capability graph endpoint consumed by chat + UI help | P0 |
| DB kernel + migrations | MVP working | `Database.php`, `QueryBuilder.php`, `EntityMigrator.php`, `MigrationStore.php` | Only `CREATE IF NOT EXISTS`; no controlled `ALTER` diff | Add safe migration planner (contract diff -> migration plan -> apply) | P1 |
| CRUD command layer | Working | `CommandLayer.php`, `/api/records/*`, `/api/command` | UX errors when entity intent is ambiguous in chat | Enforce pre-check: entity exists + mode guard before CRUD execution | P0 |
| Builder chat flow | Partial | `ConversationGateway.php`, `chat_builder.html`, demo test | Can still loop/repeat on vague requests; insufficient guided step-by-step | Add deterministic finite flow for onboarding (business -> module -> first table -> confirm -> create) | P0 |
| App chat flow | Partial | `ConversationGateway.php`, `chat_app.html` | Sometimes responds with generic guidance instead of app-specific next step | Force app responses from live registry (entities/forms/actions for current project only) | P0 |
| Memory/state per user | Partial | `storage/tenants/*/agent_state/*.json`, `ChatMemoryStore.php` | State key still weak for project/mode isolation in some paths | Standardize key = `tenant + project + mode + user`; migrate reader with fallback | P0 |
| Auth + session binding | Partial | `/api/auth/*`, `ProjectRegistry.php` | Chat can run with manual IDs from UI; weak identity guarantees | Bind chat user/role/tenant/project from authenticated session by default; hide manual overrides in normal mode | P0 |
| Tenant/project isolation | Partial | `TenantContext.php`, `ProjectRegistry.php`, `/api/registry/*` | UI still allows confusion between projects; backend mostly ready | Add mandatory project selector in Home + persist current project in session | P0 |
| Help system | Partial | `conversation_training_base.json`, `chat/help` | Generic or repetitive help in some contexts | Move help to dynamic template filled from registry + current step + requested slot | P0 |
| LLM router + cost control | Partial but usable | `LLMRouter.php`, `config/llm.php`, `/api/llm/health` | Policy not fully declarative by use case; missing per-turn budget report | Add policy table (`task -> provider order -> token cap -> timeout`) + telemetry dashboard | P1 |
| Alanube integration | Partial | `AlanubeClient.php`, `/api/integrations/alanube/*`, contracts docs | Missing full sandbox flow and robust mapping by country profile | Implement first full CO sandbox flow (emit -> persist ID -> webhook update), then country adapters | P1 |
| Reports/dashboard runtime | MVP | `ReportEngine.php`, `DashboardEngine.php`, endpoints in `api.php` | No clear quality gate for fiscal output and chart consistency | Add report regression pack with fixed fixtures and expected outputs | P1 |
| Security baseline | Partial | `.htaccess`, command allowlists, prepared queries | Missing full CSRF/rate limit/IDOR hardening pack | Add middleware pack: CSRF, per-route rate limit, explicit ownership checks | P0 |
| QA and release discipline | Partial | `tests/chat_acid.php`, `tests/chat_api_single_demo.php`, `AcidChatRunner.php` | Acid test can pass while UX still fails in real flows | Add "golden conversation" tests with strict expected assistant behavior and failure snapshots | P0 |

## Root-cause summary of current chat instability
1. Intent router still mixes "help response" with "execution response" under ambiguity.
2. Capability answers are sometimes generic, not strictly scoped to the active project.
3. Build/use conversation mode is not always enforced before guidance text generation.
4. State continuity is good per user, but not strict enough per `project+mode` across all call paths.

## No-gap implementation sequence (recommended)

### Phase 1 (stabilization, required before new features)
1. Hard guard rails:
   - App mode cannot propose structural actions.
   - Builder mode cannot execute business CRUD.
2. Capability graph endpoint:
   - Single source for entities/forms/actions from current project.
3. State key migration:
   - Persist by `tenant+project+mode+user`; keep backward compatibility reader.
4. Golden conversation tests:
   - 20 guided cases, each with expected next step (no generic loops).

## Execution update (2026-02-20, same day)
- Completed from Phase 1:
  - hard build/use guard in `ConversationGateway`.
  - capability graph endpoint `/api/registry/capabilities`.
  - registry sync with contracts in status/help/entities routes.
  - state key migration to `tenant+project+mode+user` with legacy fallback.
  - golden test harness added (`tests/chat_golden.php`).
- Remaining in Phase 1:
  - expand golden pack from 1 scenario to full 20+ scenario suite.
  - bind chat identity strictly to authenticated session in UI default mode.

### Phase 2 (guided assistant quality)
1. Guided step engine:
   - "One minimum question" with deterministic next action.
2. Dynamic help:
   - Content from registry + current step + business profile.
3. Context confidence scoring:
   - Low confidence -> ask clarification; never fake execution.

### Phase 3 (business readiness)
1. Auth-session binding mandatory for chat in normal mode.
2. Project selector + user role view in Home.
3. Alanube CO sandbox end-to-end with webhook status updates.
4. Security hardening pack (CSRF, rate limiting, IDOR checks).

## Engineering decision rule (mandatory)
- If capability is unknown -> do not execute; ask one minimal clarification.
- If entity/form does not exist -> never invent; route to creator flow.
- If request is out of scope -> state boundary clearly and provide exact next step.
- If behavior is not covered by tests -> add golden test first, then implement.
