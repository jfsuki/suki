# SUKI — Sprint Plan: Cerrar el Agente ERP Vivo
# Fecha: 2026-03-23
# Arquitectura objetivo:
# Evento → Agente decide (LLM fast) → Tool PHP → Guarda+Aprende → Notifica
# ============================================================================

## SPRINT 0 — DEUDA CRÍTICA (esta semana, sin esto nada funciona)
Duración: 2-3 días

| # | Tarea | Por qué es bloqueante |
|---|---|---|
| S0.1 | Fix tenant_id mismatch Qdrant (system vs demo) | El agente no entiende NADA en producción |
| S0.2 | Pruebas automáticas con HTTP real (no PHP interno) | Las pruebas actuales mienten |
| S0.3 | Renombrar Cami → Suki en todo el sistema | Identidad del producto |
| S0.4 | Memoria persistente UserMemoryService | Agente que no recuerda no es agente |
| S0.5 | P95 < 3000ms (hoy 8719ms) | Inaceptable para producción |

---

## SPRINT 1 — TOOLS RUNTIME (el agente "hace cosas")
Duración: 1 semana
Objetivo: Suki ejecuta acciones reales en la app creada por el usuario

Arquitectura de cada tool:
  Usuario dice → Suki entiende (Qdrant/Mistral) → PHP ejecuta tool → responde

### Tools prioritarias:

S1.T1 — inventory_query
  "¿cuántos martillos tengo?" → SQL → "Tienes 45 martillos"
  Archivos: framework/app/Core/Tools/InventoryQueryTool.php

S1.T2 — product_search
  "busca tornillos de 2 pulgadas" → SQL → lista de productos
  Archivos: framework/app/Core/Tools/ProductSearchTool.php

S1.T3 — record_create
  "agrega cliente Juan Pérez, cel 300..." → SQL INSERT → confirmación
  Archivos: framework/app/Core/Tools/RecordCreateTool.php (revisar si existe)

S1.T4 — report_summary
  "resumen de ventas de esta semana" → SQL + template → texto o tabla
  Archivos: framework/app/Core/Tools/ReportSummaryTool.php

S1.T5 — import_csv_excel
  usuario sube archivo → parsear → crear/poblar tabla
  Archivos: framework/app/Core/Tools/FileImportTool.php
  (MediaRepository.php existe, conectar al chat)

S1.T6 — formula_calculator
  "calcula IVA de $500" o "margen de ganancia al 30%" → PHP calcula → responde
  Archivos: framework/app/Core/Tools/FormulaTool.php

Contrato de toda tool (obligatorio):
{
  "tool_name": "inventory_query",
  "input": {"entity": "string", "filters": "object"},
  "output": {"result": "array", "summary": "string"},
  "fallback": "No pude consultar el inventario ahora. Intenta en un momento.",
  "max_ms": 2000
}

---

## SPRINT 2 — AGENTE PROACTIVO (trigger layer)
Duración: 1 semana
Objetivo: Suki actúa sin que el usuario lo pida

S2.1 — Trigger por cron (bin/worker.php ya existe, conectar):
  Cada hora: revisar facturas vencidas → notificar por WhatsApp/chat
  "Tienes 3 facturas vencidas. ¿Las notifico a los clientes?"

S2.2 — Trigger por webhook:
  Llegó pago de Alanube → Suki registra automático → notifica
  "Recibí pago de Juan por $500. Ya lo registré."

S2.3 — Trigger por chat con programación:
  "recuérdame cobrarle a Juan el viernes" → cron entry → Friday → actúa

S2.4 — Flujo de confirmación humana:
  Suki propone → usuario dice "sí" o "no" → Suki ejecuta o cancela
  NUNCA ejecutar acción financiera sin confirmación explícita

Tabla SQL necesaria:
  agent_scheduled_tasks (id, tenant_id, user_id, trigger_type, trigger_config,
                          tool_name, tool_params, status, run_at, ran_at)

---

## SPRINT 3 — MARKETPLACE MULTITENANT
Duración: 1-2 semanas
Objetivo: Una app creada sirve a muchos tenants sin clonar estructura

S3.1 — App como plantilla compartida:
  app_templates tabla: id, name, sector, entities_json, forms_json, version
  Tenant suscribe → usa la plantilla → sus DATOS son propios en su SQLite

