# SUKI AGENT CODING CANON
# Versión: 1.0 — 2026-03-21
# Fuente: Patrones de CrewAI, AutoGen, LangGraph adaptados a SUKI AI-AOS
# Ubicación sugerida: docs/canon/SUKI_AGENT_CODING_CANON.md
#
# LEY SUPREMA: Este archivo define cómo piensan, codifican y se comunican
# todos los agentes (Windsurf, Codex, Claude, Gemini) al trabajar en SUKI.
# Cualquier agente que lo ignore está cometiendo un error de arquitectura.
# ============================================================================

---

## 0. PREÁMBULO — POR QUÉ EXISTE ESTE ARCHIVO

Durante el desarrollo de SUKI se detectó un patrón de regresión crítico:
los agentes de codificación (Codex, Claude en Windsurf) tendían a resolver
problemas de lenguaje natural con condicionales PHP rígidos (`if/else`,
`preg_match`, arrays de palabras clave), aunque el proyecto ya tenía
infraestructura LLM y Qdrant disponible.

Resultado: más de 50 condicionales rígidos en producción que bloqueaban
el 80% del lenguaje natural del usuario antes de que cualquier NLP actuara.

Este archivo existe para que ESO NUNCA VUELVA A PASAR.

---

## 1. LAS 7 LEYES DEL AGENTE SUKI

Todo agente que codifique en SUKI debe seguir estas leyes sin excepción.

### LEY 1 — LLM TRADUCE, PHP EJECUTA

```
Usuario → LLM (traductor) → JSON estricto → PHP (validador + ejecutor)
```

El LLM nunca ejecuta lógica de negocio. PHP nunca interpreta lenguaje natural.
Esta separación es absoluta e irrompible.

Correcto:
```php
$parsed = $this->fastParser->parse($userText, $step, $allowedIntents);
if ($parsed['intent'] === 'set_business_type') {
    $this->persistBusinessType($parsed['mapped_fields']['business_type']);
}
```

Incorrecto:
```php
if (str_contains($userText, 'ferretería') || str_contains($userText, 'tienda')) {
    $businessType = 'retail';
}
```

### LEY 2 — SEMANTIC FIRST, REGEX LAST

El orden de clasificación de intenciones es SIEMPRE:

```
1. Cache de intents conocidos       (0ms, $0)
2. Qdrant cosine similarity         (~2ms, $0)
3. Fast Parser LLM (qwen/deepseek)  (<600ms, ~$0.0001)
4. Fallback PHP hardcodeado         (0ms, $0) ← NUNCA null
```

Las regex (`preg_match`) están PROHIBIDAS para clasificar intenciones de negocio.
Solo se permiten para:
- Tokens de seguridad (`/reset`, `/admin`, CSRF)
- Normalización superficial de typos obvios
- Comandos de sistema explícitos con `/`

### LEY 3 — PROMPT EN ARCHIVO, NO EN CÓDIGO

Ningún prompt de LLM vive embebido en PHP como string.

Correcto:
```
framework/app/Core/Prompts/builder_fast_path_prompt.json
framework/app/Core/Prompts/operator_intent_prompt.json
framework/app/Core/Prompts/support_clarify_prompt.json
```

Formato obligatorio de cada archivo de prompt:
```json
{
  "version": "1.0",
  "role": "builder_fast_parser",
  "system": "Eres un clasificador de intenciones para onboarding ERP...",
  "user_template": "Step: {step}\nInput: {user_text}\nAllowed: {intents}\nJSON only:",
  "constraints": {
    "max_tokens": 220,
    "temperature": 0.1,
    "timeout_ms": 2500
  }
}
```

Esto permite versionar prompts sin tocar código PHP.

### LEY 4 — JSON ESTRICTO CON EJEMPLOS NEGATIVOS

Todo output de LLM en SUKI tiene un schema validado en PHP.
El schema SIEMPRE incluye ejemplos de lo que NO es válido.

