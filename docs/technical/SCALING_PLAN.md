# SCALING_PLAN — SUKI Technical Scaling Strategy

**Scope**: How to scale from 1 tenant to 100K+ without rewriting the platform.  
**See also**: `docs/ESTRATEGIA_ESCALABILIDAD_COSTOS.md` (cost analysis), `docs/technical/HOSTING_MIGRATION_PLAN.md` (migration path)  
**Updated**: 2026-05-16

---

## Current Architecture (Phase 0 — Single VPS)

```
[Browser] → Apache → framework/public/index.php (Builder/Marketplace)
                   → project/public/index.php  (Tenant Apps)
                   → tower/public/index.php    (Torre)
              ↓
           MySQL 8  +  SQLite (registry/knowledge)  +  Qdrant (vectors)
```

**Limits**: ~500 concurrent tenants on a 4-core VPS.

---

## Phase 1: Vertical Scale (0–1K tenants, ~6 months)

No architecture change needed. Upgrade the VPS:

| Resource | Current | Target |
|----------|---------|--------|
| CPU | 2 cores | 8 cores |
| RAM | 4 GB | 16 GB |
| MySQL | 1 instance | 1 instance + read replica |
| Qdrant | Local | Local (dedicated container) |

**Enable**:
- PHP-FPM connection pooling (`pm.max_children = 50`)
- MySQL query cache + slow query log
- Redis for PHP session storage (replaces file sessions)
- CDN for static assets

**Zero-downtime deploy**:
```bash
# 1. Pull new code to /var/www/suki_next
# 2. Run migrations against live DB
php framework/scripts/db_migrate.php --dry-run
php framework/scripts/db_migrate.php
# 3. Swap symlink atomically
ln -sfn /var/www/suki_next /var/www/suki
# 4. Reload PHP-FPM (graceful, no dropped requests)
systemctl reload php8.1-fpm
```

---

## Phase 2: Horizontal Scale (1K–10K tenants)

Split read and write paths:

```
[Load Balancer (HAProxy/Nginx)]
    ├── App Server 1 (PHP-FPM)
    ├── App Server 2 (PHP-FPM)
    └── App Server N (PHP-FPM)
              ↓
    MySQL Primary (writes) + Replicas (reads)
    Redis Cluster (sessions, cache)
    Qdrant Cluster (vectors)
    S3-compatible storage (media, RUT PDFs)
```

**Multi-tenant isolation** already enforced by `BaseRepository` — no code change needed.

**Session sharing**: Move `session_save_path` to Redis so any app server handles any request.

**Qdrant tenant filtering**: Must fix `QdrantVectorStore::query()` to add `tenant_id` filter before scaling (see FAILURE_MAP F05).

---

## Phase 3: Full Multi-Region (10K–100K tenants)

```
[Global CDN]
    ├── Region CO (Bogotá primary)
    │     ├── App Servers
    │     ├── MySQL Primary
    │     └── Qdrant Primary
    └── Region MX (Mexico City replica)
          ├── App Servers
          ├── MySQL Replica (async)
          └── Qdrant Replica
```

**Tenant routing**: Route tenant traffic to nearest region by phone/NIT prefix.

**DIAN compliance**: Colombian fiscal data MUST stay in CO region.

---

## LLM Cost Control

The 6-provider failover chain (`config/llm.php`) already handles provider outages. At scale, add:

1. **Semantic cache** — Cache LLM responses by embedding similarity (>0.95 score). Cuts 40% of LLM calls for repetitive queries.
2. **Model tiering** — Use fast/cheap models (Groq/DeepSeek) for simple intents, premium (Claude/GPT) for fiscal/legal queries.
3. **Router-first** — Every request tries Cache→Rules→RAG before reaching LLM. At 10K tenants, 70%+ should never hit LLM.

---

## Database Sharding Strategy

Current: Single MySQL DB, all tenants share tables (rows isolated by `tenant_id`).

At 100K tenants (~10B rows), shard by `tenant_id` hash:
- Shard 0: `tenant_id` hash % 4 === 0
- Shard 1: `tenant_id` hash % 4 === 1
- etc.

`BaseRepository` can be extended with a shard resolver. No app code changes needed.

App Creator tables (`app_{app_id}__{entity}`) already per-app — natural shard boundary.

---

## Monitoring Checklist (Before Phase 2)

- [ ] MySQL slow query log enabled
- [ ] AgentOps traces shipped to centralized log store
- [ ] Qdrant health endpoint monitored
- [ ] PHP error log aggregated
- [ ] Tenant count metric tracked
- [ ] LLM provider error rates tracked per provider
- [ ] `ops_token_usage` table archived monthly (prevents unbounded growth)
