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

   /**
     * Llamado desde el Controller (el botón).
     * Determina el monto, inicia el DEBIN PULL con BIND y registra el seguimiento en BD.
     * @param string $fechaLiquidacionDDMMAA La fecha recibida del frontend.
     */
    public function executeDebinPull(string $fechaLiquidacionDDMMAA): array
    {
        // 1. Normalizar la fecha a formato SQL (YYYY-MM-DD)
        $fechaSQL = \DateTime::createFromFormat('dmy', $fechaLiquidacionDDMMAA)->format('Y-m-d');
        
        // 2. Obtener la data pendiente para esta fecha
        $pendingData = $this->getPendingTransfersData($fechaSQL);
        $totalMontoPull = $pendingData['total_monto'];
        
        if ($totalMontoPull <= 0) {
            return ['status' => 'info', 'message' => "No hay montos pendientes para liquidar para {$fechaLiquidacionDDMMAA}."];
        }

        // Generar referencia única
        $referencia = $fechaLiquidacionDDMMAA . '-' . time(); 
        
        // 3. Iniciar DEBIN PULL con BindService
        $debinResponse = $this->bindService->initiateDebinPull($totalMontoPull, $referencia);
        
        // 4. Persistir en la BD (Tabla debin_seguimiento)
        $this->dbConnection->insert('debin_seguimiento', [
            'fecha_liquidacion' => $fechaSQL, // Clave para el Job CLI
            'id_comprobante_bind' => $debinResponse['idComprobante'],
            'monto_solicitado' => $totalMontoPull,
            'estado_actual' => $debinResponse['estado'], // Ej: 'APROBADO' / 'EN_PROCESO'
            'procesado_push' => false,
            'transacciones_ids' => json_encode($pendingData['transacciones_ids']),
            'fecha_creacion' => (new \DateTime())->format('Y-m-d H:i:s'),
        ]);

        return [
            'status' => 'debin_initiated', 
            'id_debin' => $debinResponse['idComprobante'], 
            'message' => "DEBIN PULL iniciado. Será monitoreado."
        ];
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
        // 1. Consulta el monto total y los IDs de las transacciones
        $query = "
            SELECT 
                SUM(ROUND((((t.importeprimervenc)*(splits.porcentajepdv))/100), 2)) AS total_monto, 
                GROUP_CONCAT(t.nrotransaccion) AS transacciones_ids_csv
            FROM transacciones t
            INNER JOIN splits ON t.idpdv = splits.idpdv
            WHERE splits.fecha = 
                (
                SELECT MAX(s2.fecha)
                FROM splits s2
                WHERE s2.idpdv = t.idpdv 
                AND s2.fecha <= DATE(t.fechapagobind) -- Vigente hasta la fecha de pago de la transacción
                )
            AND DATE(t.fechapagobind) = :fecha 
            AND t.transferencia_procesada  = 0  
        ";
        
        $stmt = $this->dbConnection->prepare($query);
        $result = $this->dbConnection->executeQuery($query, 
        [ 'fecha' => $fechaSQL ],
        [ 'fecha' => Types::STRING ] // O usa 'string' si Types no está importado
         );
        $data = $result->fetchAssociative();

        if (empty($data['total_monto'])) {
            return ['total_monto' => 0.0, 'transacciones_ids' => []];
        }

        // 2. Formateamos el resultado
        $transaccionIds = explode(',', $data['transacciones_ids_csv']);
        
        return [
            'total_monto' => (float) $data['total_monto'],
            // Convertimos la lista de IDs de string CSV a un array de enteros
            'transacciones_ids' => array_map('intval', $transaccionIds), 
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