# LLM Conversation Matrix and Implementation Plan (2026-02-20)

## Scope
Matrix for selecting and orchestrating LLM conversation flows for Suki/Cami (builder + app mode), with local-first cost control and strict no-hallucination execution.

## Vendor matrix (conversation flow capabilities)

| Vendor | Strong points for conversation flows | Gaps / risks | Best role in Suki |
|---|---|---|---|
| OpenAI | Strong tool orchestration (`tool_choice`, strict function schema), state threading (`previous_response_id`), prompt caching (`cached_tokens`), async background runs + polling. | Cost can grow if context/threading is unmanaged. | Complex routing fallback, strict command JSON, long-running workflows. |
| Anthropic | Very strong tool-use discipline and clarifying behavior for ambiguous tool calls; long context (200K, 1M tiered), prompt caching and web-search tool guidance. | 1M context is tier/beta constrained; premium pricing at very long context. | High-precision planner for ambiguous enterprise cases. |
| Gemini | Good low-cost function calling, structured JSON output (`response_json_schema`), token accounting APIs (`count_tokens`, usage metadata), generous free-tier quotas in eligible regions. | Quotas/rate limits are tiered and can throttle if bursts are unmanaged. | Primary low-cost parser/planner for non-critical complexity. |
| Microsoft Copilot Studio | Mature autonomous-agent governance model: scoped permissions, guardrails, audit and human-in-the-loop patterns. | Platform coupling if copied literally. | Security/operations design reference (not direct runtime dependency). |
| Perplexity Sonar | Citation-first response patterns and explicit tool-call budgeting in presets. | Better for research/explanations than transactional CRUD. | External research/explainer mode with citations. |
| Groq | Fast local tool-calling loop, explicit tool forcing options, good for low-latency function dispatch. | You still own orchestration quality and guardrails. | Ultra-fast parser fallback for tool-call extraction. |
| xAI | Function calling + parallel tool call handling patterns. | Requires strong local orchestration to avoid drift. | Optional alternate provider for tool-call redundancy. |

## What top flows do better (patterns to copy)

1. Deterministic execution boundary:
   - Conversation text -> strict structured action -> local execution.
2. Tool policy controls:
   - `auto`, `required`, forced tool, and allowlists.
3. Context discipline:
   - state threading without resending full history.
4. Token discipline:
   - caching + token counting before expensive calls.
5. Governance:
   - scoped permissions, audit trails, and human approval on critical actions.

## Current Suki state (technical reality)

### Already in place
- Local-first `ConversationGateway` + `ChatAgent` + `CommandLayer`.
- Multi-provider router (`Gemini`, `Groq`, `OpenRouter`, `Claude` adapters).
- Telemetry and acid tests.
- Builder/app mode separation guard (partially improved).
- Domain memory JSON and business playbooks.

### Main gaps still causing instability
1. State key granularity:
   - Agent state is keyed by `tenant + user`, not `tenant + project + user + mode`.
   - This can leak builder context into app context.
2. Identity source of truth:
   - UI still allows manual `user_id` editing; not strictly auth-bound.
3. Contract vs DB drift:
   - Some entities use pluralized table names (`entity.name` vs `table.name`), increasing confusion and migration drift risk.
4. Registry-driven capability graph:
   - Help/capabilities are mostly real now, but still need one canonical graph API used by both builder and app chat.
5. LLM policy engine:
   - Router exists, but policy rules (when to call which provider) are not fully declarative/versioned.
6. Test isolation:
   - Acid tests still generate runtime artifacts unless cleaned; should use disposable tenant/project sandbox per run.

## Implementation target (no gaps, production-safe)

### Layer A - Deterministic local core (must pass before LLM)
1. Canonical state key:
   - `state_key = tenant_id + project_id + mode + user_id`.
2. Capability graph endpoint:
   - Single source for available entities/forms/actions from registry.
3. Strict CRUD gate:
   - App mode never executes structural commands.
4. Missing-entity behavior:
   - App: redirect to creator; Builder: guided create-table flow.

### Layer B - LLM routing policy (only when local fails)
1. Provider order by policy:
   - Cheap parse first (Gemini Flash / Groq low-latency).
   - Fallback planner (OpenRouter/Anthropic/OpenAI depending availability).
2. Strict JSON output for commands:
   - Require schema-valid command object before execution.
3. Token budget guard:
   - Hard cap request size and max output.
4. Retry/fallback with circuit breaker:
   - Already present partially; complete with per-provider cooldown telemetry.

### Layer C - Governance and quality
1. Role/permission normalization end-to-end (auth -> role context -> command gate).
2. Human-approval hooks for destructive/financial actions.
3. Daily replay of golden conversations + drift report.
4. Memory TTL and compaction policy to prevent stale context.

## Recommended provider strategy for Suki (current phase)

1. Default: Local-first (no LLM).
2. Complex parse fallback: Gemini Flash-Lite/Flash (cost-effective).
3. Fast backup: Groq model if key present.
4. High-precision fallback: OpenAI/Anthropic for ambiguous multi-step planning.
5. Research answers with references: Perplexity mode (non-transactional).

## Evidence links (primary docs)
- OpenAI function calling strict mode and tool choice:
  - https://developers.openai.com/api/docs/guides/function-calling
- OpenAI conversation state (`previous_response_id`, `store`):
  - https://developers.openai.com/api/docs/guides/conversation-state
- OpenAI prompt caching (`cached_tokens`):
  - https://developers.openai.com/api/docs/guides/prompt-caching
- OpenAI background mode + polling:
  - https://developers.openai.com/api/docs/guides/background
- Anthropic tool-use implementation:
  - https://docs.anthropic.com/en/docs/agents-and-tools/tool-use/implement-tool-use
- Anthropic context windows:
  - https://docs.anthropic.com/en/docs/build-with-claude/context-windows
- Google Gemini function calling:
  - https://ai.google.dev/gemini-api/docs/function-calling
- Google Gemini structured outputs:
  - https://ai.google.dev/gemini-api/docs/structured-output
- Google Gemini token counting:
  - https://ai.google.dev/gemini-api/docs/tokens
- Google Gemini quotas/pricing:
  - https://ai.google.dev/gemini-api/docs/quota
  - https://ai.google.dev/pricing
- Microsoft Copilot Studio autonomous agents guidance:
  - https://learn.microsoft.com/en-us/microsoft-copilot-studio/guidance/autonomous-agents
- Perplexity agent presets + citation workflow:
  - https://docs.perplexity.ai/docs/agent-api/presets
- Groq local tool-calling:
  - https://console.groq.com/docs/tool-use/local-tool-calling
- xAI function calling:
  - https://docs.x.ai/developers/tools/function-calling

## Next engineering checkpoint
- Implement state-key isolation (`tenant+project+mode+user`) and capability-graph endpoint first.
- Re-run acid tests in disposable sandbox tenant/project and block merge on any regression.
