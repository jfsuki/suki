---
name: suki-codex-optimizer
description: Prompt and task optimizer for Codex-style work in SUKI. Use when drafting tasks, reviews, or implementation prompts so they stay bounded, precise, low-noise, validation-ready, and architecture-aligned.
---

# SUKI Codex Optimizer

## Optimize for precision

- Bound the task clearly.
- Name the exact files to inspect or change.
- State non-goals explicitly.
- Ask for specific validation commands and expected outputs.

## Keep token usage low

- load only relevant SUKI docs,
- reference canons instead of repeating them,
- prefer short acceptance criteria,
- ask for exact return sections when format matters.

## Prompt ingredients

Include:
- objective
- mandatory laws
- files to review first
- allowed scope
- validation required
- expected output format

## Good SUKI prompt traits

- incremental, not rewrite-heavy
- contract-aware
- multitenant-safe
- router-aware
- observability-aware
- honest about pass/fail

## Avoid

- vague "improve everything" requests
- hidden runtime changes
- requests that bypass `skills -> CommandBus -> PHP kernel`
- prompts that omit validation
- prompts that force silent conflict resolution when canon drift exists

## Fast template

Use this shape:
- context
- objective
- mandatory rules
- files to inspect
- implementation constraints
- expected output
- validation
