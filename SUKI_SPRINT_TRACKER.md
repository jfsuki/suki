# SUKI — Sprint Tracker & Work Control
> Última actualización: 2026-03-22
> Responsable: Equipo SUKI Core
> Estado global: 🟡 AMARILLO — Endurecimiento Operativo

---

## 📌 Misión de SUKI (Recordatorio Permanente)

SUKI es un **AI Application Operating System (AI-AOS)** chat-first:
- LLM interpreta → Qdrant aporta memoria semántica → PHP valida, persiste y ejecuta
- Control Tower + QA Agent auditan y bloquean errores
- El usuario final NO es técnico: habla como habla, con typos, frustración y cambios de idea

**La prioridad ya NO es crear más. Es: hacer que lo creado funcione, se pruebe, se mida y se gobierne correctamente.**

---

## 🎯 Principios de Trabajo Obligatorios

1. Un cambio → una prueba → una evidencia
2. Nada se marca resuelto sin validación end-to-end
3. El usuario NUNCA debe ver errores internos
4. La conversación se resuelve con comprensión flexible, no listas rígidas
5. Las métricas deben servir para verdad operativa, no para decorar paneles

---

## 📊 Resumen Ejecutivo por Área

| Área | Estado | % | Evidencia |
|---|---|---|---|
| Infraestructura IA (LLM/Qdrant/Embeddings) | ✅ Operativa | 85% | LLMRouter, GeminiEmbeddings, QdrantVectorStore, MistralProvider |
| Entrenamiento base (datasets/seeds) | ✅ Operativo | 80% | 79 intents en Qdrant, sector seeds, training_base v0.3.7 |
| Vectorización / Qdrant | ✅ Operativo | 85% | 3 colecciones activas, cosine 768d |
| Router / Gates / Respuesta final | 🟡 Parcial | 60% | IntentRouter + RouterPolicyEvaluator + IntentClassifier (nuevo) |
| Conversación real del Builder | 🟡 Problemático | 50% | isPureGreeting/classify ahora semánticos, pero onboarding aún rígido |
| Métricas de calidad | 🟡 Parcial | 55% | Telemetría inyectada para IntentClassifier, falta dashboard real |
| Seguridad / Auth | 🟡 Parcial | 60% | ApiSecurityGuard, CSRF, rate-limit, tenant binding — falta OTP |
| DB Kernel / CRUD | ✅ Operativo | 75% | QueryBuilder, BaseRepository, EntityMigrator — falta ALTER diff |

---

## 🔴 SPRINT ACTUAL — Fase Inmediata (Estabilización)

### S1: Builder Conversacional Real
> Objetivo: El builder entiende lenguaje humano real sin loops ni errores visibles

| # | Tarea | Estado | Archivos Clave | Notas |
|---|---|---|---|---|
| S1.1 | Reemplazar regex rígidas por clasificación semántica | ✅ Hecho | `IntentClassifier.php`, `ConversationGateway.php` | isPureGreeting + classify ahora usan Qdrant→Mistral→keywords |
| S1.2 | Sembrar 79 intenciones base en Qdrant | ✅ Hecho | `seed_builder_intents.php` | agent_training collection |
| S1.3 | Auto-training loop (SQLite → Qdrant) | ✅ Hecho | `IntentClassifier::logTraining()`, `seed_builder_intents.php` | Graba clasificaciones LLM con score ≥ 0.85 |
| S1.4 | Reducir rigidez en `handleBuilderOnboardingCore` | 🟡 En progreso | `ConversationGatewayBuilderOnboardingTrait.php` | Todavía usa catálogos exactos y confirmaciones binarias |
| S1.5 | Eliminar `isBuilderUserFrustrated` regex | ⬜ Pendiente | `ConversationGatewayHandlePipelineTrait.php` | Mover a IntentClassifier intent=frustration |
| S1.6 | Eliminar `isOutOfScopeQuestion` regex | ⬜ Pendiente | `ConversationGatewayHandlePipelineTrait.php` | Mover a IntentClassifier intent=out_of_scope |
| S1.7 | Eliminar `isFarewell` regex | ⬜ Pendiente | `ConversationGatewayHandlePipelineTrait.php` | Mover a IntentClassifier intent=farewell |
| S1.8 | Eliminar `isAmbiguousBuilderCreateRequest` regex | ⬜ Pendiente | `ConversationGatewayHandlePipelineTrait.php` | Mover a IntentClassifier |
| S1.9 | Validación multi-turno humano real del builder | ⬜ Pendiente | `chat_golden.php`, `chat_real_100.php` | Probar con frustración, typos, cambios de idea |
| S1.10 | Error técnico NUNCA visible al usuario | 🟡 Parcial | `Database.php` (fix getenv), `ConversationGateway.php` | Corregido DB_USER vacío; revisar otros puntos |

