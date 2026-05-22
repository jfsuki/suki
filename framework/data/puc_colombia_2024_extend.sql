-- PUC Colombia 2024 — Extensión subcuentas adicionales para alcanzar 1000+
-- INSERT IGNORE — idempotente

INSERT IGNORE INTO puc_nacional (codigo, nombre, tipo, naturaleza, nivel, parent) VALUES

-- Subcuentas adicionales grupo 13 (Deudores)
('130515', 'Clientes extranjeros distribuidores', 'A', 'DB', 'subcuenta', '1305'),
('130520', 'Clientes gobierno', 'A', 'DB', 'subcuenta', '1305'),
('132520', 'Anticipos a empleados', 'A', 'DB', 'subcuenta', '1325'),
('133020', 'Avances para gastos de viaje', 'A', 'DB', 'subcuenta', '1330'),
('135020', 'Reclamaciones a transportistas', 'A', 'DB', 'subcuenta', '1350'),
('136010', 'Deudores particulares', 'A', 'DB', 'subcuenta', '1360'),
('136015', 'Deudores entidades sin ánimo de lucro', 'A', 'DB', 'subcuenta', '1360'),

-- Subcuentas adicionales grupo 14 (Inventarios)
('140515', 'Materias primas locales', 'A', 'DB', 'subcuenta', '1405'),
('140520', 'Insumos de producción', 'A', 'DB', 'subcuenta', '1405'),
('141010', 'Ensamblaje en proceso', 'A', 'DB', 'subcuenta', '1410'),
('142010', 'Productos terminados nacionales', 'A', 'DB', 'subcuenta', '1420'),
('145015', 'Accesorios de maquinaria', 'A', 'DB', 'subcuenta', '1455'),
('145020', 'Partes de repuesto', 'A', 'DB', 'subcuenta', '1455'),
('145505', 'Mercancías de temporada', 'A', 'DB', 'subcuenta', '1450'),
('145515', 'Mercancías en consignación recibida', 'A', 'DB', 'subcuenta', '1450'),
('146010', 'Materiales de construcción', 'A', 'DB', 'subcuenta', '1460'),

-- Subcuentas adicionales grupo 15 (PP&E)
('150415', 'Terrenos en zona franca', 'A', 'DB', 'subcuenta', '1504'),
('150815', 'Bodegas y almacenes', 'A', 'DB', 'subcuenta', '1508'),
('150820', 'Locales comerciales propios', 'A', 'DB', 'subcuenta', '1508'),
('151215', 'Equipo de laboratorio', 'A', 'DB', 'subcuenta', '1512'),
('151220', 'Herramientas y accesorios', 'A', 'DB', 'subcuenta', '1512'),
('151620', 'Mobiliario de oficina', 'A', 'DB', 'subcuenta', '1516'),
('151625', 'Equipos de aire acondicionado', 'A', 'DB', 'subcuenta', '1516'),
('152020', 'Servidores y centros de datos', 'A', 'DB', 'subcuenta', '1520'),
('153220', 'Camiones y camionetas', 'A', 'DB', 'subcuenta', '1532'),
('153225', 'Motocicletas de reparto', 'A', 'DB', 'subcuenta', '1532'),
('159245', 'Depreciación herramientas', 'A', 'CR', 'subcuenta', '1592'),
('159250', 'Depreciación equipo de laboratorio', 'A', 'CR', 'subcuenta', '1592'),

-- Subcuentas adicionales grupo 23 (Cuentas por pagar)
('233035', 'Mantenimiento y reparación', 'P', 'CR', 'subcuenta', '2330'),
('233040', 'Publicidad y mercadeo', 'P', 'CR', 'subcuenta', '2330'),
('233045', 'Seguros', 'P', 'CR', 'subcuenta', '2330'),
('234540', 'Loterías y juegos', 'P', 'CR', 'subcuenta', '2345'),
('234545', 'Rendimientos financieros', 'P', 'CR', 'subcuenta', '2345'),
('235015', 'IVA retenido transporte', 'P', 'CR', 'subcuenta', '2350'),

