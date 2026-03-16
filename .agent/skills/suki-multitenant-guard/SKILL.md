---
name: suki-multitenant-guard
description: Multitenant safety guard for SUKI. Use when a task touches data scope, memory, RAG, credentials, sector packs, reusable apps, storage, or any artifact that could mix tenant boundaries.
---

# SUKI Multitenant Guard

## Mandatory scope discipline

- Keep `tenant_id` mandatory in operational scope.
- Preserve `tenant_id`, `project_id` or `app_id`, `mode`, and `user_id` in request-time envelopes when applicable.
- Reject designs that can read or write across tenants.

## Isolation rules

- Tenant operational data stays tenant-scoped.
- Tenant configuration, AKP memory, credentials, and overlays stay isolated.
- User memory stays isolated by tenant, project/app, mode, and user.
- Shared canons, sector packs, and reusable templates stay tenant-free.

## Reusable asset safety

- Reusable apps contain zero tenant operational data.
- Sector packs may include reusable patterns, never tenant records.
- Production learning may promote abstract patterns only.

## RAG and memory boundaries

- Use tenant-scoped retrieval for tenant context.
- Do not expose raw tenant memory beyond the active request need.
- Do not promote raw tenant data into shared vector knowledge.
- Require hygiene and approval before any shared-memory promotion.

## Storage and schema guard

- Prefer shared multitenant structures before new tables.
- Add new tables only with explicit justification and governance.
- Ensure indices and storage design remain tenant-first.

## Safe response rule

- If scope is ambiguous, stop and clarify scope before proposing execution.
