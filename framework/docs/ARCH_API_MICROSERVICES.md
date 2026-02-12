# ARCH_API_MICROSERVICES

## API-first principle
Everything created by the builder must run via API. Chat is just another client.

## Layers
1) Contract Registry
- Catalog of apps/forms/grids/contracts with versions.
- Dependency graph and "where used".

2) Runtime Engine
- CRUD, validation, formula engine, permission checks.
- Uses Kernel DB (QueryBuilder/Repository).

3) Command Layer (chat core)
- Commands: CreateApp, CreateTable, CreateField, CreateForm,
  CreateRecord, UpdateRecord, QueryRecords, RenderView.
- Each command validates against contracts + permissions.

4) Process Engine
- Workflow definitions (triggers, actions, async).
- Shared by UI and chat.

## Microservice boundaries (future)
- Schema/DB service
- Runtime service
- Auth/RBAC service
- Files service
- Accounting service
- DIAN e-invoicing service

## Transport
- REST + optional async (queue).

## Contract versioning
- Every contract has version + checksum; registry stores history.
