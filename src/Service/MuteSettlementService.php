<?php

namespace App\Service;

use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use App\Service\BindServiceInterface;
use App\Service\Notifier;

class MuteSettlementService
{
    private BindServiceInterface $bindService;
    private Notifier $notifier;
    private Connection $db;
    private LoggerInterface $logger;
    private LoggerInterface $reportLogger; 
    private string $logFilePath;

    private string $cvuOrigen;
    private string $cvuTercero;
    private string $cvuPagosDigitales;
    private float $comisionPorcentaje;
    private float $ivaPorcentaje;
    private string $emailDestino;
    private bool $enableRealTransfer;

    public function __construct(
        BindServiceInterface $bindService,
        Notifier $notifier,
        Connection $dbConnection,
        LoggerInterface $logger,
        LoggerInterface $reportLogger,
        string $logFilePath,
        string $cvuOrigen,
        string $cvuTercero,
        string $cvuPagosDigitales,
        float $comisionPorcentaje,
        float $ivaPorcentaje,
        string $emailDestino,
        bool $enableRealTransfer
    ) {
        $this->bindService = $bindService;
        $this->notifier = $notifier;
        $this->db = $dbConnection;
        $this->logger = $logger;
        $this->reportLogger = $reportLogger; 
        $this->logFilePath = $logFilePath;
        $this->cvuOrigen = $cvuOrigen;
        $this->cvuTercero = $cvuTercero;
        $this->cvuPagosDigitales = $cvuPagosDigitales;
        $this->comisionPorcentaje = $comisionPorcentaje;
        $this->ivaPorcentaje = $ivaPorcentaje;
        $this->emailDestino = $emailDestino;
        $this->enableRealTransfer = $enableRealTransfer;
    }

