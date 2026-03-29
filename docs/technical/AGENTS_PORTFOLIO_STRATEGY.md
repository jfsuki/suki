# AGENTS_PORTFOLIO_STRATEGY (2026-02-21)

## Vision
Ofrecer dos lineas de producto:
1. **Builder + App propia** (apps creadas en SUKI).
2. **Agentes de operacion para terceros** (SaaS externos via API).

## Principio operativo
- El agente no “inventa” operaciones.
- Todo se ejecuta por **capabilities declaradas** + API oficial del tercero.
- Cada integracion vive como conector aislado (sin romper el nucleo).

## Arquitectura recomendada para terceros
1. `IntegrationContract` por proveedor:
   - auth,
   - endpoints,
   - acciones permitidas,
   - mapeo de errores humano.
2. `Capability Catalog`:
   - “crear cliente”, “emitir factura”, “consultar saldo”, etc.
3. `Command Layer`:
   - traduce lenguaje natural a comando interno.
4. `Provider Adapter`:
   - adapta comando a API externa.
5. `Audit + Retry Outbox`:
   - trazabilidad y reintentos seguros.

## Requisitos minimos para vender “agentes low-cost”
- Multi-tenant estricto (sin mezcla de datos).
- RBAC por rol (owner/admin/vendedor/contador).
- Respuestas guiadas para usuario no tecnico:
  - una pregunta minima,
  - explicacion corta,
  - confirmacion antes de ejecutar accion critica.
- Telemetria por sesion:
  - tasa de exito,
  - intents no entendidos,
  - costo tokens/request.

## Flujo conversacional objetivo
1. Detectar modo: Build o Use.
2. Validar contexto: app/proyecto/tenant/rol.
3. Resolver local-first (sin LLM cuando sea posible).
4. Pedir dato faltante minimo.
5. Ejecutar comando y devolver resultado humano + accion siguiente.

## Roadmap corto
1. Conectores base (Alanube, Alegra u otro).
2. Perfilado por industria (veterinaria, spa, ferreteria, iglesia, etc.).
3. Biblioteca de playbooks de negocio (campos + formularios + reportes).
4. Auto-nutricion controlada desde telemetria (sin romper contratos).
