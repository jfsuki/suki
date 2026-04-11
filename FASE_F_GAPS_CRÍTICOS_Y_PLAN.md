# FASE F — GAPS CRÍTICOS & PLAN DE ACCIÓN: ROADMAP 8 SEMANAS

**Fecha**: 2026-04-09  
**Alcance**: Consolidación de todos los gaps encontrados en FASES A-E + priorización + timeline  
**Audiencia**: Equipo técnico, stakeholders, decision makers

---

## EXECUTIVE SUMMARY

### Status Actual
- **Code Base**: 71/71 unit tests PASS, 24/24 chat golden PASS, security hardened
- **Agentes**: 30+ funcionales, 6 especializados (50% con cálculos reales)
- **Memoria**: 4 capas implementadas (session, user, business, semantic)
- **Aislamiento**: Multi-tenant OK en queries, CRÍTICO en Qdrant
- **Learning**: Pipeline completo pero falta automación

### GO/NO-GO Assessment

**GO READY**: Capas de agentes, routing, memoria, aislamiento (SQL)  
**BLOCKERS CRÍTICOS**: 3 gaps que DEBEN fixearse antes de producción  
**BLOCKERS ALTOS**: 5 gaps que deben fixearse en primera semana  
**DEUDA TÉCNICA**: 10 gaps que pueden ir post-GO

---

## PARTE 1: GAPS CRÍTICOS (GO/NO-GO)

### 🔴 CRÍTICO #1: Qdrant Payload Filtering (Tenant Isolation)

**Severidad**: P0 — Seguridad crítica  
**Impacto**: Leak de datos entre tenants en búsquedas semánticas  
**Evidencia**: QdrantVectorStore::query() sin auto-filtrado tenant_id  
**Escenario Real**:
```
Tenant A: Query "presupuestos" → Qdrant top-5 (no filtra tenant)
          Retorna chunks de Tenant A, B, C, D
          User A ve datos de User B ✗ LEAK
```

**Fix Requerido**:
```php
// En QdrantVectorStore::query()
if ($this->tenantId !== null) {
    $filter['must'][] = [
        'match' => ['tenant_id' => ['value' => $this->tenantId]]
    ];
}
```

**Esfuerzo**: 2-3 horas  
**Tests Requeridos**: 
- Test cross-tenant retrieval blocked
- Test same-tenant retrieval works
- Integration test with RAG

**Timeline**: INMEDIATAMENTE (antes de cualquier deploy)

---

### 🔴 CRÍTICO #2: FE Electrónica DIAN (XML UBL 2.1 + CUFE + Firma)

**Severidad**: P0 — Compliance obligatorio (Colombia)  
**Impacto**: Sistema no cumple requisitos fiscales DIAN  
**Evidencia**: 
- FiscalEngineService línea ~200 → AlanubeClient llamada
- AlanubeIntegrationAdapter payload VACÍO (stub)
- No XML UBL 2.1, no CUFE, no firma digital

**Qué Falta**:
1. **XML UBL 2.1 Generation** (~40 horas)
   - Estructura documento fiscal (invoice type, items, taxes)
   - Namespaces DIAN (NIT, DV validation)
   - Líneas de detalle con códigos UNECE

2. **CUFE (Código Único Facturación Electrónica)** (~20 horas)
   - Hash SHA-256 de (NIT+factura+fecha+total+empresa+software)
   - Validación contra DIAN
   - Storage en DB

3. **Firma Digital** (~40 horas)
   - Certificado X.509 (obtener de Alanube o GoDaddy)
   - Firma RSA de XML
   - Timestamp DIAN

4. **Integración Alanube Real** (~20 horas)
   - Test real API (no mock)
   - Manejo de errores DIAN
   - Retry policy

**Total Esfuerzo**: 15-20 días (120-160 horas)  
**Bloqueador Absoluto**: NO VENDER sin esto en Colombia

**Timeline**: SEMANAS 1-3 (paralelo con otros fixes)

---

### 🔴 CRÍTICO #3: PUC Real + ReteFuente + ICA

**Severidad**: P0 — Contabilidad correcta  
**Impacto**: Balances contables incorrectos, auditoría DIAN fallida

