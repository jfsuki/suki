-- seed_puc_colombia.sql — PUC Colombia PYME (Decreto 2650/1993 + NIIF PYME)
-- Niveles: 1-dígito (clase), 2-dígitos (grupo), 4-dígitos (cuenta), 6-dígitos (subcuenta)
-- Total: ~600 cuentas operativas para PYME colombiana
-- tabla: cuentas_contables (tenant_id, code, name, level, type, is_active)

-- ============================================================
-- CLASE 1 — ACTIVO
-- ============================================================
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','1','ACTIVO',1,'asset',1);

-- Grupo 11 Disponible
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','11','DISPONIBLE',2,'asset',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','1105','CAJA',3,'asset',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','110505','Caja general',4,'asset',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','110510','Cajas menores',4,'asset',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','1110','BANCOS',3,'asset',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','111005','Banco cuenta corriente',4,'asset',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','111010','Banco cuenta de ahorros',4,'asset',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','1115','REMESAS EN TRÁNSITO',3,'asset',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','111505','Nacionales',4,'asset',1);

-- Grupo 12 Inversiones
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','12','INVERSIONES',2,'asset',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','1205','ACCIONES',3,'asset',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','120505','Ordinarias',4,'asset',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','1210','CUOTAS O PARTES DE INTERÉS SOCIAL',3,'asset',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','121005','En sociedades de responsabilidad limitada',4,'asset',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','1225','CDT',3,'asset',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','122505','Certificados de depósito a término',4,'asset',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','1255','DERECHOS FIDUCIARIOS',3,'asset',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','1260','TÍTULOS VALORES',3,'asset',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','126005','Bonos',4,'asset',1);

-- Grupo 13 Deudores
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','13','DEUDORES',2,'asset',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','1305','CLIENTES',3,'asset',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','130505','Nacionales',4,'asset',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','130510','Del exterior',4,'asset',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','1310','CUENTAS CORRIENTES COMERCIALES',3,'asset',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','131005','Nacionales',4,'asset',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','1320','CUENTAS POR COBRAR A SOCIOS Y ACCIONISTAS',3,'asset',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','132005','A socios',4,'asset',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','1330','ANTICIPOS Y AVANCES',3,'asset',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','133005','A proveedores',4,'asset',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','133010','A empleados',4,'asset',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','1335','DEPÓSITOS',3,'asset',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','133505','En garantía',4,'asset',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','1345','PROMESAS DE COMPRAVENTA',3,'asset',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','1355','ANTICIPO DE IMPUESTOS Y CONTRIBUCIONES',3,'asset',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','135505','Anticipo renta y complementarios',4,'asset',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','135510','Sobretasa del impuesto',4,'asset',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','135515','IVA - Saldo a favor',4,'asset',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','135520','Retefuente a favor',4,'asset',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','1360','RECLAMACIONES',3,'asset',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','136005','A compañías aseguradoras',4,'asset',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','1365','CUENTAS POR COBRAR A EMPLEADOS',3,'asset',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','136505','Préstamos de vivienda',4,'asset',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','136510','Otros préstamos',4,'asset',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','1380','DEUDORES VARIOS',3,'asset',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','138005','Depósitos',4,'asset',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','138010','Derechos por arrendamiento',4,'asset',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','1399','PROVISIÓN DEUDORES',3,'asset',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','139905','Clientes',4,'asset',1);

-- Grupo 14 Inventarios
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','14','INVENTARIOS',2,'asset',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','1405','MATERIAS PRIMAS',3,'asset',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','140505','Materias primas',4,'asset',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','1410','PRODUCTOS EN PROCESO',3,'asset',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','141005','Costo de los productos en proceso',4,'asset',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','1415','OBRAS DE CONSTRUCCIÓN EN CURSO',3,'asset',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','1430','PRODUCTOS TERMINADOS',3,'asset',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','143005','Productos terminados',4,'asset',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','1435','MERCANCÍAS NO FABRICADAS POR LA EMPRESA',3,'asset',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','143505','Mercancías',4,'asset',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','1440','MATERIALES, REPUESTOS Y ACCESORIOS',3,'asset',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','144005','Materiales y repuestos',4,'asset',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','1445','ENVASES Y EMPAQUES',3,'asset',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','1450','INVENTARIOS EN TRÁNSITO',3,'asset',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','145005','Nacionales',4,'asset',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','1499','PROVISIÓN PARA PROTECCIÓN DE INVENTARIOS',3,'asset',1);