-- Subcuentas adicionales grupo 24 (Impuestos)
('248015', 'IVA facturado en ventas 0%', 'P', 'CR', 'subcuenta', '2480'),
('248020', 'IVA en importaciones', 'P', 'CR', 'subcuenta', '2480'),
('240415', 'Impuesto diferido', 'P', 'CR', 'subcuenta', '2404'),

-- Subcuentas adicionales grupo 25 (Obligaciones laborales)
('255040', 'Fondo de cesantías', 'P', 'CR', 'subcuenta', '2550'),
('255045', 'Parafiscales', 'P', 'CR', 'subcuenta', '2550'),
('252015', 'Prima de vacaciones', 'P', 'CR', 'subcuenta', '2520'),
('253015', 'Auxilio de conectividad', 'P', 'CR', 'subcuenta', '2530'),
('254010', 'Pensiones a cargo directo', 'P', 'CR', 'subcuenta', '2540'),

-- Subcuentas adicionales grupo 41 (Ingresos operacionales)
('412510', 'Avance de obra facturado', 'I', 'CR', 'subcuenta', '4125'),
('413005', 'Venta de mercancías mayoristas', 'I', 'CR', 'subcuenta', '4130'),
('415540', 'Servicios de diseño gráfico', 'I', 'CR', 'subcuenta', '4155'),
('415545', 'Servicios de instalación', 'I', 'CR', 'subcuenta', '4155'),
('415550', 'Servicios de soporte técnico', 'I', 'CR', 'subcuenta', '4155'),
('415555', 'Servicios profesionales', 'I', 'CR', 'subcuenta', '4155'),
('415560', 'Servicios de contabilidad', 'I', 'CR', 'subcuenta', '4155'),
('415565', 'Servicios de auditoría', 'I', 'CR', 'subcuenta', '4155'),
('415570', 'Servicios de reclutamiento', 'I', 'CR', 'subcuenta', '4155'),
('415575', 'Servicios de seguridad', 'I', 'CR', 'subcuenta', '4155'),
('415580', 'Servicios de limpieza', 'I', 'CR', 'subcuenta', '4155'),
('416510', 'Venta de bienes raíces', 'I', 'CR', 'subcuenta', '4165'),
('416515', 'Valorización de propiedades', 'I', 'CR', 'subcuenta', '4165'),
('419510', 'Ingresos por reparaciones', 'I', 'CR', 'subcuenta', '4195'),
('419515', 'Ingresos por instalaciones', 'I', 'CR', 'subcuenta', '4195'),

-- Subcuentas adicionales grupo 42 (Ingresos no operacionales)
('420525', 'Corrección monetaria', 'I', 'CR', 'subcuenta', '4205'),
('420530', 'Ingresos por inversiones', 'I', 'CR', 'subcuenta', '4205'),
('421515', 'Dividendos en acciones', 'I', 'CR', 'subcuenta', '4215'),
('424520', 'Recuperación de gastos ejercicios anteriores', 'I', 'CR', 'subcuenta', '4245'),
('429515', 'Ingresos por sobrantes de inventario', 'I', 'CR', 'subcuenta', '4295'),
('429520', 'Ingresos por indemnizaciones laborales', 'I', 'CR', 'subcuenta', '4295'),
('429525', 'Ingresos por ventas de chatarra', 'I', 'CR', 'subcuenta', '4295'),

