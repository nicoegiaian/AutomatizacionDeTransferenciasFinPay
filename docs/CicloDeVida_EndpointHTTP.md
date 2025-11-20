```mermaid
sequenceDiagram
    autonumber
    participant Client as Cliente API
    participant Firewall as JwtTokenSubscriber
    participant Controller as TransferController
    participant Manager as TransferManager

    Note over Client, Firewall: POST /transfers/execute

    Client->>Firewall: Header: Authorization Bearer {TOKEN}
    
    alt Token Inválido / Ausente
        Firewall-->>Client: 401 Unauthorized / 403 Forbidden
    else Token Válido
        Firewall->>Controller: Pasa Petición
        
        alt Body JSON Inválido o sin Fecha
            Controller-->>Client: 400 Bad Request
            Note right of Controller: {"status": "error", "message": "..."}
        else Body Correcto
            Controller->>Manager: executeDebinPull(fecha)
            
            alt Bloqueo: Ya existe DEBIN
                Manager-->>Controller: Error Array
                Controller-->>Client: 500 Internal Server Error
            else Éxito: DEBIN Iniciado
                Manager-->>Controller: Success Array
                Controller-->>Client: 200 OK
                Note right of Controller: {"status": "success", "debin_id": "..."}
            end
        end
    end