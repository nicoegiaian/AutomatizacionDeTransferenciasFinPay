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
    private bool $skipDuplicateCheck;

    public function __construct(
        BindServiceInterface $bindService, 
        Notifier $notifier, 
        Connection $defaultConnection, 
        LoggerInterface $finpaySettlementLogger, 
        string $logFilePath, 
        bool $BIND_ENABLE_REAL_TRANSFER,
        bool $BIND_SKIP_DUPLICATE_CHECK,
        LoggerInterface $jsonLogger 
        )
    {
        $this->bindService = $bindService;
        $this->notifier = $notifier;
        $this->dbConnection = $defaultConnection; 
        $this->logger = $finpaySettlementLogger;
        $this->logFilePath = $logFilePath;
        $this->enableRealTransfer = $BIND_ENABLE_REAL_TRANSFER;
        $this->skipDuplicateCheck = $BIND_SKIP_DUPLICATE_CHECK;
        $this->jsonLogger = $jsonLogger;
        }

    /**
     * Ejecuta el proceso de transferencia PUSH (PDVs y Multi-Fabricante).
     */
    public function executeTransferProcess(string $fechaLiquidacionDDMMAA): array
    {
        // 0. Configuración inicial
        $estadoExito = $this->enableRealTransfer ? 'COMPLETED' : 'AUDIT_COMPLETED';

        // --- MUTEX: previene ejecuciones concurrentes ---
        $lockFilePath = dirname($this->logFilePath) . '/transferencias.lock';
        $lockHandle = fopen($lockFilePath, 'c');
        if ($lockHandle === false) {
            throw new \RuntimeException("No se pudo abrir el archivo de lock: $lockFilePath");
        }
        if (!flock($lockHandle, LOCK_EX | LOCK_NB)) {
            fclose($lockHandle);
            throw new \RuntimeException("El proceso de transferencias ya está en ejecución. Intente nuevamente en unos minutos.");
        }

        if (file_exists($this->logFilePath)) {
            file_put_contents($this->logFilePath, '');
        }

        set_time_limit(600); // 10 minutos máximo (protección contra procesos eternos)
        ini_set('max_execution_time', '600');
        ignore_user_abort(true);    

        $fechaSQL = \DateTime::createFromFormat('dmy', $fechaLiquidacionDDMMAA)->format('Y-m-d');
        $agrupadorPorUnidad = [];

        $this->logger->info("=== INICIO PROCESO DE LIQUIDACIÓN MULTI-DESTINO ===");
        $this->logger->info("=== FECHA LOTE: $fechaLiquidacionDDMMAA");
        
        // Verificamos si existe lote para esta fecha
        $loteExistente = $this->checkExistingLote($fechaSQL);
        $loteYaCompletado = false; // Flag para saber si ya estaba completado antes de esta ejecución
        
        if ($loteExistente) {
            $loteId = $loteExistente['id'];
            
            if ($loteExistente['completed']) {
                // Si está COMPLETED, consultamos las transferencias ya realizadas
                $loteYaCompletado = true;
                $this->logger->info("✅ Lote ID #$loteId ya está COMPLETED. Consultando transferencias realizadas...");
            } else {
                // Si está PROCESSING o con errores, lo retomamos
                $estadoPdv = $loteExistente['estado_pdv'] ?? 'UNKNOWN';
                $estadoMoura = $loteExistente['estado_moura'] ?? 'UNKNOWN';
                $this->logger->info("♻️  RETOMANDO Lote ID #$loteId (PDV: $estadoPdv, Moura: $estadoMoura). Se procesarán solo las transferencias pendientes.");
            }
        } else {
            // Si no existe, creamos uno nuevo
            $this->dbConnection->insert('lotes_liquidacion', [
                'fecha_liquidacion' => $fechaSQL,
                'estado_actual_pdv' => 'PROCESSING',
                'estado_actual_moura' => 'PROCESSING',
                'monto_solicitado' => 0, 
                'fecha_creacion' => (new \DateTime())->format('Y-m-d H:i:s'),
            ]);
            $loteId = $this->dbConnection->lastInsertId();
            $this->logger->info("✨ Lote ID #$loteId creado. Estados: PROCESSING");
        }

        try {
            // 2. Cálculo de Montos
            $data = $this->getPendingTransfersData($fechaSQL);
            $contablesMarcadas = $this->markFabricanteAsAccountingAdvance($fechaSQL);
            if ($contablesMarcadas > 0) {
                $this->logger->info("🧾 Adelanto UN contable: {$contablesMarcadas} transacciones marcadas sin transferencia de dinero.");
            }
            
            // Si no hay nada pendiente, devolvemos las transferencias ya completadas
            if ($data['total_monto'] <= 0) {
                $this->logger->info("✅ Sin montos pendientes. Consultando transferencias completadas...");
                
                // Obtener las transferencias completadas
                $completadas = $this->getCompletedTransfersData($fechaSQL);
                
                // Reconstruir transacciones_ids y detalle del lote con datos reales
                $this->updateLoteConDatosCompletos($loteId, $fechaSQL);
                $this->closeLote($loteId, 'COMPLETED', 'COMPLETED'); 
                
                $infoLote = $this->getLoteInfo($loteId);
                
                // Generar mensaje según si ya estaba completado o se acaba de completar
                if ($loteYaCompletado) {
                    $mensajeResumen = "✅ Las transferencias de esta fecha ya fueron procesadas anteriormente.\n" .
                                    "📅 Fecha de procesamiento: " . date('d/m/Y H:i:s', strtotime($infoLote['fecha_creacion'])) . "\n" .
                                    "📊 Total: " . count($completadas['unidades']) . " unidades de negocio.";
                } else {
                    $mensajeResumen = "✅ Liquidación completada exitosamente.\n" .
                                    "🕐 Procesado el: " . date('d/m/Y H:i:s') . "\n" .
                                    "📊 Total: " . count($completadas['unidades']) . " unidades de negocio.";
                }
                
                return [
                    'unidades' => $completadas['unidades'], 
                    'info_adicional' => $mensajeResumen,
                    'lote_id' => $loteId,
                    'fecha_procesamiento' => $infoLote['fecha_creacion'],
                    'ya_procesado' => $loteYaCompletado
                ];
            }

            // Recopilamos TODOS los IDs de transacciones (PDV + Fabricante)
            $todosLosIds = $data['transacciones_ids']; // IDs de PDV
            foreach ($data['desglose_fabricante'] as $unidad) {
                if (!empty($unidad['transacciones_ids_csv'])) {
                    $idsFab = array_map('intval', explode(',', $unidad['transacciones_ids_csv']));
                    $todosLosIds = array_merge($todosLosIds, $idsFab);
                }
            }
            $todosLosIds = array_unique($todosLosIds);

            // Actualizamos el lote
            $this->dbConnection->update('lotes_liquidacion', [
                'monto_solicitado' => $data['total_monto'],
                'monto_pdv' => $data['monto_pdv'],
                'monto_fabricante' => $data['monto_fabricante_total'], 
                'transacciones_ids' => json_encode($todosLosIds)
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
                        'pdvs' => [],
                        'recupero_financiacion' => null,
                    ];
                }

                // --- APLICAR DESCUENTOS PENDIENTES ---
                $montoOriginal = $pdv['monto'];
                $descuentosAplicados = $this->aplicarDescuentosPdv($idPdv, $pdv['monto'], $loteId);
                $pdv['monto'] = $descuentosAplicados['monto_final'];
                
                if ($descuentosAplicados['total_descontado'] > 0) {
                    $this->logger->info("    💰 PDV $razonSocial: Descuento aplicado \${$descuentosAplicados['total_descontado']} (Original: \$$montoOriginal → Final: \${$pdv['monto']})");
                    $this->logDetallado("DESCUENTO PDV-$razonSocial", 
                        "Monto original: \$$montoOriginal\n" .
                        "Total descontado: \${$descuentosAplicados['total_descontado']}\n" .
                        "Monto final a transferir: \${$pdv['monto']}\n" .
                        "Descuentos aplicados: " . json_encode($descuentosAplicados['detalle'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
                    );
                }

                $detallePdv = [
                    'razonsocial' => $razonSocial, 
                    'monto' => $pdv['monto'],
                    'seguro_incobrabilidad_moura' => (float)($pdv['seguro_incobrabilidad_moura'] ?? 0),
                    'iva_seguro_incobrabilidad_moura' => (float)($pdv['iva_seguro_incobrabilidad_moura'] ?? 0),
                    'monto_original' => $montoOriginal,
                    'descuentos_aplicados' => $descuentosAplicados['total_descontado'],
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
                        // Validación anti-duplicado: consultar movimientos BIND
                        if ($this->enableRealTransfer && !$this->skipDuplicateCheck) {
                            $movExistente = $this->bindService->existeTransferenciaPrevia($pdv['cbu'], $pdv['monto'], $fechaSQL);
                            if ($movExistente !== null) {
                                $bindIdExistente = $movExistente['idOperacion'] ?? 'ENCONTRADO';
                                $coelsaIdExistente = $movExistente['coelsaId'] ?? null;
                                $this->logger->warning("    ⚠️ PDV $idPdv ($razonSocial): Transferencia YA EXISTE en BIND (ID: $bindIdExistente). Marcando sin re-enviar.");
                                $this->logDetallado("DUPLICADO PDV-$razonSocial", "Transferencia encontrada en BIND. ID: $bindIdExistente\nMonto: {$pdv['monto']}\nCBU: {$pdv['cbu']}");
                                $this->updateTransactionStatus($pdv['transacciones_ids'], 'COMPLETADA', (string)$bindIdExistente, $coelsaIdExistente, true, 'Ya transferido en BIND');
                                $detallePdv['estado'] = 'YA TRANSFERIDO (BIND)';
                                $agrupadorPorUnidad[$nombreUnidad]['pdvs'][] = $detallePdv;
                                continue;
                            }
                        }

                            $res = $this->bindService->transferToThirdParty(
                            $pdv['cbu'], 
                            $pdv['monto'], 
                            null, // Origen default
                            $this->enableRealTransfer, // <--- Flag local de TransferManager
                            $pdv['cuit'] ?? null
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
                            
                            // No marcamos como procesada (procesada=0) para que el run real las encuentre
                            $this->updateTransactionStatus($pdv['transacciones_ids'], 'AUDIT_COMPLETED', 'MOCK-ID-' . time(), null, false);
                            // Revertir descuentos para que el run real los aplique (dry run no debe consumirlos)
                            $this->revertirDescuentosLote($loteId, $idPdv, null, $descuentosAplicados['detalle'] ?? []);
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
                        // Revertir descuentos para que se re-apliquen en el próximo intento
                        $this->revertirDescuentosLote($loteId, $idPdv, null, $descuentosAplicados['detalle'] ?? []);
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

                // --- APLICAR DESCUENTOS PENDIENTES A FABRICANTE ---
                $montoOriginalFab = $montoUnidad;
                $descuentosFab = $this->aplicarDescuentosUnidad($idUnidad, $montoUnidad, $loteId);
                $montoUnidad = $descuentosFab['monto_final'];
                
                if ($descuentosFab['total_descontado'] > 0) {
                    $this->logger->info("    💰 FAB $nombreUnidad: Descuento aplicado \${$descuentosFab['total_descontado']} (Original: \$$montoOriginalFab → Final: \$$montoUnidad)");
                    $this->logDetallado("DESCUENTO FAB-$nombreUnidad", 
                        "Monto original: \$$montoOriginalFab\n" .
                        "Total descontado: \${$descuentosFab['total_descontado']}\n" .
                        "Monto final a transferir: \$$montoUnidad\n" .
                        "Descuentos aplicados: " . json_encode($descuentosFab['detalle'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
                    );
                }

                $payloadLog = [
                    'unidad_negocio' => $nombreUnidad,
                    'cbu_destino' => $cbuUnidad,
                    'importe' => $montoUnidad,
                    'importe_original' => $montoOriginalFab,
                    'concepto' => 'Liquidacion Fabricante',
                    'referencia_interna' => "FAB-{$idUnidad}-" . date('His'),
                    'fecha_proceso' => date('Y-m-d H:i:s')
                ];
                $jsonLog = json_encode($payloadLog, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
                
                if (!isset($agrupadorPorUnidad[$nombreUnidad])) {
                    $agrupadorPorUnidad[$nombreUnidad] = [
                        'nombre' => $nombreUnidad,
                        'moura' => null,
                        'pdvs' => [],
                        'recupero_financiacion' => null,
                    ];
                }
                
                $detalleFab = [
                    'monto' => $montoUnidad,
                    'monto_original' => $montoOriginalFab,
                    'monto_recupero_financiacion' => (float)($unidad['monto_recupero_financiacion'] ?? 0),
                    'descuentos_aplicados' => $descuentosFab['total_descontado'],
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
                        // Validación anti-duplicado: consultar movimientos BIND
                        if ($this->enableRealTransfer && !$this->skipDuplicateCheck) {
                            $movExistenteFab = $this->bindService->existeTransferenciaPrevia($cbuUnidad, $montoUnidad, $fechaSQL);
                            if ($movExistenteFab !== null) {
                                $bindIdExistente = $movExistenteFab['idOperacion'] ?? 'ENCONTRADO';
                                $coelsaIdExistente = $movExistenteFab['coelsaId'] ?? null;
                                $this->logger->warning("    ⚠️ FAB $nombreUnidad: Transferencia YA EXISTE en BIND (ID: $bindIdExistente). Marcando sin re-enviar.");
                                $this->logDetallado("DUPLICADO FAB-$nombreUnidad", "Transferencia encontrada en BIND. ID: $bindIdExistente\nMonto: $montoUnidad\nCBU: $cbuUnidad");
                                $this->updateFabricanteStatus(
                                    $unidad['transacciones_ids_csv'] ?? '',
                                    'COMPLETADA',
                                    (string)$bindIdExistente,
                                    $coelsaIdExistente,
                                    'Ya transferido en BIND'
                                );
                                $this->updateFinancingInstallmentsStatus(
                                    $unidad['cuota_ids_csv'] ?? '',
                                    'Cobrada',
                                    $fechaSQL
                                );
                                $detalleFab['estado'] = 'YA TRANSFERIDO (BIND)';
                                $detalleMouraLog[$nombreUnidad] = 'OK';
                                $agrupadorPorUnidad[$nombreUnidad]['moura'] = $detalleFab;
                                continue;
                            }
                        }

                        // Intentamos Transferir
                            $resFab = $this->bindService->transferToThirdParty(
                            $cbuUnidad, 
                            $montoUnidad,
                            null, // Origen por defecto
                            $this->enableRealTransfer, // <--- Flag local de TransferManager
                            $unidad['cuit_unidad'] ?? null
                        );            
                        $this->jsonLogger->info("RESPUESTA FABRICANTE ({$nombreUnidad}):", [
                            'monto' => $montoUnidad,
                            'destino' => $cbuUnidad,
                            'respuesta_api' => $resFab
                        ]);            
                        // LÓGICA DE UPDATE DE ESTADO
                        if (isset($resFab['estado']) && $resFab['estado'] === 'SIMULATED') {
                            // --- CASO SIMULADO ---
                            $simBindId = $resFab['comprobanteId'] ?? 'SIM-NO-ID';
                            // No marcamos como procesada (procesada=0) para que el run real las encuentre
                            $this->updateFabricanteStatus(
                                $unidad['transacciones_ids_csv'] ?? '',
                                'SIMULADA',
                                $simBindId,
                                null,
                                'Simulación DRY RUN',
                                false
                            );
                            
                            $this->logDetallado("SIMULACIÓN FAB-$nombreUnidad", "Payload simulado:\n" . json_encode($resFab['payload_debug'] ?? [], JSON_PRETTY_PRINT));
                            // Revertir descuentos para que el run real los aplique (dry run no debe consumirlos)
                            $this->revertirDescuentosLote($loteId, null, $idUnidad, $descuentosFab['detalle'] ?? []);
                            $detalleFab['estado'] = 'SIMULADA (DRY RUN)';           
                            $detalleMouraLog[$nombreUnidad] = 'DRY_RUN_OK';
                            
                        } else {
                            // --- CASO REAL ---
                            $bindId = $resFab['comprobanteId'] ?? 'OK-NO-ID';
                            $coelsaIdFab = $resFab['coelsaId'] ?? null;
                            $this->updateFabricanteStatus(
                                $unidad['transacciones_ids_csv'] ?? '',
                                'COMPLETADA',
                                $bindId,
                                $coelsaIdFab,
                                json_encode($resFab)
                            );
                            $this->updateFinancingInstallmentsStatus(
                                $unidad['cuota_ids_csv'] ?? '',
                                'Cobrada',
                                $fechaSQL
                            );
                            
                            $this->logger->info("    -> OK Fab $nombreUnidad (ID: $bindId)");
                            
                            $detalleFab['estado'] = 'ENVIADA';
                            $detalleMouraLog[$nombreUnidad] = 'OK';
                            $this->logDetallado("ÉXITO REAL FAB-$nombreUnidad", "ID BIND: $bindId\n$jsonLog");
                        }

                    } catch (\App\Exception\DryRunException $e) {
                        // No marcamos como procesada (procesada=0) para que el run real las encuentre
                        $this->updateFabricanteStatus(
                            $unidad['transacciones_ids_csv'] ?? '',
                            'SIMULADA',
                            null,
                            null,
                            'DryRun: ' . $e->getMessage(),
                            false
                        );
                        $this->logDetallado("SIMULACIÓN FAB-$nombreUnidad", $e->getMessage() . "\n" . $jsonLog);
                        // Revertir descuentos para que el run real los aplique (dry run no debe consumirlos)
                        $this->revertirDescuentosLote($loteId, null, $idUnidad, $descuentosFab['detalle'] ?? []);
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
                        // Revertir descuentos para que se re-apliquen en el próximo intento
                        $this->revertirDescuentosLote($loteId, null, $idUnidad, $descuentosFab['detalle'] ?? []);
                    }
                }

                $agrupadorPorUnidad[$nombreUnidad]['moura'] = $detalleFab;
                if (($detalleFab['monto_recupero_financiacion'] ?? 0) > 0) {
                    $agrupadorPorUnidad[$nombreUnidad]['recupero_financiacion'] = [
                        'monto' => $detalleFab['monto_recupero_financiacion'],
                        'estado' => $detalleFab['estado'],
                    ];
                }
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
        } finally {
            // --- Liberar mutex ---
            flock($lockHandle, LOCK_UN);
            fclose($lockHandle);
        }

    }

    private function getPendingTransfersData(string $fechaSQL): array
    {
        // 1. PDV
        $queryPdv = "SELECT 
                SUM(ROUND((((cac.totalneto) * splits.porcentajepdv)/100), 2)) AS monto_pdv,
            ROUND(SUM(IFNULL(cac.seguro_incobrabilidad_moura, 0)), 2) AS seguro_incobrabilidad_moura,
            ROUND(SUM(IFNULL(cac.iva_seguro_incobrabilidad_moura, 0)), 2) AS iva_seguro_incobrabilidad_moura,
                GROUP_CONCAT(t.nrotransaccion) AS transacciones_ids_csv
            FROM transacciones t
            INNER JOIN calculos_ahorro_credmoura cac ON t.nrotransaccion = cac.nrotransaccion
            INNER JOIN splits ON splits.idpdv = t.idpdv
              AND splits.fecha = (
                SELECT MAX(s2.fecha) FROM splits s2
                WHERE s2.idpdv = t.idpdv AND s2.fecha < t.fecha
                AND s2.estatus_aprobacion = 'Aprobado' AND s2.borrado_en IS NULL
              )
            WHERE DATE(t.fechapagobind) = :fecha 
            AND t.transferencia_procesada = 0
        ";
        $dataPdv = $this->dbConnection->executeQuery($queryPdv, ['fecha' => $fechaSQL])->fetchAssociative();

                // 2. FABRICANTE (UN): en adelanto NO se transfiere dinero a UN.
                // Se mantiene únicamente el flujo de recupero_financiacion.
                $unidadesFabricante = [];

        $queryRecuperoFinanciacion = "SELECT 
                u.id as id_unidad,
                u.nombre as nombre_unidad,
                u.cbu as cbu_unidad,
                u.cuit as cuit_unidad,
                GROUP_CONCAT(cf.id) AS cuota_ids_csv,
                ROUND(SUM(cf.monto_neto_cuota), 2) AS monto_recupero_financiacion
            FROM cuotas_financiacion cf
            INNER JOIN financiacion_12cuotas f ON f.id = cf.id_financiacion
            INNER JOIN unidadesdenegocio u ON u.id = cf.id_unidad_financiadora
            WHERE cf.fecha_vencimiento = :fecha
              AND cf.estado IN ('Pendiente', 'Vencida')
              AND f.estado = 'Activo'
            GROUP BY u.id, u.nombre, u.cbu, u.cuit";
        $recuperosFinanciacion = $this->dbConnection
            ->executeQuery($queryRecuperoFinanciacion, ['fecha' => $fechaSQL])
            ->fetchAllAssociative();

        $unidadesFabricanteMap = [];
        foreach ($unidadesFabricante as $unidad) {
            $key = (string)$unidad['id_unidad'];
            $unidad['cuota_ids_csv'] = '';
            $unidad['monto_recupero_financiacion'] = 0.0;
            $unidadesFabricanteMap[$key] = $unidad;
        }

        foreach ($recuperosFinanciacion as $recupero) {
            $key = (string)$recupero['id_unidad'];
            if (!isset($unidadesFabricanteMap[$key])) {
                $unidadesFabricanteMap[$key] = [
                    'id_unidad' => $recupero['id_unidad'],
                    'nombre_unidad' => $recupero['nombre_unidad'],
                    'cbu_unidad' => $recupero['cbu_unidad'],
                    'cuit_unidad' => $recupero['cuit_unidad'],
                    'transacciones_ids_csv' => '',
                    'monto_calculado' => 0.0,
                    'cuota_ids_csv' => '',
                    'monto_recupero_financiacion' => 0.0,
                ];
            }

            $unidadesFabricanteMap[$key]['cuota_ids_csv'] = $recupero['cuota_ids_csv'] ?? '';
            $unidadesFabricanteMap[$key]['monto_recupero_financiacion'] = (float)($recupero['monto_recupero_financiacion'] ?? 0);
            $unidadesFabricanteMap[$key]['monto_calculado'] = round(
                (float)$unidadesFabricanteMap[$key]['monto_calculado']
                + (float)($recupero['monto_recupero_financiacion'] ?? 0),
                2
            );
        }

        $unidadesFabricante = array_values($unidadesFabricanteMap);

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
            'seguro_incobrabilidad_moura_pdv' => (float)($dataPdv['seguro_incobrabilidad_moura'] ?? 0),
            'iva_seguro_incobrabilidad_moura_pdv' => (float)($dataPdv['iva_seguro_incobrabilidad_moura'] ?? 0),
            'monto_fabricante_total' => $montoFabTotal,
            'desglose_fabricante' => $unidadesFabricante,
            'transacciones_ids' => $transaccionIds, 
        ];
    }

    private function getPendingTransfersForPush(string $fechaSQL): array
    {
        $query = "SELECT 
                p.id AS idpdv,
                p.razonsocial,
                p.cbu AS cbuDestino,
                p.cuit AS cuitDestino,
                u.nombre AS nombre_unidad,  
                SUM(ROUND((((cac.totalneto) * splits.porcentajepdv)/100), 2)) AS total_monto,
            ROUND(SUM(IFNULL(cac.seguro_incobrabilidad_moura, 0)), 2) AS seguro_incobrabilidad_moura,
            ROUND(SUM(IFNULL(cac.iva_seguro_incobrabilidad_moura, 0)), 2) AS iva_seguro_incobrabilidad_moura,
                GROUP_CONCAT(t.nrotransaccion) AS transacciones_ids_csv
            FROM transacciones t
            INNER JOIN calculos_ahorro_credmoura cac ON t.nrotransaccion = cac.nrotransaccion
            JOIN puntosdeventa p ON t.idpdv = p.id
            JOIN unidadesdenegocio u ON p.idunidadnegocio = u.id 
            LEFT JOIN liquidacionesdetalle ld ON t.nrotransaccion = ld.nrotransaccion
            INNER JOIN splits ON splits.idpdv = t.idpdv
              AND splits.fecha = (
                SELECT MAX(s2.fecha) FROM splits s2
                WHERE s2.idpdv = t.idpdv AND s2.fecha < t.fecha
                AND s2.estatus_aprobacion = 'Aprobado' AND s2.borrado_en IS NULL
              )
            WHERE DATE(t.fechapagobind) = :fecha 
            AND t.transferencia_procesada = 0
            GROUP BY p.id, p.razonsocial, p.cbu, p.cuit, u.nombre 
        ";
        
        $result = $this->dbConnection->executeQuery($query, ['fecha' => $fechaSQL]);
        
        $transfers = [];
        foreach ($result->fetchAllAssociative() as $row) {
            $transfers[] = [
                'idpdv' => (int) $row['idpdv'],
                'razonsocial' => $row['razonsocial'],
                'nombre_unidad' => $row['nombre_unidad'],
                'cbu' => $row['cbuDestino'],
                'cuit' => $row['cuitDestino'],
                'monto' => (float) $row['total_monto'],
                'seguro_incobrabilidad_moura' => (float)($row['seguro_incobrabilidad_moura'] ?? 0),
                'iva_seguro_incobrabilidad_moura' => (float)($row['iva_seguro_incobrabilidad_moura'] ?? 0),
                'transacciones_ids' => array_map('intval', explode(',', $row['transacciones_ids_csv']))
            ];
        }
        
        return $transfers;
    }

    /**
     * Marca como contabilizado el adelanto de UN sin realizar transferencia de dinero.
     */
    private function markFabricanteAsAccountingAdvance(string $fechaSQL): int
    {
        $sql = "UPDATE transacciones
                SET transferencia_fabricante_procesada = 1,
                    transferencia_fabricante_estado = 'CONTABLE_ADELANTO',
                    fecha_transferencia_fabricante = NOW(),
                                        respuesta_BIND_fabricante = 'Adelanto contable 12 cuotas: no se transfiere dinero a UN'
                WHERE DATE(fechapagobind) = :fecha
                                    AND CAST(canal AS UNSIGNED) = 12
                  AND transferencia_fabricante_procesada = 0";

        return $this->dbConnection->executeStatement($sql, ['fecha' => $fechaSQL]);
    }
    
    private function updateTransactionStatus(array $transaccionIds, string $estado, string $bindId, ?string $coelsaId = null, bool $marcarProcesada = true, ?string $respuestaBind = null): void
    {
        if (empty($transaccionIds)) {
            return;
        }

        $procesadaInt = $marcarProcesada ? 1 : 0; 
        $idsList = implode(', ', $transaccionIds);
        
        $sql = "UPDATE transacciones 
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

    private function updateFabricanteStatus(string $idsCsv, string $estado = 'COMPLETADA', ?string $bindId = null, ?string $coelsaId = null, ?string $respuestaBind = null, bool $marcarProcesada = true): void
    {
        if (empty($idsCsv)) return;
        
        $idsArray = explode(',', $idsCsv);
        $idsList = implode(',', array_map('intval', $idsArray)); 

        if (empty($idsList)) return;

        $procesadaInt = $marcarProcesada ? 1 : 0;

        $sql = "UPDATE transacciones 
            SET 
                transferencia_fabricante_procesada = $procesadaInt,
                transferencia_fabricante_estado = :estado,
                transferencia_fabricante_id_bind = :bind_id,
                coelsa_id_fabricante = :coelsa_id,
                respuesta_BIND_fabricante = :respuesta,
                fecha_transferencia_fabricante = NOW()
            WHERE nrotransaccion IN ($idsList)";
        
        $this->dbConnection->executeStatement($sql, [
            'estado' => $estado,
            'bind_id' => $bindId,
            'coelsa_id' => $coelsaId,
            'respuesta' => $respuestaBind
        ]);
    }

    private function updateFinancingInstallmentsStatus(string $idsCsv, string $estado, string $fechaCobro): void
    {
        if (empty($idsCsv)) {
            return;
        }

        $idsArray = array_filter(array_map('intval', explode(',', $idsCsv)));
        if (empty($idsArray)) {
            return;
        }

        $idsList = implode(',', $idsArray);
        $sql = "UPDATE cuotas_financiacion
                SET estado = :estado,
                    fecha_cobro = :fecha_cobro,
                    actualizado_en = NOW()
                WHERE id IN ($idsList)";

        $this->dbConnection->executeStatement($sql, [
            'estado' => $estado,
            'fecha_cobro' => $fechaCobro,
        ]);

        $this->syncFinancingHeadersByInstallments($idsArray);
    }

    private function syncFinancingHeadersByInstallments(array $installmentIds): void
    {
        if (empty($installmentIds)) {
            return;
        }

        $idsList = implode(',', array_map('intval', $installmentIds));
        $financiaciones = $this->dbConnection->fetchFirstColumn(
            "SELECT DISTINCT id_financiacion FROM cuotas_financiacion WHERE id IN ($idsList)"
        );

        foreach ($financiaciones as $idFinanciacion) {
            $resumen = $this->dbConnection->fetchAssociative(
                "SELECT
                        SUM(CASE WHEN estado = 'Cobrada' THEN 1 ELSE 0 END) AS cobradas,
                        COUNT(*) AS total
                   FROM cuotas_financiacion
                  WHERE id_financiacion = :id",
                ['id' => (int)$idFinanciacion]
            );

            $cobradas = (int)($resumen['cobradas'] ?? 0);
            $total = (int)($resumen['total'] ?? 0);
            $estadoCabecera = ($total > 0 && $cobradas >= $total) ? 'Completado' : 'Activo';

            $this->dbConnection->update('financiacion_12cuotas', [
                'cuotas_cobradas' => $cobradas,
                'estado' => $estadoCabecera,
                'actualizado_en' => (new \DateTime())->format('Y-m-d H:i:s'),
            ], ['id' => (int)$idFinanciacion]);
        }
    }

    private function checkExistingLote(string $fechaSQL): ?array
    {
        $sql = "SELECT id, estado_actual_pdv, estado_actual_moura 
                FROM lotes_liquidacion 
                WHERE fecha_liquidacion = :f 
                ORDER BY id DESC 
                LIMIT 1";
        $result = $this->dbConnection->fetchAssociative($sql, ['f' => $fechaSQL]);
        
        if (!$result) {
            return null;
        }
        
        // Verificamos si está completado (ambos deben estar COMPLETED)
        $isCompleted = ($result['estado_actual_pdv'] === 'COMPLETED' && $result['estado_actual_moura'] === 'COMPLETED');
        
        // Verificamos si está en proceso o con errores parciales (retomable)
        // AUDIT_COMPLETED = dry run ejecutado; el run real lo retoma y lo lleva a COMPLETED
        $isProcessing = (
            $result['estado_actual_pdv'] === 'PROCESSING' || 
            $result['estado_actual_moura'] === 'PROCESSING' ||
            $result['estado_actual_pdv'] === 'PARTIAL_ERROR' ||
            $result['estado_actual_moura'] === 'PARTIAL_ERROR' ||
            $result['estado_actual_pdv'] === 'ERROR' ||
            $result['estado_actual_moura'] === 'ERROR' ||
            $result['estado_actual_pdv'] === 'AUDIT_COMPLETED' ||
            $result['estado_actual_moura'] === 'AUDIT_COMPLETED'
        );
        
        // Solo permitimos crear nuevo lote si está COMPLETADO totalmente
        // Cualquier otro estado (PROCESSING, ERROR, PARTIAL_ERROR) se retoma
        if (!$isProcessing && !$isCompleted) {
            // Estado inesperado, permitimos recrear por seguridad
            return null;
        }
        
        return [
            'id' => (int) $result['id'],
            'completed' => $isCompleted,
            'processing' => $isProcessing,
            'estado_pdv' => $result['estado_actual_pdv'],
            'estado_moura' => $result['estado_actual_moura']
        ];
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

    /**
     * Reconstruye los datos del lote con la información real de transacciones procesadas.
     */
    private function updateLoteConDatosCompletos(int $loteId, string $fechaSQL): void
    {
        // 1. Obtener TODOS los IDs de transacciones de esa fecha
        $sqlIds = "SELECT GROUP_CONCAT(DISTINCT nrotransaccion ORDER BY nrotransaccion) as ids 
                   FROM transacciones 
                   WHERE DATE(fechapagobind) = :fecha";
        $idsResult = $this->dbConnection->fetchAssociative($sqlIds, ['fecha' => $fechaSQL]);
        $idsCsv = $idsResult['ids'] ?? '';
        
        $transaccionesIds = [];
        if (!empty($idsCsv)) {
            $transaccionesIds = array_map('intval', explode(',', $idsCsv));
        }
        
        // 2. Obtener montos reales PDV (procesados)
        $sqlPdv = "SELECT 
                COALESCE(SUM(ROUND((((cac.totalneto) * splits.porcentajepdv)/100), 2)), 0) AS monto_pdv
            FROM transacciones t
            INNER JOIN calculos_ahorro_credmoura cac ON t.nrotransaccion = cac.nrotransaccion
            INNER JOIN splits ON splits.idpdv = t.idpdv
              AND splits.fecha = (
                SELECT MAX(s2.fecha) FROM splits s2
                WHERE s2.idpdv = t.idpdv AND s2.fecha < t.fecha
                AND s2.estatus_aprobacion = 'Aprobado' AND s2.borrado_en IS NULL
              )
            WHERE DATE(t.fechapagobind) = :fecha 
            AND t.transferencia_procesada = 1";
        $dataPdv = $this->dbConnection->fetchAssociative($sqlPdv, ['fecha' => $fechaSQL]);
        $montoPdv = (float)($dataPdv['monto_pdv'] ?? 0);
        
        // 3. Obtener montos y detalle reales Fabricante (procesados) por unidad.
        // Prioriza el campo precomputado en CAC y, si viene en 0, usa fallback legacy
        // para filas históricas o sin recálculo.
        $sqlFab = "SELECT 
                u.nombre as nombre_unidad,
            ROUND(SUM(COALESCE(NULLIF(cac.transferencia_moura_visible, 0),
                    (cac.totalneto * (100 - splits.porcentajepdv) / 100)
                    - IFNULL(cac.comision_moura, 0)
                    - GREATEST(0, IFNULL(cac.beneficiocredmoura, 0) - cac.importecheque * 0.002)
                    - IFNULL(cac.aranceltarjeta, 0) * IFNULL(cac.pct_financiacion_arancel, 0) / 100
                    - IFNULL(ld.ivaaranceltarjeta, 0) * IFNULL(cac.pct_financiacion_arancel, 0) / 100
                )), 2) AS monto_calculado
            FROM transacciones t
            INNER JOIN calculos_ahorro_credmoura cac ON t.nrotransaccion = cac.nrotransaccion
            INNER JOIN puntosdeventa p ON t.idpdv = p.id
            INNER JOIN unidadesdenegocio u ON p.idunidadnegocio = u.id
            INNER JOIN liquidacionesdetalle ld ON t.nrotransaccion = ld.nrotransaccion
            INNER JOIN splits ON splits.idpdv = t.idpdv
              AND splits.fecha = (
                SELECT MAX(s2.fecha) FROM splits s2 
                WHERE s2.idpdv = t.idpdv AND s2.fecha < t.fecha 
                AND s2.estatus_aprobacion = 'Aprobado' AND s2.borrado_en IS NULL
              )
            WHERE DATE(t.fechapagobind) = :fecha
            AND t.transferencia_fabricante_procesada = 1
            AND t.transferencia_fabricante_estado = 'COMPLETADA'
            GROUP BY u.id, u.nombre";
        $fabricantes = $this->dbConnection->executeQuery($sqlFab, ['fecha' => $fechaSQL])->fetchAllAssociative();

        $sqlRecupero = "SELECT 
                u.nombre as nombre_unidad,
                ROUND(SUM(cf.monto_neto_cuota), 2) AS monto_calculado
            FROM cuotas_financiacion cf
            INNER JOIN financiacion_12cuotas f ON f.id = cf.id_financiacion
            INNER JOIN unidadesdenegocio u ON u.id = cf.id_unidad_financiadora
            WHERE cf.fecha_cobro = :fecha
              AND cf.estado = 'Cobrada'
              AND f.estado IN ('Activo', 'Completado')
            GROUP BY u.id, u.nombre";
        $recuperos = $this->dbConnection->executeQuery($sqlRecupero, ['fecha' => $fechaSQL])->fetchAllAssociative();
        
        $montoFab = 0.0;
        $detalleMoura = [];
        foreach ($fabricantes as $fab) {
            $montoFab += (float)$fab['monto_calculado'];
            $detalleMoura[$fab['nombre_unidad']] = 'OK';
        }

        foreach ($recuperos as $recupero) {
            $nombreUnidad = $recupero['nombre_unidad'];
            $montoRecupero = (float)($recupero['monto_calculado'] ?? 0);
            $montoFab += $montoRecupero;
            $detalleMoura[$nombreUnidad] = 'OK';
        }
        
        // 4. Actualizar el lote con datos completos
        $updateData = [
            'transacciones_ids' => json_encode($transaccionesIds),
            'monto_pdv' => $montoPdv,
            'monto_fabricante' => $montoFab,
            'monto_solicitado' => $montoPdv + $montoFab,
        ];
        
        if (!empty($detalleMoura)) {
            $updateData['detalle_moura_json'] = json_encode($detalleMoura);
        }
        
        $this->dbConnection->update('lotes_liquidacion', $updateData, ['id' => $loteId]);
        $this->logger->info("📝 Lote #$loteId actualizado con datos completos: " . count($transaccionesIds) . " transacciones, PDV: $montoPdv, Fab: $montoFab");
    }

    private function getLoteInfo(int $loteId): array
    {
        $sql = "SELECT id, fecha_liquidacion, fecha_creacion, estado_actual_pdv, estado_actual_moura, 
                       monto_solicitado, monto_pdv, monto_fabricante
                FROM lotes_liquidacion 
                WHERE id = :id";
        $result = $this->dbConnection->fetchAssociative($sql, ['id' => $loteId]);
        
        return $result ?: [];
    }

    private function getCompletedTransfersData(string $fechaSQL): array
    {
        $agrupadorPorUnidad = [];

        // 1. Consultar PDVs completados
        $queryPdvCompletos = "
            SELECT 
                p.id AS idpdv,
                p.razonsocial,
                u.nombre AS nombre_unidad,
                p.cbu AS cbu_destino,
                SUM(ROUND((((cac.totalneto) * splits.porcentajepdv)/100), 2)) AS monto,
                ROUND(SUM(IFNULL(cac.seguro_incobrabilidad_moura, 0)), 2) AS seguro_incobrabilidad_moura,
                ROUND(SUM(IFNULL(cac.iva_seguro_incobrabilidad_moura, 0)), 2) AS iva_seguro_incobrabilidad_moura,
                t.transferencia_id_bind,
                t.transferencia_estado,
                t.fecha_transferencia
            FROM transacciones t
            INNER JOIN calculos_ahorro_credmoura cac ON t.nrotransaccion = cac.nrotransaccion
            JOIN puntosdeventa p ON t.idpdv = p.id
            JOIN unidadesdenegocio u ON p.idunidadnegocio = u.id
            INNER JOIN splits ON splits.idpdv = t.idpdv
              AND splits.fecha = (
                SELECT MAX(s2.fecha) FROM splits s2
                WHERE s2.idpdv = t.idpdv AND s2.fecha < t.fecha
                AND s2.estatus_aprobacion = 'Aprobado' AND s2.borrado_en IS NULL
              )
            WHERE DATE(t.fechapagobind) = :fecha 
            AND t.transferencia_procesada = 1
            GROUP BY p.id, p.razonsocial, u.nombre, p.cbu, t.transferencia_id_bind, t.transferencia_estado, t.fecha_transferencia
        ";
        
        $pdvsCompletados = $this->dbConnection->executeQuery($queryPdvCompletos, ['fecha' => $fechaSQL])->fetchAllAssociative();

        foreach ($pdvsCompletados as $pdv) {
            $nombreUnidad = $pdv['nombre_unidad'] ?? 'SIN_UNIDAD';
            
            if (!isset($agrupadorPorUnidad[$nombreUnidad])) {
                $agrupadorPorUnidad[$nombreUnidad] = [
                    'nombre' => $nombreUnidad,
                    'moura' => null,
                    'pdvs' => [],
                    'recupero_financiacion' => null,
                ];
            }

            $monto = (float)$pdv['monto'];
            $estado = $monto > 0 ? 'COMPLETADA' : 'OMITIDA (Monto 0)';
            
            $agrupadorPorUnidad[$nombreUnidad]['pdvs'][] = [
                'razonsocial' => $pdv['razonsocial'],
                'monto' => $monto,
                'seguro_incobrabilidad_moura' => (float)($pdv['seguro_incobrabilidad_moura'] ?? 0),
                'iva_seguro_incobrabilidad_moura' => (float)($pdv['iva_seguro_incobrabilidad_moura'] ?? 0),
                'cbu_destino' => $pdv['cbu_destino'],
                'estado' => $estado,
                'bind_id' => $pdv['transferencia_id_bind'],
                'fecha_transferencia' => $pdv['fecha_transferencia']
            ];
        }

        // 2. Consultar Fabricante completado por unidad.
        // Prioriza el campo precomputado en CAC y conserva fallback legacy para datos históricos.
        $queryFabCompleto = "
            SELECT 
                u.id as id_unidad,
                u.nombre as nombre_unidad,
                u.cbu as cbu_unidad,
                ROUND(SUM(IFNULL(cac.transferencia_moura_visible,
                    (cac.totalneto * (100 - splits.porcentajepdv) / 100)
                    - IFNULL(cac.comision_moura, 0)
                    - GREATEST(0, IFNULL(cac.beneficiocredmoura, 0) - cac.importecheque * 0.002)
                    - IFNULL(cac.aranceltarjeta, 0) * IFNULL(cac.pct_financiacion_arancel, 0) / 100
                    - IFNULL(ld.ivaaranceltarjeta, 0) * IFNULL(cac.pct_financiacion_arancel, 0) / 100
                )), 2) AS monto_calculado,
                ROUND(SUM(IFNULL(cac.seguro_incobrabilidad_moura, 0)), 2) AS seguro_incobrabilidad_moura,
                ROUND(SUM(IFNULL(cac.iva_seguro_incobrabilidad_moura, 0)), 2) AS iva_seguro_incobrabilidad_moura
            FROM transacciones t
            INNER JOIN calculos_ahorro_credmoura cac ON t.nrotransaccion = cac.nrotransaccion
            INNER JOIN puntosdeventa p ON t.idpdv = p.id
            INNER JOIN unidadesdenegocio u ON p.idunidadnegocio = u.id
            INNER JOIN liquidacionesdetalle ld ON t.nrotransaccion = ld.nrotransaccion
            INNER JOIN splits ON splits.idpdv = t.idpdv
              AND splits.fecha = (
                SELECT MAX(s2.fecha) FROM splits s2 
                WHERE s2.idpdv = t.idpdv AND s2.fecha < t.fecha 
                AND s2.estatus_aprobacion = 'Aprobado' AND s2.borrado_en IS NULL
              )
            WHERE DATE(t.fechapagobind) = :fecha
            AND t.transferencia_fabricante_procesada = 1
            AND t.transferencia_fabricante_estado = 'COMPLETADA'
            GROUP BY u.id, u.nombre, u.cbu
        ";

        $fabricantesCompletados = $this->dbConnection->executeQuery($queryFabCompleto, ['fecha' => $fechaSQL])->fetchAllAssociative();

        $queryRecuperoCompleto = "
            SELECT 
                u.id as id_unidad,
                u.nombre as nombre_unidad,
                u.cbu as cbu_unidad,
                ROUND(SUM(cf.monto_neto_cuota), 2) AS monto_recupero_financiacion
            FROM cuotas_financiacion cf
            INNER JOIN financiacion_12cuotas f ON f.id = cf.id_financiacion
            INNER JOIN unidadesdenegocio u ON u.id = cf.id_unidad_financiadora
            WHERE cf.fecha_cobro = :fecha
              AND cf.estado = 'Cobrada'
              AND f.estado IN ('Activo', 'Completado')
            GROUP BY u.id, u.nombre, u.cbu
        ";
        $recuperosCompletados = $this->dbConnection->executeQuery($queryRecuperoCompleto, ['fecha' => $fechaSQL])->fetchAllAssociative();

        $fabricantesMap = [];
        foreach ($fabricantesCompletados as $fab) {
            $key = (string)$fab['id_unidad'];
            $fab['monto_recupero_financiacion'] = 0.0;
            $fabricantesMap[$key] = $fab;
        }

        foreach ($recuperosCompletados as $recupero) {
            $key = (string)$recupero['id_unidad'];
            if (!isset($fabricantesMap[$key])) {
                $fabricantesMap[$key] = [
                    'id_unidad' => $recupero['id_unidad'],
                    'nombre_unidad' => $recupero['nombre_unidad'],
                    'cbu_unidad' => $recupero['cbu_unidad'],
                    'monto_calculado' => 0.0,
                    'monto_recupero_financiacion' => 0.0,
                ];
            }

            $fabricantesMap[$key]['monto_recupero_financiacion'] = (float)($recupero['monto_recupero_financiacion'] ?? 0);
            $fabricantesMap[$key]['monto_calculado'] = round(
                (float)$fabricantesMap[$key]['monto_calculado']
                + (float)($recupero['monto_recupero_financiacion'] ?? 0),
                2
            );
        }

        $fabricantesCompletados = array_values($fabricantesMap);

        foreach ($fabricantesCompletados as $fab) {
            $nombreUnidad = $fab['nombre_unidad'];
            
            if (!isset($agrupadorPorUnidad[$nombreUnidad])) {
                $agrupadorPorUnidad[$nombreUnidad] = [
                    'nombre' => $nombreUnidad,
                    'moura' => null,
                    'pdvs' => [],
                    'recupero_financiacion' => null,
                ];
            }

            $monto = (float)$fab['monto_calculado'];
            
            $agrupadorPorUnidad[$nombreUnidad]['moura'] = [
                'monto' => $monto,
                'seguro_incobrabilidad_moura' => (float)($fab['seguro_incobrabilidad_moura'] ?? 0),
                'iva_seguro_incobrabilidad_moura' => (float)($fab['iva_seguro_incobrabilidad_moura'] ?? 0),
                'cbu_destino' => $fab['cbu_unidad'],
                'estado' => $monto > 0 ? 'COMPLETADA' : 'OMITIDA (Sin saldo)'
            ];

            if ((float)($fab['monto_recupero_financiacion'] ?? 0) > 0) {
                $agrupadorPorUnidad[$nombreUnidad]['recupero_financiacion'] = [
                    'monto' => (float)$fab['monto_recupero_financiacion'],
                    'estado' => $monto > 0 ? 'COMPLETADA' : 'OMITIDA (Sin saldo)'
                ];
            }
        }

        return [
            'unidades' => array_values($agrupadorPorUnidad)
        ];
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

    /**
     * Aplica descuentos pendientes a un PDV. 
     * Resta del monto a transferir y marca cada descuento como "Descontado".
     */
    private function aplicarDescuentosPdv(int $idPdv, float $monto, int $loteId): array
    {
        $descuentos = $this->dbConnection->fetchAllAssociative(
            "SELECT id, monto, COALESCE(monto_descontado, 0) AS monto_descontado FROM descuentos_transferencias WHERE idpdv = :idpdv AND estado = 'Pendiente' AND borrado_en IS NULL ORDER BY creado_en ASC",
            ['idpdv' => $idPdv]
        );

        return $this->procesarDescuentos($descuentos, $monto, $loteId);
    }

    /**
     * Aplica descuentos pendientes a una Unidad de Negocio.
     */
    private function aplicarDescuentosUnidad(int $idUnidad, float $monto, int $loteId): array
    {
        $descuentos = $this->dbConnection->fetchAllAssociative(
            "SELECT id, monto, COALESCE(monto_descontado, 0) AS monto_descontado FROM descuentos_transferencias WHERE id_unidad_negocio = :idUnidad AND estado = 'Pendiente' AND borrado_en IS NULL ORDER BY creado_en ASC",
            ['idUnidad' => $idUnidad]
        );

        return $this->procesarDescuentos($descuentos, $monto, $loteId);
    }

    /**
     * Procesa una lista de descuentos pendientes contra un monto a transferir.
     * Descuenta lo máximo posible (sin dejar el monto en negativo).
     */
    private function procesarDescuentos(array $descuentos, float $montoOriginal, int $loteId): array
    {
        $montoFinal = $montoOriginal;
        $totalDescontado = 0.0;
        $detalle = [];
        $now = (new \DateTime())->format('Y-m-d H:i:s');

        foreach ($descuentos as $desc) {
            if ($montoFinal <= 0) break;

            $montoOriginalDesc = (float)$desc['monto'];                  // INMUTABLE: importe original del descuento
            $yaDescontadoPrev  = (float)($desc['monto_descontado'] ?? 0); // acumulado de aplicaciones previas
            $remanente = round($montoOriginalDesc - $yaDescontadoPrev, 2);
            if ($remanente <= 0) continue;

            $aplicar = min($remanente, $montoFinal);
            $aplicar = round($aplicar, 2);
            if ($aplicar <= 0) continue;

            $montoFinal       = round($montoFinal - $aplicar, 2);
            $totalDescontado  = round($totalDescontado + $aplicar, 2);
            $nuevoAcumulado   = round($yaDescontadoPrev + $aplicar, 2);
            $cubreTotal       = $nuevoAcumulado >= $montoOriginalDesc - 0.005;

            // IMPORTANTE: NO mutamos `monto` (queda como valor original).
            // Acumulamos `monto_descontado` y marcamos 'Descontado' sólo cuando se cubre el total.
            $update = [
                'monto_descontado'    => $nuevoAcumulado,
                'lote_liquidacion_id' => $loteId,
                'fecha_descuento'     => $now,
                'actualizado_en'      => $now,
            ];
            if ($cubreTotal) {
                $update['estado'] = 'Descontado';
            }
            $this->dbConnection->update('descuentos_transferencias', $update, ['id' => $desc['id']]);

            $detalle[] = [
                'descuento_id'      => (int)$desc['id'],
                'monto_descuento'   => $montoOriginalDesc,
                'ya_descontado_prev'=> $yaDescontadoPrev,
                'monto_aplicado'    => $aplicar,         // aplicado EN ESTE LOTE (necesario para revertir)
                'acumulado_total'   => $nuevoAcumulado,
                'remanente'         => $cubreTotal ? 0.0 : round($montoOriginalDesc - $nuevoAcumulado, 2),
                'cubre_total'       => $cubreTotal,
            ];
        }

        return [
            'monto_final'     => round($montoFinal, 2),
            'total_descontado'=> $totalDescontado,
            'detalle'         => $detalle,
        ];
    }

    /**
     * Revierte los descuentos aplicados en este lote (parciales o totales).
     * Decrementa `monto_descontado` exactamente por el `monto_aplicado` registrado en el detalle
     * y restaura el estado a 'Pendiente'. Se usa cuando la transferencia falla.
     *
     * @param array $detalle Array devuelto por procesarDescuentos()['detalle'].
     */
    private function revertirDescuentosLote(int $loteId, ?int $idPdv, ?int $idUnidad, array $detalle = []): void
    {
        if (empty($detalle)) {
            // Fallback: sin detalle no podemos revertir parciales con precisión.
            // Sólo revertimos los marcados 'Descontado' restando el monto original.
            $where = ['lote_liquidacion_id' => $loteId, 'estado' => 'Descontado'];
            if ($idPdv !== null)    $where['idpdv'] = $idPdv;
            if ($idUnidad !== null) $where['id_unidad_negocio'] = $idUnidad;
            $rows = $this->dbConnection->fetchAllAssociative(
                "SELECT id, monto, COALESCE(monto_descontado,0) AS monto_descontado
                 FROM descuentos_transferencias
                 WHERE " . implode(' AND ', array_map(fn($k)=>"$k = :$k", array_keys($where))),
                $where
            );
            foreach ($rows as $r) {
                $nuevo = max(0.0, round((float)$r['monto_descontado'] - (float)$r['monto'], 2));
                $this->dbConnection->update('descuentos_transferencias', [
                    'estado'              => 'Pendiente',
                    'monto_descontado'    => $nuevo > 0 ? $nuevo : null,
                    'lote_liquidacion_id' => null,
                    'fecha_descuento'     => null,
                    'actualizado_en'      => (new \DateTime())->format('Y-m-d H:i:s'),
                ], ['id' => $r['id']]);
            }
            return;
        }

        $now = (new \DateTime())->format('Y-m-d H:i:s');
        foreach ($detalle as $d) {
            $row = $this->dbConnection->fetchAssociative(
                "SELECT COALESCE(monto_descontado,0) AS monto_descontado FROM descuentos_transferencias WHERE id = :id",
                ['id' => $d['descuento_id']]
            );
            if (!$row) continue;
            $nuevo = max(0.0, round((float)$row['monto_descontado'] - (float)$d['monto_aplicado'], 2));
            $this->dbConnection->update('descuentos_transferencias', [
                'estado'              => 'Pendiente',
                'monto_descontado'    => $nuevo > 0 ? $nuevo : null,
                'lote_liquidacion_id' => null,
                'fecha_descuento'     => null,
                'actualizado_en'      => $now,
            ], ['id' => $d['descuento_id']]);
        }
    }

    // Código comentado de DEBIN omitido intencionalmente
}