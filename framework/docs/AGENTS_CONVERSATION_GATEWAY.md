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

## Base conversacional (JSON)
Se consolidó y conectó al router local:
```
framework/contracts/agents/conversation_training_base.json
```
Contiene intents, entidades, typos, memoria activa y smoke tests.  
El gateway usa esta base para clasificar intents como **estado del proyecto**, **tablas**, **formularios** y **qué puedo hacer** sin llamar a IA.

## Auto‑nutrición (telemetry → training_overrides)
El job `AgentNurtureJob` analiza telemetry reciente y genera:
```
project/storage/tenants/{tenantId}/training_overrides.json
```
Estas utterances se mezclan con el training base sin reescribirlo.

## Memoria por tenant
Ruta:
```
project/storage/tenants/{tenantId}/
  agent_state/{userId}.json
  lexicon.json
  dialog_policy.json
  telemetry/YYYY-MM-DD.log.jsonl
```

Memoria compartida entre agentes (por tenant):
```
project/storage/chat/research/{tenantId}.json
```
Uso: cuando el usuario pide un tipo de app que no tiene plantilla exacta, se registra el caso para investigacion y se reutiliza en futuras conversaciones.

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
- En **modo app**: si el usuario pide crear app/programa/tablas, el agente lo redirige al chat creador.
- En **modo builder**: si falta plantilla de negocio, el agente registra tema de investigacion y sigue con una pregunta minima.

## UNSPSC (Colombia Compra)
- Base local:
```
framework/contracts/agents/unspsc_co_common.json
```
- Uso en chat:
  - Detecta preguntas: `unspsc`, `clasificador`, `codigo producto/servicio`.
  - Sugiere codigos comunes por coincidencia de texto (alias comercial) o por tipo de negocio.
  - Refuerza campo `codigo_unspsc` en tablas de `productos/servicios`.
  - Regla: siempre validar el codigo final en el clasificador oficial antes de facturar.

## Ruta tecnica guiada (builder)
El agente puede explicar por chat una ruta profesional de construccion:
- logica de programacion (validaciones, prerequisitos, 1 pregunta minima)
- arquitectura de base de datos (tenant_id, indices, maestro-detalle)
- secuencia de entrega (tablas -> formularios -> pruebas -> reportes)

## Job de nutricion
Ejecuta `AgentNurtureJob` para sumar aliases:
```
php -r "require 'framework/app/autoload.php'; (new App\\Jobs\\AgentNurtureJob())->run('default');"
```

## Notas de ahorro tokens
- Local-first siempre.
- Contexto minimo (no historial completo).
- Cache de resultados frecuentes.

## Prompts y entrenamiento (resumen)
- **Agent Training Prompt**: JSON-first, cambios incrementales, cero tecnicismos, IA solo fallback.
- **ConversationTrainer**: mejora intents/utterances/sinónimos sin reescribir; agrega pruebas smoke.
- **Memory Active Profile**: mantener objetivo, contexto del proyecto y preferencias del usuario; 1 pregunta mínima.
- **Auto‑training flow**: usa telemetry para nutrir lexicon/utterances y reducir llamadas LLM.
