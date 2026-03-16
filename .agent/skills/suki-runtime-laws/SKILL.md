---
name: suki-runtime-laws
description: Runtime law pack for SUKI. Use when a task touches request handling, execution authority, router behavior, skills, CommandBus, kernel paths, or deployment safety gates.
---

# SUKI Runtime Laws

## Execution authority

- LLM interprets, normalizes, and proposes.
- PHP kernel validates, calculates, persists, and executes.
- Never place business math, final validation, or side effects in the LLM layer.

## Active runtime path

Use this governed execution sequence:
1. `cache`
2. `rules`
3. `skills`
4. `rag`
5. `llm`

Execution path for side effects:
1. router decision
2. skill selection
3. command composition
4. `CommandBus`
5. PHP kernel services, repositories, adapters
6. audit and telemetry

## Non-negotiable runtime rules

- No raw SQL in app/business logic.
- No agent may bypass execution guards.
- No side effect may run without a governed action or command.
- Missing critical data means one critical clarification, not a guess.
- Unsupported capability means deny or redirect, never fake success.

## Mode boundaries

- APP mode operates business data only.
- BUILDER mode changes structure only.
- APP mode must not create schema.
- BUILDER mode must not execute business CRUD.

## Deployment safety

- Simulation is a mandatory safety gate before deployment.
- Simulation must use the same PHP kernel rules as production.
- A blocked simulation blocks deployment.

## Drift handling

- If canon and runtime differ, document the drift explicitly.
- Do not "repair" drift by inventing a new route in prompts or docs.