-- Grupo 15 Propiedades Planta y Equipo
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','15','PROPIEDADES, PLANTA Y EQUIPO',2,'asset',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','1504','TERRENOS',3,'asset',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','150405','Urbanos',4,'asset',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','150410','Rurales',4,'asset',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','1508','CONSTRUCCIONES EN CURSO',3,'asset',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','1512','EDIFICACIONES',3,'asset',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','151205','Edificios',4,'asset',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','151210','Locales',4,'asset',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','1516','MAQUINARIA Y EQUIPO',3,'asset',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','151605','Maquinaria y equipo de producción',4,'asset',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','1520','EQUIPO DE OFICINA',3,'asset',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','152005','Muebles y enseres',4,'asset',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','152010','Equipo de oficina',4,'asset',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','1524','EQUIPO DE COMPUTACIÓN Y COMUNICACIÓN',3,'asset',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','152405','Equipo de procesamiento de datos',4,'asset',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','152410','Equipo de comunicación',4,'asset',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','1528','EQUIPO DE TRANSPORTE',3,'asset',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','152805','Flota y equipo de transporte',4,'asset',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','1548','DEPRECIACIÓN ACUMULADA (CR)',3,'asset',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','154815','Edificaciones',4,'asset',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','154820','Maquinaria y equipo',4,'asset',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','154825','Equipo de oficina',4,'asset',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','154828','Equipo de computación',4,'asset',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','154830','Equipo de transporte',4,'asset',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','1592','DEPRECIACIÓN DIFERIDA',3,'asset',1);

-- Grupo 16 Intangibles
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','16','INTANGIBLES',2,'asset',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','1605','CRÉDITO MERCANTIL',3,'asset',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','160505','Adquirido',4,'asset',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','1610','MARCAS',3,'asset',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','1615','PATENTES',3,'asset',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','1625','LICENCIAS',3,'asset',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','162505','Licencias y software',4,'asset',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','1630','KNOW HOW',3,'asset',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','1698','AMORTIZACIÓN ACUMULADA (CR)',3,'asset',1);

-- Grupo 17 Diferidos
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','17','DIFERIDOS',2,'asset',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','1705','GASTOS PAGADOS POR ANTICIPADO',3,'asset',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','170505','Seguros',4,'asset',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','170510','Arrendamientos',4,'asset',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','170515','Publicidad y propaganda',4,'asset',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','1710','CARGOS DIFERIDOS',3,'asset',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','171005','Estudios, diseños y proyectos',4,'asset',1);

-- Grupo 19 Valorizaciones
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','19','VALORIZACIONES',2,'asset',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','1905','DE INVERSIONES',3,'asset',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','1910','DE PROPIEDADES PLANTA Y EQUIPO',3,'asset',1);

-- ============================================================
-- CLASE 2 — PASIVO
-- ============================================================
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','2','PASIVO',1,'liability',1);

-- Grupo 21 Obligaciones Financieras
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','21','OBLIGACIONES FINANCIERAS',2,'liability',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','2105','BANCOS NACIONALES',3,'liability',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','210505','Banco - crédito de libre inversión',4,'liability',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','210510','Banco - crédito de tesorería',4,'liability',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','210515','Sobregiro bancario',4,'liability',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','2110','BANCOS DEL EXTERIOR',3,'liability',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','2115','CORPORACIONES FINANCIERAS',3,'liability',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','2120','COMPAÑÍAS DE FINANCIAMIENTO',3,'liability',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','2125','LEASING',3,'liability',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','212505','Leasing operativo',4,'liability',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','212510','Leasing financiero',4,'liability',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','2145','DEUDAS CON SOCIOS Y ACCIONISTAS',3,'liability',1);

