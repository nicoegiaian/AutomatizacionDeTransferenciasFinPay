<?php
// src/Service/TransferManager.php

namespace App\Service;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;
use App\Service\Notifier;
use App\Service\BindServiceInterface; 
use Psr\Log\LoggerInterface;

class TransferManager
{
    private BindServiceInterface $bindService;
    private Notifier $notifier;
    private Connection $dbConnection; 
    private LoggerInterface $logger;
    private LoggerInterface $jsonLogger;
    private string $logFilePath; 
    private bool $enableRealTransfer;

    public function __construct(
        BindServiceInterface $bindService, 
        Notifier $notifier, 
        Connection $defaultConnection, 
        LoggerInterface $finpaySettlementLogger, 
        string $logFilePath, 
        bool $BIND_ENABLE_REAL_TRANSFER,
        LoggerInterface $jsonLogger 
        )
    {
        $this->bindService = $bindService;
        $this->notifier = $notifier;
        $this->dbConnection = $defaultConnection; 
        $this->logger = $finpaySettlementLogger;
        $this->logFilePath = $logFilePath;
        $this->enableRealTransfer = $BIND_ENABLE_REAL_TRANSFER;
        $this->jsonLogger = $jsonLogger;
        }

    /**
     * Ejecuta el proceso de transferencia PUSH (PDVs y Multi-Fabricante).
     */
    public function executeTransferProcess(string $fechaLiquidacionDDMMAA): array
    {
        // 0. Configuración inicial
        $estadoExito = $this->enableRealTransfer ? 'COMPLETED' : 'AUDIT_COMPLETED';
        
        if (file_exists($this->logFilePath)) {
            file_put_contents($this->logFilePath, '');
        }

        set_time_limit(0);          
        ignore_user_abort(true);    

        $fechaSQL = \DateTime::createFromFormat('dmy', $fechaLiquidacionDDMMAA)->format('Y-m-d');
        $agrupadorPorUnidad = [];

        $this->logger->info("=== INICIO PROCESO DE LIQUIDACIÓN MULTI-DESTINO ===");
        $this->logger->info("=== FECHA LOTE: $fechaLiquidacionDDMMAA");
        
        // Verificamos si existe lote procesando
        if ($this->checkExistingLote($fechaSQL)) {
            $msg = "Ya existe un lote COMPLETED o PROCESSING para la fecha $fechaSQL";
            $this->logger->warning($msg);
            throw new \Exception($msg); 
        }

        // 1. Crear Lote PROCESSING
        $this->dbConnection->insert('lotes_liquidacion', [
            'fecha_liquidacion' => $fechaSQL,
            'estado_actual_pdv' => 'PROCESSING',
            'estado_actual_moura' => 'PROCESSING',
            'monto_solicitado' => 0, 
            'fecha_creacion' => (new \DateTime())->format('Y-m-d H:i:s'),
        ]);
        $loteId = $this->dbConnection->lastInsertId();
        $this->logger->info("Lote ID #$loteId creado. Estados: PROCESSING");

        try {
            // 2. Cálculo de Montos
            $data = $this->getPendingTransfersData($fechaSQL);
            
            // Si no hay nada, cerramos todo.
            if ($data['total_monto'] <= 0) {
                $this->logger->info("Sin montos pendientes. Cerrando lote vacío.");
                $this->closeLote($loteId, 'COMPLETED', 'COMPLETED'); 
                return ['unidades' => [], 'info_adicional' => "No se encontraron montos pendientes."];
            }

            // Actualizamos el lote
            $this->dbConnection->update('lotes_liquidacion', [
                'monto_solicitado' => $data['total_monto'],
                'monto_pdv' => $data['monto_pdv'],
                'monto_fabricante' => $data['monto_fabricante_total'], 
                'transacciones_ids' => json_encode($data['transacciones_ids'])
            ], ['id' => $loteId]);
            
            $this->logger->info("Montos: PDV Total: {$data['monto_pdv']} | Fab Total: {$data['monto_fabricante_total']}");

            // ---------------------------------------------------------
            // 3. PROCESO PDVS (Puntos de Venta)
            // ---------------------------------------------------------
            $pdvs = $this->getPendingTransfersForPush($fechaSQL);
            $erroresPdv = 0;
            $totalPdvs = count($pdvs);

            if ($data['monto_pdv'] > 0 && $totalPdvs === 0) {
                $msg = "ALERTA CRÍTICA: Hay monto PDV ($" . $data['monto_pdv'] . ") pero NO hay cuentas destino.";
                $this->logger->error($msg);
                $this->closeLote($loteId, 'ERROR', 'ABORTED_BY_PDV_ERROR'); 
                $this->notifier->sendFailureEmail("Error Datos Faltantes PDV", $msg);
                throw new \Exception($msg);
            }

            $this->logger->info("--- Iniciando transferencias a $totalPdvs Puntos de Venta ---");

            foreach ($pdvs as $pdv) {
                $nombreUnidad = $pdv['nombre_unidad'] ?? 'SIN_UNIDAD'; 
                $razonSocial = $pdv['razonsocial']; // Variable para usar nombre amigable
                $idPdv = $pdv['idpdv'];

                if (!isset($agrupadorPorUnidad[$nombreUnidad])) {
                    $agrupadorPorUnidad[$nombreUnidad] = [
                        'nombre' => $nombreUnidad,
                        'moura' => null, 
                        'pdvs' => []
                    ];
                }

                $detallePdv = [
                    'razonsocial' => $razonSocial, 
                    'monto' => $pdv['monto'],
                    'cbu_destino' => $pdv['cbu'],
                    'estado' => 'PENDIENTE'
                ];

                // Preparamos datos visuales para el log (JSON)
                $payloadVisual = [
                    'pdv' => "$razonSocial (ID: $idPdv)",
                    'cbu_destino' => $pdv['cbu'],
                    'importe' => $pdv['monto'],
                    'unidad' => $nombreUnidad
                ];
                $jsonLogPdv = json_encode($payloadVisual, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

                if ($pdv['monto'] <= 0) {
                     $this->updateTransactionStatus($pdv['transacciones_ids'], 'COMPLETED', 'NOT-SEND-ZERO', null, true, "Monto 0 omitido");
                     $detallePdv['estado'] = 'OMITIDO (Monto 0)';
                     // AGREGADO: Loguear también los omitidos para tener trazabilidad completa
                     $this->logDetallado("OMITIDO PDV-$razonSocial", "Monto $0. No se requiere transferencia.");

                } elseif (empty($pdv['cbu'])) { 
                     // CBU NULO
                     $erroresPdv++;
                     $this->logger->error("    FALLO PDV $idPdv ($razonSocial): CBU Nulo o Vacio.");
                     $detallePdv['estado'] = 'ERROR CONFIG (Sin CBU)';
                     // AGREGADO: Loguear el error de configuración en el archivo
                     $this->logDetallado("ERROR CONFIG PDV-$razonSocial", "No tiene CBU asignado.\n$jsonLogPdv");

                } else {
                    try {
                        
                            $res = $this->bindService->transferToThirdParty(
                            $pdv['cbu'], 
                            $pdv['monto'], 
                            null, // Origen default
                            $this->enableRealTransfer // <--- Flag local de TransferManager
                        );
                        $this->jsonLogger->info("RESPUESTA PDV ({$razonSocial}):", [
                            'monto' => $pdv['monto'],
                            'destino' => $pdv['cbu'],
                            'respuesta_api' => $res
                        ]);

                        // CHEQUEO DE RESPUESTA
                        if (isset($res['estado']) && $res['estado'] === 'SIMULATED') {
                            // --- CASO SIMULACIÓN ---
                            $this->logDetallado("SIMULACIÓN PDV-$razonSocial", "Payload simulado:\n" . json_encode($res['payload_debug'] ?? [], JSON_PRETTY_PRINT));
                            
                            $this->updateTransactionStatus($pdv['transacciones_ids'], 'AUDIT_COMPLETED', 'MOCK-ID-' . time(), null, true);
                            $detallePdv['estado'] = 'SIMULADA (DRY RUN)';
                            
                        } else {
                            // --- CASO REAL ---
                            $bindId = $res['comprobanteId'] ?? 'OK-NO-ID';
                            $coelsaId = $res['coelsaId'] ?? null;
                            
                            $this->updateTransactionStatus($pdv['transacciones_ids'], 'COMPLETADA', $bindId, $coelsaId);
                            $detallePdv['estado'] = 'ENVIADA';
                            
                            // Log de éxito real
                            $this->logDetallado("ÉXITO REAL PDV-$razonSocial", "ID Bind: $bindId");
                        }


                    } catch (\Exception $e) {
                        // ERRORES REALES DE API
                        $this->jsonLogger->error("FALLO PDV ({$razonSocial}): " . $e->getMessage());
                        $erroresPdv++;
                        $this->updateTransactionStatus($pdv['transacciones_ids'], 'ERROR_TRANSFERENCIA', '', '', false, $e->getMessage());
                        $detallePdv['estado'] = 'ERROR: ' . $e->getMessage();
                        $this->logDetallado("ERROR API PDV-$razonSocial", $e->getMessage());
                    } catch (\App\Exception\DryRunException $e) {
                        $this->updateTransactionStatus($pdv['transacciones_ids'], 'TRANSFERENCIA DRY RUN ', '', '', false, $e->getMessage());
                        $this->logDetallado("SIMULACIÓN PDV-$nombreUnidad", $e->getMessage() . "\n" . $jsonLog);
                        $detalleFab['estado'] = 'SIMULADA (DRY RUN)';
                        $detalleMouraLog[$nombreUnidad] = 'DRY_RUN_OK';
                    } 
                }
                
                $agrupadorPorUnidad[$nombreUnidad]['pdvs'][] = $detallePdv;
            }

            $estadoFinalPdv = ($erroresPdv === 0) ? $estadoExito : (($erroresPdv < $totalPdvs) ? 'PARTIAL_ERROR' : 'ERROR');
            if ($totalPdvs === 0 && $data['monto_pdv'] == 0) $estadoFinalPdv = $estadoExito;

            // ---------------------------------------------------------
            // 4. PROCESO FABRICANTE (Multi-Unidad de Negocio)
            // ---------------------------------------------------------
            $unidadesMoura = $data['desglose_fabricante']; 
            $erroresMoura = 0;
            $detalleMouraLog = []; 

            foreach ($unidadesMoura as $unidad) {
                // Definimos variables al inicio para evitar warning
                $nombreUnidad = $unidad['nombre_unidad']; 
                $cbuUnidad = $unidad['cbu_unidad'];
                $montoUnidad = (float)$unidad['monto_calculado'];
                $idUnidad = $unidad['id_unidad'];

                $payloadLog = [
                    'unidad_negocio' => $nombreUnidad,
                    'cbu_destino' => $cbuUnidad,
                    'importe' => $montoUnidad,
                    'concepto' => 'Liquidacion Fabricante',
                    'referencia_interna' => "FAB-{$idUnidad}-" . date('His'),
                    'fecha_proceso' => date('Y-m-d H:i:s')
                ];
                $jsonLog = json_encode($payloadLog, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
                
                if (!isset($agrupadorPorUnidad[$nombreUnidad])) {
                    $agrupadorPorUnidad[$nombreUnidad] = [
                        'nombre' => $nombreUnidad,
                        'moura' => null,
                        'pdvs' => []
                    ];
                }
                
                $detalleFab = [
                    'monto' => $montoUnidad,
                    'cbu_destino' => $cbuUnidad,
                    'estado' => 'PENDIENTE'
                ];
                
                if ($montoUnidad <= 0) {
                    $detalleFab['estado'] = 'OMITIDO (Sin saldo)';
                    $detalleMouraLog[$nombreUnidad] = 'SKIPPED_ZERO';
                } elseif (empty($cbuUnidad)) {
                    $erroresMoura++;
                    $detalleFab['estado'] = 'ERROR CONFIG (Sin CBU)';
                    $detalleMouraLog[$nombreUnidad] = 'ERROR_NO_CBU';
                    $this->logDetallado("ERROR-CONFIG-FAB-$nombreUnidad", "CBU Vacío. Intento:\n$jsonLog");
                } else {
                    try {
                        // Intentamos Transferir
                            $resFab = $this->bindService->transferToThirdParty(
                            $cbuUnidad, 
                            $montoUnidad,
                            null, // Origen por defecto
                            $this->enableRealTransfer // <--- Flag local de TransferManager
                        );            
                        $this->jsonLogger->info("RESPUESTA FABRICANTE ({$nombreUnidad}):", [
                            'monto' => $montoUnidad,
                            'destino' => $cbuUnidad,
                            'respuesta_api' => $resFab
                        ]);            
                        // LÓGICA DE UPDATE DE ESTADO
                        if (isset($resFab['estado']) && $resFab['estado'] === 'SIMULATED') {
                            // --- CASO SIMULADO ---
                            // Marcamos en BD (lógica específica de fabricante)
                            $this->updateFabricanteStatus($unidad['transacciones_ids_csv'] ?? '');
                            
                            $this->logDetallado("SIMULACIÓN FAB-$nombreUnidad", "Payload simulado:\n" . json_encode($resFab['payload_debug'] ?? [], JSON_PRETTY_PRINT));
                            $detalleFab['estado'] = 'SIMULADA (DRY RUN)';           
                            $detalleMouraLog[$nombreUnidad] = 'DRY_RUN_OK';
                            
                        } else {
                            // --- CASO REAL ---
                            $this->updateFabricanteStatus($unidad['transacciones_ids_csv'] ?? '');
                            
                            $bindId = $resFab['comprobanteId'] ?? 'OK-NO-ID';
                            $this->logger->info("    -> OK Fab $nombreUnidad (ID: $bindId)");
                            
                            $detalleFab['estado'] = 'ENVIADA';
                            $detalleMouraLog[$nombreUnidad] = 'OK';
                            $this->logDetallado("ÉXITO REAL FAB-$nombreUnidad", "ID BIND: $bindId\n$jsonLog");
                        }

                    } catch (\App\Exception\DryRunException $e) {
                        $this->updateFabricanteStatus($unidad['transacciones_ids_csv'] ?? '');
                        $this->logDetallado("SIMULACIÓN FAB-$nombreUnidad", $e->getMessage() . "\n" . $jsonLog);
                        $detalleFab['estado'] = 'SIMULADA (DRY RUN)';
                        $detalleMouraLog[$nombreUnidad] = 'DRY_RUN_OK';
                    } catch (\Exception $e) {
                        // --- CASO ERROR DE API ---
                        $this->jsonLogger->error("FALLO FABRICANTE ({$nombreUnidad}): " . $e->getMessage());
                        $erroresMoura++;
                        $errorMsg = "EXCEPCIÓN API:\n" . $e->getMessage() . "\n\nDATOS DEL INTENTO:\n" . $jsonLog;
                        
                        $this->logDetallado("ERROR CRÍTICO FAB-$nombreUnidad", $errorMsg);
                        $this->logger->error("    -> FALLO Unidad $nombreUnidad: " . $e->getMessage());
                        
                        $detalleFab['estado'] = 'ERROR: ' . $e->getMessage();
                        $detalleMouraLog[$nombreUnidad] = 'ERROR_API: ' . $e->getMessage();
                    }
                }

                $agrupadorPorUnidad[$nombreUnidad]['moura'] = $detalleFab;
            }

            // Determinamos estado final de Moura
            $cantUnidadesConMonto = count(array_filter($unidadesMoura, fn($u) => $u['monto_calculado'] > 0));
            if ($cantUnidadesConMonto === 0) {
                $estadoFinalMoura = $estadoExito; 
            } else {
                $estadoFinalMoura = ($erroresMoura === 0) ? $estadoExito : (($erroresMoura < $cantUnidadesConMonto) ? 'PARTIAL_ERROR' : 'ERROR');
            }

            // 5. CIERRE DEL LOTE
            $this->closeLote($loteId, $estadoFinalPdv, $estadoFinalMoura, $detalleMouraLog);
            
            if ($estadoFinalPdv !== 'COMPLETED' || $estadoFinalMoura !== 'COMPLETED') {
                $this->notifier->sendFailureEmail(
                    "Alerta Liquidación $fechaLiquidacionDDMMAA", 
                    "PDV: $estadoFinalPdv (Errores: $erroresPdv)\nMoura: $estadoFinalMoura (Errores: $erroresMoura)\nVer log adjunto.",
                    $this->logFilePath
                );
            }

            return [
                'unidades' => array_values($agrupadorPorUnidad), 
                'info_adicional' => null
            ];

        } catch (\Exception $e) {
            $this->logger->critical("ERROR FATAL EN PROCESO: " . $e->getMessage());
            $this->closeLote($loteId, 'ERROR', 'ERROR'); 
            $this->notifier->sendFailureEmail("Error Fatal Liquidación $fechaLiquidacionDDMMAA", $e->getMessage(), $this->logFilePath);
            throw $e;
        }
    }

    private function getPendingTransfersData(string $fechaSQL): array
    {
        // 1. PDV
        $queryPdv = "
            SELECT 
                SUM(ROUND((((t.importeprimervenc) * CAST(SUBSTRING_INDEX(t.estadocheque, '-', 1) AS DECIMAL))/100), 2)) AS monto_pdv,
                GROUP_CONCAT(t.nrotransaccion) AS transacciones_ids_csv
            FROM transacciones t
            WHERE DATE(t.fechapagobind) = :fecha 
            AND t.transferencia_procesada = 0
        ";
        $dataPdv = $this->dbConnection->executeQuery($queryPdv, ['fecha' => $fechaSQL])->fetchAssociative();

        // 2. FABRICANTE
        $queryFab = "
            SELECT 
                u.id as id_unidad,
                u.nombre as nombre_unidad,
                u.cbu as cbu_unidad,
                GROUP_CONCAT(t.nrotransaccion) AS transacciones_ids_csv, 
                ROUND(
                    SUM(ROUND((((t.importeprimervenc) * CAST(SUBSTRING_INDEX(t.estadocheque, '-', -1) AS DECIMAL))/100), 2))
                    - 
                    (
                        SUM(t.importecheque) * (
                            SELECT porcentaje 
                            FROM porcentajes 
                            WHERE nombre = 'Subsidio Moura' 
                            AND fecha <= :fecha 
                            ORDER BY fecha DESC 
                            LIMIT 1
                        ) / 100 * 1.21
                    )
                , 2) AS monto_calculado
            FROM transacciones t
            INNER JOIN puntosdeventa p ON t.idpdv = p.id
            INNER JOIN unidadesdenegocio u ON p.idunidadnegocio = u.id
            AND DATE(t.fechapagobind) = :fecha
            AND t.transferencia_fabricante_procesada = 0  
            GROUP BY u.id, u.nombre, u.cbu
        ";

        $resultFab = $this->dbConnection->executeQuery($queryFab, ['fecha' => $fechaSQL]);
        $unidadesFabricante = $resultFab->fetchAllAssociative();

        $montoPdvTotal = (float) ($dataPdv['monto_pdv'] ?? 0);

        $montoFabTotal = 0.0;
        foreach ($unidadesFabricante as $u) {
            $montoFabTotal += (float)$u['monto_calculado'];
        }

        if ($montoPdvTotal <= 0 && $montoFabTotal <= 0) {
             return [
                'total_monto' => 0.0,
                'monto_pdv' => 0.0,
                'monto_fabricante_total' => 0.0,
                'desglose_fabricante' => [],
                'transacciones_ids' => []
            ];
        }

        $transaccionIds = [];
        if (!empty($dataPdv['transacciones_ids_csv'])) {
            $transaccionIds = array_map('intval', explode(',', $dataPdv['transacciones_ids_csv']));
        }

        return [
            'total_monto' => $montoPdvTotal + $montoFabTotal,
            'monto_pdv' => $montoPdvTotal,
            'monto_fabricante_total' => $montoFabTotal,
            'desglose_fabricante' => $unidadesFabricante,
            'transacciones_ids' => $transaccionIds, 
        ];
    }

    private function getPendingTransfersForPush(string $fechaSQL): array
    {
        $query = "
            SELECT 
                p.id AS idpdv,
                p.razonsocial,
                p.cbu AS cbuDestino,
                u.nombre AS nombre_unidad,  
                SUM(ROUND((((t.importeprimervenc) * CAST(SUBSTRING_INDEX(t.estadocheque, '-', 1) AS DECIMAL))/100), 2)) AS total_monto,
                GROUP_CONCAT(t.nrotransaccion) AS transacciones_ids_csv
            FROM transacciones t
            JOIN puntosdeventa p ON t.idpdv = p.id
            JOIN unidadesdenegocio u ON p.idunidadnegocio = u.id 
            LEFT JOIN liquidacionesdetalle ld ON t.nrotransaccion = ld.nrotransaccion
            WHERE DATE(t.fechapagobind) = :fecha 
            AND t.transferencia_procesada = 0
            GROUP BY p.id, p.razonsocial, p.cbu, u.nombre 
        ";
        
        $result = $this->dbConnection->executeQuery($query, ['fecha' => $fechaSQL]);
        
        $transfers = [];
        foreach ($result->fetchAllAssociative() as $row) {
            $transfers[] = [
                'idpdv' => (int) $row['idpdv'],
                'razonsocial' => $row['razonsocial'],
                'nombre_unidad' => $row['nombre_unidad'],
                'cbu' => $row['cbuDestino'],
                'monto' => (float) $row['total_monto'],
                'transacciones_ids' => array_map('intval', explode(',', $row['transacciones_ids_csv']))
            ];
        }
        
        return $transfers;
    }
    
    private function updateTransactionStatus(array $transaccionIds, string $estado, string $bindId, ?string $coelsaId = null, bool $marcarProcesada = true, ?string $respuestaBind = null): void
    {
        if (empty($transaccionIds)) {
            return;
        }

        $procesadaInt = $marcarProcesada ? 1 : 0; 
        $idsList = implode(', ', $transaccionIds);
        
        $sql = "
            UPDATE transacciones 
            SET 
                transferencia_procesada = :procesada, 
                transferencia_estado = :estado, 
                transferencia_id_bind = :bind_id, 
                coelsa_id = :coelsa_id, 
                respuesta_BIND = :respuesta,
                fecha_transferencia = NOW()
            WHERE nrotransaccion IN ({$idsList})
        ";

        $this->dbConnection->executeStatement($sql, [
            'procesada' => $procesadaInt,
            'estado' => $estado,
            'bind_id' => $bindId,
            'coelsa_id' => $coelsaId,
            'respuesta' => $respuestaBind
        ]);
    }

    private function updateFabricanteStatus(string $idsCsv): void
    {
        if (empty($idsCsv)) return;
        
        $idsArray = explode(',', $idsCsv);
        $idsList = implode(',', array_map('intval', $idsArray)); 

        if (empty($idsList)) return;

        $sql = "UPDATE transacciones SET transferencia_fabricante_procesada = 1 WHERE nrotransaccion IN ($idsList)";
        $this->dbConnection->executeStatement($sql);
    }

    private function checkExistingLote(string $fechaSQL): bool
    {
        $sql = "SELECT id FROM lotes_liquidacion 
                WHERE fecha_liquidacion = :f 
                AND (estado_actual_pdv IN ('PROCESSING', 'COMPLETED') OR estado_actual_moura IN ('PROCESSING', 'COMPLETED'))";
        return (bool) $this->dbConnection->fetchOne($sql, ['f' => $fechaSQL]);
    }

    private function closeLote(int $id, string $estadoPdv, string $estadoMoura = 'PENDING', array $detalleJson = []): void
    {
        $data = [
            'estado_actual_pdv' => $estadoPdv,
            'estado_actual_moura' => $estadoMoura
        ];
        
        if (!empty($detalleJson)) {
            $data['detalle_moura_json'] = json_encode($detalleJson);
        }

        $this->dbConnection->update('lotes_liquidacion', $data, ['id' => $id]);
    }

    private function logDetallado(string $titulo, string $contenido): void {
        $separador = str_repeat('-', 50);
        $mensajeLegible = "\n" . $separador . "\n" .
                          " [" . date('Y-m-d H:i:s') . "] $titulo\n" . 
                          $separador . "\n" . 
                          $contenido . "\n" . 
                          $separador . "\n";
        file_put_contents($this->logFilePath, $mensajeLegible, FILE_APPEND);   
    }

    // Código comentado de DEBIN omitido intencionalmente
}