#### PUC (Plan Único de Cuentas) Colombiano
**Problema**: Cuentas hardcodeadas (1, 2, 99)  
**Real Requerido**: 5000+ cuentas DIAN

**Estructura**:
```
1. Activo
  11. Caja y bancos
    1110. Caja
    1120. Bancos nacionales
  12. Cartera (CxC)
    1210. Clientes nacionales
  ...
2. Pasivo
  21. Obligaciones financieras
3. Patrimonio
4. Ingresos
  4105. Ventas (IVA incluido)
  4110. Ventas (sin IVA)
5. Costos
6. Gastos
7. Otros Ingresos
8. Otros Gastos
9. Provisiones
```

**Implementación**:
- JSON seed con todas las 5000+ cuentas
- Validación de estructura al crear asientos
- Mappings automáticos (Caja → 1110, Ventas → 4105)

**Esfuerzo**: 5-8 días (data + validación)

#### ReteFuente (Retención en la Fuente)
**Problema**: No implementado (stub en FiscalEngineService línea 761)  
**Real Requerido**:
- Base gravable (monto - descuentos)
- Tasa retención por tipo (personas, empresas, servicios)
- Cálculo: ReteFuente = Base × Tasa
- Asiento contable: Descuento cuenta 2365 (ReteFuente por pagar)

**Tasas Típicas**:
- Personas naturales: 3.5%
- Servicios: 5%
- Compras nacionales: 2-8% (según tipo)
- Exportación: variable

**Validación**: Retención no puede > 50% de valor

**Esfuerzo**: 5-8 días (cálculo + validación + DB)

#### ICA (Impuesto de Actividades Económicas)
**Problema**: Hardcoded al 0% o sin considerar municipio  
**Real Requerido**:
- ICA por municipio (Bogotá 6.86%, Medellín 4.14%, etc.)
- Base: Total venta
- Cálculo: ICA = Base × Tasa municipio
- Asiento: Débito gasto ICA, Crédito 2335 (ICA por pagar)

**Configuración Requerida**: JSON de municipios + tasas

**Esfuerzo**: 3-5 días (lookup table + validation)

**Total PUC + ReteFuente + ICA**: 8-12 días

**Timeline**: SEMANAS 1-2 (paralelo con FE)

---

## PARTE 2: BLOCKERS ALTOS (Primera Semana)

### 🟠 ALTO #4: ConversationMemory Thread Validation (Tenant Crossing)

**Severidad**: P1 — Security medium (mitigado por UUID session)  
**Problema**: thread_id opaco, sin validación cross-tenant  
**Fix**: Extraer tenant_id del thread_id, validar vs TenantContext

**Esfuerzo**: 4-6 horas  
**Timeline**: Semana 1 (post Qdrant filtering)

---

### 🟠 ALTO #5: Architect Agent Execution (Design Tools Real)

**Severidad**: P1 — Feature incompleto  
**Problema**: Architect agent define pero no ejecuta en builder mode  
**Gap**: 
- EntityBuilder sí crea tablas
- ContractWriter sí genera JSON
- Pero integration con builder mode incompleta

**Fix**: Hookear architect outputs a builder workflow

**Esfuerzo**: 6-10 horas  
**Timeline**: Semana 1-2

---

### 🟠 ALTO #6: DashboardEngine Raw SQL Tenant Check

**Severidad**: P1 — Minor (low impact queries)  
**Problema**: `SELECT COUNT(*) FROM {table}` sin WHERE tenant_id  
**Impact**: Dashboards podrían mostrar aggregate global vs tenant

**Fix**: Validar que calculate* methods aplican tenant scope

**Esfuerzo**: 2-3 horas  
**Timeline**: Semana 1

---

### 🟠 ALTO #7: Learning Auto-Promotion (Productivity)

**Severidad**: P1 — UX optimization  
**Problema**: Candidates pending hasta manual approval  
**Gap**: Sin auto-promote based on frequency + confidence

**Fix**: 
```php
if ($frequency >= 10 && $confidence >= 0.85) {
    review_status = 'approved';  // Auto-promote
}
```

**Esfuerzo**: 3-4 horas  
**Timeline**: Semana 2

---

### 🟠 ALTO #8: Semantic Memory Per-Sector Isolation

