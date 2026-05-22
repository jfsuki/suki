-- Índices aditivos en tablas de app para tenant_id y created_at
-- Nota: MySQL no soporta CREATE INDEX IF NOT EXISTS — el script PHP verifica antes de crear
-- Este archivo es documentación; la ejecución real está en migrate_indexes.php

-- p_37a8eec1ce__asiento_lineas
-- ALTER TABLE p_37a8eec1ce__asiento_lineas ADD INDEX idx_p37_alineas_tid (tenant_id);
-- ALTER TABLE p_37a8eec1ce__asiento_lineas ADD INDEX idx_p37_alineas_cat (created_at);

-- p_37a8eec1ce__asientos_contables
-- ALTER TABLE p_37a8eec1ce__asientos_contables ADD INDEX idx_p37_asientos_cat (created_at);

-- p_37a8eec1ce__cuentas_contables
-- ALTER TABLE p_37a8eec1ce__cuentas_contables ADD INDEX idx_p37_cuentas_cat (created_at);

-- p_37a8eec1ce__golden_clientes_1771706711s
-- ALTER TABLE p_37a8eec1ce__golden_clientes_1771706711s ADD INDEX idx_p37_golden_tid (tenant_id);
-- ALTER TABLE p_37a8eec1ce__golden_clientes_1771706711s ADD INDEX idx_p37_golden_cat (created_at);

-- p_37a8eec1ce__kardex
-- ALTER TABLE p_37a8eec1ce__kardex ADD INDEX idx_p37_kardex_tid (tenant_id);
-- ALTER TABLE p_37a8eec1ce__kardex ADD INDEX idx_p37_kardex_cat (created_at);
