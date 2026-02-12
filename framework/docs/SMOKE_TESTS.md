# SMOKE_TESTS (framework/docs)

Purpose: minimal no-regression checks before pushing changes to production.
Goal: catch breaking changes in forms, grids, summary, editor JSON.

## 0) Quick Script (1 minute)
Run the automated sanity checks:
```
powershell -ExecutionPolicy Bypass -File framework/docs/smoke.ps1
```
This validates JSON contracts and warns about broken formulas or ambiguous columns.

## 1) Manual Smoke (5-10 minutes)

### A) Facturas
- Open: /facturas
- Add 2 rows in grid
- Change cantidad, valor_unitario, IVA (5% / 0%)
- Expect:
  - Subtotal per row updates
  - IVA per row updates
  - Grid totals update
  - Summary values update

### B) Cuentas por cobrar
- Open: /cuentas_cobrar
- Add 2 rows, set valor + abono
- Expect:
  - saldo row = valor - abono
  - Totals update
  - Summary total updates (not stuck at 0)

### C) Clientes
- Open: /clientes
- Add 1 row in grid with subtotal
- Expect:
  - Totals and summary update

### D) Editor JSON
- Open: framework host /editor_json/formjson.html
- Expect:
  - Buttons work (create form, save)
  - UI sections toggle
  - JSON output updates after edits

### E) Chat tests (API)
- Open: Editor JSON > Chat/Pruebas
- Set API base to project host
- Run "Pruebas rapidas"
- Expect: responses success (CreateRecord + QueryRecords)

## 2) Minimum Release Rules
- If any step fails, rollback or fix before deploy.
- If a contract change affects summary/formula, update both:
  - project/contracts/forms
  - project/views/*/*.form.json (fallback)
- Always keep changes backward compatible.
