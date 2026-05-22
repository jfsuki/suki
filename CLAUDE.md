# CLAUDE.md — SUKI (AI-AOS)

**Status**: 🟡 AMARILLO | Base sólida operativa, gaps en contabilidad avanzada y E2E HTTP  
**Scope**: Chat-first ERP platform, multi-tenant, DIAN-ready  
**Last**: 2026-04-09

---

## WHAT IS SUKI

**AI Application Operating System** (AI-AOS): Metadata-driven platform where non-technical users create/operate ERP apps via chat.

**Core**: Deterministic router (Cache → Rules → RAG → LLM fallback) + JSON contracts + CommandBus + Multi-tenant kernel.

---

## ARCHITECTURE LAYERS

```
User Chat
    ↓
[1] Intent Classification (Qdrant semantic + router rules)
[2] Conversation Memory (tenant/session-scoped)
[3] Skill/CommandBus Execution (deterministic only)
[4] Module Handlers (POS, Purchases, Fiscal, Ecommerce, Media, Search)
[5] Database Kernel (QueryBuilder, Repository, automatic tenant isolation)
[6] AgentOps Telemetry + Audit Logs
```

**Non-negotiable laws:**
- Never raw SQL in app layer (use Repository/QueryBuilder)
- Tenant isolation mandatory on every query
- Contracts are source of truth (preserve JSON keys always)
- Only incremental, backward-compatible changes
- **App Creator DB law**: tables are created ONCE per app_type (NOT per tenant). StorageModel CANONICAL. Naming: `app_{app_id}__{entity}`. See `docs/technical/APP_CREATOR_DB_ARCHITECTURE.md` and canon §13.

---

## MODULES (Active)

| Module | Purpose | Main Files |
|--------|---------|-----------|
| **POS** | Sales tickets, drafts, cash register | framework/contracts/forms/ticket_pos.contract.json |
| **Purchases** | Supplier orders, documents | project/contracts/invoices/purchase.*.json |
| **Fiscal** | Invoices, DIAN compliance | project/contracts/invoices/facturas_co.json |
| **Ecommerce Hub** | Alanube, WooCommerce, Tienda Nube | framework/app/Core/EcommerceHubService.php |
| **Media/Documents** | File storage, OCR hooks | framework/app/Core/MediaService.php |
| **Entity Search** | Cross-module entity resolution | framework/app/Core/EntitySearchService.php |
| **Access Control** | Tenant users, roles, permissions | framework/app/Core/TenantAccessControlService.php |
| **AgentOps** | Telemetry, metrics, improvement signals | framework/app/Core/TelemetryService.php |

---

## KNOWN ISSUES (Verificados en código real — ver AUDITORIA_TECNICA.md)

### Bloquean Go-to-Market (PYME CO)
| Issue | Severity | Evidencia | Esfuerzo |
|-------|----------|-----------|---------|
| Login individual por tenant (OTP) | P0 | Solo `SUKI_MASTER_KEY` global. `register.php:21` captura phone, falta OTP | 5-8d |
| FE electrónica DIAN — XML/UBL/CUFE/firma | P0 | `AlanubeClient.php:51` HTTP real, `AlanubeIntegrationAdapter.php:8` payload vacío | 15-20d |
| PUC real + ReteFuente + ICA | ~~P1~~ RESUELTO | PUC 1002 cuentas en `puc_nacional`. ReteFuente+ICA en `FiscalRulesEngine.php` + `fiscal_rules_co.json` | — |
| Control Tower dashboards (KPIs, tokens, inbox) | P1 | `SPRINT_TRACKER.md` — S6.A-F = 100% sin iniciar | 10-15d |
| Tests E2E HTTP + CI remoto | P1 | `run.php:7` — PHP interno, sin HTTP real, sin CI | 5-8d |

### Deuda técnica (no bloquean inmediatamente)
| Issue | Severity | Evidencia |
|-------|----------|-----------|
| ALTER diff (MODIFY/DROP COLUMN) ausente | P1 | `EntityMigrator.php:101` — solo ADD COLUMN. Renombrar campo destruye datos |
| FORM_STORE solo en localStorage (no DB) | P1 | `FEATURE_MATRIX.md:11` — confirmado. Formularios se pierden si cierra browser |
| Score Qdrant 0.65 (docs decían 0.72) | P2 | `IntentClassifier.php:24` — desincronización docs vs código |
| Skills catálogo ≠ clases PHP | P2 | `skills_catalog.json` nombres vs `Skills/*.php` — no coinciden 1:1 |
| ChatAgent 2958 líneas (Strangler pendiente) | P2 | `ChatAgent.php` — Strangler iniciado, fases 3-6 extraídas. ConversationGateway ya en 245L |
| Gemini ausente como chat provider | P2 | `LLMRouter.php:169-177` — solo en embeddings, no en chat failover |
| Semantic memory cold start en deploy nuevo | P3 | Qdrant vacío sin seed en deploy fresco |

