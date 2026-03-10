# Architecture Index

## Core services
- Chat runtime
  - purpose: entrypoint chat-first, routing, command dispatch, tenant/project/session binding
  - main files: `framework/app/Core/ChatAgent.php`, `framework/app/Core/IntentRouter.php`, `framework/app/Core/CommandBus.php`
  - dependencies: `ProjectRegistry`, `ConversationGateway`, `TelemetryService`, `docs/contracts/*`
- Shared memory
  - purpose: conversational state, semantic retrieval, improvement memory, learning promotion and registry-backed context
  - main files: `framework/app/Core/SqlMemoryRepository.php`, `framework/app/Core/SemanticMemoryService.php`, `framework/app/Core/ImprovementMemoryService.php`, `framework/app/Core/LearningPromotionService.php`
  - dependencies: `project/storage/meta/project_registry.sqlite`, `QdrantVectorStore`, `docs/contracts/semantic_memory_payload.json`
- Observability
  - purpose: AgentOps telemetry, SQL metrics, supervisor evaluation, improvement candidate generation
  - main files: `framework/app/Core/Agents/Telemetry.php`, `framework/app/Core/TelemetryService.php`, `framework/app/Core/AgentOpsSupervisor.php`, `framework/app/Core/SqlMetricsRepository.php`
  - dependencies: `docs/contracts/agentops_metrics_contract.json`, `ImprovementMemoryRepository`

## Modules
- POS
  - purpose: tickets, sales-facing flows, inventory-adjacent CRUD via shared engine
  - main files: `framework/contracts/forms/ticket_pos.contract.json`, `project/contracts/entities/*`, `framework/app/Modules/POS/AGENTS.md`
  - dependencies: `ChatAgent`, `CrudCommandHandler`, `EntitySearchService`, `MediaService`
- Purchases
  - purpose: supplier-side operational flows and document-backed procurement records
  - main files: `project/contracts/entities/*`, `project/contracts/invoices/*`
  - dependencies: `CrudCommandHandler`, `MediaService`, `EntitySearchService`
- Fiscal Engine
  - purpose: invoice contracts, numbering and fiscal-safe operational flows
  - main files: `project/contracts/invoices/*`, `framework/docs/16_INVOICE_CONTRACT.md`
  - dependencies: `ChatAgent`, `IntentRouter`, `EntitySearchService`
- Ecommerce Hub
  - purpose: third-party commerce integrations and channel adapters
  - main files: `framework/app/Core/AlanubeClient.php`, `framework/app/Core/IntegrationHttpClient.php`, `framework/app/Core/OpenApiIntegrationImporter.php`, `framework/app/Modules/Ecommerce/AGENTS.md`
  - dependencies: `project/contracts/integrations/*`, `ChatAgent`, `IntentRouter`
- Media/Documents
  - purpose: tenant-isolated files for products, invoices and operational entities
  - main files: `framework/app/Core/MediaRepository.php`, `framework/app/Core/MediaService.php`, `framework/app/Core/MediaCommandHandler.php`
  - dependencies: `docs/contracts/action_catalog.json`, local storage, `AuditLogger`
- Entity Search
  - purpose: deterministic entity resolution across modules before guessing
  - main files: `framework/app/Core/EntitySearchRepository.php`, `framework/app/Core/EntitySearchService.php`, `framework/app/Core/EntitySearchCommandHandler.php`
  - dependencies: `EntityRegistry`, `MediaRepository`, `docs/contracts/skills_catalog.json`
- AgentOps
  - purpose: traceability, KPIs, runtime supervision, improvement signals
  - main files: `framework/app/Core/AgentOpsSupervisor.php`, `framework/app/Core/Agents/Telemetry.php`, `framework/app/Core/TelemetryService.php`
  - dependencies: `docs/contracts/agentops_metrics_contract.json`, `SqlMetricsRepository`, `ImprovementMemoryService`
- Semantic Memory
  - purpose: Qdrant-backed retrieval, training memory, vectorized domain knowledge
  - main files: `framework/app/Core/SemanticMemoryService.php`, `framework/app/Core/QdrantVectorStore.php`
  - dependencies: embeddings provider, `docs/contracts/semantic_memory_payload.json`