-- Grupo 22 Proveedores
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','22','PROVEEDORES',2,'liability',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','2205','NACIONALES',3,'liability',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','220505','Grandes proveedores',4,'liability',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','220510','Proveedores varios',4,'liability',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','2210','DEL EXTERIOR',3,'liability',1);

-- Grupo 23 Cuentas por Pagar
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','23','CUENTAS POR PAGAR',2,'liability',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','2305','CUENTAS CORRIENTES COMERCIALES',3,'liability',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','230505','Nacionales',4,'liability',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','2315','A COMPAÑÍAS VINCULADAS',3,'liability',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','2320','A CONTRATISTAS',3,'liability',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','2325','ÓRDENES DE COMPRA POR PAGAR',3,'liability',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','2330','COSTOS Y GASTOS POR PAGAR',3,'liability',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','233005','Honorarios',4,'liability',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','233010','Servicios',4,'liability',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','233015','Arrendamientos',4,'liability',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','2335','DEUDAS CON ACCIONISTAS O SOCIOS',3,'liability',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','2340','PROMESAS DE COMPRAVENTA',3,'liability',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','2345','RETENCIÓN EN LA FUENTE',3,'liability',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','234505','Salarios y pagos laborales',4,'liability',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','234510','Honorarios',4,'liability',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','234515','Servicios',4,'liability',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','234520','Compras',4,'liability',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','234525','Arrendamientos',4,'liability',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','234530','Otros conceptos',4,'liability',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','2350','RETENCIONES Y APORTES DE NÓMINA',3,'liability',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','235005','Aportes a fondos de cesantías',4,'liability',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','235010','Fondos de pensiones y jubilación',4,'liability',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','235015','Seguridad social',4,'liability',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','235020','Sindical',4,'liability',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','2355','CUOTAS POR DEVOLVER',3,'liability',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','2360','DIVIDENDOS O PARTICIPACIONES POR PAGAR',3,'liability',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','2365','RETENCION EN LA FUENTE',3,'liability',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','236505','Honorarios',4,'liability',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','236510','Servicios',4,'liability',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','236515','Arrendamientos',4,'liability',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','236520','Compras',4,'liability',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','2370','RETENCIONES Y APORTES LABORALES',3,'liability',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','237005','Aportes SENA',4,'liability',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','237010','Aportes ICBF',4,'liability',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','237015','Aportes cajas de compensación',4,'liability',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','2375','IMPUESTO A LAS VENTAS RETENIDO',3,'liability',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','237505','IVA retenido',4,'liability',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','2380','ACREEDORES VARIOS',3,'liability',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','238005','Acreedores varios',4,'liability',1);

-- Grupo 24 Impuestos
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','24','IMPUESTOS, GRAVÁMENES Y TASAS',2,'liability',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','2404','IMPUESTO DE RENTA Y COMPLEMENTARIOS',3,'liability',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','240405','Vigencia fiscal corriente',4,'liability',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','2408','IMPUESTO SOBRE LAS VENTAS - IVA',3,'liability',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','240805','IVA generado',4,'liability',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','240810','IVA por pagar',4,'liability',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','2412','IMPUESTO DE TIMBRE NACIONAL',3,'liability',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','2416','IMPUESTO DE INDUSTRIA Y COMERCIO - ICA',3,'liability',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','241605','ICA por pagar',4,'liability',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','2420','IMPUESTO PREDIAL UNIFICADO',3,'liability',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','2424','IMPUESTO SOBRE VEHÍCULOS',3,'liability',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','2436','IMPUESTO DE REGISTRO',3,'liability',1);