---

### S2: Métricas Útiles y Reales
> Objetivo: Métricas que muestren verdad operativa, no decoración

| # | Tarea | Estado | Archivos Clave | Notas |
|---|---|---|---|---|
| S2.1 | Inyectar telemetría del IntentClassifier al response | ✅ Hecho | `ConversationGateway::telemetry()`, `IntentClassifier::getLastClassificationTelemetry()` | Tokens Mistral ahora visibles |
| S2.2 | Distinguir sesión actual vs histórico en UI | ⬜ Pendiente | `chat_builder.html`, `chat_app.html` | Los contadores acumulan sin reset por sesión |
| S2.3 | Medir consumo por chat y por proveedor | ⬜ Pendiente | `SqlMetricsRepository`, `/api/chat/ops-quality` | Endpoint existe pero falta dashboard visual |
| S2.4 | Guardar trazas para análisis costo/eficiencia | 🟡 Parcial | `ops_token_usage` tabla en registry DB | Se graba, pero no se analiza ni se presenta |
| S2.5 | Dashboard real de consumos API | ⬜ Pendiente | Nuevo | Por proveedor, chat, intent y canal |

---

### S3: LLM-Assisted Onboarding / Fast Path
> Objetivo: El LLM es traductor temprano, no castigo final

| # | Tarea | Estado | Archivos Clave | Notas |
|---|---|---|---|---|
| S3.1 | IntentClassifier 3 capas (Qdrant→Mistral→PHP) | ✅ Hecho | `IntentClassifier.php` | Operativo y probado |
| S3.2 | Mistral como proveedor primario de LLM | ✅ Hecho | `MistralProvider.php`, `LLMRouter.php`, `.env` | Configurado y funcionando |
| S3.3 | Mejorar `clarifyBuilderStepViaLlm` | 🟡 Parcial | `ConversationGatewayBuilderOnboardingTrait.php` L913 | Funciona pero depende de BUILDER_LLM_ASSIST_ENABLED |
| S3.4 | Estado resumido comprimido para LLM | 🟡 Parcial | `ConversationGatewayBuilderOnboardingTrait.php` | El capsule ya envía step + missing + allowed, pero puede comprimirse más |

---

## 🟡 SPRINT SIGUIENTE — Gobernanza y Auditoría

### S4: Post-Change Audit Obligatorio
| # | Tarea | Estado | Notas |
|---|---|---|---|
| S4.1 | Diseñar contrato de Post-Change Audit | ⬜ Sin iniciar | Debe validar que cada cambio tiene test + evidencia |
| S4.2 | Implementar checker automático post-commit | ⬜ Sin iniciar | Integrar con qa_gate.php |
| S4.3 | Bloquear push sin audit pass | ⬜ Sin iniciar | Git hook o CI gate |

