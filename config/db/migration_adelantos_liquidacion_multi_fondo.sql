-- Split a single 12-installment advance across multiple active funds.
-- Existing advances are backfilled as a single allocation to their current fund.
CREATE TABLE IF NOT EXISTS adelantos_liquidacion_fondos (
    id_adelanto INT NOT NULL,
    id_fondo INT NOT NULL,
    monto_consumido DECIMAL(14,2) NOT NULL,
    creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id_adelanto, id_fondo),
    INDEX idx_adelanto_fondos_fondo (id_fondo),
    CONSTRAINT fk_adelanto_fondos_adelanto
        FOREIGN KEY (id_adelanto) REFERENCES adelantos_liquidacion(id),
    CONSTRAINT fk_adelanto_fondos_fondo
        FOREIGN KEY (id_fondo) REFERENCES fondos_adelanto(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT IGNORE INTO adelantos_liquidacion_fondos (id_adelanto, id_fondo, monto_consumido)
SELECT id, id_fondo, monto_adelanto_pdv
FROM adelantos_liquidacion;
