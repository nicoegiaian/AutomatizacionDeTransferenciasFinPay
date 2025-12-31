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
                }

            } else {
                // --- MODO PRUEBA (DRY RUN) ---
                $this->logger->info("[DRY RUN] Modo prueba activo. NO se ejecutan transferencias.");
                $this->logger->info("[DRY RUN] Se hubiera transferido a Tercero: $paraTercero");
                $this->logger->info("[DRY RUN] Se hubiera transferido a PD: $paraPD");

                $idsBind['tercero'] = 'SIMULACION'; 
                $idsBind['pd'] = 'SIMULACION';
                
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
            
            $cuerpoMail = $this->buildEmailBody($saldoTotal, $montoComision, $montoIva, $montoNeto, $paraTercero, $paraPD, $idsBind);
            
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

    private function buildEmailBody($total, $com, $iva, $neto, $tercero, $pd, $ids): string 
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
               "   Cuenta BIND (CVU: {$this->cvuOrigen})\n" .
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