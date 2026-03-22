---
name: suki-architect-reasoning
description: Deep architectural reasoning for SUKI changes. Use when evaluating impact before coding or reviewing changes that may affect multitenancy, router fit, contracts, observability, storage, agents, or backward compatibility.
---

# SUKI Architect Reasoning

## Reason before coding

For every non-trivial task, evaluate:
- which SUKI layer is affected,
- whether the request is build-time, runtime, governance, or learning-time,
- whether the change touches contracts, router, agents, memory, simulation, or observability.

## Mandatory review questions

- Does this preserve `LLM interprets / PHP executes`?
- Does this preserve multitenant isolation by `tenant_id` and app/project scope?
- Does this fit the active router and skills path without bypassing guards?
- Does this reuse existing canons, contracts, and services instead of creating parallel logic?
- Does this emit enough observability for Control Tower and AgentOps?
- Does this stay additive and backward-compatible?

## Architecture checkpoints

- Verify contract impact before implementation.
- Verify storage impact before adding schema or files.
- Verify operational scope before proposing memory, cache, or logs.
- Verify builder/app mode boundaries before suggesting execution.
- Verify whether simulation should gate deployment or rollout.

## Output discipline

When proposing a change, state:
- the impacted layer,
- the invariant being preserved,
- the main risk,
- the smallest safe implementation path,
- the required validation.

## Red flags

- rigid regex or `in_array` lists for Natural Language Processing (use Qdrant + LLM JSON)
- new execution path outside `skills -> CommandBus -> PHP kernel`
- cross-tenant reads or writes
- direct SQL in app logic
- silent contract renames
- hidden schema growth
- missing drift documentation where sources disagree
