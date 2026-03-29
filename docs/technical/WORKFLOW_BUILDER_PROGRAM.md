# WORKFLOW_BUILDER_PROGRAM

Version: 2026-02-24
Owner: Core Architecture (SUKI)
Scope: Define the Workflow Builder target inspired by Opal, adapted to business apps ERP and strict cost control.

## 1) Why this program exists
- Current stack already resolves chat + contracts + command execution.
- Missing piece for scale: one canonical workflow contract that supports:
  - chat editing,
  - visual graph editing,
  - deterministic runtime execution.
- Goal: convert natural language into auditable executable workflow contracts without breaking existing forms/grids/contracts.

## 2) Reference baseline (Opal-inspired)
Keep these strengths:
1) NL -> workflow graph (DAG).
2) Dual editor: chat + visual node map.
3) Small primitives: Input, Generate, Output (+ assets/tools).
4) Typed references with auto wiring.
5) First-class debug console.
6) Version snapshots and restore.
7) Remix from templates.

## 3) SUKI differentiators (must keep)
1) Business execution, not only content generation:
   - workflows can call entity CRUD, reports, dashboards, integrations, permissions, audit.
2) Canonical portable contract:
   - design-time edits compile to canonical JSON;
   - runtime executes validated contract only.
3) Anti-hallucination by architecture:
   - mandatory pipeline: Plan -> Validate -> Execute.
4) Cost and latency discipline:
   - token budgets per node/session;
   - deterministic rule/functions preferred over LLM.
5) Security by default:
   - allowlist tools/actions by app + role + environment;
   - redact sensitive data in logs/traces.

## 4) Canonical contract target
File name: `workflow.contract.json` (per app/workflow).

Minimal structure:
```json
{
  "meta": {
    "id": "wf_sales_quote_v1",
    "name": "Sales Quote Flow",
    "status": "draft",
    "revision": 1
  },
  "nodes": [
    {
      "id": "n1",
      "type": "input",
      "title": "Capture Request",
      "inputsSchema": {},
      "promptTemplate": "",
      "modelConfig": {},
      "toolsAllowed": [],
      "outputsSchema": {},
      "uiHints": {},
      "runPolicy": {
        "timeout_ms": 30000,
        "retry_max": 1,
        "token_budget": 0
      }
    }
  ],
  "edges": [
    {
      "from": "n1",
      "to": "n2",
      "mapping": {
        "request_text": "input.text"
      }
    }
  ],
  "assets": [
    {
      "id": "a1",
      "kind": "document",
      "uri": "project://assets/price_list.pdf",
      "mime": "application/pdf",
      "label": "Price List"
    }
  ],
  "theme": {
    "presetName": "clean_business"
  },
  "versioning": {
    "revision": 1,
    "historyPointers": []
  }
}
```

## 5) Execution model (hard requirement)
### Design-time
- NL compiler creates contract diff proposals, never executes business actions.
- Visual editor edits same canonical contract.
- Every change gets validation + revision.

### Runtime
- Runtime receives validated workflow revision only.
- Runtime cannot invent nodes/fields/edges.
- Executor order:
  1) schema validate
  2) dependency validate
  3) security/policy validate
  4) budget validate
  5) topological execution with safe parallelism for independent nodes

## 6) Prompt constitution for compiler mode
Every compiler prompt must use:
1) ROLE
2) CONTEXT
3) INPUT
4) CONSTRAINTS
5) OUTPUT_FORMAT
6) FAIL_RULES

Decision gate before LLM:
- `needs_ai=false` for deterministic known actions/rules.
- `needs_ai=true` only for ambiguity, NL generation, or complex planning.

If required data is missing: return `NEEDS_CLARIFICATION`.
If request conflicts with contract: return `INVALID_REQUEST`.

## 7) Typed references (@ selector)
- UI allows selecting references to:
  - node outputs,
  - tool outputs,
  - assets metadata.
- Insertion auto-creates edge mapping.
- Reference validation blocks save when source/target types mismatch.

## 8) Debug and observability requirements
- Per node trace:
  - start/end timestamp,
  - status,
  - token use,
  - tool calls,
  - normalized error.
- Node replay in isolation allowed only in design-time sandbox.
- Runtime summary API must expose p50/p95 latency by workflow and node type.

## 9) Incremental rollout (no rewrite)
### WB-0 (spec + guardrails)
- Document contract and validation rules.
- Add schema draft and docs only.

### WB-1 (runtime foundation)
- Add DAG executor service + structured node traces.
- Keep legacy flow operational.

### WB-2 (NL compiler)
- Add compiler that outputs contract diff proposals.
- Require user confirmation before apply.

### WB-3 (visual graph editor)
- Render nodes/edges, side inspector, typed @ references.

### WB-4 (templates + remix)
- Add workflow template gallery with clone + edit + audit history.

## 10) Definition of done for this program
- No breaking changes to current contracts/forms/grids.
- Contract-first execution validated by tests.
- Fast path without LLM remains default for known tasks.
- Measurable token/cost reduction over time.
- Every workflow action is auditable by tenant/project/user.