### S5: QA Agent / Audit Agent Funcional
| # | Tarea | Estado | Notas |
|---|---|---|---|
| S5.1 | Diseñar QA Agent bloqueante (PASS/WARNING/FAIL) | ⬜ Sin iniciar | Canon en `docs/canon/AGENTOPS_GOVERNANCE.md` |
| S5.2 | Implementar Audit Agent de integridad | ⬜ Sin iniciar | Canon en `framework/docs/BUSINESS_AUDIT_AGENT.md` |
| S5.3 | Alertas automáticas por desvío del roadmap | ⬜ Sin iniciar | Sin diseño |

### S6: 🏗️ CONTROL TOWER — Centro Nervioso de Administración SUKI

> **Visión**: La Torre de Control NO es solo un dashboard. Es el **centro de operaciones completo** donde el administrador de SUKI ve, controla, mide y gobierna TODO lo que pasa en la plataforma. Es la interfaz de mando para quien opera SUKI como negocio. **Aquí vive el QA Agent**: detecta errores de código, bloquea regresiones y audita cada cambio antes de que llegue al usuario.

#### S6-A: Panel de Administración General
| # | Tarea | Estado | Notas |
|---|---|---|---|
| S6.A1 | Dashboard ejecutivo con KPIs globales | ⬜ Sin iniciar | Apps activas, usuarios, conversaciones/día, uptime |
| S6.A2 | Vista de salud del framework (DB, Qdrant, LLM, APIs) | ⬜ Sin iniciar | Semáforos rojo/amarillo/verde por servicio |
| S6.A3 | Gestión de tenants y proyectos | ⬜ Sin iniciar | CRUD de tenants, ver proyectos por tenant, activar/desactivar |
| S6.A4 | Configuración global del sistema (.env, feature flags) | ⬜ Sin iniciar | UI para variables críticas sin tocar archivos |

#### S6-B: Marketplace de Apps Multitenant (Compartidas)
| # | Tarea | Estado | Notas |
|---|---|---|---|
| S6.B1 | Catálogo de apps disponibles por sector | ⬜ Sin iniciar | Listar apps con sector, entidades, formularios |
| S6.B2 | Suscribir tenant a una app existente (multitenant) | ⬜ Sin iniciar | La MISMA app sirve a muchos tenants, no se clona |
| S6.B3 | Métricas de uso por app (tenants activos, conversaciones) | ⬜ Sin iniciar | Cuántos tenants la usan, volumen, satisfacción |
| S6.B4 | Versionado de apps sin romper tenants activos | ⬜ Sin iniciar | Actualizar app v1→v2 para todos los tenants suscritos |

#### S6-C: Inbox de Soporte, Quejas y Sugerencias
| # | Tarea | Estado | Notas |
|---|---|---|---|
| S6.C1 | Canal de soporte técnico dentro del chat | ⬜ Sin iniciar | El usuario dice "tengo un problema" y se registra ticket |
| S6.C2 | Bandeja de tickets por tenant/usuario/prioridad | ⬜ Sin iniciar | Vista admin con filtros y estados |
| S6.C3 | Captura de quejas y sugerencias desde conversación | ⬜ Sin iniciar | Intent `complaint` y `suggestion` en IntentClassifier |
| S6.C4 | Alertas automáticas al admin por tickets críticos | ⬜ Sin iniciar | Email/webhook cuando hay frustración recurrente |
| S6.C5 | Agentes aprenden de tickets resueltos | ⬜ Sin iniciar | Respuestas exitosas se ingresan a Qdrant como training |

#### S6-D: Métricas de Consumo y Costos
| # | Tarea | Estado | Notas |
|---|---|---|---|
| S6.D1 | Consumo de tokens por proveedor (Mistral/Gemini/DeepSeek) | ⬜ Sin iniciar | Tabla `ops_token_usage` ya existe, falta dashboard |
| S6.D2 | Consumo de tokens por usuario/tenant/proyecto | ⬜ Sin iniciar | Detectar quién dispara el promedio |
| S6.D3 | Consumo de tokens por intent y por canal | ⬜ Sin iniciar | Saber qué tipo de petición gasta más |
| S6.D4 | Alertas de anomalía (usuario que excede promedio) | ⬜ Sin iniciar | Threshold configurable + alerta automática |
| S6.D5 | Proyección de costos mensual por proveedor | ⬜ Sin iniciar | Basado en tendencia de últimos 7/30 días |
| S6.D6 | Reporte exportable de costos (CSV/PDF) | ⬜ Sin iniciar | Para facturación y análisis financiero |

