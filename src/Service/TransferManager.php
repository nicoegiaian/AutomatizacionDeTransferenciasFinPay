<?php
// src/Service/TransferManager.php

namespace App\Service;

use Doctrine\DBAL\Connection; // Usaremos DBAL para consultas directas
use Doctrine\DBAL\Types\Types;
use App\Service\Notifier;
use App\Service\BindServiceInterface; 

class TransferManager
{
    private BindServiceInterface $bindService;
    private Notifier $notifier;
    private Connection $dbConnection; // Inyectaremos la conexión a la BD

    public function __construct(BindServiceInterface $bindService, Notifier $notifier, Connection $defaultConnection)
    {
        $this->bindService = $bindService;
        $this->notifier = $notifier;
        $this->dbConnection = $defaultConnection; 
    }

    private const ESTADOS_BIND = [
        1 => 'PENDING',   // A procesar
        2 => 'COMPLETED', // Aprobada
        3 => 'RECHAZADO', // Rechazada
        4 => 'PENDING',   // A consultar (lo tratamos como pendiente de monitoreo)
        5 => 'PENDING',   // Auditar (lo tratamos como pendiente de monitoreo)
    ];

    public function executeDebinPull(string $fechaLiquidacionDDMMAA): array
    {
        // 1. Normalizar la fecha a formato SQL (YYYY-MM-DD)
        $fechaSQL = \DateTime::createFromFormat('dmy', $fechaLiquidacionDDMMAA)->format('Y-m-d');

        // Verificamos si ya existe un DEBIN para esta fecha que NO esté rechazado.
        $debinExistente = $this->checkExistingDebin($fechaSQL);
        
        if ($debinExistente) {
            // Si ya existe y no fue rechazado, detenemos la ejecución para evitar duplicar deuda.
            return [
                'status' => 'error',
                'message' => "Ya existe un DEBIN activo (ID: {$debinExistente['id_comprobante_bind']}) para la fecha {$fechaLiquidacionDDMMAA}. Estado: {$debinExistente['estado_actual']}."
            ];
        }
        // --------------------------------

        // 2. Obtener la data pendiente para esta fecha
        $pendingData = $this->getPendingTransfersData($fechaSQL);
        $totalMontoPull = $pendingData['total_monto'];
        
        if ($totalMontoPull <= 0) {
            return ['status' => 'info', 'message' => "No hay montos pendientes para liquidar para {$fechaLiquidacionDDMMAA}."];
        }

        $referencia = $fechaLiquidacionDDMMAA . '-' . time(); 
        
        try {
            // 3. Iniciar DEBIN PULL con BindService
            $debinResponse = $this->bindService->initiateDebinPull($totalMontoPull, $referencia);
            
            // Mapeo del estado numérico (1-5) a String
            // Asumimos que 'estado' viene en el nivel superior o dentro de un objeto, ajusta según tu respuesta real JSON.
            // Si la API devuelve un objeto (ej: ['estado' => ['id' => 1]]), ajusta esta línea:
            $idEstadoBind = $debinResponse['estadoId'] ?? 0;
            $estadoInterno = self::ESTADOS_BIND[$idEstadoBind] ?? 'UNKNOWN';
            $idComprobante = $debinResponse['comprobanteId'] ?? $debinResponse['operacionId'] ?? 'ERR-ID';

            // 4. Persistir en la BD
            $this->dbConnection->insert('debin_seguimiento', [
                'fecha_liquidacion' => $fechaSQL,
                'id_comprobante_bind' => $idComprobante,
                'monto_solicitado' => $totalMontoPull,     // Total (PDV + Fab)
                'monto_pdv' => $pendingData['monto_pdv'],  // <--- Columna Nueva
                'monto_fabricante' => $pendingData['monto_fabricante'], // <--- Columna Nueva
                'estado_actual' => $estadoInterno, 
                'procesado_push' => false,
                'transacciones_ids' => json_encode($pendingData['transacciones_ids']), 
                'fecha_creacion' => (new \DateTime())->format('Y-m-d H:i:s'),
            ]);

            // 5. Manejo de Rechazo Inmediato
            if ($estadoInterno === 'RECHAZADO') {
                // A. Notificar por Email
                $asunto = "ALERTA BIND: DEBIN Rechazado Inmediatamente - {$fechaLiquidacionDDMMAA}";
                $cuerpo = "El DEBIN solicitado por $ {$totalMontoPull} ha sido RECHAZADO por BIND.\n\n" .
                          "ID Comprobante: " . ($debinResponse['idComprobante'] ?? 'N/A') . "\n" .
                          "Motivo/Detalle: " . json_encode($debinResponse) . "\n\n" . 
                          "Acción requerida: Revisar motivo y reintentar ejecución manual.";
                
                $this->notifier->sendFailureEmail($asunto, $cuerpo); //

                return [
                    'status' => 'error',
                    'message' => "DEBIN iniciado pero RECHAZADO por el banco. Se envió notificación.",
                    'debin_id' => $debinResponse['idComprobante'] ?? null,
                    'estado' => 'RECHAZADO'
                ];
            }
            // -----------------------------------------

            return [
                'status' => 'debin_initiated', 
                'id_debin' => $debinResponse['idComprobante'], 
                'message' => "DEBIN PULL iniciado correctamente (Estado: {$estadoInterno})."
            ];

        } catch (\Exception $e) {
            // Manejo de error de conexión o excepción crítica
            $this->notifier->sendFailureEmail("ERROR CRÍTICO ORQUESTADOR", "Fallo al intentar crear DEBIN: " . $e->getMessage());
            throw $e; // Re-lanzamos para que el Controller lo maneje o el comando falle
        }
    }