**Severidad**: P1 — Learning correctness  
**Problema**: Accounting learning puede contaminar Sales learning  
**Gap**: Sin sector_id filtering en Qdrant payloads

**Fix**: Add sector dimension to learning candidates + Qdrant filter

**Esfuerzo**: 8-12 horas  
**Timeline**: Semana 2-3

---

## PARTE 3: DEUDA TÉCNICA (Post-GO)

### 🟡 TECH DEBT #9: ALTER Column Diff (EntityMigrator)

**Severidad**: P1 — Data integrity risk  
**Problema**: `ALTER TABLE ... MODIFY COLUMN` no implementado  
**Impact**: Renombrar campo = pérdida de datos

**Affected**: `framework/app/Core/EntityMigrator.php` línea 101 (solo ADD)

**Fix Requerido**:
- MODIFY COLUMN (cambiar tipo)
- DROP COLUMN (con backup)
- RENAME COLUMN (mapeo de datos)

**Esfuerzo**: 5-8 días (schema versioning)  
**Timeline**: Semana 4-5 (post-GO)

---

### 🟡 TECH DEBT #10: ChatAgent Strangler (Code Smell)

**Severidad**: P2 — Maintainability  
**Problema**: ChatAgent 4652 líneas, ConversationGateway 2450 líneas  
**Goal**: Reducir ambos -15% (extraer ~700 líneas)

**Candidates to Extract**:
- FormWizard logic (200L)
- ContractWriter logic (150L)
- EntityBuilder logic (150L)
- Telemetry dispatch (100L)

**Esfuerzo**: 10-15 días  
**Timeline**: Semana 6-7 (refactor after stabilization)

---

### 🟡 TECH DEBT #11: FORM_STORE Persistence

**Severidad**: P2 — UX friction  
**Problema**: Formularios guardados SOLO en localStorage  
**Impact**: User cierra browser = pierde form data

**Fix**: Persist form_store en DB (mem_user table)

**Esfuerzo**: 3-5 días  
**Timeline**: Semana 5

---

### 🟡 TECH DEBT #12: Row-Level Security

**Severidad**: P2 — Advanced RBAC  
**Problema**: Users within same tenant ven todos los records  
**Impact**: Seller A puede ver órdenes de Seller B

**Solution**: Add user_id filtering en entity queries (conditional)

**Esfuerzo**: 10-15 días  
**Timeline**: Semana 7-8

---

### 🟡 TECH DEBT #13: E2E HTTP Tests

**Severidad**: P2 — QA confidence  
**Problema**: Tests son PHP internal, sin HTTP real  
**Gap**: No validación de API real, headers, CORS, etc.

**Solution**: Create `tests/e2e_http/` with real HTTP requests

**Test Cases**:
- POS → Fiscal → Invoice full flow
- Ecommerce sync workflow
- Multi-tenant isolation (actual HTTP)

**Esfuerzo**: 5-8 días  
**Timeline**: Week 6

---

### 🟡 TECH DEBT #14: CI Remote Integration

**Severidad**: P2 — DevOps readiness  
**Problema**: No CI/CD pipeline (tests run local only)  
**Gap**: Sin GitHub Actions, no auto-deploy

**Solution**: GitHub Actions workflow + deploy to staging

**Esfuerzo**: 3-5 días  
**Timeline**: Week 7

---

### 🟡 TECH DEBT #15: Qdrant Cold Start

**Severidad**: P2 — Deployment pain  
**Problema**: Nuevo deploy = Qdrant vacío (48h sin semantic retrieval)  
**Solution**: Seed con domain_playbooks.json + sector knowledge

**Esfuerzo**: 2-3 días  
**Timeline**: Week 5 (pre-deploy checklist)

---

## PARTE 4: TOP 10 GAPS POR IMPACTO EN USUARIOS

