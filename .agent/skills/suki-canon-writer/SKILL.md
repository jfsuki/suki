---
name: suki-canon-writer
description: Canon-writing guidance for SUKI governance documents. Use when creating or updating docs under /docs/canon or related architectural references so the result stays additive, precise, and drift-aware.
---

# SUKI Canon Writer

## Writing stance

- Write canons as architectural law, not as speculative design fiction.
- Extend existing governance additively.
- Do not replace stable canons wholesale unless explicitly required.

## Canon content rules

When relevant, include:
- Purpose
- Scope
- Canonical responsibilities
- Inputs and outputs
- Invariants or non-negotiable rules
- Safety boundaries
- Integration with other SUKI layers
- Failure modes
- Governance or observability notes
- `Detected Canon Drift`

## Drift rule

- If canon, contract, and runtime differ, document the drift explicitly.
- Do not silently resolve drift in prose.
- State the safe interpretation for today without claiming a runtime change.

## Source discipline

- Reconcile with `framework/docs/INDEX.md` first.
- Prefer canonical docs, machine-readable contracts, and active runtime references over memory alone.
- Keep statements implementation-safe and traceable to repo evidence.

## Style rules

- Keep language precise and compact.
- Separate law from implementation detail.
- Avoid fake class names, fake workers, or invented persistence unless clearly marked as future extension.