#### S6-E: Calidad Conversacional y Aprendizaje
| # | Tarea | Estado | Notas |
|---|---|---|---|
| S6.E1 | Tasa de resolución local vs LLM vs fallback | ⬜ Sin iniciar | Medir efectividad del Qdrant vs Mistral |
| S6.E2 | Conversations con frustración detectada | ⬜ Sin iniciar | Listar y analizar dónde falla el agente |
| S6.E3 | Feedback loop: satisfacción del usuario | ⬜ Sin iniciar | "¿Te sirvió?" → alimentar training |
| S6.E4 | Auto-mejora: frases más consultadas no resueltas | ⬜ Sin iniciar | Top 20 frases que caen a fallback → entrenamiento |
| S6.E5 | Tablero de regresiones por release | ⬜ Sin iniciar | Comparar chat_acid/golden antes vs después de cambios |

#### S6-F: Incident Management y Auditoría
| # | Tarea | Estado | Notas |
|---|---|---|---|
| S6.F1 | Incident Intake automático | ⬜ Sin iniciar | Canon en `docs/canon/INCIDENT_MANAGEMENT.md` |
| S6.F2 | Post-Change Audit integrado | ⬜ Sin iniciar | Cada deploy genera reporte de impacto |
| S6.F3 | Audit log de acciones administrativas | ⬜ Sin iniciar | Quién cambió qué, cuándo, con qué resultado |
| S6.F4 | Métricas de regresión automáticas | ⬜ Sin iniciar | Baseline histórica por test suite |


---

## 🔵 SPRINT POSTERIOR — Funcionalidad Avanzada

### S7: Seguridad de Producción
| # | Tarea | Estado | Notas |
|---|---|---|---|
| S7.1 | OTP Auth multitenant | ⬜ Sin iniciar | Login real para producción |
| S7.2 | CSRF obligatorio en modo estricto | ✅ Hecho | `ApiSecurityGuard.php` con API_SECURITY_STRICT |
| S7.3 | Rate-limit persistente central | ✅ Hecho | `SecurityStateRepository` sobre SQLite |
| S7.4 | Firma webhooks anti-replay | ✅ Hecho | WhatsApp + Telegram validados |

### S8: Multimodal y Documents
| # | Tarea | Estado | Notas |
|---|---|---|---|
| S8.1 | Soporte imagen en chat | ⬜ Sin iniciar | Canon en `framework/docs/MULTIMODAL_PLAN.md` |
| S8.2 | Soporte PDF/XML procesamiento | ⬜ Sin iniciar | — |
| S8.3 | Media upload con MediaRepository | 🟡 Parcial | `MediaRepository.php` existe pero no integrado al chat |

### S9: Tools Runtime Reales
| # | Tarea | Estado | Notas |
|---|---|---|---|
| S9.1 | `inventory_query` tool | ⬜ Sin iniciar | — |
| S9.2 | `product_query` tool | ⬜ Sin iniciar | — |
| S9.3 | `invoice_builder` tool | ⬜ Sin iniciar | Facturación electrónica CO parcial (Alanube) |
| S9.4 | Async operations contract | ⬜ Sin iniciar | Canon en `framework/docs/CLOUD_SCALE_MEMORY.md` |

### S10: Unificación y Escala
| # | Tarea | Estado | Notas |
|---|---|---|---|
| S10.1 | Unificación ERP vs Discovery | ⬜ Sin iniciar | — |
| S10.2 | MCP futuro | ⬜ Sin iniciar | — |
| S10.3 | Orquestación multiagente con contratos cerrados | ⬜ Sin iniciar | Canon en `docs/canon/AGENT_COLLABORATION_ENGINE.md` |
| S10.4 | Migración gradual a modelo canónico (tenant_id + app_id) | ⬜ Sin iniciar | Documentado pero no ejecutado |

