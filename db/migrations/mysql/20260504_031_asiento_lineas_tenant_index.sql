-- Migration: 20260504_031_asiento_lineas_tenant_index.sql
-- Adds missing tenant_id index on asiento_lineas to prevent full table scans
-- under multi-tenant load. Safe to run on existing tables.

CREATE INDEX IF NOT EXISTS idx_lines_tenant ON asiento_lineas(tenant_id, asiento_id);
