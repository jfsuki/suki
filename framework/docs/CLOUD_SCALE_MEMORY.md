# Cloud Scale Memory & Agents (LatAm, shared hosting)

Objetivo: masificar el "ERP Invisible" con miles de usuarios en hosting compartido
optimizando tokens, inodes y usando una sola base de datos multi-tenant.

## Principios no negociables
- Una sola DB multi-tenant con `tenant_id` en todas las tablas.
- Evitar miles de archivos (inodes): logs y memorias en DB.
- IA solo para casos complejos (portero inteligente).
- Memoria viva: por usuario + por app + global, con resumen y TTL.
- Respuestas rapidas (cache L1/L2) + queries indexadas.

## Arquitectura de agentes (orquestacion)
- **Agent Manager (lider)**: coordina reglas globales, plantillas y mejores practicas.
- **Mini-Agent por usuario/tenant**: adapta mensajes, guarda memoria local, ejecuta acciones.
- **Memoria comunitaria**: patrones exitosos se promueven a "Skill Templates".
- **Politica de entrenamiento**:
  - Si un flujo se repite >= N veces (por nicho), se guarda como plantilla.
  - Plantillas versionadas, con A/B y rollback.

## Memoria (capas y almacenamiento)
### 1) Global Memory (compartida)
Reglas comunes: impuestos, formatos, buenas practicas, prompts base.
- Tabla: `mem_global`
- Index: `(category, updated_at)`

### 2) Tenant Memory (empresa)
Contexto de negocio: NIT, monedas, reglas, catalogos base.
- Tabla: `mem_tenant`
- Index: `(tenant_id, key)`

### 3) User Memory (persona)
Preferencias, tono, clientes frecuentes.
- Tabla: `mem_user`
- Index: `(tenant_id, user_id, key)`

### 4) Session/Chat Memory (corta)
Mensajes recientes con TTL 24-72h.
- Tabla: `chat_log`
- Index: `(tenant_id, session_id, created_at)`
- Rotacion: borrar mensajes no transaccionales a 7 dias.

### 5) Action Memory (cache de intenciones)
Mapeo de "mensaje -> accion JSON" para ahorrar tokens.
- Tabla: `mem_action_cache`
- Index: `(tenant_id, intent_hash, updated_at)`
- TTL corto (7-30 dias).

## Portero inteligente (ahorro de tokens)
1) **Nivel 0**: reglas/regex locales (saldo, listar, total).
2) **Nivel 1**: clasificador ligero (intencion, entidad).
3) **Nivel 2**: DeepSeek solo si:
   - mensaje complejo
   - falta de datos
   - intencion desconocida

## Estandar de prompts (minimo costo)
- Respuesta del LLM **solo JSON** (sin texto).
- Prompt corto con schema fijo (intencion, entidad, campos, acciones).
- Contexto comprimido: solo campos requeridos + 2-3 ejemplos.

## Base de datos (una sola, optimizada)
Esquema recomendado (todas con tenant_id):
- core_users, core_roles, core_permissions
- app_entities, app_forms, app_reports, app_dashboards
- records_{entity} (o tablas fisicas por entidad)
- integration_connections, integration_documents
- audit_log, outbox_jobs
- chat_log, mem_* (ver arriba)

Indices base:
- `(tenant_id, id)` PK compuesto
- `(tenant_id, created_at)` para consultas rapidas
- `(tenant_id, name)` para lookup
- Forzar siempre filtros con tenant_id (todas las queries).
- Queries deben ser index-friendly (no full scan).

## Consultas ultra-rapidas (reglas)
- Siempre filtrar por `tenant_id` primero.
- Indices compuestos segun uso real (ej: tenant_id + status + created_at).
- Paginar con indices (LIMIT + cursor cuando sea posible).
- Nunca hacer joins masivos sin indices.

## Rendimiento y cache
L1: cache en memoria (APCu o array in-process)
L2: cache filesystem (JSON compactado)
TTL:
- contracts: 60-300s
- action cache: 7-30 dias

## Seguridad y privacidad
- Row-level security por tenant_id
- PII tokenizada (correo, documento) en logs
- Auditoria obligatoria en acciones criticas

## Integracion y soporte (agente soporte)
El mini-agent de soporte:
- Ayuda a configurar facturacion (Alanube)
- Verifica requisitos por pais
- Crea reportes base (factura/cotizacion)
- Da diagnostico de errores de emision

## Multimodal (audio/imagen/documentos)
Objetivo: el usuario envia audio, foto o PDF y el sistema crea/actualiza datos.
Pipeline recomendado:
1) **Ingestion** (Telegram/WhatsApp): webhook + descarga segura del archivo.
2) **Transcripcion** (audio): Whisper local o API (modo "low" para ahorro).
3) **OCR** (imagen/PDF): Tesseract local + parser de campos.
4) **Normalizacion**: convertir a JSON de accion (Create/Update).
5) **Confirmacion**: "Detecte esto... ¿confirmas?" (1 linea).
6) **Ejecucion**: command layer (mismo motor que el chat).

Optimizar recursos:
- Cache por hash de archivo (no reprocesar).
- Limite de tamanio (ej: 5-10MB) y resolucion.
- TTL para archivos y logs.

## Checklist para produccion masiva
- Limitar prompts a JSON
- Cachear intenciones frecuentes
- Rotar logs y limpiar inodes
- Indices por tenant
- Webhooks a cola (outbox)
- Monitoreo basico (latencia/errores)

## Procesos largos (outbox + reintento)
- Si una tarea tarda mas de X segundos, enviar a outbox.
- Worker revisa y reintenta por ventana estimada.
- El chat recibe confirmacion corta + estado ("en proceso").
