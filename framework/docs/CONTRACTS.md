# CONTRACTS

## Principles
- Contracts are the source of truth.
- Additive changes only; version and provide fallback.
- Do not rename existing keys without migration.

## FormContract (current)
Location: /project/contracts/forms/*.form.json
Keys (observed in repo):
- type, name, version, mode, entity?, action?
- layout: { type, columns, gap, sections[] }
- fields[]: { id, name, type, label, required?, validation?, options?, showSettings? }
- grids[]: { type, name, mode?, label?, columns[] }
- summary[]: { name, label, type, source?, expression?, watch? }
- reports[] (optional): { id, name, type, template?, description?, layout? }
  - layout.header: { title, subtitle?, showLogo? }
  - layout.meta: { label, fields[] } (datos del documento)
  - layout.issuer: { label, fields[] } (emisor)
  - layout.buyer: { label, fields[] } (cliente)
  - layout.fields: [] (otros datos)
  - layout.grid: { name, columns[] }
  - layout.totals: []
  - layout.footer: { text }
- dashboards[] (optional): { id, name, widgets[] }

## GridContract (current)
Location: embedded in FormContract (grids[])
Keys (observed):
- name, label
- columns[]: { name, label, input{type, optionsSource?, options?, allowSearch?}, total?, formula? }
- formula: { expression, watch[] }
- totals: legacy list (optional)

## EntityContract (current)
Location: /project/contracts/entities/*.entity.json
Schema: /framework/contracts/schemas/entity.schema.json
Keys:
- type, name, label, version
- table: { name, primaryKey, timestamps, softDelete, tenantScoped }
- fields[], grids[], relations[], rules[], permissions

## App Manifest (current)
Location: /project/contracts/app.manifest.json
Schema: /framework/contracts/schemas/app.manifest.schema.json
Keys: app, db, registry, auth?, integrations?, processes?

## IntegrationContract (new)
Location: /project/contracts/integrations/*.integration.json
Schema: /framework/contracts/schemas/integration.schema.json
Keys:
- id, type, provider, country, environment, base_url, enabled
- auth: { type, token_env }
- webhooks[], metadata?

## InvoiceContract (new)
Location: /project/contracts/invoices/*.invoice.json
Schema: /framework/contracts/schemas/invoice.schema.json
Keys:
- type=invoice, version, provider, country, entity, integration_id
- emit_endpoint/status_endpoint/cancel_endpoint
- mapping: document, sender, buyer, totals, items

## Planned optional contracts (versioned)
- DataContract v1: tables, fields, relations, indices, seeds, import metadata.
- WorkflowContract v1: triggers, actions, permissions, async.
- ChatContract v1: intents -> commands, slot policy, display policy.

## Fallback rule
If DataContract/AppContract missing, use current FormContract + EntityContract.
