# AGENT_SKILLS_MATRIX

Version: 2026-02-23
Purpose: Define expected capability baseline for each agent role in SUKI AI-AOS.

## Capability levels
- L1: basic guidance
- L2: contract-aware execution
- L3: reliable autonomous operation under guardrails
- L4: cross-system orchestration with strong auditability

## Matrix
| Agent | Primary responsibility | Required skills | Input source of truth | Output contract/artifact | Target level |
|---|---|---|---|---|---|
| Architect | Ensure coherence and compatibility | contract review, risk analysis, migration planning, anti-regression | docs + contracts + registry + QA reports | architecture decisions, compatibility constraints | L4 |
| App Builder | Build apps from natural language | intent intake, domain modeling, entity/form/grid mapping, requirement checklist | chat intake + domain playbooks + contracts | entity/form/grid contracts + build plan | L3 |
| Operator | Execute runtime operations | intent routing, permission checks, command execution, status reporting | runtime registry + permissions + state | command payloads and user-safe replies | L3 |
| Integration | Convert external APIs to contracts | API doc parsing, adapter contract design, sandbox/prod policy | vendor docs + security policies | integration contracts + adapter configs | L4 |
| Support | Guide non-technical users | progressive guidance, slot filling, fallback questions, troubleshooting | registry + user state + playbooks | contextual steps and actionable next response | L3 |
| Auditor | Enforce trust and traceability | policy validation, action auditing, anomaly detection, compliance checks | audit logs + action logs + contracts | audit reports, policy exceptions, warnings | L4 |

## Mandatory cross-agent rules
- Never claim capability not present in registry/contracts.
- Never bypass execution engine or permission guards.
- Never execute external actions without contract and audit trail.
- Always produce user responses with clear next step.
- Persist reusable learnings in tenant shared memory (`agent_shared_knowledge`) to reduce repeated LLM dependency.

## Conversation quality metrics
- Intent resolution rate (local-first).
- Clarification turns per completed task.
- Loop rate (confirmation/state repetition).
- Wrong-mode response rate (BUILD vs USE leakage).
- Audit completeness rate.

## Engineering skills used by Codex in this repo
- Local skill: `skills/software-engineering-senior/SKILL.md`
- Mandatory flow: retrieval-led -> minimal patch -> QA gate -> report evidence.
