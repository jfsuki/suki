# AGENT TRAINING PLAN (BUILDER + APP)

## Goal
Train two coordinated agents with deterministic behavior, low unresolved turns, and strong recovery when user input is ambiguous, emotional, or incomplete.

- Builder Agent: designs app structure (entities/forms/reports/workflows)
- App Agent: executes business operations (CRUD/reporting/help) on existing structure

## 1) Training architecture

### Source of truth
- Domain source: `project/contracts/knowledge/domain_playbooks.json`
- Routing/training source: `framework/contracts/agents/conversation_training_base.json`
- Runtime memory: SQL memory tables (`mem_user`, `mem_tenant`, `chat_log`)

### Sync discipline
- Keep domain intents and utterances centralized in domain playbooks.
- Generate/sync training artifacts via script (`framework/scripts/sync_domain_training.php`).
- Block drift with anti-diff tests in QA.

## 2) Core skill packs

### Builder Agent skill packs
1. Discovery and scoping
   - Detect business type, payment model, main processes, required documents.
   - Ask exactly one critical question per turn.
2. Contract design guidance
   - Field type coaching (`decimal` for money, `text` for phone, `date/datetime` for dates).
   - Relations and indexing recommendations with confirmation.
3. Unknown-business protocol
   - Build technical brief before generation.
   - Trigger external LLM research only when local confidence is low.
4. Transaction safety
   - Use `pending_command` + user confirmation for structural changes.

### App Agent skill packs
1. Runtime operations
   - CRUD only for existing entities in APP mode.
   - Explain missing entities and redirect to builder without fake success.
2. Support and diagnostics
   - Explain current status, pending actions, and next step in plain language.
3. Recovery and continuity
   - Resume paused flows with state machine (`retomar`).
   - Respect flow controls (`cancelar`, `atras`, `reiniciar`).

## 3) Conversation hardening tracks

### A) Unknown/ambiguous text
- Add hard-negatives per sector.
- Add correction intents (`USER_CORRECTION`, flow cancel/restart/back).
- Require re-classification when user negates prior assumption.

### B) Implicit action detection
- Detect hidden intents like:
  - "se me pierde inventario" -> suggest inventory audit controls
  - "todo va lento" -> suggest indexing/performance pending command
- Always ask confirmation before execution.

### C) Emotion-aware response policy
- Detect frustration markers (`no sirve`, `otra vez`, `me canse`, `no entiende`).
- Behavior:
  1. acknowledge issue in one sentence,
  2. provide concrete recovery step,
  3. ask one minimal clarifying question.
- Never escalate complexity or ask multiple questions in frustrated turns.

## 4) Data expansion targets

### Sector coverage target
- Current baseline: 15 sectors
- Target: 20 sectors with:
  - 45-60 utterances per solver intent,
  - hard-negatives per sector,
  - mini-app blueprints + KPIs + logic rules.

### Conversational dataset target
- Maintain `chat_real_100` and grow to `chat_real_150` with:
  - 30% correction scenarios,
  - 20% unknown-business scenarios,
  - 15% frustration/recovery scenarios,
  - 10% mode-guard violations,
  - 25% normal happy paths.

## 5) Evaluation and promotion loop

### KPI gates (must pass)
- Intent accuracy >= 0.90
- Correction success >= 0.95
- Unknown-business success >= 0.90
- Fallback rate <= 0.45
- Avg tokens/session <= 12000
- Avg cost/session <= 0.05 USD

### Promotion pipeline
1. Collect telemetry + chat outcomes.
2. Mine failures into candidate overrides.
3. Validate overrides via tests (`chat_golden`, `chat_real_100`, KPI gate).
4. Promote to `training_overrides` only after QA pass.

## 6) Operational checklist per sprint
1. Expand playbooks/utterances for 1-2 sectors.
2. Add hard-negative and correction tests.
3. Run full QA + KPI gate.
4. Review top unresolved intents and add targeted guidance.
5. Publish updated memory notes in `PROJECT_MEMORY.md`.