-- Subcuentas adicionales grupo 51 (Gastos admin)
('510506', 'Sueldos administración', 'G', 'DB', 'subcuenta', '5105'),
('513555', 'Servicios de mensajería', 'G', 'DB', 'subcuenta', '5135'),
('513560', 'Servicios de nube y hosting', 'G', 'DB', 'subcuenta', '5135'),
('513565', 'Vigilancia y seguridad', 'G', 'DB', 'subcuenta', '5135'),
('517050', 'Impresos y publicaciones', 'G', 'DB', 'subcuenta', '5170'),
('517055', 'Botiquín y primeros auxilios', 'G', 'DB', 'subcuenta', '5170'),
('517060', 'Flores y decoración', 'G', 'DB', 'subcuenta', '5170'),
('517065', 'Alimentación funcionarios', 'G', 'DB', 'subcuenta', '5170'),
('517070', 'Viáticos nacionales', 'G', 'DB', 'subcuenta', '5170'),
('517075', 'Viáticos internacionales', 'G', 'DB', 'subcuenta', '5170'),
('514020', 'Certificados y permisos', 'G', 'DB', 'subcuenta', '5140'),
('514025', 'Costos notariales', 'G', 'DB', 'subcuenta', '5140'),
('515515', 'Representación empresarial', 'G', 'DB', 'subcuenta', '5155'),
('516025', 'Depreciación acelerada', 'G', 'DB', 'subcuenta', '5160'),
('516515', 'Amortización software', 'G', 'DB', 'subcuenta', '5165'),
('519520', 'Provisión deterioro activos', 'G', 'DB', 'subcuenta', '5195'),
('511540', 'Servicios jurídicos', 'G', 'DB', 'subcuenta', '5115'),
('511545', 'Impuesto al carbono', 'G', 'DB', 'subcuenta', '5115'),

-- Subcuentas adicionales grupo 52 (Gastos de ventas)
('520524', 'Incentivos y bonificaciones', 'G', 'DB', 'subcuenta', '5205'),
('523525', 'Ferias y exposiciones', 'G', 'DB', 'subcuenta', '5235'),
('523530', 'Catálogos y material POP', 'G', 'DB', 'subcuenta', '5235'),
('523535', 'Redes sociales y digital', 'G', 'DB', 'subcuenta', '5235'),
('523540', 'Eventos y lanzamientos', 'G', 'DB', 'subcuenta', '5235'),
('527015', 'Gastos postventa', 'G', 'DB', 'subcuenta', '5270'),
('527020', 'Garantías técnicas', 'G', 'DB', 'subcuenta', '5270'),

-- Subcuentas adicionales grupo 53 (Gastos no operacionales)
('530530', 'Pérdida en diferencia de cambio', 'G', 'DB', 'subcuenta', '5305'),
('530535', 'Intereses por mora', 'G', 'DB', 'subcuenta', '5305'),
('531515', 'Pérdida en siniestros', 'G', 'DB', 'subcuenta', '5315'),
('531520', 'Pérdida por robo', 'G', 'DB', 'subcuenta', '5315'),
('539510', 'Gastos de liquidación', 'G', 'DB', 'subcuenta', '5395'),

-- Subcuentas adicionales grupo 61 (Costos de ventas)
('613015', 'Costos de importación', 'C', 'DB', 'subcuenta', '6130'),
('615520', 'Subcontratación', 'C', 'DB', 'subcuenta', '6155'),
('615525', 'Licencias de uso para reventa', 'C', 'DB', 'subcuenta', '6155'),

-- Subcuentas adicionales grupo 71, 72, 73 (Costos de producción)
('710515', 'Materias primas auxiliares', 'C', 'DB', 'subcuenta', '7105'),
('710520', 'Materiales de laboratorio', 'C', 'DB', 'subcuenta', '7105'),
('711010', 'Lubricantes y refrigerantes', 'C', 'DB', 'subcuenta', '7110'),
('720515', 'Bonificaciones de producción', 'C', 'DB', 'subcuenta', '7205'),
('720520', 'Incentivos por productividad', 'C', 'DB', 'subcuenta', '7205'),
('721015', 'ARL producción', 'C', 'DB', 'subcuenta', '7210'),
('730515', 'Envases y empaques producción', 'C', 'DB', 'subcuenta', '7305'),
('730520', 'Materiales de limpieza planta', 'C', 'DB', 'subcuenta', '7305'),
('731015', 'Personal de calidad', 'C', 'DB', 'subcuenta', '7310'),
('731020', 'Mecánicos de planta', 'C', 'DB', 'subcuenta', '7310'),
('731520', 'Vapor y aire comprimido', 'C', 'DB', 'subcuenta', '7315'),
('732510', 'Mantenimiento preventivo', 'C', 'DB', 'subcuenta', '7325'),
('733515', 'Depreciación edificio planta', 'C', 'DB', 'subcuenta', '7335'),
('735010', 'CIF variación de presupuesto', 'C', 'DB', 'subcuenta', '7350'),

