# SUKI — Arquitectura de Memoria del Agente
> Documento canónico. Fuente de verdad para la capa de conversación.
> Creado: 2026-03-29 · NO modificar sin actualizar esta fecha y el historial al final.

---

## 1. Misión del agente (invariante)

SUKI habla con personas comunes. No con programadores.
Un tendero, un sastre, un mecánico, un vendedor de herramientas.

Ellos hablan como hablan: con errores de ortografía, frases incompletas,
cambios de idea, frustración. El agente debe entenderlos y guiarlos,
no esperar instrucciones perfectas.

**El agente NO es un chatbot de menús ni una interfaz de comandos.**
Es un asistente que escucha, entiende, propone, confirma y ejecuta.

---

## 2. Flujo de un mensaje — el contrato más importante

Este flujo es **invariante de diseño**. Todo cambio en `ChatAgent.php`
debe respetar estos 6 pasos sin excepción:

```
Entrada: userMessage (string)

1. threadId  = tenant_id . '_' . session_id
2. history   = ConversationMemory::load(threadId)
3.             ConversationMemory::append(threadId, 'user', userMessage)
4. messages  = [system_prompt] + history + [{role:user, content:userMessage}]
5. response  = LLMRouter::complete(messages)
6.             ConversationMemory::append(threadId, 'assistant', response)

Salida: response (string — nunca un error técnico)
```

**Por qué este orden:**
- El paso 3 guarda el mensaje del usuario ANTES de llamar al LLM.
  Si el LLM falla, el mensaje del usuario quedó registrado.
- El paso 4 envía el historial COMPLETO. El LLM siempre tiene contexto de la conversación.
- El paso 6 persiste la respuesta. El siguiente turno la verá en el historial.

---

## 3. ConversationMemory

### Ubicación
```
framework/app/Core/ConversationMemory.php
```

### Contrato público (no modificar sin consenso)
```php
class ConversationMemory {
    public function load(string $threadId): array;         // retorna [{role, content}]
    public function append(string $threadId, string $role, string $content): void;
    public function clear(string $threadId): void;
}
```

### thread_id
```
formato: {tenant_id}_{session_id}
ejemplo: "demo_sess_abc123"
         "ferreteria_juan_sess_xyz789"
```

El `tenant_id` garantiza que un thread de un negocio nunca sea visible
para otro negocio. Verificar siempre que el thread_id empiece con el tenant_id
del request actual antes de cargar historial.

### Almacenamiento
```
Base de datos: project/storage/meta/project_registry.sqlite
Tabla: conversation_memory

Esquema:
  id            INTEGER PRIMARY KEY AUTOINCREMENT
  thread_id     TEXT NOT NULL
  role          TEXT NOT NULL — solo 'user', 'assistant', 'system'
  content       TEXT NOT NULL
  token_estimate INTEGER DEFAULT 0
  created_at    TEXT DEFAULT (datetime('now'))

Índice: idx_cm_thread ON (thread_id, id)
```

### Migración
```
db/migrations/YYYYMMDD_create_conversation_memory.php
```
Siempre usar el sistema de migraciones formal. Nunca `ensureSchema()` en runtime.

### Ventana de contexto
- Máximo 40 mensajes por thread (configurable en constructor).
- Al exceder, se eliminan los más viejos que no sean `system`.
- El mensaje `system` se mantiene siempre.

### Reglas de oro
```
✓ Todos los métodos tienen try/catch — nunca propagan al usuario
✓ Cada mensaje es una fila — no JSON serializado en un campo
✓ role solo puede ser: user | assistant | system
✓ No guardar passwords, tokens ni datos sensibles
✓ No usar $_SESSION para guardar historial
```

---

## 4. System prompt — el contrato de identidad del agente

### Ubicación
```
framework/prompts/builder_system_prompt.txt    ← modo builder
framework/prompts/app_system_prompt.txt        ← modo app (usuario final)
```

Los system prompts viven en archivos externos, no como strings hardcoded en PHP.

### Principios del system prompt de SUKI

```
QUIÉN SOY:
Soy SUKI, un asistente para personas que quieren administrar su negocio
sin necesitar conocimientos técnicos.

QUIÉN ES EL USUARIO:
Una persona común. Puede ser un tendero, un sastre, un mecánico.
Habla con palabras del día a día, comete errores de ortografía,
no sabe qué es una "tabla" o un "campo" o un "endpoint".

CÓMO ME COMPORTO:
- Hablo en el idioma del usuario
- Nunca uso: tabla, entidad, campo, schema, endpoint, API, base de datos,
  backend, frontend, query, CRUD, registro de entidades
- En su lugar digo: lista de productos, información del cliente, pedido, factura
- Confirmo lo que entendí antes de avanzar
- Si no entendí, hago UNA sola pregunta corta y directa
- Si el usuario se frustra, lo reconozco con empatía y explico diferente
- Avanzo un paso a la vez
- El siguiente paso es siempre concreto y simple

QUÉ PUEDO HACER:
- Ayudarte a crear listas de productos, clientes, pedidos, facturas, proveedores
- Buscar información de tu negocio
- Generar reportes simples
- Conectarme con otros servicios que uses

QUÉ NO HAGO:
- No invento datos que no existen en tu negocio
- No hago cálculos críticos yo solo — los valido con el sistema
- No ejecuto acciones irreversibles sin confirmación
```