    /**
     * Verifica si existe un DEBIN previo para la fecha que NO esté rechazado.
     * Retorna los datos del DEBIN si existe y bloquea, o null si se puede proceder.
     */
    private function checkExistingDebin(string $fechaSQL): ?array
    {
        $query = "SELECT * FROM debin_seguimiento WHERE fecha_liquidacion = :fecha ORDER BY id DESC LIMIT 1";
        $result = $this->dbConnection->executeQuery(
            $query, 
            ['fecha' => $fechaSQL]
        )->fetchAssociative();

        if ($result) {
            // Si el estado es RECHAZADO, permitimos crear uno nuevo (retornamos null)
            if ($result['estado_actual'] === 'RECHAZADO') {
                return null;
            }
            // Si es PENDING, COMPLETED, o UNKNOWN, devolvemos el registro para bloquear.
            return $result;
        }

        return null;
    }
    /**
     * Obtiene el listado de transferencias a PDVs agrupadas por CBU/Cuenta.
     * Llamado solo DESPUÉS de confirmar que el PULL DEBIN fue COMPLETED.
     * @param string $fechaSQL Formato YYYY-MM-DD
     * @return array Array de arrays, cada uno con 'idpdv', 'cbu', 'monto' y 'transacciones_ids'.
     */
    private function getPendingTransfersForPush(string $fechaSQL): array
    {
        $query = "
            SELECT 
                p.id AS idpdv, 
                p.cbu AS cbuDestino, 
                 SUM(ROUND((((t.importeprimervenc)*(splits.porcentajepdv))/100), 2)) AS total_monto, 
                GROUP_CONCAT(t.nrotransaccion) AS transacciones_ids_csv
            FROM transacciones t
            JOIN puntosdeventa p ON t.idpdv = p.id
            INNER JOIN splits ON t.idpdv = splits.idpdv
            -- Filtramos por fecha y por transacciones que fueron parte del PULL exitoso (no procesadas)
            WHERE DATE(t.fechapagobind) = :fecha AND t.transferencia_procesada = 0 AND t.completada = 0 
            GROUP BY p.id, p.cbu
            HAVING p.cbu IS NOT NULL AND p.cbu != ''
        ";
        
        $stmt = $this->dbConnection->prepare($query);
        $result = $stmt->executeQuery(['fecha' => $fechaSQL]);

        $transfers = [];
        foreach ($result->fetchAllAssociative() as $row) {
            $transfers[] = [
                'idpdv' => (int) $row['idpdv'],
                'cbu' => $row['cbuDestino'],
                'monto' => (float) $row['total_monto'],
                'transacciones_ids' => array_map('intval', explode(',', $row['transacciones_ids_csv']))
            ];
        }
        
        return $transfers;
    }
    