S3.2 — Suscripción de tenant a app:
  "quiero usar la app de ferretería" → Suki busca template → lo activa para ese tenant
  Sin re-crear entidades, sin re-entrenar

S3.3 — Catálogo de apps por sector:
  Ferretería, Clínica, Restaurante, Iglesia, E-commerce básico
  Cada una con su training semántico especializado ya en Qdrant

S3.4 — Actualización de template sin romper tenants:
  v1 → v2: solo migra campos nuevos, no destruye datos existentes

---

## SPRINT 4 — ERP COMPLETO + CONTABILIDAD + POS
Duración: 2 semanas
Objetivo: Suki maneja un negocio real

S4.1 — Módulo POS (punto de venta):
  "vendo 2 camisas a María" → crea venta → descuenta inventario → genera recibo
  Tools: sale_create, inventory_decrease, receipt_generate

S4.2 — Módulo contabilidad básica:
  Ingresos/egresos, cuentas por cobrar/pagar
  "¿cuánto me deben?" → SQL → "Te deben $1.2M: Juan $500k, María $700k"

S4.3 — Facturación electrónica CO (Alanube):
  invoice_builder tool completar (S9.3 del tracker)
  "factura a Juan por $500 por mantenimiento" → Alanube → DIAN

S4.4 — E-commerce básico:
  Catálogo de productos → carrito → pedido → inventario
  Integración con MercadoPago o PayU vía webhook

S4.5 — Reportes y gráficos:
  "muéstrame ventas de este mes en gráfico" → SQL → Chart.js → imagen
  Tool: chart_generator (datos → JSON → render frontend)

---

## SPRINT 5 — MCP + INTEGRACIONES EXTERNAS
Duración: 2 semanas
Objetivo: Suki conecta con sistemas externos sin que el usuario sepa de APIs

S5.1 — MCP Client en PHP:
  framework/app/Core/MCP/MCPClient.php
  Conectar a servidores MCP de: Alegra, Siigo, WooCommerce, Shopify

S5.2 — Tool de integración genérica:
  "sincroniza mi inventario con WooCommerce" → MCP → bidireccional
  Sin que el usuario configure nada técnico

S5.3 — Credenciales por tenant (no globales):
  Cada tenant conecta SU Alegra, SU Siigo
  Tabla: tenant_integrations (tenant_id, provider, credentials_encrypted, status)

S5.4 — Para usuarios sin API key propia:
  Pool compartido SUKI (Mistral Nemo ~$0.02/M tokens)
  Plan free: 500 mensajes/mes del pool
  Plan pro: conecta tu key → sin límite → 30% descuento

---

## SPRINT 6 — CONTROL TOWER + CALIDAD REAL
Duración: 2 semanas
Objetivo: Administrar SUKI como negocio SaaS

S6.1 — Dashboard de consumo tokens por tenant/usuario
S6.2 — Métricas de conversación real (no tests falsos)
S6.3 — Inbox de soporte desde el chat
S6.4 — Auto-mejora: frases no resueltas → entrenamiento
S6.5 — CI/CD con qa_gate obligatorio antes de deploy

---

## REGLA DE ORO PARA TODOS LOS SPRINTS

Cada tool que se implemente DEBE:
1. Tener contrato JSON en framework/app/Core/Tools/contracts/
2. Tener 1 prueba HTTP real (no PHP interno)
3. Tener fallback humano (nunca null, nunca error técnico visible)
4. Estar registrada en el catálogo de tools del agente
5. Costar < 0 tokens LLM si el intent es claro (Qdrant resuelve)

Solo llamar LLM cuando:
- El intent es ambiguo (score Qdrant < 0.72)
- Se necesita generar texto libre (reportes, explicaciones)
- Hay múltiples entidades en un mensaje

---

## PRIORIDAD PARA EL USUARIO QUE "NO SABE DE APIS"

El usuario ve SOLO esto:
  Suki: "¿Conectamos tu inventario con tu tienda online?"
  Usuario: "sí"
  Suki: "Listo. Cuando vendas en la tienda, yo actualizo el inventario solo."

El usuario NO ve: MCP, webhook, API key, endpoint, JSON, error 404.

Para lograrlo:
- Onboarding de integración conversacional (no formulario técnico)
- Credenciales guardadas cifradas por tenant
- Errores de integración → Suki los maneja y le dice al usuario en humano
  "Tuve un problema conectando con tu tienda. ¿Me das acceso de nuevo?"