-- Grupo 25 Obligaciones Laborales
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','25','OBLIGACIONES LABORALES',2,'liability',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','2505','SALARIOS POR PAGAR',3,'liability',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','250505','Salarios',4,'liability',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','250510','Horas extras',4,'liability',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','250515','Comisiones',4,'liability',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','2510','CESANTÍAS CONSOLIDADAS',3,'liability',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','251005','Cesantías',4,'liability',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','2515','INTERESES SOBRE CESANTÍAS',3,'liability',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','251505','Intereses cesantías',4,'liability',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','2520','PRIMA DE SERVICIOS',3,'liability',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','252005','Prima de servicios',4,'liability',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','2525','VACACIONES CONSOLIDADAS',3,'liability',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','252505','Vacaciones',4,'liability',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','2530','PRESTACIONES EXTRALEGALES',3,'liability',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','2535','PENSIONES POR PAGAR',3,'liability',1);

-- Grupo 26 Pasivos Estimados y Provisiones
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','26','PASIVOS ESTIMADOS Y PROVISIONES',2,'liability',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','2605','PARA COSTOS Y GASTOS',3,'liability',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','260505','Provisión para garantías',4,'liability',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','260510','Provisión para contingencias',4,'liability',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','2610','PARA OBLIGACIONES FISCALES',3,'liability',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','261005','Para impuesto de renta',4,'liability',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','2615','PARA OBLIGACIONES LABORALES',3,'liability',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','261505','Para prestaciones sociales',4,'liability',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','2620','PARA OBLIGACIONES PRESUNTAS',3,'liability',1);

-- Grupo 27 Diferidos
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','27','DIFERIDOS',2,'liability',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','2705','INGRESOS RECIBIDOS POR ANTICIPADO',3,'liability',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','270505','Arrendamientos',4,'liability',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','270510','Intereses',4,'liability',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','270515','Comisiones',4,'liability',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','2710','INGRESOS DIFERIDOS',3,'liability',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','271005','Utilidad diferida en ventas a plazos',4,'liability',1);

-- Grupo 28 Otros Pasivos
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','28','OTROS PASIVOS',2,'liability',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','2805','ANTICIPOS Y AVANCES RECIBIDOS',3,'liability',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','280505','De clientes',4,'liability',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','2815','DEPÓSITOS RECIBIDOS',3,'liability',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','2820','INGRESOS RECIBIDOS PARA TERCEROS',3,'liability',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','2825','RETENCIONES A TERCEROS',3,'liability',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','282505','Retenciones sobre ventas',4,'liability',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','2830','CUENTAS EN PARTICIPACIÓN',3,'liability',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','2895','OTROS',3,'liability',1);

-- ============================================================
-- CLASE 3 — PATRIMONIO
-- ============================================================
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','3','PATRIMONIO',1,'equity',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','31','CAPITAL SOCIAL',2,'equity',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','3105','CAPITAL AUTORIZADO',3,'equity',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','3110','CAPITAL POR SUSCRIBIR (DB)',3,'equity',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','3115','CAPITAL SUSCRITO Y PAGADO',3,'equity',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','311505','Capital suscrito y pagado',4,'equity',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','32','SUPERÁVIT DE CAPITAL',2,'equity',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','3205','PRIMA EN COLOCACIÓN DE ACCIONES',3,'equity',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','3210','CRÉDITO MERCANTIL',3,'equity',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','3225','KNOW HOW',3,'equity',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','33','RESERVAS',2,'equity',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','3305','RESERVA LEGAL',3,'equity',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','330505','Reserva legal',4,'equity',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','3310','RESERVAS ESTATUTARIAS',3,'equity',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','3315','RESERVAS OCASIONALES',3,'equity',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','331505','Para futuros ensanches',4,'equity',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','331510','Para reposición de activos',4,'equity',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','34','REVALORIZACIÓN DEL PATRIMONIO',2,'equity',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','3405','DE CAPITAL',3,'equity',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','35','DIVIDENDOS O PARTICIPACIONES DECRETADAS',2,'equity',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','3505','EN ACCIONES',3,'equity',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','3510','EN CUOTAS O PARTES DE INTERÉS',3,'equity',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','36','RESULTADOS DEL EJERCICIO',2,'equity',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','3605','UTILIDAD DEL EJERCICIO',3,'equity',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','360505','Utilidad del ejercicio',4,'equity',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','3610','PÉRDIDA DEL EJERCICIO (DB)',3,'equity',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','37','RESULTADOS DE EJERCICIOS ANTERIORES',2,'equity',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','3705','UTILIDADES ACUMULADAS',3,'equity',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','3710','PÉRDIDAS ACUMULADAS (DB)',3,'equity',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','38','SUPERÁVIT POR VALORIZACIONES',2,'equity',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','3805','DE INVERSIONES',3,'equity',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','3810','DE PROPIEDADES PLANTA Y EQUIPO',3,'equity',1);

