# PREPROD RELEASE CHECKLIST

## 1) Config profile (staging)
Use this primary/secondary policy for deterministic JSON and stable failover:

- Primary provider: OpenRouter (`LLM_ROUTER_MODE=openrouter`)
- Primary model: `qwen/qwen3-coder-next`
- Secondary provider: Gemini (`GEMINI_MODEL=gemini-2.5-flash-lite`)
- Strict JSON required in LLMRouter for contract-first prompts (`requires_strict_json=true`)

Required `.env` knobs:

```
LLM_ROUTER_MODE=openrouter
OPENROUTER_MODEL=qwen/qwen3-coder-next
LLM_SMOKE_PRIMARY=openrouter
LLM_SMOKE_MODE=openrouter
LLM_SESSION_QUOTA_ENABLED=1
LLM_MAX_REQUESTS_PER_SESSION=120
LLM_SESSION_QUOTA_WINDOW_SECONDS=86400
LLM_MAX_REQUESTS_PER_MINUTE_OPENROUTER=90
LLM_MAX_REQUESTS_PER_MINUTE_GEMINI=20
```

## 2) Mandatory release gate
Run in order:

1. `php framework/scripts/codex_self_check.php --strict`
2. `php framework/tests/run.php`
3. `php framework/tests/chat_acid.php`
4. `php framework/tests/chat_golden.php`
5. `php framework/tests/chat_real_100.php`
6. `php framework/tests/db_health.php`
7. `php framework/tests/llm_smoke.php`
8. `php framework/tests/conversation_kpi_gate.php`
9. `php framework/scripts/qa_gate.php post`

Release only if all commands are green in 2 consecutive runs.

## 3) Definitive KPI thresholds
Conversation quality:

- Intent accuracy >= 0.90
- Correction success >= 0.95
- Unknown-business success >= 0.90
- Fallback rate <= 0.45
- Average tokens per session <= 12000
- Average cost per session <= 0.05 USD

Performance/latency:

- Intent p95 <= 1200 ms
- Intent p99 <= 2500 ms
- Command p95 <= 1500 ms
- Command p99 <= 3000 ms

Security:

- CSRF strict enabled on mutating endpoints (`API_SECURITY_STRICT=1`)
- Webhook signature + anti-replay enabled on external channels
- Central rate-limit enabled and validated by tests

## 4) Manual smoke before go-live
- Builder flow: create entity + form + relation + index from chat
- App flow: CRUD full cycle + report + dashboard query
- Flow controls: `cancelar`, `atras`, `reiniciar`, `retomar`
- Unknown business flow: correction + discovery + technical brief
- LLM fallback: force primary quota/retry scenario and validate failover

## 5) Stop-release conditions
Do not release if any of these happen:

- `llm_smoke` fails strict JSON validation
- KPI gate below thresholds
- chat_golden correction scenarios fail
- db_health reports risk/warnings not triaged
- security E2E for webhook/CSRF fails