Schema mínimo obligatorio:
```json
{
  "intent": "string — del catálogo permitido del paso",
  "mapped_fields": "object — solo keys del paso actual",
  "reply": "string — máx 180 chars, nunca null",
  "confidence": "float — entre 0.0 y 1.0",
  "needs_clarification": "boolean"
}
```

PHP debe rechazar silenciosamente y usar fallback si:
- El LLM devuelve texto libre (no JSON)
- El JSON tiene campos fuera del schema
- `reply` es null o vacío
- `confidence` está fuera de [0.0, 1.0]
- `mapped_fields` tiene keys de otro paso

### LEY 5 — CONTEXTO MÍNIMO AL LLM

Inspirado en AutoGen: cada llamada al LLM recibe SOLO lo necesario para ese turno.

PROHIBIDO enviar al LLM:
- Historial completo de conversación
- Toda la metadata del tenant
- Chunks RAG cuando no son necesarios
- El estado completo del ERP

PERMITIDO enviar al LLM (Fast Path):
```json
{
  "step": "business_type",
  "user_text": "vendo herramientas y taladros",
  "known_fields": {"sector": null},
  "missing_fields": ["business_type", "operation_model"],
  "allowed_intents": ["set_business_type", "ask_user", "frustration_help"]
}
```

Límite: prompt total < 350 tokens para Fast Path.

### LEY 6 — FALLBACK CHAIN NUNCA ROMPE

Inspirado en CrewAI: toda llamada LLM tiene cadena de fallback definida.

```
OpenRouter (qwen/qwen3-coder-next)
  → si falla → DeepSeek API directo
    → si falla → Mistral API
      → si falla → PHP hardcoded humano (nunca null, nunca stacktrace)
```

El usuario NUNCA ve:
- Mensajes de error técnicos
- Stack traces
- "null" o respuesta vacía
- Timeouts sin respuesta

### LEY 7 — CAMBIO MÍNIMO, EVIDENCIA REAL

Inspirado en LangGraph: cada cambio es un nodo del grafo con entrada y salida verificable.

```
cambio mínimo → prueba mínima → evidencia → commit → siguiente cambio
```

PROHIBIDO:
- Cambiar más de 3 archivos en un solo task sin evidencia intermedia
- Agregar nueva abstracción sin test que la cubra
- Refactorizar código sano para "mejorar estructura"
- Hacer mega-refactor en lugar de cambio incremental

---

## 2. ARQUITECTURA DE 3 CAPAS (LA ÚNICA PERMITIDA)

```
┌─────────────────────────────────────────────────────────────┐
│  CAPA 1 — SEMANTIC CLASSIFIER (Qdrant)                       │
│  Input: user_text                                            │
│  Proceso: embed → cosine similarity → colección de intents   │
│  Score >= 0.82 → intent clasificado (sin LLM)                │
│  Score < 0.82  → pasa a Capa 2                               │
│  Costo: $0 | Latencia: ~2ms                                  │
└─────────────────────────────────────────────────────────────┘
         ↓ solo si score < 0.82
┌─────────────────────────────────────────────────────────────┐
│  CAPA 2 — FAST PATH SOFT PARSER (LLM barato)                 │
│  Input: contexto mínimo (ver Ley 5)                          │
│  Proceso: qwen/qwen3-coder-next → JSON estricto              │
│  Valida schema v3 → si inválido → fallback                   │
│  Costo: ~$0.0001 | Latencia: <600ms                          │
└─────────────────────────────────────────────────────────────┘
         ↓ siempre
┌─────────────────────────────────────────────────────────────┐
│  CAPA 3 — PHP KERNEL EXECUTOR                                │
│  Input: JSON validado                                        │
│  Proceso: validar → CommandBus → persistir → responder       │
│  El LLM NUNCA llega aquí. PHP es el único ejecutor.          │
│  Costo: $0 | Latencia: síncrono                              │
└─────────────────────────────────────────────────────────────┘
```