    public function executeSettlement(): void
    {
        // Limpieza de log al inicio
        file_put_contents($this->logFilePath, "=== INICIO PROCESO MUTE: " . date('Y-m-d H:i:s') . " ===\n");

        $this->logger->info("Consultando saldo en cuenta origen: {$this->cvuOrigen}");

        $saldoTotal = 0.0;

        try {
            // 1. Obtener Saldo (100% de fondos)
            $saldoTotal = $this->bindService->getAccountBalance($this->cvuOrigen);
            
            if ($saldoTotal <= 0) {
                $this->logger->info("Saldo es 0 o negativo ($saldoTotal). No se procesa nada.");
                return;
            }

            $this->logger->info("Saldo encontrado: $saldoTotal");

            // 2. Cálculos (4 decimales para intermedios)
            // 3.a) Calcular Comisión e IVA
            $montoComision = round(($saldoTotal * $this->comisionPorcentaje) / 100, 4);
            $montoIva = round(($montoComision * $this->ivaPorcentaje) / 100, 4);
            
            // Monto Neto
            $montoNeto = round($saldoTotal - $montoComision - $montoIva, 4);

            $this->logger->info("Cálculos Intermedios : Comisión: $montoComision | IVA: $montoIva | Neto: $montoNeto");
            
            $paraTercero = floor(($montoNeto * 0.90) * 100) / 100;

            $paraPD = round($saldoTotal - $paraTercero, 2);

            $this->logger->info("Distribución Final (2 decimales): Tercero: $paraTercero | PD (Resto): $paraPD | Total: " . ($paraTercero + $paraPD));

            if ($paraPD < 0) {
                throw new \Exception("Error lógico: El monto calculado para PD es negativo. Revisar configuración.");
            }

            // 3. Ejecutar Transferencias
            $idsBind = [];
            
            $estadoFinal = 'EXITO'; // Estado por defecto

            // -------------------------------------------------------
            // BLOQUE DE DECISIÓN: REAL VS PRUEBA
            // -------------------------------------------------------
            $cvuOrigenConfirmado = $this->cvuOrigen; 

            if ($this->enableRealTransfer) {
                // --- MODO REAL ---
                
                // Transferencia 1: Tercero
                if ($paraTercero > 0) {
                    $this->logger->info("Transfiriendo a Tercero ($paraTercero) desde {$this->cvuOrigen}...");
                    
                    $res1 = $this->bindService->transferToThirdParty(
                        $this->cvuTercero, 
                        $paraTercero, 
                        $this->cvuOrigen 
                    );
                    
                    $idsBind['tercero'] = $res1['comprobanteId'] ?? 'ENVIADO';
                    // CAPTURAMOS EL ORIGEN REAL USADO
                    if (isset($res1['audit_cvu_origen'])) {
                        $cvuOrigenConfirmado = $res1['audit_cvu_origen'];
                        $this->logger->info("Transferencia a Tercero EXITOSA desde: $cvuOrigenConfirmado");
                    }
                }

                // Transferencia 2: Pagos Digitales
                if ($paraPD > 0) {
                    $this->logger->info("Transfiriendo a PagosDigitales ($paraPD) desde {$this->cvuOrigen}...");
                    
                    $res2 = $this->bindService->transferToThirdParty(
                        $this->cvuPagosDigitales, 
                        $paraPD, 
                        $this->cvuOrigen
                    );
                    
                    $idsBind['pd'] = $res2['comprobanteId'] ?? 'ENVIADO';
                    if (isset($res2['audit_cvu_origen'])) {
                        $cvuOrigenConfirmado = $res2['audit_cvu_origen'];
                    }
                }

            } else {
                // --- MODO PRUEBA (DRY RUN) ---
                $this->logger->info("[DRY RUN] Modo prueba activo. NO se ejecutan transferencias.");
                $this->logger->info("[DRY RUN] Se hubiera transferido a Tercero: $paraTercero");
                $this->logger->info("[DRY RUN] Se hubiera transferido a PD: $paraPD");

                $idsBind['tercero'] = 'SIMULACION'; 
                $idsBind['pd'] = 'SIMULACION';
                $cvuOrigenConfirmado = $this->cvuOrigen . ' (Simulado)';
                // Cambiamos el estado para la BD
                $estadoFinal = 'AUDIT_COMPLETED';
            }

            // 4. Persistir en BD
            // Notar que pasamos $estadoFinal
            $idsJson = ($estadoFinal === 'AUDIT_COMPLETED') ? 'NULL' : json_encode($idsBind);
            
            $this->recordTransaction(
                $saldoTotal, $montoComision, $montoIva, $montoNeto, 
                $paraTercero, $paraPD, $estadoFinal, null, $idsJson
            );

            // 5. Enviar Mail (Se envía en ambos casos para que valides el contenido)
            $prefijo = ($estadoFinal === 'AUDIT_COMPLETED') ? '[PRUEBA] ' : '';
            
            $cuerpoMail = $this->buildEmailBody($saldoTotal, $montoComision, $montoIva, $montoNeto, $paraTercero, $paraPD, $idsBind,$cvuOrigenConfirmado);
            
            if (!$this->enableRealTransfer) {
                $cuerpoMail = "ATENCIÓN: ESTO ES UN SIMULACRO. NO SE MOVIÓ DINERO.\n\n" . $cuerpoMail;
            }

            $this->notifier->sendFailureEmail(
                $prefijo . "Reporte Diario Mute - " . date('d/m/Y'), 
                $cuerpoMail, 
                null
            );

            $this->logger->info("Proceso finalizado con ÉXITO.");

        } catch (\Exception $e) {
            $this->logger->error("ERROR PROCESO MUTE: " . $e->getMessage());
            
            // Intentar registrar el error en BD
            try {
                $this->recordTransaction(
                    $saldoTotal ?? 0, 0, 0, 0, 0, 0, 
                    'ERROR', $e->getMessage(), null
                );
            } catch (\Exception $dbEx) {
                $this->logger->error("No se pudo guardar error en BD: " . $dbEx->getMessage());
            }

            $this->notifier->sendFailureEmail(
                "ALERTA: Falla Automatización Mute", 
                "El proceso falló: " . $e->getMessage(),
                $this->logFilePath, // Adjuntamos el log
                $this->emailDestino 
            );
        }
    }

    private function recordTransaction(
        float $saldoInicial,
        float $montoComision,
        float $montoIva,
        float $montoNeto,
        float $montoTercero,
        float $montoPD,
        string $estado,
        ?string $error,
        ?string $idsBind
    ): void {
        $this->db->insert('lotes_transferencias_mute', [
            'fecha' => (new \DateTime())->format('Y-m-d H:i:s'),
            'saldo_inicial_bind' => $saldoInicial,
            'comision_porcentaje' => $this->comisionPorcentaje,
            'comision_monto' => $montoComision,
            'iva_porcentaje' => $this->ivaPorcentaje,
            'iva_monto' => $montoIva,
            'monto_neto_calculado' => $montoNeto,
            'monto_transferido_tercero' => $montoTercero,
            'monto_transferido_pd' => $montoPD,
            'estado' => $estado,
            'detalle_error' => $error,
            'ids_bind' => $idsBind
        ]);
    }