-- ============================================================
-- CLASE 4 — INGRESOS
-- ============================================================
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','4','INGRESOS',1,'revenue',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','41','OPERACIONALES',2,'revenue',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','4105','AGRICULTURA, GANADERÍA, CAZA Y SILVICULTURA',3,'revenue',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','4110','PESCA',3,'revenue',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','4115','EXPLOTACIÓN DE MINAS Y CANTERAS',3,'revenue',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','4120','INDUSTRIAS MANUFACTURERAS',3,'revenue',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','412005','Venta de productos fabricados',4,'revenue',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','4125','SUMINISTRO DE ENERGÍA',3,'revenue',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','4130','CONSTRUCCIÓN',3,'revenue',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','4135','COMERCIO AL POR MAYOR Y AL POR MENOR',3,'revenue',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','413505','Venta de mercancías',4,'revenue',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','413510','Ingresos por ventas',4,'revenue',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','4140','HOTELES Y RESTAURANTES',3,'revenue',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','414005','Alojamiento',4,'revenue',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','414010','Restaurante y bar',4,'revenue',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','4145','TRANSPORTE',3,'revenue',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','4148','CORREOS Y TELECOMUNICACIONES',3,'revenue',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','4150','INTERMEDIACIÓN FINANCIERA',3,'revenue',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','4155','ACTIVIDADES INMOBILIARIAS',3,'revenue',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','415505','Arrendamiento de bienes inmuebles',4,'revenue',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','4160','SERVICIOS SOCIALES Y DE SALUD',3,'revenue',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','416005','Consultas médicas',4,'revenue',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','416010','Procedimientos médicos',4,'revenue',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','416015','Odontología',4,'revenue',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','4165','EDUCACIÓN',3,'revenue',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','416505','Pensiones',4,'revenue',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','416510','Matrículas',4,'revenue',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','4170','SERVICIOS COMUNITARIOS Y PERSONALES',3,'revenue',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','4175','DEVOLUCIONES EN VENTAS (DB)',3,'revenue',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','417505','Devoluciones en ventas',4,'revenue',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','4180','DESCUENTOS EN VENTAS (DB)',3,'revenue',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','418005','Descuentos comerciales',4,'revenue',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','42','NO OPERACIONALES',2,'revenue',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','4205','FINANCIEROS',3,'revenue',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','420505','Intereses',4,'revenue',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','420510','Descuentos comerciales',4,'revenue',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','420515','Corrección monetaria',4,'revenue',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','4210','DIVIDENDOS',3,'revenue',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','4215','INDEMNIZACIONES',3,'revenue',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','4220','ARRENDAMIENTOS',3,'revenue',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','422005','De inmuebles',4,'revenue',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','422010','De muebles',4,'revenue',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','4225','COMISIONES',3,'revenue',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','4230','HONORARIOS',3,'revenue',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','4235','SERVICIOS',3,'revenue',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','4240','UTILIDAD EN VENTA DE INVERSIONES',3,'revenue',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','4245','UTILIDAD EN VENTA DE PROP. PLANTA Y EQUIPO',3,'revenue',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','4250','RECUPERACIONES',3,'revenue',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','4255','INGRESOS DE EJERCICIOS ANTERIORES',3,'revenue',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','4295','DIVERSOS',3,'revenue',1);