### Colección Qdrant obligatoria: `builder_intent_signatures`

| Intent | Ejemplos semilla |
|--------|-----------------|
| greeting | "hola", "buenos días", "hey qué tal" |
| farewell | "chao", "hasta luego", "me voy" |
| frustration | "no entiendo", "me perdí", "me rindo" |
| affirmation | "sí exacto", "ajá", "correcto", "dale" |
| negation | "no", "eso no", "me equivoqué" |
| business_description | "vendo ropa", "tengo una ferretería" |
| create_request | "crear una app", "quiero un sistema" |
| scope_question | "qué necesito", "qué módulos" |
| document_request | "facturas", "contratos", "remisiones" |
| help_request | "ayuda", "cómo funciona", "explícame" |
| ambiguous | "algo así", "tal vez", "más o menos" |
| out_of_scope | "quién es el presidente", "dime un chiste" |

---

## 3. MAPA DE MÓDULOS CANÓNICO

El agente DEBE conocer estos módulos antes de codificar:

| Módulo | Archivo | Responsabilidad |
|--------|---------|----------------|
| `BuilderFastPathParser` | `Core/Agents/BuilderFastPathParser.php` | Capa 1 + Capa 2 del builder |
| `ConversationGateway` | `Core/Agents/ConversationGateway.php` | Coordinador central |
| `HandlePipelineTrait` | `Core/Agents/ConversationGatewayHandlePipelineTrait.php` | Guards de entrada |
| `BuilderOnboardingTrait` | `Core/Agents/ConversationGatewayBuilderOnboardingTrait.php` | Flujo onboarding |
| `IntentRouter` | `Core/IntentRouter.php` | Router ERP (NO tocar para builder casual) |
| `LLMRouter` | `Core/LLM/LLMRouter.php` | Llamadas LLM con fallback |
| `QdrantVectorStore` | `Core/QdrantVectorStore.php` | Embeddings y similitud |
| `CommandBus` | `Core/CommandBus.php` | Ejecución de comandos PHP |
| Prompts | `Core/Prompts/*.json` | Prompts versionados externos |

**REGLA**: El agente lee estos archivos ANTES de escribir código.
**REGLA**: El agente NO modifica `IntentRouter.php` para casos de builder/onboarding.
**REGLA**: El agente NO toca módulos ERP de runtime para casos conversacionales.

---

## 4. ANTI-PATRONES PROHIBIDOS

Estos patrones están EXPLÍCITAMENTE PROHIBIDOS en SUKI.
Si un agente los produce, debe revertir y usar el patrón correcto.

### ❌ PROHIBIDO: Regex para clasificar intención de negocio
```php
// NUNCA hacer esto
if (preg_match('/\b(ferretería|tienda|negocio)\b/u', $text)) {
    $intent = 'business';
}
```
✅ Correcto: Qdrant cosine similarity o Fast Parser LLM.

### ❌ PROHIBIDO: Array hardcodeado de palabras clave
```php
// NUNCA hacer esto
$greetings = ['hola', 'buenos días', 'saludos', 'hey'];
if (in_array(strtolower($text), $greetings)) { ... }
```
✅ Correcto: builder_intent_signatures en Qdrant, intent=greeting.

### ❌ PROHIBIDO: Retornar null al router ERP
```php
// NUNCA hacer esto — el IntentRouter bloqueará con "falta evidencia"
return null;
```
✅ Correcto: siempre retornar fallback estructurado con `action=ask_user`.

### ❌ PROHIBIDO: Prompt embebido en PHP
```php
// NUNCA hacer esto
$prompt = "Eres un asistente de SUKI. El usuario dijo: " . $text . "...";
```
✅ Correcto: cargar desde `Core/Prompts/builder_fast_path_prompt.json`.