    // ... imports y constructor existentes ...

    /**
     * Genera el reporte mensual de transferencias.
     * Si no se especifica mes/año, toma el mes anterior a la fecha actual.
     */
    public function generateMonthlyReport(?int $month = null, ?int $year = null): void
    {
        // 1. Calcular Fechas (Inicio y Fin de Mes)
        if ($month === null || $year === null) {
            // Por defecto: Mes anterior
            $fechaBase = new \DateTime('first day of last month');
        } else {
            $fechaBase = new \DateTime("$year-$month-01");
        }

        $fechaInicio = $fechaBase->format('Y-m-01 00:00:00');
        $fechaFin    = $fechaBase->format('Y-m-t 23:59:59');
        
        $nombreMes   = $fechaBase->format('F Y'); // Ej: "November 2025"

        $this->reportLogger->info("Generando reporte mensual para: $nombreMes ($fechaInicio a $fechaFin)");

        // 2. Consulta SQL Agregada
        $sql = "SELECT 
                    SUM(saldo_inicial_bind) as total_bruto,
                    SUM(comision_monto) as total_comision,
                    SUM(iva_monto) as total_iva,
                    SUM(monto_neto_calculado) as total_neto,
                    SUM(monto_transferido_tercero) as total_tercero,
                    SUM(monto_transferido_pd) as total_pd,
                    COUNT(id) as cantidad_lotes
                FROM lotes_transferencias_mute 
                WHERE fecha >= :inicio 
                  AND fecha <= :fin 
                  AND estado = 'EXITO'";

        // Ejecutar consulta (Doctrine DBAL)
        $stm = $this->db->executeQuery($sql, [
            'inicio' => $fechaInicio,
            'fin'    => $fechaFin
        ]);
        
        $datos = $stm->fetchAssociative();

        // Validar si hubo movimientos
        if (!$datos || $datos['cantidad_lotes'] == 0) {
            $this->reportLogger->info("No se encontraron movimientos exitosos para $nombreMes. Reporte omitido.");
            return;
        }

        // 3. Armar HTML y Enviar
        $htmlBody = $this->buildMonthlyEmailBody($datos, $nombreMes);
        
        $asunto = "Reporte MENSUAL Mute - " . $fechaBase->format('m/Y');
        
        // Usamos el nuevo método HTML del Notifier
        $this->notifier->sendHtmlEmail($asunto, $htmlBody);
        
        $this->reportLogger->info("Reporte mensual enviado con éxito.");
    }