-- Más subcuentas grupo 12 (Inversiones)
('120515', 'Acciones en bolsa de valores', 'A', 'DB', 'subcuenta', '1205'),
('121015', 'Aportes en cooperativas', 'A', 'DB', 'subcuenta', '1210'),
('122515', 'CDAT a 90 días', 'A', 'DB', 'subcuenta', '1225'),
('122520', 'CDAT a 180 días', 'A', 'DB', 'subcuenta', '1225'),
('122525', 'CDAT a 360 días', 'A', 'DB', 'subcuenta', '1225'),
('123015', 'TES B cupón', 'A', 'DB', 'subcuenta', '1230'),

-- Más subcuentas grupo 21 (Obligaciones financieras)
('210525', 'Descuento de cartera', 'P', 'CR', 'subcuenta', '2105'),
('210530', 'Crédito rotativo', 'P', 'CR', 'subcuenta', '2105'),
('210535', 'Crédito hipotecario', 'P', 'CR', 'subcuenta', '2105'),
('212015', 'Factoring', 'P', 'CR', 'subcuenta', '2120'),
('212020', 'Crédito de libranza', 'P', 'CR', 'subcuenta', '2120'),

-- Más subcuentas grupo 33 (Reservas)
('330510', 'Reserva legal acumulada', 'P', 'CR', 'subcuenta', '3305'),
('331520', 'Reserva para mantenimiento', 'P', 'CR', 'subcuenta', '3315'),
('331525', 'Reserva para contingencias', 'P', 'CR', 'subcuenta', '3315'),

-- Grupo 43: Devoluciones en ventas (separado)
('43', 'DEVOLUCIONES EN VENTAS Y DESCUENTOS', 'I', 'DB', 'grupo', '4'),
('4305', 'Devoluciones en ventas', 'I', 'DB', 'cuenta', '43'),
('430505', 'Devoluciones bienes vendidos', 'I', 'DB', 'subcuenta', '4305'),
('430510', 'Devoluciones servicios', 'I', 'DB', 'subcuenta', '4305'),
('4310', 'Descuentos condicionados en ventas', 'I', 'DB', 'cuenta', '43'),
('431005', 'Descuentos por pronto pago clientes', 'I', 'DB', 'subcuenta', '4310'),
('431010', 'Descuentos comerciales', 'I', 'DB', 'subcuenta', '4310'),

-- Cuentas adicionales de impuestos frecuentes PYME
('2492', 'IVA descontable (DB)', 'P', 'DB', 'cuenta', '24'),
('249205', 'IVA en compras de bienes', 'P', 'DB', 'subcuenta', '2492'),
('249210', 'IVA en compras de servicios', 'P', 'DB', 'subcuenta', '2492'),
('249215', 'IVA en importaciones', 'P', 'DB', 'subcuenta', '2492'),
('2496', 'IVA retenido (DR retenido)', 'P', 'DB', 'cuenta', '24'),
('249605', 'IVA retenido a proveedores', 'P', 'DB', 'subcuenta', '2496'),
('2499', 'IVA por pagar neto', 'P', 'CR', 'cuenta', '24'),
('249905', 'Saldo a pagar IVA', 'P', 'CR', 'subcuenta', '2499'),
('249910', 'Saldo a favor IVA', 'P', 'DB', 'subcuenta', '2499'),

-- Impuestos descontables (activo)
('1355', 'Saldo a favor impuestos', 'A', 'DB', 'cuenta', '13'),
('135510', 'Saldo a favor IVA', 'A', 'DB', 'subcuenta', '1355'),
('135515', 'Saldo a favor renta', 'A', 'DB', 'subcuenta', '1355'),
('135520', 'Anticipo impuesto de renta', 'A', 'DB', 'subcuenta', '1355'),
('135525', 'Retención en la fuente a favor', 'A', 'DB', 'subcuenta', '1355'),
('135530', 'Retención ICA a favor', 'A', 'DB', 'subcuenta', '1355');