-- ============================================================
-- CLASE 5 — GASTOS OPERACIONALES DE ADMINISTRACIÓN
-- ============================================================
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','5','GASTOS',1,'expense',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','51','OPERACIONALES DE ADMINISTRACIÓN',2,'expense',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','5101','SUELDOS',3,'expense',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','510105','Sueldos',4,'expense',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','5102','HORAS EXTRAS Y RECARGOS',3,'expense',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','510205','Horas extras',4,'expense',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','5103','COMISIONES',3,'expense',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','5104','AUXILIO DE TRANSPORTE',3,'expense',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','510405','Auxilio de transporte',4,'expense',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','5105','GASTOS DE PERSONAL',3,'expense',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','510505','Jornales',4,'expense',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','510510','Gastos de representación',4,'expense',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','5106','CESANTÍAS',3,'expense',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','510605','Cesantías',4,'expense',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','5107','INTERESES SOBRE CESANTÍAS',3,'expense',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','5108','PRIMA DE SERVICIOS',3,'expense',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','5109','VACACIONES',3,'expense',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','5110','PENSIONES Y JUBILACIONES',3,'expense',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','511005','Aportes pensión',4,'expense',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','5111','DOTACIÓN Y SUMINISTRO A TRABAJADORES',3,'expense',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','511105','Dotación de empleados',4,'expense',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','5112','GASTOS DE SALUD',3,'expense',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','511205','Aportes salud',4,'expense',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','5113','APORTES PARAFISCALES',3,'expense',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','511305','SENA',4,'expense',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','511310','ICBF',4,'expense',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','511315','Cajas de compensación',4,'expense',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','5114','CAPACITACIÓN AL PERSONAL',3,'expense',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','5115','BONIFICACIONES',3,'expense',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','5120','HONORARIOS',3,'expense',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','512005','Juntas directivas',4,'expense',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','512010','Revisoría fiscal',4,'expense',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','512015','Servicios contables',4,'expense',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','512020','Servicios jurídicos',4,'expense',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','512025','Asesorías',4,'expense',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','5125','IMPUESTOS',3,'expense',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','512505','ICA - Industria y comercio',4,'expense',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','512510','Predial',4,'expense',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','512515','Vehículos',4,'expense',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','512520','Otros impuestos',4,'expense',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','5130','ARRENDAMIENTOS',3,'expense',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','513005','Construcciones y edificaciones',4,'expense',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','513010','Maquinaria y equipo',4,'expense',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','513015','Equipo de cómputo',4,'expense',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','5135','CONTRIBUCIONES Y AFILIACIONES',3,'expense',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','513505','Contribuciones',4,'expense',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','513510','Afiliaciones y sostenimiento',4,'expense',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','5140','SEGUROS',3,'expense',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','514005','Manejo',4,'expense',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','514010','Incendio y terremoto',4,'expense',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','514015','Equipo',4,'expense',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','514020','Vida colectivo',4,'expense',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','5145','SERVICIOS',3,'expense',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','514505','Aseo y vigilancia',4,'expense',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','514510','Acueducto y alcantarillado',4,'expense',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','514515','Energía eléctrica',4,'expense',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','514520','Teléfono',4,'expense',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','514525','Correo, portes y telégrafos',4,'expense',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','514530','Transporte',4,'expense',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','514535','Gas',4,'expense',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','514540','Internet y hosting',4,'expense',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','5150','GASTOS LEGALES',3,'expense',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','515005','Notariales',4,'expense',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','515010','Registro mercantil',4,'expense',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','515015','Licencias',4,'expense',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','5155','MANTENIMIENTO Y REPARACIONES',3,'expense',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','515505','Construcciones y edificaciones',4,'expense',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','515510','Maquinaria y equipo',4,'expense',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','515515','Equipo de oficina',4,'expense',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','515520','Equipo de cómputo',4,'expense',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','515525','Equipo de transporte',4,'expense',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','5160','DEPRECIACIONES',3,'expense',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','516005','Construcciones y edificaciones',4,'expense',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','516010','Maquinaria y equipo',4,'expense',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','516015','Equipo de oficina',4,'expense',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','516020','Equipo de cómputo',4,'expense',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','516025','Equipo de transporte',4,'expense',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','5165','AMORTIZACIONES',3,'expense',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','516505','Intangibles',4,'expense',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','516510','Diferidos',4,'expense',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','5170','GASTOS DE VIAJE',3,'expense',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','517005','Fletes',4,'expense',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','517010','Viáticos y gastos de viaje',4,'expense',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','5175','PUBLICIDAD Y PROPAGANDA',3,'expense',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','517505','Publicidad',4,'expense',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','517510','Propaganda',4,'expense',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','5180','ELEMENTOS DE ASEO Y CAFETERÍA',3,'expense',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','518005','Elementos de aseo',4,'expense',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','518010','Elementos de cafetería',4,'expense',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','5185','ÚTILES, PAPELERÍA Y FOTOCOPIAS',3,'expense',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','518505','Útiles y papelería',4,'expense',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','518510','Fotocopias',4,'expense',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','5190','PROVISIONES',3,'expense',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','519005','Provisión cartera',4,'expense',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','519010','Provisión inventarios',4,'expense',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','5195','GASTOS DIVERSOS',3,'expense',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','519505','Suscripciones',4,'expense',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','519510','Libros y revistas',4,'expense',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','519515','Gastos de representación',4,'expense',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','519520','Parqueadero',4,'expense',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','519525','Combustibles y lubricantes',4,'expense',1);