---

## STATUS

✅ **PASS**: 121/121 unit tests (requiere INSTALL.md sin keys reales — ver FIX 1), DB health OK, security hardening complete, TC01-TC26 integration tests (Fases 1-8)  
✅ **FASE 8**: TC24-TC26 feedback loop 21/21 PASS — AppFeedbackService wired, auto-promote a Qdrant, Torre tab activo  
❌ **FAIL**: `llm_smoke.php` (credentials, not code bug)  
❌ **ELIMINADO**: `chat_golden.php` — era smoke puro (str_contains sobre respuestas LLM), no medía calidad real  
⚠️ **YELLOW**: 6 gaps abiertos para producción — ver docs/troubleshooting/FAILURE_MAP.md

---

## MANDATORY DOCUMENTS

**Read in order:**
1. `AGENTS.md` (repo root) — Developer protocol
2. `docs/INDEX.md` — Navigation guide
3. `docs/PROJECT_MEMORY.md` — Current state
4. `docs/canon/SUKI_ARCHITECTURE_CANON.md` — Immutable laws
5. `docs/canon/ROUTER_CANON.md` — Router order
6. `docs/technical/07_DATABASE_MODEL.md` — DB schema
7. `docs/technical/AGENTS_CONVERSATION_GATEWAY.md` — Chat routing

---

## DEVELOPMENT WORKFLOW

```bash
# 1. Pre-check (mandatory)
php framework/scripts/codex_self_check.php --strict

# 2. Code (incremental only)
# Read relevant docs, preserve contracts, no rewrites

# 3. Test locally
php framework/tests/run.php                    # All unit tests (121/121)
php framework/tests/fase1_tc01_tc04.php        # Chat básico, guardrails
php framework/tests/fase3_tc09_tc11.php        # POS, Compras, Contabilidad
php framework/tests/fase4_tc12_tc15.php        # Memoria, multi-agente
php framework/tests/fase5_7_tc16_tc23.php      # Seguridad, observabilidad, fallos
php framework/tests/db_health.php              # DB integrity

# 4. Post-check
php framework/scripts/qa_gate.php post

# 5. Commit with evidence
git add <files>
git commit -m "feat(module): description. Tests: [pass/fail evidence]"
```

**QA gates are NOT optional.** No evidence = task incomplete.

---

## KEY COMMANDS

```bash
# Testing
php framework/tests/run.php                    # All unit tests
php framework/tests/fase5_7_tc16_tc23.php      # Integration full (TC16-TC23)
php framework/tests/db_health.php              # DB check

# Database
php framework/scripts/db_backup.php                        # Backup before changes
php framework/scripts/codex_self_check.php --strict        # Pre-flight check

# Observability
tail -f project/storage/logs/agentops/trace_*.jsonl        # Chat traces
tail -f project/storage/logs/transcripts/history_*.txt     # Conversations
```

---

## CRITICAL FILES (NEVER BREAK)

- `docs/contracts/action_catalog.json` — Action whitelist
- `docs/contracts/skills_catalog.json` — Skill registry
- `project/contracts/entities/*.json` — Entity schemas
- `project/contracts/invoices/*.json` — Fiscal contracts
- `framework/app/Core/ChatAgent.php` — Message orchestrator
- `framework/app/Core/IntentRouter.php` — Routing engine
- `framework/app/Core/Database.php` — ORM kernel
- `AGENTS.md` — This protocol

---

## NEXT STEPS (orden por impacto real)

1. **Alanube XML/UBL** → Completar payload DIAN en `AlanubeIntegrationAdapter` (HTTP client real, payload vacío)
2. **E2E HTTP tests** → Añadir pruebas HTTP reales sobre POS→Fiscal→Invoice flow
3. **ReportEngine financiero** → Balance general, P&G, Flujo de efectivo real
4. **Strangler ChatAgent** → Continuar extracción (hoy 2958 líneas, era 4652 — -36% logrado)

---

## QUICK REFERENCE

- **Router order**: Cache → Rules → RAG → LLM (last resort)
- **Tenant scope**: Every table has `tenant_id`, automatic isolation enforced
- **Backward compat**: Additive changes only, preserve all contract keys
- **Test before commit**: No blind pushes, evidence required
- **Source of truth**: JSON contracts, not code comments
