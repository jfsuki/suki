# FAILURE_MAP — SUKI Known Failure Modes

**Scope**: Operational failures, symptoms, root causes, and fixes.  
**Updated**: 2026-05-16

---

## How to Use

For each failure, the map provides: symptom → root cause → file:line → fix.

---

## F01 — Chat history always empty (frontend)

**Symptom**: Chat loads but past messages never appear.  
**Root cause**: Frontend reads `m.dir`/`m.msg`/`m.ts` but API returns `direction`/`message`/`created_at`.  
**Files**: `project/views/chat/app.php:649`, `framework/views/builder/chat_builder.php:1064`  
**Fix**: Use `m.direction`, `m.message`, `new Date(m.created_at)`.  
**Status**: Fixed 2026-05-16.

---

## F02 — Torre tab "Training" crashes on fresh deploy

**Symptom**: `knowledge_catalog.sqlite` not found → `RuntimeException` in Torre.  
**Root cause**: `KnowledgeRegistryRepository` opens the SQLite file without creating it first.  
**Files**: `framework/app/Core/KnowledgeRegistryRepository.php`  
**Fix**: Run `php framework/scripts/seed_knowledge_catalog.php` on first deploy.  
**Status**: Open (P2).

---

## F03 — New tenant gets no accounting roles

**Symptom**: First accounting operation fails with "Rol contable X no configurado".  
**Root cause**: `AppInstallService::seedAgents()` did not call `seedDefaultRolesForTenant()`.  
**Files**: `framework/app/Core/AppInstallService.php:107`  
**Fix**: `seedDefaultRolesForTenant()` is now called at end of `seedAgents()`. Lazy fallback also exists in `AccountingService::resolveByRole()`.  
**Status**: Fixed 2026-05-16.

---

## F04 — LLM smoke test fails on fresh install

**Symptom**: `php framework/tests/llm_smoke.php` exits non-zero.  
**Root cause**: API credentials (`OPENROUTER_API_KEY`, etc.) not set in `.env`.  
**Files**: `framework/config/llm.php`, `.env`  
**Fix**: Set at least one provider key in `.env`. System has 6 providers with failover.  
**Status**: Open (environment configuration, not code bug).

---

## F05 — Qdrant returns vectors from wrong tenant

**Symptom**: Chat responses contain data from other tenants.  
**Root cause**: `QdrantVectorStore::query()` missing `tenant_id` filter.  
**Files**: `framework/app/Core/QdrantVectorStore.php`  
**Fix**: Add `must: [{key: "tenant_id", match: {value: $tenantId}}]` to all query payloads.  
**Status**: Open (P0 — critical before multi-tenant go-live).

---

## F06 — ConversationMemory accepts tenant_id='default'

**Symptom**: Messages stored under `default` tenant leak across sessions.  
**Root cause**: `ConversationMemory` has unsafe branch that accepts literal `'default'` as tenant.  
**Files**: `framework/app/Core/ConversationMemory.php`  
**Fix**: Reject `tenant_id === 'default'` in write path. Require authenticated tenant ID.  
**Status**: Open (P1).

---

## F07 — Acid test reports `cases=0`

**Symptom**: TC20 acid test API returns `{"cases":0}`.  
**Root cause**: `AcidChatRunner` reads `acid_conversation_cases` from `conversation_confusion_base.json`. If field missing or empty, returns 0 silently.  
**Files**: `framework/app/Core/AcidChatRunner.php:234`, `framework/contracts/agents/conversation_confusion_base.json`  
**Fix**: File now has 11 cases. Verify with `php -r "echo count(json_decode(file_get_contents('framework/contracts/agents/conversation_confusion_base.json'), true)['acid_conversation_cases']);"`.  
**Status**: Fixed 2026-05-15.

---

## F08 — PUC has only 109 accounts (needs 5000+)

**Symptom**: `AccountingRepository::getPucNacional()` returns sparse catalog.  
**Root cause**: `puc_nacional` table seeded with synthetic entries only.  
**Files**: `framework/app/Core/AccountingRepository.php`  
**Fix**: Import full PUC colombiano (Decreto 2420/2015 + NIIF para Pymes). Seed script needed.  
**Status**: Open (P1 — blocks Colombian go-live).

---

## F09 — ReteFuente not applied in POS chat flow

**Symptom**: Invoices created via chat don't calculate ReteFuente.  
**Root cause**: `FiscalRulesEngine` implements retention but POS chat flow doesn't call it.  
**Files**: `framework/app/Core/FiscalRulesEngine.php`, `framework/app/Core/ChatAgent.php`  
**Fix**: Wire `FiscalRulesEngine::applyWithholding()` in POS finalize path.  
**Status**: Open (P1).

---

## F10 — ALTER MODIFY/DROP COLUMN missing

**Symptom**: Renaming or removing a field via Builder corrupts data.  
**Root cause**: `EntityMigrator` only implements `ADD COLUMN`, not `MODIFY` or `DROP`.  
**Files**: `framework/app/Core/EntityMigrator.php:101`  
**Fix**: Implement diff-based migration: detect removed/renamed columns, emit safe SQL.  
**Status**: Open (P1 — blocks schema evolution).

---

## Quick Diagnostics

```bash
# Check DB health
php framework/tests/db_health.php

# Check chat routing
ENFORCEMENT_MODE=strict php framework/tests/chat_golden.php

# Check acid conversation cases
php -r "echo count(json_decode(file_get_contents('framework/contracts/agents/conversation_confusion_base.json'), true)['acid_conversation_cases']);"

# Check Qdrant collection status
curl -s http://localhost:6333/collections/suki_intents | php -r "echo json_decode(file_get_contents('php://stdin'), true)['result']['vectors_count'] ?? 'N/A';"
```
