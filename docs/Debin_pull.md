```mermaid
sequenceDiagram
    participant Client as Cliente (Postman/Front)
    participant Ctrl as TransferController
    participant Mgr as TransferManager
    participant DB as Base de Datos
    participant Bind as BindService (API)
    participant Email as Notifier

    Client->>Ctrl: POST /transfers/execute {fecha_liquidacion}
    Ctrl->>Mgr: executeDebinPull(fecha)
    
    Note over Mgr: 1. Validación Preventiva
    Mgr->>DB: checkExistingDebin(fecha)
    DB-->>Mgr: Resultado (null o array)

    alt DEBIN existe y NO es RECHAZADO
        Mgr-->>Ctrl: Error: "Ya existe DEBIN activo"
        Ctrl-->>Client: 500 Internal Server Error (con mensaje)
    else DEBIN no existe o es RECHAZADO (Reintento)
        Mgr->>DB: getPendingTransfersData(fecha)
        DB-->>Mgr: Monto Total + IDs Transacciones

        alt Monto <= 0
            Mgr-->>Ctrl: Info: "No hay deuda pendiente"
            Ctrl-->>Client: 200 OK (Info)
        else Monto > 0
            Mgr->>Bind: initiateDebinPull(monto, ref)
            Bind-->>Mgr: Respuesta {id, estado}
            
            Mgr->>DB: INSERT debin_seguimiento (Estado: PENDING/REJECTED)
            
            alt Estado Inmediato == RECHAZADO
                Mgr->>Email: sendFailureEmail()
                Mgr-->>Ctrl: Error: "DEBIN Rechazado por Banco"
                Ctrl-->>Client: 500 Error
            else Estado == PENDING / APROBADO
                Mgr-->>Ctrl: Success: "DEBIN Iniciado"
                Ctrl-->>Client: 200 OK (JSON con debin_id)
            end
        end
    end