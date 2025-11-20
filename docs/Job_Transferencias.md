```mermaid
flowchart TD
    Start(Inicio Job Monitor) --> GetPend[Obtener DEBINs Pendientes]
    GetPend -->|TransferManager::getPendingDebins| DB[(Base de Datos)]
    DB --> List{¿Hay DEBINs?}
    
    List -- No --> End(Fin del Proceso)
    List -- Si --> Loop[Iterar por cada DEBIN]
    
    Loop --> CallBind[Consultar Estado en BIND]
    CallBind -->|BindService::getDebinStatusById| API_BIND((API BIND))
    
    API_BIND --> CheckState{Evaluar Estado}
    
    CheckState -- PENDING / IN_PROGRESS --> LogWait[Log: Esperando...]
    LogWait --> Loop
    
    CheckState -- REJECTED / UNKNOWN --> MarkFail[Marcar como FALLIDO en BD]
    MarkFail --> Alert[Notifier::sendFailureEmail]
    Alert --> Loop
    
    CheckState -- COMPLETED --> PUSH_PROCESS[Iniciar Proceso PUSH]
    
    subgraph Etapa de Dispersión
    PUSH_PROCESS --> GetPDVs[Obtener Comercios y Montos]
    GetPDVs --> LoopPUSH[Iterar por Comercio]
    LoopPUSH --> Transfer[Transferir a CBU Comercio]
    Transfer -->|BindService::transferToThirdParty| API_BIND
    Transfer --> UpdateTrx[Actualizar Transacciones en BD]
    UpdateTrx -->|Marca procesada=1| DB
    LoopPUSH --> EndPUSH
    end
    
    EndPUSH --> MarkDebinOK[Marcar DEBIN como FINALIZADO]
    MarkDebinOK --> Loop