-- Grupo 52 Gastos Operacionales de Ventas
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','52','OPERACIONALES DE VENTAS',2,'expense',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','5201','SUELDOS',3,'expense',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','520105','Sueldos vendedores',4,'expense',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','5203','COMISIONES',3,'expense',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','520305','Comisiones vendedores',4,'expense',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','5245','SERVICIOS',3,'expense',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','524505','Publicidad y mercadeo',4,'expense',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','5260','DEPRECIACIONES',3,'expense',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','5295','GASTOS DIVERSOS',3,'expense',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','529505','Gastos de ventas varios',4,'expense',1);

-- Grupo 53 No Operacionales
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','53','NO OPERACIONALES',2,'expense',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','5305','FINANCIEROS',3,'expense',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','530505','Intereses',4,'expense',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','530510','Comisiones',4,'expense',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','530515','Corrección monetaria',4,'expense',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','530520','Diferencia en cambio',4,'expense',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','530525','GMF - Gravamen movimientos financieros',4,'expense',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','5310','PÉRDIDAS EN VENTA Y RETIRO DE INVERSIONES',3,'expense',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','5315','GASTOS EXTRAORDINARIOS',3,'expense',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','531505','Multas y sanciones',4,'expense',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','531510','Pérdidas en retiro de activos',4,'expense',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','5320','GASTOS DE EJERCICIOS ANTERIORES',3,'expense',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','5395','GASTOS DIVERSOS',3,'expense',1);

-- Grupo 54 Impuesto de Renta y Complementarios
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','54','IMPUESTO DE RENTA Y COMPLEMENTARIOS',2,'expense',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','5405','IMPUESTO RENTA CORRIENTE',3,'expense',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','540505','Impuesto renta vigencia actual',4,'expense',1);

