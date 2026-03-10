# Core AGENTS

## Responsibility
- `framework/app/Core` contiene el runtime compartido: router, command bus, repositorios, observabilidad, memoria y servicios cross-module.
- Todo cambio aqui impacta multiples modulos. Mantener cambios incrementales y retro-compatibles.

## Key classes
- `ChatAgent.php`: entrypoint principal de ejecucion chat-first.
- `IntentRouter.php`: seleccion deterministica de ruta.
- `SkillExecutor.php`: orquestacion de skills y tools.
- `TelemetryService.php` + `SqlMetricsRepository.php`: metricas SQL.
- `ImprovementMemoryService.php`: memoria de mejora derivada de AgentOps.
- `LearningPromotionService.php`: promocion controlada de candidatos aprobados a backlog estructurado.
- `SemanticMemoryService.php`: retrieval/vector memory.
- `MediaService.php` y `EntitySearchService.php`: modulos compartidos recientes.

## Contracts involved
- `docs/contracts/action_catalog.json`
- `docs/contracts/skills_catalog.json`
- `docs/contracts/router_policy.json`
- `docs/contracts/agentops_metrics_contract.json`
- `framework/contracts/schemas/*`

## Local rules
- No bypass del router ni de los execution guards.
- Si agregas una capacidad compartida, agrega prueba externa y registrala en `UnitTestRunner.php`.
- Mantener tenant isolation en servicios y repositorios.