---

## 📋 Lo Ya Construido y Cerrado (Referencia)

| Componente | Estado | Evidencia |
|---|---|---|
| Sector Seed Contract | ✅ Cerrado | `sector_seed_contract.json` validado |
| Business Discovery pipeline | ✅ Cerrado | `knowledge_stable` → `sector_knowledge`, `intents_expansion` → `agent_training` |
| Vectorización híbrida Gemini 768d | ✅ Cerrado | 3 colecciones Qdrant operativas |
| Builder continuidad de estado | ✅ Cerrado | State key = `tenant+project+mode+user` |
| P0 Secrets Hardening | ✅ PASS | `secrets_guard_test.php` |
| P0.1 Domain Training Sync | ✅ PASS | `domain_training_sync_test.php` |
| P1 Runtime Contract Enforcement | ✅ PASS | `RouterPolicyEvaluator.php` |
| P1.1 Migration Discipline | ✅ PASS | `OperationalQueueStore.php` guards |
| P1.2 Strangler Gateway (parcial) | 🟡 PARTIAL | 3 traits extraídos, -18.95% líneas |
| WB-0/WB-1/WB-2 Workflow Builder | ✅ Cerrado MVP | Validator, Executor, Compiler operativos |
| OpenAPI → Contract Importer | ✅ Cerrado | `OpenApiIntegrationImporter.php` |
| Canales Telegram + WhatsApp | ✅ Cerrado | Webhooks validados con firma + anti-replay |
| Stress tests chat + canales | ✅ Cerrado | `chat_stress.php`, `channels_stress.php` |

---

## 🚨 Bloqueantes Conocidos

| # | Bloqueante | Impacto | Acción Requerida |
|---|---|---|---|
| B1 | `llm_smoke` FAIL por credenciales expiradas | NO-GO para training phase | Renovar API keys Gemini/OpenRouter |
| B2 | ConversationGateway aún ~10K líneas | Riesgo de regresión alto | Continuar Strangler pattern |
| B3 | Builder onboarding todavía rígido en pasos 2-5 | UX pobre para usuario real | Migrar validaciones a IntentClassifier |
| B4 | NO hay pipeline CI remoto | Todo depende de ejecución local | Implementar CI con qa_gate obligatorio |

---

## 📁 Archivos Canónicos de Referencia

> Todo agente DEBE leer antes de actuar:

| Archivo | Propósito |
|---|---|
| [AGENTS.md](file:///c:/laragon/www/suki/AGENTS.md) | Reglas de gobierno de agentes |
| [.windsurfrules](file:///c:/laragon/www/suki/.windsurfrules) | Arquitectura canónica + reglas de código |
| [SUKI_AGENT_CODING_CANON.md](file:///c:/laragon/www/suki/SUKI_AGENT_CODING_CANON.md) | Canon de codificación |
| [PROJECT_MEMORY_CANONICAL.md](file:///c:/laragon/www/suki/framework/docs/PROJECT_MEMORY_CANONICAL.md) | Memoria canónica del proyecto |
| [08_WORK_PLAN.md](file:///c:/laragon/www/suki/framework/docs/08_WORK_PLAN.md) | Plan de trabajo detallado |

---

## 📅 Historial de Cambios del Tracker

| Fecha | Cambio |
|---|---|
| 2026-03-22 | Creación inicial. Auditoría completa de 87+ docs, STATUS.md, FEATURE_MATRIX, ROADMAP, IMPLEMENTATION_GAP_MATRIX. Cross-referencia con código real. |
| 2026-03-22 | **S6 expandido**: Control Tower redefinida como centro nervioso completo con 6 sub-módulos (Admin, Marketplace, Soporte, Costos, Calidad, Incidents) y 25 tareas individuales. |
