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
        // Fechas para la query
        $startDate = sprintf('%d-%02d-01', $year, $month);
        $endDate = date('Y-m-t', strtotime($startDate));

        // Formateo del periodo para el encabezado del PDF (ej: "Noviembre 2025")
        $nombresMeses = [
            1 => 'Enero', 2 => 'Febrero', 3 => 'Marzo', 4 => 'Abril', 
            5 => 'Mayo', 6 => 'Junio', 7 => 'Julio', 8 => 'Agosto',
            9 => 'Septiembre', 10 => 'Octubre', 11 => 'Noviembre', 12 => 'Diciembre'
        ];
        $periodoTexto = $nombresMeses[$month] . ' ' . $year;

        
        $sql = "
            SELECT
            puntosdeventa.razonsocial as 'razon_social',
            puntosdeventa.cuit,
            puntosdeventa.comercio,
            Count(transacciones.nrotransaccion) as 'Cantidad',
            metodopago as 'metodopago',
            SUM(importecheque) as 'venta',
            SUM(comisionpd) as 'costo_servicio',
            SUM(comisionprontopago + descuentocuotas) as 'costo_financiacion',
            SUM(IFNULL(aranceltarjeta, 0)) as 'arancel_tarjeta',
            SUM(ivacomisionpd + ivacomisionprontopago + ivadescuentocuotas + ivacostoacreditacion + ivaaranceltarjeta) as 'iva',
            SUM(sirtac + otrosimpuestos) as 'otros_impuestos',
            SUM(IFNULL(beneficiocredmoura, 0)) as 'beneficio_credmoura',
            SUM(importeprimervenc) as 'total_neto',
            SUM(ROUND((((importeprimervenc)*(splits.porcentajepdv))/100), 2)) as 'acred_cc_com',
            SUM(ROUND((((importeprimervenc)*(100 - splits.porcentajepdv))/100), 2)) as 'acred_cc_moura',
            0 as 'Subsidio Moura',
            0 as 'IVA Subsidio Moura',
            0 as 'Transferencia Moura'
            FROM
            transacciones
                        INNER JOIN liquidacionesdetalle ON transacciones.nrotransaccion = liquidacionesdetalle.nrotransaccion    
            INNER JOIN splits ON transacciones.idpdv = splits.idpdv
            INNER JOIN puntosdeventa ON puntosdeventa.id = transacciones.idpdv
            WHERE splits.fecha = (SELECT MAX(s2.fecha) FROM splits s2 WHERE s2.idpdv = transacciones.idpdv AND s2.fecha < transacciones.fecha AND s2.estatus_aprobacion = 'Aprobado' AND s2.borrado_en IS NULL)
            AND fechapagobind >= :startDate 
            AND fechapagobind <= DATE_ADD(:endDate, INTERVAL 1 DAY)
            GROUP BY puntosdeventa.razonsocial, puntosdeventa.cuit, metodopago
        ";

        $rows = $this->connection->executeQuery($sql, [
            'startDate' => $startDate,
            'endDate' => $endDate
        ])->fetchAllAssociative();

        
        $reports = [];

        foreach ($rows as $row) {
            $pdvId = $row['comercio'];

            if (!isset($reports[$pdvId])) {
                // Cálculo Nro Resumen: Comercio (sin C) + Mes (2) + Año (4)
                $nroComercio = str_replace('C', '', $row['comercio'] ?? '');
                $nroResumen = $nroComercio . str_pad((string)$month, 2, '0', STR_PAD_LEFT) . $year;

                $reports[$pdvId] = [
                    'idpdv' => $pdvId,
                    'header' => [
                        'razon_social' => $row['razon_social'],
                        'cuit' => $row['cuit'],
                        'periodo' => $periodoTexto,
                        'nro_resumen' => $nroResumen,
                    ],
                    // Inicializamos contadores en 0
                    'items' => [
                        'debito' => ['cant' => 0, 'monto' => 0.0],
                        'credito' => ['cant' => 0, 'monto' => 0.0],
                        'qr' => ['cant' => 0, 'monto' => 0.0],
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

            // Agrupación Métodos de Pago
            $metodo = strtoupper($row['metodopago'] ?? '');
            
            // Regla: DE/PR -> Débito, CR -> Crédito, QR -> QR
            $categoria = match ($metodo) {
                'DE', 'PR' => 'debito',
                'CR' => 'credito',
                'QR' => 'qr',
                default => null 
            };

            if ($categoria) {
                $reports[$pdvId]['items'][$categoria]['cant'] += (int)$row['cantidad'];
                $reports[$pdvId]['items'][$categoria]['monto'] += (float)$row['venta'];
            }

            // Acumuladores Totales
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
    /**
     * Calcula los totales globales para la carátula de Moura
     */
    public function getMouraTotals(int $month, int $year): array
    {
        // Reutilizamos la lógica llamando a getMonthlyData y sumando en PHP
        // (Es más seguro que duplicar la query y errarle en un WHERE)
        $data = $this->getMonthlyData($month, $year);

        $totals = [
            'periodo' => "$month/$year",
            'cant_comercios' => count($data),
            'total_ventas_bruto' => 0.0,
            'total_comisiones_ganadas' => 0.0, // (Costo servicio + financiación)
            'total_recaudado_para_moura' => 0.0, // (en_cc_moura)
            'total_dispersado_comercios' => 0.0, // (en_cuenta_comercio)
            'total_beneficios_otorgados' => 0.0, // (beneficio_credmoura)
        ];

        foreach ($data as $pdv) {
            $t = $pdv['totales'];
            $totals['total_ventas_bruto'] += $t['bruto'];
            $totals['total_comisiones_ganadas'] += ($t['costo_servicio'] + $t['costo_financiacion']);
            $totals['total_recaudado_para_moura'] += $t['en_cc_moura'];
            $totals['total_dispersado_comercios'] += $t['en_cuenta_comercio'];
            $totals['total_beneficios_otorgados'] += $t['beneficio_credmoura'];
        }

        return $totals;
    }
}