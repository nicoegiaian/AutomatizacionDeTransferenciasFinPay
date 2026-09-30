<?php

namespace App\Service\Report;

use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;

class MonthlySettlementService
{
    private Connection $connection;
    private LoggerInterface $logger;

    public function __construct(Connection $defaultConnection, LoggerInterface $logger)
    {
        $this->connection = $defaultConnection;
        $this->logger = $logger;
    }

    public function getMonthlyData(int $month, int $year): array
    {
        $startDate = sprintf('%d-%02d-01', $year, $month);
        $endDate = date('Y-m-t', strtotime($startDate));

        $nombresMeses = [
            1 => 'Enero', 2 => 'Febrero', 3 => 'Marzo', 4 => 'Abril', 
            5 => 'Mayo', 6 => 'Junio', 7 => 'Julio', 8 => 'Agosto',
            9 => 'Septiembre', 10 => 'Octubre', 11 => 'Noviembre', 12 => 'Diciembre'
        ];
        $periodoTexto = $nombresMeses[$month] . ' ' . $year;

        $metodoPagoNormSql = "
            CASE
                WHEN UPPER(TRIM(COALESCE(NULLIF(t.metodopagoOriginal, ''), t.metodopago, ''))) IN ('DE', 'DEBITO', 'DÉBITO') THEN 'DE'
                WHEN UPPER(TRIM(COALESCE(NULLIF(t.metodopagoOriginal, ''), t.metodopago, ''))) IN ('CR', 'CREDITO', 'CRÉDITO') THEN 'CR'
                WHEN UPPER(TRIM(COALESCE(NULLIF(t.metodopagoOriginal, ''), t.metodopago, ''))) IN ('QR') THEN 'QR'
                WHEN UPPER(TRIM(COALESCE(NULLIF(t.metodopagoOriginal, ''), t.metodopago, ''))) IN ('PR', 'PREPAGO', 'PREPAGA') THEN 'PR'
                ELSE UPPER(TRIM(COALESCE(NULLIF(t.metodopagoOriginal, ''), t.metodopago, '')))
            END
        ";
        
        $sql = "
            SELECT
            pdv.razonsocial as 'razon_social',
            pdv.cuit,
            pdv.comercio,
            und.nombre as 'unidad_negocio', -- CAMPO NUEVO
            Count(t.nrotransaccion) as 'cantidad',
            {$metodoPagoNormSql} as 'metodopago',
            SUM(t.importecheque) as 'venta',
            SUM(IFNULL(ld.comisionpd, 0)) as 'costo_servicio',
            SUM(IFNULL(cac.costofinanciero, 0)) as 'costo_financiacion',
            SUM((IFNULL(cac.aranceltarjeta, 0) + IFNULL(t.importecheque, 0) * 0.002) * (1 - IFNULL(cac.pct_financiacion_arancel, 0) / 100)) as 'arancel_tarjeta',
            SUM(IFNULL(cac.iva_total,
                IFNULL(ld.ivacomisionpd, 0)
                + IFNULL(ld.ivacomisionprontopago, 0)
                + IFNULL(ld.ivadescuentocuotas, 0)
                + IFNULL(ld.ivacostoacreditacion, 0)
                + IFNULL(ld.ivaaranceltarjeta, 0)
                + IFNULL(ld.IVAcostomipyme, 0)
            ) - LEAST(IFNULL(cac.iva_arancel, 0), IFNULL(ld.ivaaranceltarjeta, 0) * IFNULL(cac.pct_financiacion_arancel, 0) / 100)) as 'iva',
            SUM(IFNULL(cac.otros_impuestos, IFNULL(ld.sirtac, 0) + IFNULL(ld.otrosimpuestos, 0))) as 'otros_impuestos',
            SUM(GREATEST(IFNULL(cac.beneficiocredmoura, 0) - (IFNULL(t.importecheque, 0) * 0.002 * CASE WHEN t.fecha >= '2026-09-03' THEN (1 - IFNULL(cac.pct_financiacion_arancel, 0) / 100) ELSE 1 END), 0)) as 'beneficio_credmoura',
            SUM(cac.totalneto) as 'total_neto',
            SUM(ROUND((
                cac.totalneto * CAST(SUBSTRING_INDEX(estadocheque, '-', 1) AS DECIMAL(5,2)) / 100
            ), 2)) as 'acred_cc_com',
            SUM(ROUND((
                cac.totalneto * CAST(SUBSTRING_INDEX(estadocheque, '-', -1) AS DECIMAL(5,2)) / 100
            ), 2)) as 'acred_cc_moura',

            0 as 'Subsidio Moura',
            0 as 'IVA Subsidio Moura',
            0 as 'Transferencia Moura'
            FROM
            transacciones t
            INNER JOIN calculos_ahorro_credmoura cac ON t.nrotransaccion = cac.nrotransaccion
            INNER JOIN liquidacionesdetalle ld ON t.nrotransaccion = ld.nrotransaccion    
            INNER JOIN puntosdeventa pdv ON pdv.id = t.idpdv
                        INNER JOIN splits ON splits.idpdv = t.idpdv
                            AND splits.fecha = (
                                SELECT MAX(s2.fecha) FROM splits s2
                                WHERE s2.idpdv = t.idpdv AND s2.fecha < t.fecha
                                AND s2.estatus_aprobacion = 'Aprobado' AND s2.borrado_en IS NULL
                            )
            LEFT JOIN unidadesdenegocio und ON pdv.idunidadnegocio = und.id -- JOIN NUEVO
            WHERE 
            t.fechapagobind >= :startDate 
            AND t.fechapagobind < DATE_ADD(:endDate, INTERVAL 1 DAY)
            GROUP BY pdv.razonsocial, pdv.cuit, {$metodoPagoNormSql}, und.nombre
        ";

        $rows = $this->connection->executeQuery($sql, [
            'startDate' => $startDate,
            'endDate' => $endDate
        ])->fetchAllAssociative();

        $reports = [];
        
        foreach ($rows as $row) {
            $pdvId = $row['comercio'];
            if (!isset($reports[$pdvId])) {
                $nroComercio = str_replace('C', '', $row['comercio'] ?? '');
                $nroResumen = $nroComercio . str_pad((string)$month, 2, '0', STR_PAD_LEFT) . $year;

                $reports[$pdvId] = [
                    'idpdv' => $pdvId,
                    'header' => [
                        'razon_social' => $row['razon_social'],
                        'cuit' => $row['cuit'],
                        'periodo' => $periodoTexto,
                        'nro_resumen' => $nroResumen,
                        // CAMPO NUEVO IMPORTANTE PARA EL COMANDO
                        'unidad_de_negocio' => $row['unidad_negocio'] ?? 'Sin Unidad' 
                    ],
                    'items' => [
                        'debito' => ['cant' => 0, 'monto' => 0.0],
                        'credito' => ['cant' => 0, 'monto' => 0.0],
                        'qr' => ['cant' => 0, 'monto' => 0.0],
                        'prepago' => ['cant' => 0, 'monto' => 0.0],
                        'devoluciones' => ['cant' => 0, 'monto' => 0.0],
                    ],
                    'totales' => [
                        'bruto' => 0.0,
                        'transacciones' => 0,
                        'costo_servicio' => 0.0,
                        'costo_financiacion' => 0.0,
                        'aranceles' => 0.0,
                        'iva' => 0.0,
                        'otros_impuestos' => 0.0,
                        'beneficio_credmoura' => 0.0,
                        'neto_percibido' => 0.0,
                        'en_cuenta_comercio' => 0.0,
                        'en_cc_moura' => 0.0,
                    ]
                ];
            }
            $metodo = strtoupper($row['metodopago'] ?? '');
            $categoria = match ($metodo) {
                'DE' => 'debito',
                'CR' => 'credito',
                'QR' => 'qr',
                'PR' => 'prepago',
                default => null 
            };
            if ($categoria) {
                $reports[$pdvId]['items'][$categoria]['cant'] += (int)$row['cantidad'];
                $reports[$pdvId]['items'][$categoria]['monto'] += (float)$row['venta'];
            }
            $r = &$reports[$pdvId]['totales'];
            $r['bruto'] += (float)$row['venta'];
            $r['transacciones'] += (int)$row['cantidad'];
            $r['costo_servicio'] += (float)$row['costo_servicio'];
            $r['costo_financiacion'] += (float)$row['costo_financiacion'];
            $r['aranceles'] += (float)$row['arancel_tarjeta'];
            $r['iva'] += (float)$row['iva'];
            $r['otros_impuestos'] += (float)$row['otros_impuestos'];
            $r['beneficio_credmoura'] += (float)$row['beneficio_credmoura'];
            $r['neto_percibido'] += (float)$row['total_neto'];
            $r['en_cuenta_comercio'] += (float)$row['acred_cc_com'];
            $r['en_cc_moura'] += (float)$row['acred_cc_moura'];
        }
        return $reports;
    }