| # | Gap | Impacto | Severidad | Esfuerzo | Timeline |
|---|-----|---------|-----------|----------|----------|
| 1 | **FE Electrónica (XML+CUFE+Firma)** | NO vender sin esto (DIAN) | 🔴 P0 | 15-20d | W1-3 |
| 2 | **Qdrant Tenant Filtering** | Security leak between tenants | 🔴 P0 | 2-3h | NOW |
| 3 | **PUC Real (5000+ cuentas)** | Balances incorrectos | 🔴 P0 | 5-8d | W1-2 |
| 4 | **ReteFuente Calculation** | Impuestos mal calculados | 🔴 P0 | 5-8d | W1-2 |
| 5 | **ICA Variable (municipio)** | Impuestos incompletos | 🔴 P0 | 3-5d | W1-2 |
| 6 | **Conversation Thread Isolation** | Leak de chat entre tenants | 🟠 P1 | 4-6h | W1 |
| 7 | **Learning Auto-Promotion** | Slow improvement loop | 🟠 P1 | 3-4h | W2 |
| 8 | **Learning Per-Sector** | Mixed training data | 🟠 P1 | 8-12h | W2-3 |
| 9 | **ALTER Table Migrations** | Data loss risk on schema changes | 🟠 P1 | 5-8d | W4-5 |
| 10 | **Row-Level Security** | Users see other users' data (same tenant) | 🟡 P2 | 10-15d | W7-8 |

---

## PARTE 5: ROADMAP 8 SEMANAS

### ⚠️ PRE-REQUISITO: Qdrant Tenant Filtering (AHORA)
- 2-3 horas
- BLOCKER para cualquier deploy
- Validar con test cross-tenant

---

### **SEMANA 1: SECURITY + CORE FIXES**

#### Día 1-2: Qdrant Filtering (Complete)
- [ ] Add tenant_id auto-filter in query()
- [ ] Tests: cross-tenant retrieval blocked
- [ ] Tests: same-tenant retrieval works
- [ ] Code review + merge

#### Día 3-4: ConversationMemory Validation
- [ ] Extract tenant from thread_id
- [ ] Validate vs TenantContext
- [ ] Tests: thread_id guess attack blocked
- [ ] Merge

#### Día 5: DashboardEngine Audit
- [ ] Review all raw SQL queries
- [ ] Add tenant_id WHERE clauses
- [ ] Tests: global aggregates filtered by tenant
- [ ] Merge

#### Deliverables
- ✅ All security gaps closed
- ✅ Cross-tenant tests PASS
- ✅ Ready for Accounting work

---

### **SEMANA 2: FISCAL FOUNDATION**

#### Parallel Streams:

**Stream A: FE Electrónica (40h)**
- [ ] Day 1-2: XML UBL 2.1 generator
- [ ] Day 3: CUFE hash implementation
- [ ] Day 4-5: Sign & integrate Alanube

**Stream B: PUC + Taxes (30h)**
- [ ] Day 1: Load PUC 5000+ accounts
- [ ] Day 2: ReteFuente rates + calc
- [ ] Day 3: ICA municipal lookup
- [ ] Day 4-5: Tests + validation

**Deliverables**
- ✅ FE structure ready (XML generation works)
- ✅ PUC fully loaded
- ✅ ReteFuente + ICA calculated
- ✅ Tests: create invoice → XML → CUFE generated

---

### **SEMANA 3: FE COMPLETION + LEARNING**

#### Parallel:

**Stream A: FE Testing & Polish (24h)**
- [ ] Real integration tests with mock Alanube
- [ ] Error handling (DIAN rejection cases)
- [ ] Edge cases (zero-tax, negative amounts)
- [ ] Performance (batch invoice generation)

**Stream B: Learning Automation (18h)**
- [ ] Auto-promotion logic (freq >= 10, conf >= 0.85)
- [ ] Per-sector isolation in learning candidates
- [ ] Tests: auto-promotion workflow

**Deliverables**
- ✅ FE production-ready (tests PASS)
- ✅ Learning auto-promotion working
- ✅ Ready for alpha testing

---

### **SEMANA 4-5: TECH DEBT + POLISH**

#### Stream A: EntityMigrator (40h)
- [ ] Week 4: MODIFY COLUMN support
- [ ] Week 4: DROP COLUMN with backup
- [ ] Week 5: Schema versioning

#### Stream B: FORM_STORE Persistence (24h)
- [ ] Week 4: Persist to mem_user
- [ ] Week 5: Sync localStorage ↔ DB

**Deliverables**
- ✅ Schema migrations robust
- ✅ Form data persisted across sessions

---

### **SEMANA 6: QA + DEPLOYMENT READINESS**

