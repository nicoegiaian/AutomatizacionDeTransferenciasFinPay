```mermaid
graph TD
    A[Inicio: app:orquestador_trx] --> B{¿Fecha Parámetro?}
    B -- No --> C[Usar Fecha de Ayer]
    B -- Si --> D[Usar Fecha Parámetro]
    
    C & D --> E[Calcular Fechas Proceso]
    
    E --> F[Ejecutar: procesador_API_Menta.php]
    F --> G{¿Éxito?}
    G -- No --> H[Notifier: Email Alerta Crítica]
    H --> Z[Fin con Error]
    
    G -- Si --> I[Ejecutar: archivosdiarios.php]
    I --> J{¿Éxito?}
    J -- No --> H
    
    J -- Si --> K{¿Es Día Hábil?}
    K -- No (Sáb/Dom/Feriado) --> L[Log: AVISO, salteando liquidación]
    L --> M[Fin Exitoso]
    
    K -- Si --> N[Ejecutar: liquidaciondiaria.php]
    N --> O{¿Éxito?}
    O -- No --> H
    O -- Si --> P[Fin Exitoso de Orquestación]