# SUKI — Análisis Competitivo vs Plataformas Enterprise Multi-Agente
**Generado:** 2026-05-31 | **Para revisar post-pruebas manuales**

---

## Mapa del Mercado

| Plataforma | Modelo | Foco Principal | Precio Referencia |
|---|---|---|---|
| Microsoft Copilot | Asistente integrado | M365 / productividad | $30/usr/mes |
| Salesforce AgentForce | Agentes autónomos | CRM / ventas / CS | $150-$500/usr/mes |
| SAP Joule | Copilot ERP | Procesos S/4HANA | $200k+ implementación |
| Zendesk AI | Ticketing + CS | Soporte al cliente | $55-$115/usr/mes |
| n8n | Orquestador visual | Automatización flujos | $20-$50/mes |
| LangGraph | Framework dev | Construcción agentes | Open source + infra |
| CrewAI | Multi-agent SDK | Orquestación agentes | Open source |
| ServiceNow AI | ITSM + workflows | TI empresarial | Enterprise pricing |
| **SUKI** | **AI-AOS (nuevo)** | **ERP creado por chat** | **Por definir** |

---

## Comparativa Técnica

### Arquitectura Agentes
| Capacidad | Copilot | AgentForce | SAP Joule | n8n | LangGraph | **SUKI** |
|---|---|---|---|---|---|---|
| Router determinístico | ✅ | ✅ | ✅ | ✅ nodos | ✅ | ✅ Cache→Rules→RAG→LLM |
| Grounding contextual | ✅ Avanzado | ✅ | Parcial | ❌ | Manual | ✅ SCML 4 Fases |
| Validación output LLM | ✅ | ✅ | ✅ | ❌ | Manual | ✅ OutputValidator |
| Multi-tenant nativo | ❌ org=tenant | Parcial | ✅ | ❌ | ❌ | ✅ Row-level isolation |
| Skills JSON-driven | ❌ | ❌ | ❌ | ✅ nodos | ❌ | ✅ sin deploy PHP |
| Memoria cross-session | ✅ | ✅ | Parcial | ❌ | Manual | ✅ persistida |
| Anti-prompt injection | ✅ | ✅ | ✅ | ❌ | Manual | ✅ sanitizeForPrompt() |

### Capacidades ERP Colombia
| Capacidad | SAP | Salesforce | Zendesk | **SUKI** |
|---|---|---|---|---|
| PUC contable Colombia | Vía addon | ❌ | ❌ | ✅ 1002 cuentas |
| ReteFuente + ICA | Vía addon | ❌ | ❌ | ✅ FiscalRulesEngine |
| Factura electrónica DIAN | Vía SAP FI | ❌ | ❌ | ⚠️ P0 pendiente |
| ERP creado por chat | ❌ | ❌ | ❌ | ✅ ÚNICO diferenciador |
| cPanel / hosting local | ❌ | ❌ | ❌ | ✅ PHP 8.3 |

---

## Dónde SUKI Gana, Empata y Pierde

### GANA (ventajas reales)
- ✅ ERP creado desde cero por chat — nadie más hace esto para PYME
- ✅ Fiscal Colombia nativo (PUC, ReteFuente, ICA)
- ✅ Corre en cPanel/hosting compartido — precio PYME accesible
- ✅ Multi-tenant real con row-level isolation verificado
- ✅ SCML completo: grounding + validación LLM output
- ✅ PHP: sin dependencias de runtime costosas
- ✅ JSON-driven: añadir skills sin cambiar PHP

### PIERDE (brechas reales)
- ❌ DIAN XML/UBL/CUFE — factura electrónica Colombia NO implementada (P0)
- ❌ OTP self-service por tenant — login individual NO (P0)
- ❌ E2E tests HTTP — solo unit tests internos
- ❌ Escalabilidad horizontal — stateful
- ❌ Async job processing — todo síncrono
- ❌ SDK público para terceros
- ❌ SLA + DR plan documentados
- ❌ SOC2 / ISO 27001

---

## Veredicto Producción
- ✅ **LISTO PARA:** Piloto privado 3-5 PYME CO, sin DIAN, con onboarding asistido
- ❌ **NO LISTO PARA:** Comercialización masiva, self-service, SaaS público

---
*Revisar post-pruebas manuales para actualizar veredicto*
