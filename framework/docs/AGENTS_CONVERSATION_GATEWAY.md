# Conversation Gateway (PHP) - Arquitectura y Uso

Objetivo: resolver conversaciones con costo minimo. Local-first, y LLM solo si es necesario.

## Flujo
1) ConversationGateway clasifica el mensaje (saludo/faq/crud).
2) Si se resuelve localmente → responde y ejecuta Command Layer.
3) Si no se puede → construye Context Capsule minimo.
4) LLMRouter elige proveedor (Groq/Gemini/OpenRouter/Claude).
5) Respuesta JSON → Command Layer o respuesta humana.

## Archivos principales
- `app/Core/Agents/ConversationGateway.php`
- `app/Core/Agents/Telemetry.php`
- `app/Jobs/AgentNurtureJob.php`
- `app/Core/LLM/LLMRouter.php`
- `app/Core/LLM/Providers/*`

## Memoria por tenant
Ruta:
```
project/storage/tenants/{tenantId}/
  agent_state/{userId}.json
  lexicon.json
  dialog_policy.json
  telemetry/YYYY-MM-DD.log.jsonl
```

## Context Capsule minimo
```
{
  "intent": "create|update|delete|list|question",
  "entity": "cliente",
  "entity_contract_min": { "required": ["nombre"], "types": {"nombre":"string"} },
  "state": { "collected": {}, "missing": [] },
  "user_message": "mensaje",
  "policy": { "requires_strict_json": true, "latency_budget_ms": 1200, "max_output_tokens": 400 }
}
```

## Comandos locales (sin LLM)
- crear cliente nombre=Juan nit=123
- listar cliente
- actualizar cliente id=1 email=juan@mail.com
- eliminar cliente id=1
- crear tabla productos nombre:texto precio:numero
- crear formulario productos
- probar sistema

## Job de nutricion
Ejecuta `AgentNurtureJob` para sumar aliases:
```
php -r "require 'framework/app/autoload.php'; (new App\\Jobs\\AgentNurtureJob())->run('default');"
```

## Notas de ahorro tokens
- Local-first siempre.
- Contexto minimo (no historial completo).
- Cache de resultados frecuentes.