   public function getMouraSummaries(int $month, int $year): array
    {
        $startDate = sprintf('%d-%02d-01', $year, $month);
        $endDate = date('Y-m-t', strtotime($startDate));
        
        $nombresMeses = [
            1 => 'Enero', 2 => 'Febrero', 3 => 'Marzo', 4 => 'Abril', 
            5 => 'Mayo', 6 => 'Junio', 7 => 'Julio', 8 => 'Agosto',
            9 => 'Septiembre', 10 => 'Octubre', 11 => 'Noviembre', 12 => 'Diciembre'
        ];
        $periodoTexto = $nombresMeses[$month] . ' ' . $year;

        $metodoPagoNormSql = "
            CASE
                WHEN UPPER(TRIM(COALESCE(NULLIF(t.metodopagoOriginal, ''), t.metodopago, ''))) IN ('DE', 'DEBITO', 'DÉBITO') THEN 'DE'
                WHEN UPPER(TRIM(COALESCE(NULLIF(t.metodopagoOriginal, ''), t.metodopago, ''))) IN ('CR', 'CREDITO', 'CRÉDITO') THEN 'CR'
                WHEN UPPER(TRIM(COALESCE(NULLIF(t.metodopagoOriginal, ''), t.metodopago, ''))) IN ('QR') THEN 'QR'
                WHEN UPPER(TRIM(COALESCE(NULLIF(t.metodopagoOriginal, ''), t.metodopago, ''))) IN ('PR', 'PREPAGO', 'PREPAGA') THEN 'PR'
                ELSE UPPER(TRIM(COALESCE(NULLIF(t.metodopagoOriginal, ''), t.metodopago, '')))
            END
        ";

        $sql = "
            SELECT
                unidadesdenegocio.nombre as 'unidad_negocio',
                SUM(IF({$metodoPagoNormSql} IN ('DE', 'PR'), 1, 0)) as cant_debito,
                SUM(IF({$metodoPagoNormSql} IN ('DE', 'PR'), t.importecheque, 0)) as monto_debito,
                SUM(IF({$metodoPagoNormSql} = 'CR', 1, 0)) as cant_credito,
                SUM(IF({$metodoPagoNormSql} = 'CR', t.importecheque, 0)) as monto_credito,
                SUM(IF({$metodoPagoNormSql} = 'QR', 1, 0)) as cant_qr,
                SUM(IF({$metodoPagoNormSql} = 'QR', t.importecheque, 0)) as monto_qr,
                SUM(IF({$metodoPagoNormSql} = 'PR', 1, 0)) as cant_prepago,
                SUM(IF({$metodoPagoNormSql} = 'PR', t.importecheque, 0)) as monto_prepago,
                COUNT(t.nrotransaccion) as 'transacciones',
                SUM(t.importecheque) as 'bruto',
                SUM(IFNULL(ld.comisionpd, 0)) as 'costo_servicio',
                SUM(IFNULL(cac.iva_total,
                    IFNULL(ld.ivacomisionpd, 0)
                    + IFNULL(ld.ivacomisionprontopago, 0)
                    + IFNULL(ld.ivadescuentocuotas, 0)
                    + IFNULL(ld.ivacostoacreditacion, 0)
                    + IFNULL(ld.ivaaranceltarjeta, 0)
                    + IFNULL(ld.IVAcostomipyme, 0)
                ) - LEAST(IFNULL(cac.iva_arancel, 0), IFNULL(ld.ivaaranceltarjeta, 0) * IFNULL(cac.pct_financiacion_arancel, 0) / 100)) as 'iva',
                SUM(GREATEST(IFNULL(cac.beneficiocredmoura, 0) - (IFNULL(t.importecheque, 0) * 0.002 * CASE WHEN t.fecha >= '2026-09-03' THEN (1 - IFNULL(cac.pct_financiacion_arancel, 0) / 100) ELSE 1 END), 0)) as 'beneficio_credmoura',
                SUM(cac.totalneto) as 'neto_percibido',
                SUM(ROUND((cac.totalneto * CAST(SUBSTRING_INDEX(estadocheque, '-', 1) AS DECIMAL(5,2)) / 100), 2)) as 'en_cuenta_comercio',
                SUM(ROUND((cac.totalneto * CAST(SUBSTRING_INDEX(estadocheque, '-', -1) AS DECIMAL(5,2)) / 100), 2)) as 'en_cc_moura',
                SUM(CASE
                    WHEN CAST(IFNULL(t.Canal, 0) AS UNSIGNED) = 12
                    THEN (IFNULL(cac.comision_moura, 0) / 1.21)
                        + GREATEST(IFNULL(cac.beneficiocredmoura, 0) - (IFNULL(t.importecheque, 0) * 0.002 * CASE WHEN t.fecha >= '2026-09-03' THEN (1 - IFNULL(cac.pct_financiacion_arancel, 0) / 100) ELSE 1 END), 0)
                    WHEN IFNULL(cac.comision_moura_sin_iva, 0) = 0 AND IFNULL(cac.comision_moura, 0) = 0
                    THEN IFNULL(ld.subsidiomoura, 0)
                    ELSE (IFNULL(cac.comision_moura, 0) / 1.21)
                        + GREATEST(IFNULL(cac.beneficiocredmoura, 0) - (IFNULL(t.importecheque, 0) * 0.002 * CASE WHEN t.fecha >= '2026-09-03' THEN (1 - IFNULL(cac.pct_financiacion_arancel, 0) / 100) ELSE 1 END), 0)
                END) as 'total_subsidio',
                SUM(CASE
                    WHEN IFNULL(cac.comision_moura_sin_iva, 0) = 0 AND IFNULL(cac.comision_moura, 0) = 0
                    THEN IFNULL(ld.ivasubsidiomoura, 0)
                    ELSE (IFNULL(cac.comision_moura, 0) / 1.21) * 0.21
                END) as 'total_iva_subsidio',
                SUM(IFNULL(cac.aranceltarjeta, 0) * IFNULL(cac.pct_financiacion_arancel, 0) / 100) as 'arancel_tarjeta_moura',
                SUM(LEAST(IFNULL(cac.iva_arancel, 0), IFNULL(ld.ivaaranceltarjeta, 0) * IFNULL(cac.pct_financiacion_arancel, 0) / 100)) as 'iva_arancel_moura',
                SUM(
                    (IFNULL(cac.totalneto, 0) * (100 - splits.porcentajepdv) / 100)
                    - (CASE
                        WHEN CAST(IFNULL(t.Canal, 0) AS UNSIGNED) = 12
                        THEN (IFNULL(cac.comision_moura, 0) / 1.21)
                            + GREATEST(IFNULL(cac.beneficiocredmoura, 0) - (IFNULL(t.importecheque, 0) * 0.002 * CASE WHEN t.fecha >= '2026-09-03' THEN (1 - IFNULL(cac.pct_financiacion_arancel, 0) / 100) ELSE 1 END), 0)
                            + ((IFNULL(cac.comision_moura, 0) / 1.21) * 0.21)
                        WHEN IFNULL(cac.comision_moura, 0) = 0
                        THEN IFNULL(ld.ivasubsidiomoura, 0) + IFNULL(ld.subsidiomoura, 0)
                        ELSE (IFNULL(cac.comision_moura, 0) / 1.21)
                            + GREATEST(IFNULL(cac.beneficiocredmoura, 0) - (IFNULL(t.importecheque, 0) * 0.002 * CASE WHEN t.fecha >= '2026-09-03' THEN (1 - IFNULL(cac.pct_financiacion_arancel, 0) / 100) ELSE 1 END), 0)
                            + ((IFNULL(cac.comision_moura, 0) / 1.21) * 0.21)
                    END)
                    - (IFNULL(cac.aranceltarjeta, 0) * IFNULL(cac.pct_financiacion_arancel, 0) / 100)
                    - LEAST(IFNULL(cac.iva_arancel, 0), IFNULL(ld.ivaaranceltarjeta, 0) * IFNULL(cac.pct_financiacion_arancel, 0) / 100)
                ) as 'transferencia_moura'
            FROM transacciones t
            INNER JOIN calculos_ahorro_credmoura cac ON t.nrotransaccion = cac.nrotransaccion
            INNER JOIN liquidacionesdetalle ld ON t.nrotransaccion = ld.nrotransaccion    
            INNER JOIN puntosdeventa ON puntosdeventa.id = t.idpdv
            INNER JOIN unidadesdenegocio ON puntosdeventa.idunidadnegocio = unidadesdenegocio.id
            INNER JOIN splits ON splits.idpdv = t.idpdv
              AND splits.fecha = (
                SELECT MAX(s2.fecha) FROM splits s2
                WHERE s2.idpdv = t.idpdv AND s2.fecha < t.fecha
                AND s2.estatus_aprobacion = 'Aprobado' AND s2.borrado_en IS NULL
              )
            WHERE t.fechapagobind >= :startDate AND t.fechapagobind < DATE_ADD(:endDate, INTERVAL 1 DAY)
            GROUP BY unidadesdenegocio.nombre
        ";

        $rows = $this->connection->executeQuery($sql, [
            'startDate' => $startDate,
            'endDate' => $endDate
        ])->fetchAllAssociative();

        $unitsReports = [];
        
        $global = [
            'header' => ['unidad_de_negocio' => '', 'periodo' => $periodoTexto, 'titulo' => 'Resumen total Liquidaciones Cobres Flex'],
            'items' => [
                'debito' => ['cant' => 0, 'monto' => 0.0],
                'credito' => ['cant' => 0, 'monto' => 0.0],
                'qr' => ['cant' => 0, 'monto' => 0.0],
                'prepago' => ['cant' => 0, 'monto' => 0.0],
                'devoluciones' => ['cant' => 0, 'monto' => 0.0],
            ],
            'totales' => [
                'transacciones' => 0,
                'bruto' => 0.0,
                'neto_percibido' => 0.0,
                'en_cuenta_comercio' => 0.0,
                'en_cc_moura' => 0.0,
                'transferencia_moura' => 0.0,
                'costo_servicio' => 0.0,
                'iva' => 0.0,
                'beneficio_credmoura' => 0.0,
            ]
        ];

        foreach ($rows as $row) {
            $transferenciaMoura = (float)($row['transferencia_moura'] ?? 0);
            $bruto = (float)$row['bruto'];
            $costoServicio = (float)($row['costo_servicio'] ?? 0);
            $iva = (float)($row['iva'] ?? 0);
            $beneficio = (float)($row['beneficio_credmoura'] ?? 0);

            $unitReport = [
                'header' => [
                    'titulo' => 'Resumen total Liquidaciones Cobres Flex',
                    'unidad_de_negocio' => $row['unidad_negocio'],
                    'periodo' => $periodoTexto
                ],
                'items' => [
                    'debito' => ['cant' => $row['cant_debito'], 'monto' => $row['monto_debito']],
                    'credito' => ['cant' => $row['cant_credito'], 'monto' => $row['monto_credito']],
                    'qr' => ['cant' => $row['cant_qr'], 'monto' => $row['monto_qr']],
                    'prepago' => ['cant' => $row['cant_prepago'], 'monto' => $row['monto_prepago']],
                ],
                'totales' => [
                    'transacciones' => $row['transacciones'],
                    'bruto' => $bruto,
                    'costo_servicio' => $costoServicio,
                    'iva' => $iva,
                    'beneficio_credmoura' => $beneficio,
                    'neto_percibido' => $row['neto_percibido'],
                    'en_cuenta_comercio' => $row['en_cuenta_comercio'],
                    'en_cc_moura' => $row['en_cc_moura'],
                    'transferencia_moura' => $transferenciaMoura,
                ]
            ];
            
            $unitsReports[$row['unidad_negocio']] = $unitReport;

            $global['items']['debito']['cant'] += $row['cant_debito'];
            $global['items']['debito']['monto'] += $row['monto_debito'];
            $global['items']['credito']['cant'] += $row['cant_credito'];
            $global['items']['credito']['monto'] += $row['monto_credito'];
            $global['items']['qr']['cant'] += $row['cant_qr'];
            $global['items']['qr']['monto'] += $row['monto_qr'];
            $global['items']['prepago']['cant'] += $row['cant_prepago'];
            $global['items']['prepago']['monto'] += $row['monto_prepago'];
            $global['totales']['transacciones'] += $row['transacciones'];
            $global['totales']['bruto'] += $bruto;
            $global['totales']['neto_percibido'] += $row['neto_percibido'];
            $global['totales']['en_cuenta_comercio'] += $row['en_cuenta_comercio'];
            $global['totales']['en_cc_moura'] += $row['en_cc_moura'];
            $global['totales']['transferencia_moura'] += $transferenciaMoura;
        }

        return [
            'global' => $global,
            'units' => $unitsReports
        ];
    }
}