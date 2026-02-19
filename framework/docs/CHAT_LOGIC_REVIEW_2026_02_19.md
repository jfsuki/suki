## Chat Logic Review (2026-02-19)

### 1) What failed in your conversation

- The builder interpreted filler words as entity names (`en`, `quiero`) because entity extraction was too literal.
- In app mode, some intents matched "entity list/status" when the user actually asked for runtime CRUD.
- The chat sometimes answered as if data existed ("Ana") without a real query result.
- `agent_state` is persisted by tenant+user; stale `active_task` can leak into a new UI session.
- When DB connection fails, user saw generic error instead of a clear action.

### 2) Logic baseline that must be enforced

1. **Mode guard first**  
   - BUILD: create structure (tables/forms/contracts).  
   - USE: CRUD and reports only.
2. **Registry reality check before answer**  
   - Every response must be based on current entities/forms/integrations in registry.
3. **Entity existence before CRUD**  
   - If entity missing in APP -> ask to contact creator.
   - If entity missing in BUILDER -> offer create table flow.
4. **No hallucinations**  
   - Never claim a customer exists unless query returned rows.
5. **One-question slot filling**  
   - Ask only the next required field.
6. **Session-safe state**  
   - Keep profile memory long-term.
   - Keep `active_task/requested_slot` short-lived and resettable.

### 3) Business-knowledge memory added

New external memory contracts (editable JSON):

- `framework/contracts/agents/domain_playbooks.json`
  - Business profiles (veterinaria, farmacia, ferreteria, corte_laser).
  - Suggested entities and recommended fields.
  - Response policy for APP vs BUILDER behavior.

- `framework/contracts/agents/accounting_tax_knowledge_co.json`
  - Accounting/tax baseline for CO assistant behavior.
  - Invoice minimum data, pre/post emission checklists.
  - Strict anti-hallucination operational rules.

### 4) Reference patterns from market leaders (for product direction)

- **Power Apps**: guided creation over Dataverse and templates.  
  https://learn.microsoft.com/en-us/power-apps/
- **AppSheet**: data-first generation and no-code app from existing data.  
  https://about.appsheet.com/home/
- **Odoo**: modular business apps by domain/industry workflows.  
  https://www.odoo.com/app
- **ERPNext**: open ERP modules with accounting/inventory/manufacturing.  
  https://docs.erpnext.com/
- **Supabase**: strong backend primitives and policy-first data access.  
  https://supabase.com/docs
- **Vercel v0**: prompt-driven UI generation with rapid iteration loop.  
  https://v0.dev/
- **Alanube API** (e-invoicing provider): auth, endpoints, integration flow.  
  https://developer.alanube.co/docs/getting-started
- **DIAN** (CO authority): legal baseline reference for FE context.  
  https://www.dian.gov.co/

### 5) Next implementation step (before more UI work)

1. Add **context compiler** in gateway:
   - `available_entities`, `available_forms`, `enabled_integrations`, `db_health`.
2. Bind conversation to external memory:
   - load business playbook by profile,
   - suggest fields when user asks "que campos debe tener...".
3. Separate state layers:
   - long-term profile memory,
   - short-lived task state with TTL/reset per chat session.
4. Enforce anti-hallucination response policy:
   - if no query executed -> no claim about specific records.