    private function buildMonthlyEmailBody(array $d, string $periodo): string
    {
        // Estilos CSS inline para asegurar compatibilidad con Gmail/Outlook
        $styleTable = "width: 100%; border-collapse: collapse; font-family: Arial, sans-serif; font-size: 14px;";
        $styleTh    = "background-color: #f2f2f2; border: 1px solid #ddd; padding: 10px; text-align: left;";
        $styleTd    = "border: 1px solid #ddd; padding: 10px; text-align: right;";
        $styleTdTxt = "border: 1px solid #ddd; padding: 10px; text-align: left;";
        
        return "
        <h2 style='font-family: Arial, sans-serif; color: #333;'>Reporte Mensual de Liquidaciones - Mute</h2>
        <p style='font-family: Arial, sans-serif;'><strong>Período:</strong> $periodo</p>
        <p style='font-family: Arial, sans-serif;'><strong>Cantidad de Lotes Procesados:</strong> {$d['cantidad_lotes']}</p>
        <br>
        <table style='$styleTable'>
            <thead>
                <tr>
                    <th style='$styleTh'>Concepto</th>
                    <th style='$styleTh'>Monto Acumulado ($)</th>
                    <th style='$styleTh'>Destino</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td style='$styleTdTxt'><strong>Total Bruto Recaudado (BIND)</strong></td>
                    <td style='$styleTd'><strong>" . number_format($d['total_bruto'], 2) . "</strong></td>
                    <td style='$styleTdTxt'>Cuenta Recaudadora ($this->cvuOrigen)</td>
                </tr>
                <tr>
                    <td style='$styleTdTxt' colspan='3'>&nbsp;</td>
                </tr>
                <tr>
                    <td style='$styleTdTxt'>(-) Total Comisiones</td>
                    <td style='$styleTd'>" . number_format($d['total_comision'], 2) . "</td>
                    <td style='$styleTdTxt'>-</td>
                </tr>
                <tr>
                    <td style='$styleTdTxt'>(-) Total IVA Comisiones</td>
                    <td style='$styleTd'>" . number_format($d['total_iva'], 2) . "</td>
                    <td style='$styleTdTxt'>-</td>
                </tr>
                <tr>
                    <td style='$styleTdTxt'><strong>(=) Total Neto Distribuible</strong></td>
                    <td style='$styleTd'><strong>" . number_format($d['total_neto'], 2) . "</strong></td>
                    <td style='$styleTdTxt'>-</td>
                </tr>
                <tr>
                    <td style='$styleTdTxt' colspan='3'>&nbsp;</td>
                </tr>
                <tr style='background-color: #eafaf1;'>
                    <td style='$styleTdTxt'><strong>Transferido a TERCERO (90%)</strong></td>
                    <td style='$styleTd; color: green;'><strong>" . number_format($d['total_tercero'], 2) . "</strong></td>
                    <td style='$styleTdTxt'>$this->cvuTercero</td>
                </tr>
                <tr style='background-color: #ebf5fb;'>
                    <td style='$styleTdTxt'><strong>Transferido a PAGOS DIGITALES</strong><br><small>(10% + Com + IVA)</small></td>
                    <td style='$styleTd; color: #0056b3;'><strong>" . number_format($d['total_pd'], 2) . "</strong></td>
                    <td style='$styleTdTxt'>$this->cvuPagosDigitales</td>
                </tr>
            </tbody>
        </table>
        <br>
        <p style='font-family: Arial, sans-serif; font-size: 12px; color: #777;'>
            Este reporte fue generado automáticamente el " . date('d/m/Y H:i:s') . ".
        </p>";
    }

    private function buildEmailBody($total, $com, $iva, $neto, $tercero, $pd, $ids, string $cvuOrigenReal): string 
    {
        // Formateamos los montos para que se vean bien
        $fTotal = number_format($total, 2);
        $fCom = number_format($com, 4);
        $fIva = number_format($iva, 4);
        $fNeto = number_format($neto, 4);
        $fTercero = number_format($tercero, 2);
        $fPD = number_format($pd, 2);

        return "REPORTE DE AUTOMATIZACIÓN MUTE\n" .
               "Fecha: " . date('d/m/Y H:i:s') . "\n" .
               "========================================\n\n" .
               
               "1. ORIGEN DE FONDOS\n" .
               "   Cuenta BIND (CVU: {$cvuOrigenReal})\n" .
               "   Saldo Inicial Encontrado: $ {$fTotal}\n\n" .
               
               "2. CÁLCULOS Y DEDUCCIONES\n" .
               "   ----------------------------------------\n" .
               "   (-) Comisión ({$this->comisionPorcentaje}%): $ {$fCom}\n" .
               "   (-) IVA Comisión ({$this->ivaPorcentaje}%):  $ {$fIva}\n" .
               "   (=) Monto Neto Distribuible: $ {$fNeto}\n\n" .
               
               "3. DISTRIBUCIÓN DE FONDOS\n" .
               "   ----------------------------------------\n" .
               "   A) TERCERO (90% del Neto)\n" .
               "      Destino CVU: {$this->cvuTercero}\n" .
               "      Monto Transferido: $ {$fTercero}\n" .
               "      ID Operación: " . ($ids['tercero'] ?? 'N/A') . "\n\n" .
               
               "   B) PAGOS DIGITALES (Resto: 10% + Coms + IVA)\n" .
               "      Destino CVU: {$this->cvuPagosDigitales}\n" .
               "      Monto Transferido: $ {$fPD}\n" .
               "      ID Operación: " . ($ids['pd'] ?? 'N/A') . "\n\n" .
               
               "========================================\n" .
               "Estado Final Cuenta Origen: $ 0.00 (Teórico)";
    }
}