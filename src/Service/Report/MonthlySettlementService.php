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
        
        $sql = "
            SELECT
            pdv.razonsocial as 'razon_social',
            pdv.cuit,
            pdv.comercio,
            und.nombre as 'unidad_negocio', -- CAMPO NUEVO
            Count(t.nrotransaccion) as 'cantidad',
            t.metodopago as 'metodopago',
            SUM(t.importecheque) as 'venta',
            SUM(ld.comisionpd) as 'costo_servicio',
            SUM(ld.comisionprontopago + ld.descuentocuotas) as 'costo_financiacion',
            SUM(IFNULL(cac.aranceltarjeta, 0)) as 'arancel_tarjeta',
            SUM(ld.ivacomisionpd + ld.ivacomisionprontopago + ld.ivadescuentocuotas + ld.ivacostoacreditacion + ld.ivaaranceltarjeta) as 'iva',
            SUM(ld.sirtac + ld.otrosimpuestos) as 'otros_impuestos',
            SUM(IFNULL(cac.beneficiocredmoura, 0)) as 'beneficio_credmoura',
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
            LEFT JOIN unidadesdenegocio und ON pdv.idunidadnegocio = und.id -- JOIN NUEVO
            WHERE 
            t.fechapagobind >= :startDate 
            AND t.fechapagobind < DATE_ADD(:endDate, INTERVAL 1 DAY)
            GROUP BY pdv.razonsocial, pdv.cuit, t.metodopago, und.nombre
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

        $sql = "
            SELECT
                unidadesdenegocio.nombre as 'unidad_negocio',
                SUM(IF(t.metodopago IN ('DE', 'PR'), 1, 0)) as cant_debito,
                SUM(IF(t.metodopago IN ('DE', 'PR'), t.importecheque, 0)) as monto_debito,
                SUM(IF(t.metodopago = 'CR', 1, 0)) as cant_credito,
                SUM(IF(t.metodopago = 'CR', t.importecheque, 0)) as monto_credito,
                SUM(IF(t.metodopago = 'QR', 1, 0)) as cant_qr,
                SUM(IF(t.metodopago = 'QR', t.importecheque, 0)) as monto_qr,
                SUM(IF(t.metodopago = 'PR', 1, 0)) as cant_prepago,
                SUM(IF(t.metodopago = 'PR', t.importecheque, 0)) as monto_prepago,
                COUNT(t.nrotransaccion) as 'transacciones',
                SUM(t.importecheque) as 'bruto',
                SUM(cac.totalneto) as 'neto_percibido',
                SUM(ROUND((cac.totalneto * CAST(SUBSTRING_INDEX(estadocheque, '-', 1) AS DECIMAL(5,2)) / 100), 2)) as 'en_cuenta_comercio',
                SUM(ROUND((cac.totalneto * CAST(SUBSTRING_INDEX(estadocheque, '-', -1) AS DECIMAL(5,2)) / 100), 2)) as 'en_cc_moura',
                SUM(liquidacionesdetalle.subsidiomoura) as 'total_subsidio',
                SUM(liquidacionesdetalle.ivasubsidiomoura) as 'total_iva_subsidio'
            FROM transacciones t
            INNER JOIN calculos_ahorro_credmoura cac ON t.nrotransaccion = cac.nrotransaccion
            INNER JOIN liquidacionesdetalle ON t.nrotransaccion = liquidacionesdetalle.nrotransaccion    
            INNER JOIN puntosdeventa ON puntosdeventa.id = t.idpdv
            INNER JOIN unidadesdenegocio ON puntosdeventa.idunidadnegocio = unidadesdenegocio.id
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
            $transferenciaMoura = $row['en_cc_moura'] - ($row['total_subsidio'] + $row['total_iva_subsidio']);
            $bruto = (float)$row['bruto'];

            $costoServicio = $bruto * 0.03;
            $iva = $costoServicio * 0.21;
            $beneficio = $bruto * 0.005;

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

        $globalBruto = $global['totales']['bruto'];
        $global['totales']['costo_servicio'] = $globalBruto * 0.03;
        $global['totales']['iva'] = $global['totales']['costo_servicio'] * 0.21;
        $global['totales']['beneficio_credmoura'] = $globalBruto * 0.005;

        return [
            'global' => $global,
            'units' => $unitsReports
        ];
    }
}