## Contracts
- Action catalog
  - purpose: schema-first tool/action registry for execution engine
  - main files: `docs/contracts/action_catalog.json`
  - dependencies: `SkillExecutor`, `IntentRouter`, command handlers
- Skills catalog
  - purpose: skill discovery and tool orchestration rules
  - main files: `docs/contracts/skills_catalog.json`
  - dependencies: `SkillResolver`, `SkillExecutor`
- Router policy
  - purpose: deterministic routing policy before LLM fallback
  - main files: `docs/contracts/router_policy.json`
  - dependencies: `IntentRouter`, `AgentOpsSupervisor`
- AgentOps metrics contract
  - purpose: minimum runtime observability fields and KPI vocabulary
  - main files: `docs/contracts/agentops_metrics_contract.json`
  - dependencies: `ChatAgent`, `Agents/Telemetry`, `TelemetryService`

## Router
- Gateway + router
  - purpose: stateful dialogue handling and route selection
  - main files: `framework/app/Core/Agents/ConversationGateway.php`, `framework/app/Core/IntentRouter.php`
  - dependencies: `SqlMemoryRepository`, contracts, `ProjectRegistry`
- Router output execution
  - purpose: execute local responses, skills, commands or llm fallback
  - main files: `framework/app/Core/ChatAgent.php`, `framework/app/Core/SkillExecutor.php`, `framework/app/Core/CommandBus.php`
  - dependencies: action catalog, skills catalog, module handlers

## Skills
- Skill resolution
  - purpose: map utterances to deterministic tools before LLM
  - main files: `framework/app/Core/SkillResolver.php`, `framework/app/Core/SkillRegistry.php`
  - dependencies: `docs/contracts/skills_catalog.json`
- Skill execution
  - purpose: execute tool payloads and normalize safe fallbacks
  - main files: `framework/app/Core/SkillExecutor.php`
  - dependencies: `CommandBus`, action catalog, module handlers

## Tools
- CRUD + builders
  - purpose: create/use entities and forms without bypassing contracts
  - main files: `framework/app/Core/CrudCommandHandler.php`, `CreateEntityCommandHandler.php`, `CreateFormCommandHandler.php`
  - dependencies: `project/contracts/*`, `Database`, `EntityRegistry`
- Module tools
  - purpose: shared tools for alerts, media, entity search and integrations
  - main files: `framework/app/Core/AlertsCenterCommandHandler.php`, `MediaCommandHandler.php`, `EntitySearchCommandHandler.php`, `ImportIntegrationOpenApiCommandHandler.php`
  - dependencies: `docs/contracts/action_catalog.json`, module services

## Observability
- Runtime telemetry
  - purpose: append tenant-scoped AgentOps JSONL logs
  - main files: `framework/app/Core/Agents/Telemetry.php`
  - dependencies: `project/storage/tenants/{tenant}/telemetry/*`
- SQL metrics
  - purpose: compact KPIs for latency, fallbacks, blocked commands and token usage
  - main files: `framework/app/Core/SqlMetricsRepository.php`, `framework/app/Core/TelemetryService.php`
  - dependencies: `project/storage/meta/project_registry.sqlite`
- Improvement memory
  - purpose: convert recurring AgentOps issues into tracked improvements, learning candidates and promoted proposals
  - main files: `framework/app/Core/ImprovementMemoryRepository.php`, `framework/app/Core/ImprovementMemoryService.php`, `framework/app/Core/LearningPromotionService.php`
  - dependencies: `Agents/Telemetry`, `SqlMetricsRepository`

## Tests
- Core regression suite
  - purpose: smoke/unit coverage for routing, security, contracts and modules
  - main files: `framework/tests/run.php`, `framework/app/Core/UnitTestRunner.php`
  - dependencies: external test scripts under `framework/tests/*.php`
- Chat and DB gates
  - purpose: required QA gates before closing backend/chat tasks
  - main files: `framework/tests/chat_acid.php`, `framework/tests/chat_golden.php`, `framework/tests/db_health.php`
  - dependencies: runtime env, sqlite/mysql config, contracts