- [ ] E2E HTTP tests (POS → Fiscal → Invoice)
- [ ] E2E Ecommerce sync tests
- [ ] Cross-tenant isolation validation (actual HTTP)
- [ ] Qdrant cold start seed
- [ ] Performance profiling
- [ ] Security audit (OWASP top 10)

**Deliverables**
- ✅ All E2E tests PASS
- ✅ Deployment checklist ready

---

### **SEMANA 7: STABILIZATION + ADVANCED FEATURES**

- [ ] CI/CD pipeline (GitHub Actions)
- [ ] Staging deploy + smoke tests
- [ ] Strangler pattern (refactor ChatAgent -200L)
- [ ] Row-level security foundation

**Deliverables**
- ✅ Automated CI/CD
- ✅ Code cleaner (4452L → 4250L targets)

---

### **SEMANA 8: LAUNCH PREP + DOCS**

- [ ] Final regression testing
- [ ] Production deployment docs
- [ ] Operations runbook
- [ ] User guide (Fiscal features)
- [ ] Go-live checklist

**Deliverables**
- ✅ Ready for production launch

---

## PARTE 6: DEPENDENCY MAP

```
NOW (Pre-requisite)
└─ Qdrant Filtering (2-3h)

SEMANA 1
├─ Qdrant Filtering ✓
├─ Conversation Thread Validation
└─ DashboardEngine Audit

SEMANA 2 (Parallel)
├─ FE Electrónica XML (blocks CUFE signing)
│  └─ FE Complete (S3)
├─ PUC Loading (blocks ReteFuente calc)
│  ├─ ReteFuente (blocks tests)
│  └─ ICA Lookup (blocks tests)

SEMANA 3
├─ FE Integration Tests (depends S2 FE)
└─ Learning Auto (depends S1)

SEMANA 4-5
├─ EntityMigrator (independent)
└─ FORM_STORE (independent)

SEMANA 6
├─ E2E Tests (depends S3 FE, S2 PUC, S3 Learning)
├─ Qdrant Cold Start (depends S3)
└─ Security Audit (depends all)

SEMANA 7
├─ CI/CD (independent)
├─ RLS Foundation (depends S1 queries)
└─ Refactor (independent)

SEMANA 8
└─ Launch (depends S6 E2E + S7 CI/CD)
```

---

## PARTE 7: ESTIMACIONES & RECURSOS

### Esfuerzo Total: 63-85 días (~320-425 horas)

| Category | Hours | Days | % of Total |
|----------|-------|------|-----------|
| **Críticos (MUST)** | 280 | 35 | 65% |
| Alto (HIGH) | 80 | 10 | 19% |
| Deuda Técnica | 60 | 7.5 | 14% |
| Docs + Launch | 20 | 2.5 | 5% |
| **TOTAL** | **440** | **55** | **100%** |

### Team Composition (Weeks 1-3)
- **Backend Lead**: Fiscal + Accounting (FE, PUC, ReteFuente, ICA)
- **Security Lead**: Qdrant filtering, Conversation validation
- **QA Lead**: Testing, E2E setup
- **DevOps**: CI/CD setup (parallel W7)

### Critical Path
**Must Start Day 1**: Qdrant filtering  
**Must Complete W2**: FE + PUC + ReteFuente  
**Must Complete W3**: FE tests + Learning auto  
**Launch Readiness**: W6 end

---

## PARTE 8: GO/NO-GO DECISION GATES

### Gate 1: End of Week 1
**Criteria**:
- ✅ Qdrant tenant filtering merged + tested
- ✅ ConversationMemory validation merged
- ✅ Zero security findings in audit
- ✅ All S1 tests PASS

**Decision**: Proceed to FE development

---

### Gate 2: End of Week 2
**Criteria**:
- ✅ FE XML generation works (real format)
- ✅ CUFE hash implemented
- ✅ PUC fully loaded (5000+ accounts validated)
- ✅ ReteFuente + ICA calculated correctly
- ✅ No data corruption in test imports

**Decision**: Proceed to FE integration

---

### Gate 3: End of Week 3
**Criteria**:
- ✅ FE tests PASS (mock Alanube integration)
- ✅ Learning auto-promotion working
- ✅ Per-sector learning isolation confirmed
- ✅ POS→Fiscal→Invoice E2E PASS

