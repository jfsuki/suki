# SECURITY

## Current risks (found)
- XSS: labels and values are inserted without escaping in FormBuilder/FormGenerator. (mitigated: Html::e + escapes)
- Formula eval: form-grid.js uses new Function; grid-engine.php uses eval. (mitigated: safe expression engine)
- CSRF: no tokens for POST forms when using cookies.
- IDOR/RBAC: no centralized permission checks in controllers.

## Minimum hardening plan (incremental)
1) Output escaping (DONE)
- Escape labels and values by default (Html::e).

2) Safe formula engine (DONE)
- Replace eval/new Function with a small expression parser (AST + whitelist).

3) Request security
- Add CSRF middleware for form POST.
- Rate limit sensitive endpoints.

4) Tenant guard
- Ensure all queries filter tenant_id when tenantScoped.

5) Headers
- Add CSP, HSTS (if TLS), X-Frame-Options, Referrer-Policy.

## Tests
- XSS payload in label/value should be escaped.
- Formula injection should be blocked.
- CSRF should fail without token.