-- ============================================================
-- CLASE 6 — COSTOS DE VENTAS
-- ============================================================
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','6','COSTOS DE VENTAS',1,'cost',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','61','COSTO DE VENTAS Y PRESTACIÓN DE SERVICIOS',2,'cost',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','6105','AGRICULTURA, GANADERÍA, CAZA Y SILVICULTURA',3,'cost',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','6110','PESCA',3,'cost',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','6120','EXPLOTACIÓN DE MINAS Y CANTERAS',3,'cost',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','6125','INDUSTRIAS MANUFACTURERAS',3,'cost',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','612505','Costo de producción',4,'cost',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','6130','CONSTRUCCIÓN',3,'cost',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','6135','COMERCIO AL POR MAYOR Y AL POR MENOR',3,'cost',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','613505','Costo de mercancías vendidas',4,'cost',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','613510','Costo de productos vendidos',4,'cost',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','6140','HOTELES Y RESTAURANTES',3,'cost',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','614005','Costo alojamiento',4,'cost',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','614010','Costo alimentación',4,'cost',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','6145','TRANSPORTE',3,'cost',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','6155','INTERMEDIACIÓN FINANCIERA',3,'cost',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','6160','SERVICIOS SOCIALES Y DE SALUD',3,'cost',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','616005','Costo consultas médicas',4,'cost',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','616010','Costo medicamentos',4,'cost',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','6165','EDUCACIÓN',3,'cost',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','6170','SERVICIOS COMUNITARIOS Y PERSONALES',3,'cost',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','617005','Costo servicio técnico',4,'cost',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','617010','Costo servicios profesionales',4,'cost',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','6175','DEVOLUCIONES EN COMPRAS (DB)',3,'cost',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','617505','Devoluciones en compras',4,'cost',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','6180','DESCUENTOS EN COMPRAS (DB)',3,'cost',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','618005','Descuentos comerciales en compras',4,'cost',1);

-- ============================================================
-- CLASE 7 — COSTOS DE PRODUCCIÓN (Manufactura)
-- ============================================================
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','7','COSTOS DE PRODUCCIÓN',1,'cost',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','71','MATERIA PRIMA',2,'cost',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','7105','MATERIAS PRIMAS',3,'cost',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','710505','Materias primas directas',4,'cost',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','72','MANO DE OBRA DIRECTA',2,'cost',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','7205','SUELDOS DE PRODUCCIÓN',3,'cost',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','720505','Sueldos operarios',4,'cost',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','73','COSTOS INDIRECTOS',2,'cost',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','7305','MATERIALES INDIRECTOS',3,'cost',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','7310','MANO DE OBRA INDIRECTA',3,'cost',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','7315','DEPRECIACIONES DE PRODUCCIÓN',3,'cost',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','7320','SERVICIOS PÚBLICOS DE PRODUCCIÓN',3,'cost',1);

-- ============================================================
-- CLASE 8 — CUENTAS DE ORDEN DEUDORAS
-- ============================================================
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','8','CUENTAS DE ORDEN DEUDORAS',1,'memo',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','81','DERECHOS CONTINGENTES',2,'memo',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','8105','BIENES Y VALORES ENTREGADOS EN GARANTÍA',3,'memo',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','83','ACREEDORAS DE CONTROL',2,'memo',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','8305','ACTIVOS TOTALMENTE DEPRECIADOS',3,'memo',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','8310','ACTIVOS RETIRADOS DEL SERVICIO',3,'memo',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','86','ACTIVOS CASTIGADOS',2,'memo',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','89','OTRAS CUENTAS DE ORDEN DEUDORAS',2,'memo',1);

-- ============================================================
-- CLASE 9 — CUENTAS DE ORDEN ACREEDORAS
-- ============================================================
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','9','CUENTAS DE ORDEN ACREEDORAS',1,'memo',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','91','RESPONSABILIDADES CONTINGENTES',2,'memo',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','9105','GARANTÍAS Y COMPROMISOS',3,'memo',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','96','ACREEDORAS DE CONTROL',2,'memo',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','9605','DEPRECIACIÓN ACUMULADA',3,'memo',1);
INSERT INTO cuentas_contables (tenant_id,code,name,level,type,is_active) VALUES ('system','99','OTRAS CUENTAS DE ORDEN ACREEDORAS',2,'memo',1);
