-- ============================================================
-- MIGRACIÓN: Módulo de Financiamiento y Adelanto de Liquidaciones
-- Fecha: 2026-04-09
-- ============================================================

-- 1. Columnas nuevas en transacciones (necesarias para el procesamiento)
ALTER TABLE transacciones 
    ADD COLUMN IF NOT EXISTS fecha_operacion DATE NULL COMMENT 'Fecha original de la venta/operación'
    AFTER fechapagobind;

ALTER TABLE transacciones 
    ADD COLUMN IF NOT EXISTS cantidad_cuotas TINYINT NULL DEFAULT NULL COMMENT 'Cantidad de cuotas (NULL=1 cuota, 12=financiación Moura)'
    AFTER metodopago;

-- 2. Fondos mensuales Moura → FinPay para adelantos
CREATE TABLE IF NOT EXISTS fondos_adelanto (
    id INT AUTO_INCREMENT PRIMARY KEY,
    monto_asignado DECIMAL(14,2) NOT NULL COMMENT 'Monto total transferido por Moura',
    monto_consumido DECIMAL(14,2) NOT NULL DEFAULT 0.00 COMMENT 'Monto ya utilizado en adelantos',
    mes TINYINT NOT NULL COMMENT 'Mes (1-12)',
    anio SMALLINT NOT NULL COMMENT 'Año',
    fecha_transferencia DATE NULL COMMENT 'Fecha en que Moura realizó la transferencia',
    estado ENUM('Activo', 'Agotado', 'Cerrado') NOT NULL DEFAULT 'Activo',
    umbral_alerta_porcentaje DECIMAL(5,2) NULL COMMENT 'Porcentaje mínimo disponible para disparar alerta (ej: 10.00 = 10%)',
    umbral_alerta_monto DECIMAL(14,2) NULL COMMENT 'Monto mínimo disponible para disparar alerta',
    observaciones TEXT NULL,
    creado_por INT NOT NULL,
    creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    actualizado_en DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_mes_anio (mes, anio)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Fondos mensuales Moura→FinPay para adelantos';

-- 3. Configuración de adelanto por PDV (default = habilitado)
CREATE TABLE IF NOT EXISTS config_adelanto_pdv (
    id INT AUTO_INCREMENT PRIMARY KEY,
    idpdv INT NOT NULL,
    habilitado TINYINT(1) NOT NULL DEFAULT 1 COMMENT '1=adelanto activo, 0=deshabilitado',
    creado_por INT NOT NULL,
    creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    actualizado_en DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_pdv (idpdv)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Configuración de adelanto de liquidación por PDV';

-- 4. Registros individuales de adelanto
CREATE TABLE IF NOT EXISTS adelantos_liquidacion (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nrotransaccion VARCHAR(50) NOT NULL,
    idpdv INT NOT NULL,
    id_fondo INT NOT NULL COMMENT 'Fondo del que se consume el adelanto',
    monto_venta DECIMAL(14,2) NOT NULL COMMENT 'Importe original de la venta',
    monto_neto DECIMAL(14,2) NOT NULL COMMENT 'Total neto (post impuestos/aranceles)',
    monto_adelanto_pdv DECIMAL(14,2) NOT NULL COMMENT 'Porción PDV adelantada',
    porcentaje_split DECIMAL(5,2) NOT NULL COMMENT 'Porcentaje split PDV al momento del adelanto',
    fecha_venta DATETIME NOT NULL,
    fecha_liquidacion_original DATE NOT NULL COMMENT 'Fecha original de liquidación BIND',
    fecha_liquidacion_adelantada DATE NOT NULL COMMENT 'Fecha adelantada (48hs hábiles desde venta)',
    estado ENUM('Pendiente', 'Liquidado', 'Cancelado') NOT NULL DEFAULT 'Pendiente',
    fecha_liquidacion_efectiva DATETIME NULL COMMENT 'Fecha en que se liquidó realmente',
    creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    actualizado_en DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_transaccion (nrotransaccion),
    INDEX idx_estado (estado),
    INDEX idx_fecha_adelantada (fecha_liquidacion_adelantada),
    INDEX idx_fondo (id_fondo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Adelantos individuales de liquidación por transacción';

-- 5. ABM de tasa de descuento para 12 cuotas
CREATE TABLE IF NOT EXISTS tasa_descuento_12cuotas (
    id INT AUTO_INCREMENT PRIMARY KEY,
    porcentaje DECIMAL(8,4) NOT NULL COMMENT 'Tasa de descuento (ej: 7.7800 para 7.78%)',
    fecha_desde DATE NOT NULL COMMENT 'Inicio de vigencia',
    fecha_hasta DATE NULL COMMENT 'Fin de vigencia (NULL = vigente indefinidamente)',
    estado ENUM('Pendiente', 'Aprobado', 'Rechazado') NOT NULL DEFAULT 'Pendiente',
    aprobado_por INT NULL,
    fecha_aprobacion DATETIME NULL,
    creado_por INT NOT NULL,
    creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    actualizado_en DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
    borrado_en DATETIME NULL,
    INDEX idx_vigencia (fecha_desde, fecha_hasta, estado)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Tasas de descuento para financiación 12 cuotas';

-- 6. Cabecera de ventas financiadas en 12 cuotas
CREATE TABLE IF NOT EXISTS financiacion_12cuotas (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nrotransaccion VARCHAR(50) NOT NULL,
    idpdv INT NOT NULL,
    id_unidad_negocio INT NOT NULL,
    monto_venta DECIMAL(14,2) NOT NULL COMMENT 'Importe original de la venta',
    tasa_descuento DECIMAL(8,4) NOT NULL COMMENT 'Tasa aplicada al momento de la venta',
    monto_descuento DECIMAL(14,2) NOT NULL COMMENT 'Monto de la tasa de descuento',
    arancel_tarjeta DECIMAL(14,2) NOT NULL DEFAULT 0.00,
    iva DECIMAL(14,2) NOT NULL DEFAULT 0.00,
    beneficio_credmoura DECIMAL(14,2) NOT NULL DEFAULT 0.00,
    monto_neto DECIMAL(14,2) NOT NULL COMMENT 'Total neto de la venta',
    porcentaje_pdv DECIMAL(5,2) NOT NULL COMMENT 'Split PDV (ej: 30.00)',
    monto_pdv DECIMAL(14,2) NOT NULL COMMENT 'Monto PDV (30% del neto)',
    monto_un DECIMAL(14,2) NOT NULL COMMENT 'Monto UN (70% del neto)',
    monto_cuota DECIMAL(14,2) NOT NULL COMMENT 'Valor de cada cuota (neto/12)',
    seguro_base DECIMAL(14,2) NOT NULL DEFAULT 0.00 COMMENT '1.6% de la venta',
    seguro_iva DECIMAL(14,2) NOT NULL DEFAULT 0.00 COMMENT 'IVA del seguro',
    seguro_total DECIMAL(14,2) NOT NULL DEFAULT 0.00 COMMENT 'Seguro + IVA',
    fecha_venta DATETIME NOT NULL,
    fecha_primera_liquidacion DATE NOT NULL COMMENT 'Fecha de la primera cuota',
    estado ENUM('Activo', 'Completado', 'Cancelado') NOT NULL DEFAULT 'Activo',
    cuotas_cobradas TINYINT NOT NULL DEFAULT 0 COMMENT 'Contador de cuotas cobradas (0-12)',
    creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    actualizado_en DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_transaccion (nrotransaccion),
    INDEX idx_estado (estado),
    INDEX idx_pdv (idpdv),
    INDEX idx_un (id_unidad_negocio)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Cabecera de ventas financiadas en 12 cuotas';

-- 7. Detalle de cuotas (12 por venta)
CREATE TABLE IF NOT EXISTS cuotas_financiacion (
    id INT AUTO_INCREMENT PRIMARY KEY,
    id_financiacion INT NOT NULL,
    nro_cuota TINYINT NOT NULL COMMENT 'Número de cuota (1-12)',
    monto_cuota DECIMAL(14,2) NOT NULL COMMENT 'Valor de la cuota',
    monto_seguro DECIMAL(14,2) NOT NULL DEFAULT 0.00 COMMENT 'Seguro aplicado a esta cuota',
    monto_neto_cuota DECIMAL(14,2) NOT NULL COMMENT 'Cuota - seguro = neto a recibir por Moura',
    fecha_vencimiento DATE NOT NULL COMMENT 'Fecha esperada de cobro',
    fecha_cobro DATE NULL COMMENT 'Fecha real de cobro',
    estado ENUM('Pendiente', 'Cobrada', 'Vencida', 'Cancelada') NOT NULL DEFAULT 'Pendiente',
    creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    actualizado_en DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (id_financiacion) REFERENCES financiacion_12cuotas(id),
    UNIQUE KEY uk_financiacion_cuota (id_financiacion, nro_cuota),
    INDEX idx_vencimiento (fecha_vencimiento),
    INDEX idx_estado (estado)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Detalle de cuotas individuales por financiación';

-- 8. Recibos Moura → FinPay
CREATE TABLE IF NOT EXISTS recibos_anticipo (
    id INT AUTO_INCREMENT PRIMARY KEY,
    id_fondo INT NOT NULL,
    nro_recibo VARCHAR(50) NOT NULL COMMENT 'Número correlativo del recibo',
    monto DECIMAL(14,2) NOT NULL,
    fecha DATE NOT NULL,
    observaciones TEXT NULL,
    archivo VARCHAR(255) NULL COMMENT 'Ruta al archivo PDF del recibo',
    creado_por INT NOT NULL,
    creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_nro_recibo (nro_recibo),
    INDEX idx_fondo (id_fondo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Recibos emitidos por anticipo Moura→FinPay';
