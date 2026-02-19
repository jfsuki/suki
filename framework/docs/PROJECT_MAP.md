# Project Map (Workspace)

## Workspace root
- framework/: kernel distribuible (no contiene archivos del proyecto).
- project/: implementacion del proyecto/app (archivos del usuario).

## Framework (kernel)
- framework/app/Core/Controller.php: view loader.
- framework/app/Core/FormGenerator.php: renders forms/grids/summary.
- framework/app/Core/FormBuilder.php: UI primitives.
- framework/app/Core/TableGenerator.php: table shell for JS data load.
- framework/app/Core/Database.php: PDO connection helper.
- framework/app/Core/QueryBuilder.php: safe query builder (prepared statements).
- framework/app/Core/BaseRepository.php: CRUD base + allowlist + tenant scope.
- framework/app/Core/EntityRegistry.php: loader + validator (entity contracts).
- framework/app/Core/EntityMigrator.php: auto-migrations from entity contracts.
- framework/app/Core/CommandLayer.php: CRUD + validation + audit.
- framework/app/Core/ChatAgent.php: API chat agent (local-first + LLM fallback).
- framework/app/Core/Agents/ConversationGateway.php: routing local, slots, state per tenant.
- framework/app/Core/Agents/Telemetry.php: telemetry JSONL.
- framework/app/Core/Agents/AcidChatRunner.php: test acido conversacional.
- framework/app/Core/LLM/LLMRouter.php + Providers/*: multi-proveedor.
- framework/app/Core/IntegrationRegistry.php: integration contracts.
- framework/app/Core/InvoiceRegistry.php: invoice contracts.
- framework/app/Core/IntegrationStore.php: integraciones/documentos/webhooks.
- framework/app/Core/AlanubeClient.php: cliente API Alanube.
- framework/contracts/agents/conversation_training_base.json: help + intents.
- framework/docs/*: fuente de verdad de contratos y procesos.
- framework/public/editor_json/formjson.html: builder visual (no cambia contratos).

## Project (app)
- project/public/index.php: router de vistas.
- project/public/api.php: router API.
- project/public/assets.php: proxy assets al framework.
- project/public/chat_app.html: chat uso de la app.
- project/public/chat_builder.html: chat creador.
- project/public/chat_gateway.html: UI legacy para simular chat.
- project/views/*: vistas del app.
- project/contracts/app.manifest.json: contrato global del app.
- project/contracts/entities/*.entity.json: contratos de entidades.
- project/contracts/forms/*.json: contratos de formularios.
- project/contracts/integrations/*.integration.json: integraciones externas.
- project/contracts/invoices/*.invoice.json: factura electronica.
- project/storage/tenants/*: memoria conversacional (state, lexicon, policy).
- project/storage/chat/research/*.json: cola de investigacion para dominios de negocio nuevos (memoria compartida de agentes).
- project/storage/reports/*: reportes de pruebas acidas.
- project/.env: variables del proyecto.