    /**
     * Obtiene montos y transacciones pendientes para una fecha dada.
     */
    private function getPendingTransfersData(string $fechaSQL): array
    {
        // 1. Consulta el monto total y los IDs de las transacciones tanto para los PDV como para Moura
        $query = "
            SELECT 
                -- 1. Monto para Puntos de Venta
                SUM(ROUND((((t.importeprimervenc)*(splits.porcentajepdv))/100), 2)) AS monto_pdv,
                
                -- 2. Monto para Fabricante: (Split% Fabricante) - (Subsidio + IVA)
                -- Nota: Asumimos que el porcentaje del fabricante es el restante (100 - porcentajepdv)
                SUM(
                    ROUND((((t.importeprimervenc)*(100 - splits.porcentajepdv))/100), 2) - 
                    (COALESCE(ld.subsidiomoura, 0) + COALESCE(ld.ivasubsidiomoura, 0))
                ) AS monto_fabricante,

                GROUP_CONCAT(t.nrotransaccion) AS transacciones_ids_csv

            FROM transacciones t
            INNER JOIN splits ON t.idpdv = splits.idpdv
            -- Join con liquidacionesdetalle para obtener subsidios
            LEFT JOIN liquidacionesdetalle ld ON t.nrotransaccion = ld.nrotransaccion
            
            WHERE splits.fecha = 
                (
                SELECT MAX(s2.fecha)
                FROM splits s2
                WHERE s2.idpdv = t.idpdv 
                AND s2.fecha <= DATE(t.fechapagobind)
                )
            AND DATE(t.fechapagobind) = :fecha 
            AND t.transferencia_procesada = 0  
        ";
        
        $result = $this->dbConnection->executeQuery($query, ['fecha' => $fechaSQL]);
        $data = $result->fetchAssociative();
        /* el siguiente bloque comentado es porque en algun momento me dio error la invocacion como la de arriba y tuve que aclarar el tipo string
        $stmt = $this->dbConnection->prepare($query);
        $result = $this->dbConnection->executeQuery($query, 
        [ 'fecha' => $fechaSQL ],
        [ 'fecha' => Types::STRING ] // O usa 'string' si Types no está importado
         );
        $data = $result->fetchAssociative();
        */

        // Validar si vino vacío (nulls)
        if (empty($data['transacciones_ids_csv'])) {
            return [
                'total_monto' => 0.0,
                'monto_pdv' => 0.0,
                'monto_fabricante' => 0.0,
                'transacciones_ids' => []
            ];
        }

        $montoPdv = (float) ($data['monto_pdv'] ?? 0);
        $montoFab = (float) ($data['monto_fabricante'] ?? 0);
        
        // El DEBIN debe ser la suma de ambos
        $totalDebin = $montoPdv + $montoFab;

        $transaccionIds = array_map('intval', explode(',', $data['transacciones_ids_csv']));

        return [
            'total_monto' => $totalDebin,
            'monto_pdv' => $montoPdv,
            'monto_fabricante' => $montoFab,
            'transacciones_ids' => $transaccionIds, 
        ];
    }

### 2. `TransferManager` para el Monitoreo (Llamado desde el Job CLI)

### Ahora preparamos el método que el *Job* CLI usará para obtener qué DEBINs debe monitorear.