**Decision**: Proceed to QA/polish phase

---

### Gate 4: End of Week 6
**Criteria**:
- ✅ All E2E HTTP tests PASS
- ✅ Cross-tenant isolation validated (HTTP level)
- ✅ Performance acceptable (< 2s per request)
- ✅ Security audit PASS (0 critical findings)
- ✅ Qdrant cold start functional

**Decision**: Ready for staging/production deploy

---

## PARTE 9: RISK MITIGATION

### Risk 1: FE DIAN Complexity
**Likelihood**: MEDIUM (XML specs evolve)  
**Impact**: CRITICAL (can't sell)  
**Mitigation**: 
- Start with Alanube API docs week 1
- Mock Alanube early (don't wait for real API)
- Create test cases for DIAN rejection scenarios

### Risk 2: PUC Data Quality
**Likelihood**: LOW (public data available)  
**Impact**: HIGH (wrong balances)  
**Mitigation**:
- Source PUC from official DIAN/DANE
- Validate account codes, no duplicates
- Import script with rollback

### Risk 3: Qdrant Migration Complexity
**Likelihood**: MEDIUM (vector DBs tricky)  
**Impact**: MEDIUM (learning disabled)  
**Mitigation**:
- Qdrant filtering is ISOLATED change
- Test with mock first (all tests use mocks)
- Gradual rollout: sandbox → staging → prod

### Risk 4: Timeline Slip
**Likelihood**: MEDIUM (FE is complex)  
**Impact**: HIGH (delays launch)  
**Mitigation**:
- Parallel streams (don't serialize)
- Daily standup (identify blockers early)
- Stretch vs Critical priorities clear

---

## PARTE 10: SUCCESS CRITERIA

### For Production Launch
- ✅ All P0 gaps closed (Qdrant, FE, PUC, Taxes)
- ✅ 71/71 unit tests PASS
- ✅ 30+ E2E tests PASS
- ✅ Security audit: 0 critical, < 5 high
- ✅ Cross-tenant isolation: 100% validated
- ✅ FE generation: XML + CUFE + Signature working
- ✅ POS→Fiscal→Invoice: Full flow tested
- ✅ Performance: < 2s per request p95

### For Post-Launch (Continuous)
- ✅ Learning auto-promotion active
- ✅ Per-sector training isolated
- ✅ Monitoring + alerting setup
- ✅ Runbook + escalation paths documented

---

## CONCLUSIÓN

### What's Working Now
- ✅ 30+ agents, routing, memory (4 layers), security (SQL)
- ✅ 71/71 tests PASS, chat flows operational
- ✅ Multi-tenant kernel solid
- ✅ Learning pipeline complete (needs automation)

### What Must Be Fixed
1. **Qdrant tenant filtering** (2-3h) — IMMEDIATE
2. **FE Electrónica** (15-20d) — W1-3
3. **PUC + Taxes** (8-12d) — W1-2
4. **Security gaps** (1d) — W1

### What Can Wait
- Strangler refactoring, Row-level security, Advanced RLS (post-GO)

### Recommendation
**LAUNCH READY**: If all P0 gaps fixed in W1-3 + E2E tests pass W6  
**TIMELINE**: 8 weeks (aggressive but achievable with parallel streams)  
**TEAM**: 3-4 people (Backend + Security + QA)  
**CONFIDENCE**: 75% (FE complexity is main risk)

---

## APPENDIX: CHANGE LOG

| Date | Event | Impact |
|------|-------|--------|
| 2026-04-09 | FASE A-F Audit Complete | Baseline established |
| W1 D1 | Qdrant filtering deployed | Security improved |
| W1 D5 | All S1 gates passed | FE development green-lit |
| W2 D5 | FE XML + PUC ready | Fiscal foundation solid |
| W3 D5 | FE + Learning complete | MVP feature-complete |
| W6 D5 | All E2E tests PASS | Production-ready |
| W8 D5 | Go-Live | 🚀 LAUNCH |

---

**FASE F Completada**: Análisis integral de gaps + roadmap 8 semanas  
**Status**: 🟡 YELLOW → 🟢 GREEN (if W1-3 delivered)  
**Recomendación**: Start immediately, parallelize streams, daily standups