### ❌ PROHIBIDO: Enviar historial completo al LLM Fast Path
```php
// NUNCA hacer esto
$capsule['history'] = $this->getFullConversationHistory($tenantId);
```
✅ Correcto: solo step + user_text + known_fields + allowed_intents.

### ❌ PROHIBIDO: Mega-refactor sin evidencia
```
// NUNCA hacer esto
"Voy a refactorizar ConversationGateway.php completo para mejorar la estructura"
```
✅ Correcto: cambio mínimo → prueba → evidencia → commit → siguiente.

### ❌ PROHIBIDO: LLM ejecuta lógica de negocio
```php
// NUNCA hacer esto — el LLM no puede persistir, calcular ni validar
$llmResponse = $this->llm->execute("Guarda la factura de Juan por $500");
```
✅ Correcto: LLM devuelve intent + params → PHP ejecuta via CommandBus.

---

## 5. SCHEMA JSON CANÓNICO v3

Este es el único schema aceptado para output del Fast Parser en modo builder.

```json
{
  "intent": "set_business_type | set_operation_model | set_scope | set_documents | confirm | adjust | frustration_help | create_table | create_form | ask_user | unknown",
  "mapped_fields": {
    "NOTA": "Solo keys permitidas del paso actual. Ver sección 6."
  },
  "reply": "String humano, máx 180 chars, nunca null ni vacío",
  "confidence": 0.91,
  "needs_clarification": false
}
```

### Keys permitidas por paso:

| Paso | Keys permitidas en `mapped_fields` |
|------|------------------------------------|
| `business_type` | `business_type`, `sector` |
| `operation_model` | `operation_model`, `payment_type` |
| `needs_scope` | `needs`, `scope_items` |
| `documents` | `documents`, `doc_types` |
| `confirm` | `confirmed` (boolean) |

PHP rechaza silenciosamente cualquier key fuera de esta tabla.

---

## 6. FORMATO DE PROMPT CANÓNICO

Basado en CrewAI (separación de roles) + AutoGen (contexto mínimo):

```json
{
  "version": "1.0",
  "role": "builder_fast_parser",
  "system": "Eres un clasificador JSON de intenciones para onboarding ERP. SOLO devuelves JSON válido. NUNCA texto libre. NUNCA campos fuera del schema. Si no entiendes, devuelve intent=unknown con needs_clarification=true.",
  "user_template": "Step: {step}\nUser: {user_text}\nKnown: {known_fields}\nMissing: {missing_fields}\nAllowed intents: {allowed_intents}\nResponde SOLO con JSON válido:",
  "negative_examples": [
    {
      "bad_output": "Claro, el usuario tiene una ferretería y voy a registrarlo.",
      "reason": "Texto libre prohibido"
    },
    {
      "bad_output": "{\"intent\":\"set_business_type\",\"admin\":true}",
      "reason": "Campo no permitido en schema"
    }
  ],
  "constraints": {
    "max_tokens": 220,
    "temperature": 0.1,
    "timeout_ms": 2500,
    "model_primary": "qwen/qwen3-coder-next",
    "model_fallback_1": "deepseek-chat",
    "model_fallback_2": "mistral/mistral-small",
    "on_all_fail": "php_hardcoded_reply"
  }
}
```

---

## 7. CHECKLIST PRE-CODIFICACIÓN

El agente responde estas preguntas ANTES de escribir código:

```
[ ] ¿Leí .windsurfrules?
[ ] ¿Leí los archivos relevantes del mapa de módulos (Sección 3)?
[ ] ¿El cambio toca máximo 3 archivos?
[ ] ¿Estoy usando Qdrant semántico en lugar de regex para intenciones?
[ ] ¿El prompt del LLM vive en Core/Prompts/*.json?
[ ] ¿El JSON output tiene schema validado en PHP?
[ ] ¿Tengo fallback chain definido (nunca null)?
[ ] ¿El LLM solo traduce y PHP solo ejecuta?
[ ] ¿Tengo al menos una prueba para el cambio?
[ ] ¿El cambio es incremental (no mega-refactor)?
```