    /**
     * Llamado desde el Job CLI. Obtiene DEBINs no completados que necesitan chequeo.
     */
    public function getPendingDebins(): array
    {
        // Excluir DEBINs que ya fueron exitosos/fallidos o procesados
        $query = "
            SELECT * FROM debin_seguimiento 
            WHERE procesado_push = 0 
            AND estado_actual NOT IN ('COMPLETED', 'UNKNOWN', 'UNKNOWN_FOREVER')
        ";
        
        // Usar DBAL para obtener los resultados
        $stmt = $this->dbConnection->prepare($query);
        $result = $stmt->executeQuery();

        return $result->fetchAllAssociative();
    }
    
    // Y los métodos para marcar estados...
    public function markDebinAsFailed(array $debinData, string $estado): void
    {
        $this->dbConnection->update('debin_seguimiento', 
            ['estado_actual' => $estado, 'procesado_push' => true], // Marcar como procesado (fallido)
            ['id' => $debinData['id']]
        );
    }
    
    public function markDebinAsCompleted(array $debinData): void
    {
        $this->dbConnection->update('debin_seguimiento', 
            ['estado_actual' => 'COMPLETED', 'fecha_aprobacion' => (new \DateTime())->format('Y-m-d H:i:s')],
            ['id' => $debinData['id']]
        );
    }
    
    /**
     * Ejecuta el proceso de transferencia PUSH a todos los PDVs pendientes.
     */
    public function executeTransferProcess(): array
    {
        $pendingTransfers = $this->getPendingTransfersData();
        $results = [];

        foreach ($pendingTransfers as $pdvTransfer) {
            try {
                $bindResponse = $this->bindService->transferToThirdParty($pdvTransfer['cbu'], $pdvTransfer['monto_a_liquidar']);
                $estado = $bindResponse['estado']; // Asumimos que BIND devuelve un campo 'estado'
                
                // 1. Persistencia (Actualizar la tabla transacciones)
                $this->updateTransactionStatus($pdvTransfer['transacciones_ids'], $estado, $bindResponse['comprobanteId'], $bindResponse['coelsaId']);
                
                // 2. Notificación (Si el estado no es exitoso)
                if ($estado !== 'COMPLETADA') { // Ajustar según los estados reales de BIND
                    $this->notifier->sendFailureEmail(
                        "ALERTA BIND: Transferencia a PDV {$pdvTransfer['idpdv']} en estado: {$estado}",
                        "Detalles: La transferencia con ID {$bindResponse['comprobanteId']} requiere revisión. Estado devuelto por BIND: {$estado}."
                    );
                }

                $results[] = ['idpdv' => $pdvTransfer['idpdv'], 'status' => $estado];

            } catch (\Exception $e) {
                // Fallo de conexión o error no controlado de Guzzle
                $this->notifier->sendFailureEmail("FALLO CRÍTICO DE CONEXIÓN BIND", "No se pudo comunicar con la API. Error: " . $e->getMessage());
                $results[] = ['idpdv' => $pdvTransfer['idpdv'], 'status' => 'ERROR_CONEXION'];
            }
        }
        
        return $results;
    }
    
    /**
     * Actualiza las transacciones con los datos de la transferencia BIND.
     * Se llama después de cada transferencia PUSH individual a un PDV.
     */
    private function updateTransactionStatus(array $transaccionIds, string $estado, string $bindId, ?string $coelsaId = null): void
    {
        if (empty($transaccionIds)) {
            return;
        }

        // Convertir el array de IDs a una lista separada por comas para la clausula IN
        $idsList = implode(', ', $transaccionIds);
        
        // El estado 'COMPLETADA' (o el que uses para éxito) marca la transacción como finalizada
        $procesada = ($estado === 'COMPLETADA') ? 1 : 0; 
        
        $sql = "
            UPDATE transacciones 
            SET 
                transferencia_procesada = :procesada, 
                transferencia_estado = :estado, 
                transferencia_id_bind = :bind_id, 
                coelsa_id = :coelsa_id, 
                fecha_transferencia = NOW()
            WHERE nrotransaccion IN ({$idsList})
        ";

        $this->dbConnection->executeStatement($sql, [
            'procesada' => $procesada,
            'estado' => $estado,
            'bind_id' => $bindId,
            'coelsa_id' => $coelsaId,
        ]);
    }
}