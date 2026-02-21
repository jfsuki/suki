# Conversation Gateway (PHP) - Arquitectura y Uso

Objetivo: resolver conversaciones con costo minimo. Local-first, y LLM solo si es necesario.

## Flujo
1) ConversationGateway clasifica el mensaje (saludo/faq/crud).
2) Si se resuelve localmente, responde y ejecuta Command Layer.
3) Si no se puede, construye Context Capsule minimo.
4) LLMRouter elige proveedor (Groq/Gemini/OpenRouter/Claude).
5) Respuesta JSON pasa a Command Layer o a respuesta humana.

## Mejoras P0/P1/P2 (2026-02-21)
- P0 Dialogo por estados: `DialogStateEngine` para modo `builder` y `app` con checklist vivo.
  - Triggers: "paso actual", "checklist", "en que vamos", "que falta", "que sigue".
  - El estado se sincroniza en cada guardado y despues de ejecuciones reales (`rememberExecution`).
- P1 Entrenamiento por logs + pais:
  - `AgentNurtureJob` nutre `training_overrides.json` y `country_language_overrides.json` por tenant.
  - Aprende aliases de campo, typos frecuentes y variantes locales desde telemetry real.
  - `ConversationGateway` aplica normalizacion por pais en `normalizeWithTraining(...)`.
- P2 Dashboard de calidad conversacional:
  - Nuevo agregador `ConversationQualityDashboard`.
  - Nuevo endpoint: `POST/GET /api/chat/quality?tenant_id=default&days=7`.
  - UI de chat (`chat_builder.html` y `chat_app.html`) muestra exito, no resueltas, repreguntas y top pendientes.

## Archivos principales
- `app/Core/Agents/ConversationGateway.php`
- `app/Core/Agents/DialogStateEngine.php`
- `app/Core/Agents/Telemetry.php`
- `app/Core/Agents/ConversationQualityDashboard.php`
- `app/Jobs/AgentNurtureJob.php`
- `app/Core/LLM/LLMRouter.php`
- `app/Core/LLM/Providers/*`

## Base conversacional (JSON)
Se consolido y conecto al router local:
```
framework/contracts/agents/conversation_training_base.json
```
Contiene intents, entidades, typos, memoria activa y smoke tests.

## Memoria por tenant
Ruta:
```
project/storage/tenants/{tenantId}/
  agent_state/{project}__{mode}__{user}.json
  lexicon.json
  dialog_policy.json
  training_overrides.json
  country_language_overrides.json
  telemetry/YYYY-MM-DD.log.jsonl
```

Memoria compartida:
```
project/storage/chat/research/{tenantId}.json
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

## Guardrails de modo
- En modo app: si el usuario pide crear app/programa/tablas, redirige al chat creador.
- En modo builder: si falta plantilla de negocio, registra tema de investigacion y sigue con pregunta minima.

## UNSPSC (Colombia Compra)
- Base local:
```
framework/contracts/agents/unspsc_co_common.json
```
- Uso: detecta preguntas de clasificador y sugiere codigos comunes por negocio.

## Job de nutricion
Ejecuta `AgentNurtureJob` para sumar aliases:
```
php -r "require 'framework/app/autoload.php'; (new App\\Jobs\\AgentNurtureJob())->run('default');"
```

Salida:
- `added`: aliases nuevos en lexicon.
- `added_utterances`: utterances nuevas para intents.
- `added_country_rules`: reglas nuevas por pais (typos/sinonimos).

## Notas de ahorro tokens
- Local-first siempre.
- Contexto minimo (no historial completo).
- Cache de resultados frecuentes.
