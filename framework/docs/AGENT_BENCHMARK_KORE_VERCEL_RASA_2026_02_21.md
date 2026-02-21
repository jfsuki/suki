# Agent Benchmark (Kore/Vercel/Rasa) - 2026-02-21

## Objetivo
Consolidar practicas reales de mercado para llevar a SUKI/Cami de "chatbot transaccional" a "agente hibrido" con alta resolucion local y LLM solo cuando aporte valor.

## Hallazgos comunes (referentes)

### 1) Kore.ai
- Flujo por nodos con control explicito de **Intent**, **Entity**, **Form** y **Confirmation**.
- Diseño de dialogo con estados y transiciones claras para evitar loops.
- Separacion entre deteccion de intencion, captura de datos y confirmacion de accion.
- Escala multicanal y capa de analitica operacional.

### 2) Vercel Agents
- Mejor resultado con base documental indexada (`AGENTS.md`) + retrieval dirigido.
- Skills puntuales funcionan mejor cuando hay una guia operacional fuerte.
- Evaluaciones automáticas continuas (antes/despues) para detectar regresiones.

### 3) Rasa (forms/flows)
- Slot-filling robusto con validacion por campo.
- Una pregunta por turno, con reglas de repregunta y fallback.
- Flujos declarativos reutilizables por dominio.

### 4) Agentes con grafo de estado (LangGraph/SK)
- Estado durable por sesion.
- Orquestacion por pasos (planner -> validator -> executor).
- Guardrails para no ejecutar fuera de contexto.

## Estado SUKI actual vs gap

### Ya implementado
- Router local + fallback LLM.
- Estado por tenant/proyecto/modo/usuario.
- Slot-filling minimo y policy por modo (`builder` / `app`).
- Acid/golden tests y telemetry base.
- Auto-nutricion de utterances/sinonimos.

### Gap principal
- Falta endurecer la capa de **precondiciones conversacionales**:
  - validar que la ruta del plan actual es consistente antes de ejecutar;
  - bloquear repeticion de pasos ya cerrados (ej. crear tabla ya creada);
  - distinguir mejor "pregunta de aclaracion" vs "confirmacion de ejecucion".

## Prioridad de implementacion (sin romper contratos)

### P0 - Gobernanza de dialogo (critico)
1. Checklist obligatorio por plan (`plan_id`, `step`, `status`) en estado de conversacion.
2. Confirmacion transaccional explicita con resumen de impacto:
   - que voy a crear,
   - donde,
   - con que campos.
3. No repetir acciones cerradas:
   - si existe entidad, pasar al siguiente paso del plan.

### P1 - Comprension LATAM/CO (alto impacto)
1. Lexicon no tecnico por pais (tabla/lista/planilla, columna/campo, fila/registro, formula/sumatoria).
2. Correccion de errores ortograficos frecuentes y muletillas locales.
3. Entrenamiento incremental por logs reales anonimizados.

### P2 - Calidad operacional (escala)
1. Dashboard de calidad conversacional:
   - intenciones no resueltas,
   - loops,
   - repreguntas,
   - tasa de exito por modo.
2. Alertas de regresion cuando suben loops o cae resolucion local.

## Modelo para ofrecer "agentes por API" a terceros

### Capa canonica
- `Intent -> Action -> API Adapter -> Result Narration`
- Cada conector externo (Alegra/Alanube/ERP tercero) se monta como Adapter.
- Contrato estable para tools: auth, schemas, retries, errores humanizados.

### Requisitos minimos de producto
- Catálogo de conectores por vertical.
- Modo sandbox/produccion por tenant.
- Audit log completo por accion.
- Simulador previo ("dry-run") para acciones de riesgo.

## Resultado esperado
- Menos dependencia del LLM en tareas repetitivas.
- Conversacion mas humana para usuario no tecnico.
- Menos errores por ambiguedad y menos loops en builder/app.

## Matriz corta: Kore.ai vs SUKI (hoy)
- NLP/LLM hibrido:
  - Kore: maduro.
  - SUKI: parcial (local-first + fallback LLM). Falta: policy de fallback por riesgo/impacto por intent.
- Estado persistente:
  - Kore: fuerte.
  - SUKI: implementado por tenant/proyecto/modo/usuario. Falta: versionado de estado por release.
- Integracion API empresarial:
  - Kore: fuerte.
  - SUKI: implementado via capa canonica (`/api/integrations/action`). Falta: catalogo visual de conectores y test packs por proveedor.
- Multi-canal:
  - Kore: nativo.
  - SUKI: simulado chat gateway + API lista. Falta: hardening operativo WhatsApp/Telegram en produccion.
- Analitica conversacional:
  - Kore: fuerte.
  - SUKI: dashboard base + acid/golden tests. Falta: SLA por intent y alertas automaticas.

## Ventaja competitiva que podemos explotar
- Creador de apps + uso de apps por chat en un solo framework.
- Flujo de "analisis de necesidad -> plan -> confirmacion -> ejecucion" orientado a usuario no tecnico.
- Costo bajo por arquitectura local-first (menos llamadas LLM).

## Caso de oportunidad: Alegra (soporte) vs SUKI (agente de accion)
- Alegra hoy:
  - chatbot util para soporte guiado,
  - API potente disponible,
  - poca ejecucion autonoma desde lenguaje natural libre.
- Brecha convertida en ventaja para SUKI:
  - el agente debe traducir lenguaje natural a acciones API reales,
  - mantener estado y confirmacion transaccional,
  - operar multicanal con la misma logica canonica.
- Resultado esperado del posicionamiento:
  - pasar de "chat de ayuda" a "chat que ejecuta procesos".
