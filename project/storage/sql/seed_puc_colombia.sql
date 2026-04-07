-- seed_puc_colombia.sql
-- Carga base del Plan Único de Cuentas (PUC) estándar para PYMES en Colombia.
-- Se asume una tabla 'cuentas_contables' con columnas: tenant_id, code, name, parent_id, level, type, is_active.

-- ACTIVO (1)
INSERT INTO cuentas_contables (tenant_id, code, name, level, type, is_active) VALUES ('system', '1', 'ACTIVO', 1, 'asset', 1);
INSERT INTO cuentas_contables (tenant_id, code, name, level, type, is_active) VALUES ('system', '11', 'DISPONIBLE', 2, 'asset', 1);
INSERT INTO cuentas_contables (tenant_id, code, name, level, type, is_active) VALUES ('system', '1105', 'CAJA', 3, 'asset', 1);
INSERT INTO cuentas_contables (tenant_id, code, name, level, type, is_active) VALUES ('system', '1110', 'BANCOS', 3, 'asset', 1);
INSERT INTO cuentas_contables (tenant_id, code, name, level, type, is_active) VALUES ('system', '13', 'DEUDORES / CARTERA', 2, 'asset', 1);
INSERT INTO cuentas_contables (tenant_id, code, name, level, type, is_active) VALUES ('system', '1305', 'CLIENTES', 3, 'asset', 1);
INSERT INTO cuentas_contables (tenant_id, code, name, level, type, is_active) VALUES ('system', '1355', 'ANTICIPO DE IMPUESTOS Y CONTRIBUCIONES', 3, 'asset', 1);
INSERT INTO cuentas_contables (tenant_id, code, name, level, type, is_active) VALUES ('system', '14', 'INVENTARIOS', 2, 'asset', 1);
INSERT INTO cuentas_contables (tenant_id, code, name, level, type, is_active) VALUES ('system', '1435', 'MERCANCIAS NO FABRICADAS POR LA EMPRESA', 3, 'asset', 1);

-- PASIVO (2)
INSERT INTO cuentas_contables (tenant_id, code, name, level, type, is_active) VALUES ('system', '2', 'PASIVO', 1, 'liability', 1);
INSERT INTO cuentas_contables (tenant_id, code, name, level, type, is_active) VALUES ('system', '22', 'PROVEEDORES', 2, 'liability', 1);
INSERT INTO cuentas_contables (tenant_id, code, name, level, type, is_active) VALUES ('system', '2205', 'NACIONALES', 3, 'liability', 1);
INSERT INTO cuentas_contables (tenant_id, code, name, level, type, is_active) VALUES ('system', '23', 'CUENTAS POR PAGAR', 2, 'liability', 1);
INSERT INTO cuentas_contables (tenant_id, code, name, level, type, is_active) VALUES ('system', '2335', 'COSTOS Y GASTOS POR PAGAR', 3, 'liability', 1);
INSERT INTO cuentas_contables (tenant_id, code, name, level, type, is_active) VALUES ('system', '2365', 'RETENCION EN LA FUENTE', 3, 'liability', 1);
INSERT INTO cuentas_contables (tenant_id, code, name, level, type, is_active) VALUES ('system', '24', 'IMPUESTOS, GRAVAMENES Y TASAS', 2, 'liability', 1);
INSERT INTO cuentas_contables (tenant_id, code, name, level, type, is_active) VALUES ('system', '2408', 'IMPUESTO SOBRE LAS VENTAS POR PAGAR (IVA)', 3, 'liability', 1);

-- PATRIMONIO (3)
INSERT INTO cuentas_contables (tenant_id, code, name, level, type, is_active) VALUES ('system', '3', 'PATRIMONIO', 1, 'equity', 1);
INSERT INTO cuentas_contables (tenant_id, code, name, level, type, is_active) VALUES ('system', '31', 'CAPITAL SOCIAL', 2, 'equity', 1);
INSERT INTO cuentas_contables (tenant_id, code, name, level, type, is_active) VALUES ('system', '3115', 'APORTES SOCIALES', 3, 'equity', 1);
INSERT INTO cuentas_contables (tenant_id, code, name, level, type, is_active) VALUES ('system', '36', 'RESULTADO DEL EJERCICIO', 2, 'equity', 1);
INSERT INTO cuentas_contables (tenant_id, code, name, level, type, is_active) VALUES ('system', '3605', 'UTILIDAD DEL EJERCICIO', 3, 'equity', 1);

-- INGRESOS (4)
INSERT INTO cuentas_contables (tenant_id, code, name, level, type, is_active) VALUES ('system', '4', 'INGRESOS', 1, 'revenue', 1);
INSERT INTO cuentas_contables (tenant_id, code, name, level, type, is_active) VALUES ('system', '41', 'OPERACIONALES', 2, 'revenue', 1);
INSERT INTO cuentas_contables (tenant_id, code, name, level, type, is_active) VALUES ('system', '4135', 'COMERCIO AL POR MAYOR Y AL POR MENOR', 3, 'revenue', 1);
INSERT INTO cuentas_contables (tenant_id, code, name, level, type, is_active) VALUES ('system', '4175', 'DEVOLUCIONES, REBAJAS Y DESCUENTOS EN VENTAS (DB)', 3, 'revenue', 1);

-- GASTOS (5)
INSERT INTO cuentas_contables (tenant_id, code, name, level, type, is_active) VALUES ('system', '5', 'GASTOS', 1, 'expense', 1);
INSERT INTO cuentas_contables (tenant_id, code, name, level, type, is_active) VALUES ('system', '51', 'OPERACIONALES DE ADMINISTRACION', 2, 'expense', 1);
INSERT INTO cuentas_contables (tenant_id, code, name, level, type, is_active) VALUES ('system', '5105', 'GASTOS DE PERSONAL', 3, 'expense', 1);
INSERT INTO cuentas_contables (tenant_id, code, name, level, type, is_active) VALUES ('system', '5135', 'SERVICIOS', 3, 'expense', 1);
INSERT INTO cuentas_contables (tenant_id, code, name, level, type, is_active) VALUES ('system', '52', 'OPERACIONALES DE VENTAS', 2, 'expense', 1);

-- COSTOS DE VENTAS (6)
INSERT INTO cuentas_contables (tenant_id, code, name, level, type, is_active) VALUES ('system', '6', 'COSTOS DE VENTAS', 1, 'cost', 1);
INSERT INTO cuentas_contables (tenant_id, code, name, level, type, is_active) VALUES ('system', '61', 'COSTO DE VENTAS Y DE PRESTACION DE SERVICIOS', 2, 'cost', 1);
INSERT INTO cuentas_contables (tenant_id, code, name, level, type, is_active) VALUES ('system', '6135', 'COMERCIO AL POR MAYOR Y AL POR MENOR', 3, 'cost', 1);