### Cómo se inyecta el contexto del negocio

El system prompt base se complementa con el contexto del tenant activo:
```
{system_prompt_base}

NEGOCIO ACTUAL:
Nombre: {tenant_name}
Sector: {tenant_sector}
Apps activas: {app_list}
Entidades disponibles: {entity_list_in_human_language}
```

Esto es lo que diferencia a SUKI como SaaS multi-tenant de un bot genérico.

---

## 5. Clasificación de intención — capas

El router de intención sigue este orden estricto (más barato primero):

```
Capa 0: Reglas deterministas (PHP puro)
        Costo: 0 tokens | Latencia: <1ms
        Ejemplos: comando exacto conocido, ruta específica

Capa 1: Semantic cache (Qdrant)
        Costo: ~0 tokens | Latencia: ~50ms
        Si hay match semántico con score > 0.90 → respuesta directa

Capa 2: IntentClassifier (Mistral rápido, JSON estricto)
        Costo: ~50 tokens | Latencia: ~300ms
        Retorna: {intent, confidence, entities, language}
        Nunca texto libre — siempre JSON estructurado

Capa 3: LLM con historial completo (Mistral/fallback)
        Costo: ~500-2000 tokens | Latencia: ~1-3s
        Se activa cuando la intención no es clara o requiere razonamiento
        Recibe el historial completo (ConversationMemory)

Capa 4: Fallback empático
        Si todo falla, respuesta genérica de ayuda — NUNCA error técnico
```

**Regla de oro:** Si Capa 1 falla (API key, timeout), ir a Capa 2.
Si Capa 2 falla, ir a Capa 3. Si Capa 3 falla, ir a Capa 4.
**NUNCA** mostrar el error al usuario.

---

## 6. Manejo de errores — garantías al usuario

```
Error de API externa (Gemini, Mistral, OpenRouter):
  → Capturar en try/catch
  → Log interno con error_log()
  → Continuar con el siguiente proveedor (LLMRouter hace esto)
  → Si todos fallan: Capa 4 (fallback empático)
  → El usuario NUNCA ve "API key expired" ni ningún error técnico

Error de base de datos (ConversationMemory):
  → Capturar en try/catch
  → Log interno
  → Continuar sin historial (degradación graciosa)
  → El usuario recibe respuesta, aunque sin contexto previo

Timeout de llamada LLM:
  → Timeout máximo: 10 segundos por llamada
  → 1 retry máximo
  → Si falla el retry: fallback empático
  → El usuario NUNCA espera más de ~15 segundos
```

---

## 7. Lo que está prohibido — reglas no negociables

Estas reglas vienen de `AGENTS.md` y `SUKI_AGENT_CODING_CANON.md`.
No se pueden votar ni negociar:

```
✗ NUNCA usar preg_match() o in_array() para clasificar intenciones de negocio
✗ NUNCA propagar errores técnicos al response del usuario
✗ NUNCA guardar historial de conversación en $_SESSION
✗ NUNCA enviar al LLM solo el mensaje actual sin historial
✗ NUNCA hacer ensureSchema()/ensureTables() en el pipeline del chat
✗ NUNCA reescribir módulos estables completos — cambios incrementales
✗ NUNCA cerrar una tarea sin evidencia de test real (output del terminal)
✗ NUNCA inventar que algo funciona sin ejecutarlo
```

---

## 8. Patrones de referencia adoptados

### De neuron-ai (PHP)
- `SQLChatHistory`: persistencia de historial por thread_id → `ConversationMemory`
- `SystemPrompt` estructurado: background + steps + output → system prompt en archivo externo
- Tool loop con `maxRuns` guard → implementado en `IntentRouter`
- Error handling tipado: nunca propagar al usuario

### De CrewAI (Python — adaptado a PHP)
- Contexto compartido de misión: el system prompt rico que define quién es el agente
- Agentes con roles especializados: builder, app, soporte
- Memoria de largo plazo para contexto persistente entre sesiones

### Esencia SUKI
- PHP como ejecutor determinista: valida, persiste, ejecuta
- LLM como capa de razonamiento: interpreta, decide qué tool usar
- Qdrant como memoria semántica: recupera contexto del negocio
- MCP/API: se conecta con herramientas externas con el mismo patrón

---

## 9. Archivos que implementan esta arquitectura

```
framework/app/Core/ConversationMemory.php     ← memoria por turno
framework/app/Core/ChatAgent.php              ← orquestador principal
framework/app/Core/LLMRouter.php              ← router de proveedores LLM
framework/app/Core/IntentClassifier.php       ← clasificación semántica
framework/app/Core/QdrantVectorStore.php      ← memoria semántica
framework/prompts/builder_system_prompt.txt   ← identidad del agente builder
framework/prompts/app_system_prompt.txt       ← identidad del agente app
db/migrations/*_create_conversation_memory.php ← migración de tabla
```

---

## 10. Historial de cambios de este documento

| Fecha | Cambio | Sprint |
|-------|--------|--------|
| 2026-03-29 | Creación inicial. ConversationMemory pattern. Flujo de 6 pasos. Manejo de errores. Patrones neuron+crew+SUKI. | S1.4/S1.9/S1.10 |

> Próximo cambio esperado: cuando se conecten las tools reales (S9 del tracker).
> Actualizar sección 5 (capas de clasificación) y añadir sección de tool calling.