Si alguna respuesta es NO, corregir antes de continuar.

---

## 8. CHECKLIST POST-CODIFICACIÓN

Antes de hacer commit:

```
[ ] php framework/scripts/codex_self_check.php --strict → PASS
[ ] php framework/tests/run.php → PASS
[ ] php framework/tests/chat_acid.php → PASS
[ ] Evidencia multi-turno real documentada
[ ] Ningún stacktrace visible al usuario
[ ] Ningún null retornado al IntentRouter desde builder
[ ] Git commit con mensaje trazable
```

---

## 9. GUÍA DE INTEGRACIÓN DE MODELOS LLM

Todos los modelos se configuran en `.env` (nunca en código).

### Jerarquía de modelos por caso de uso:

| Caso | Modelo primario | Fallback 1 | Fallback 2 |
|------|----------------|------------|------------|
| Fast Path / Soft Parsing | `qwen/qwen3-coder-next` | `deepseek-chat` | `mistral/mistral-small` |
| Análisis profundo / Slow Path | `deepseek-chat` | `qwen/qwen3-coder-next` | `gemini-1.5-pro` |
| Embeddings | `gemini-embedding-001` | `text-embedding-ada-002` | local |
| Builder sector desconocido | `gemini-1.5-flash` | `deepseek-chat` | hardcoded |

### Parámetros obligatorios por caso:

| Caso | temperature | max_tokens | timeout_ms |
|------|------------|------------|------------|
| Fast Path | 0.1 | 220 | 2500 |
| Slow Path | 0.3 | 800 | 8000 |
| Embeddings | — | — | 3000 |

---

## 10. REFERENCIA RÁPIDA — QUÉ HACER CUANDO...

| Situación | Acción correcta |
|-----------|----------------|
| Usuario escribe con typos | Capa 1 Qdrant → si score < 0.82 → Capa 2 Fast Parser |
| Usuario frustrado | Capa 1 clasifica `frustration` → reply empático sin LLM |
| Usuario dice algo ambiguo | Fast Parser devuelve `needs_clarification: true` |
| LLM devuelve texto libre | PHP descarta, usa fallback hardcodeado |
| LLM timeout | Fallback chain → PHP hardcoded, nunca null |
| Quiero agregar regex nueva | STOP — ¿puede resolverlo Qdrant semántico? Si sí, hacerlo así |
| Quiero refactorizar Gateway | STOP — cambio mínimo primero, evidencia, luego el siguiente |
| Quiero meter lógica en el prompt | STOP — la lógica va en PHP, el prompt solo traduce |

---

## 11. CÓMO AGREGAR UN NUEVO AGENTE A SUKI

Basado en CrewAI (roles aislados) + LangGraph (nodos con contrato):

1. Definir el rol en `AGENT_SKILLS_MATRIX.md`
2. Crear prompt en `Core/Prompts/{agent_name}_prompt.json`
3. Crear clase en `Core/Agents/{AgentName}.php` con método `handle(array $input): array`
4. Registrar en `ContractRegistry` con schema de input/output
5. El agente SOLO recibe contexto mínimo, SOLO devuelve JSON validado
6. Agregar test en `framework/tests/`
7. Actualizar `INDEX.md` y `PROJECT_MEMORY.md`

Nunca crear un agente que:
- Reciba historial completo
- Devuelva texto libre
- Ejecute lógica de negocio directamente
- Comparta estado con otro agente sin pasar por CommandBus

---

*Este archivo es ley. Cualquier agente que lo contradiga está en error.*
*Actualizar este archivo cuando se detecten nuevos anti-patrones en producción.*
*Ubicar en: docs/canon/SUKI_AGENT_CODING_CANON.md*
*Referenciar en: .windsurfrules → agent_reading_list → mandatory_before_changes*
