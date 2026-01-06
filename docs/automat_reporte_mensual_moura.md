```mermaid
flowchart TD

    A[Cron / Ejecución Manual] -->|Parámetros: Mes, Año| B[Command: GenerateMonthlyReports]
    
    B --> C{Consultar Datos}
    C -->|SQL Query| D[MonthlySettlementService]
    D -->|Array de Datos Agrupados| B
    
    B --> E{Generar PDFs Individuales}
    E -->|Render HTML Twig| F[PdfGenerator]
    
    F -->|Guardar Archivo| G[Sistema de Archivos]
    
    G -->|Ruta: /public/portal/resumenes/2025/11/| H[Archivo: RAZON SOCIAL Nov-2025.pdf]
    
    B --> I{Generar Anexo Moura}
    I -->|Concatenar todos los PDFs| J[Archivo: MOURA_ANEXO.pdf]
    
    style H fill:#f9f,stroke:#333,stroke-width:2px
    style G fill:#ccf,stroke:#333,stroke-width:2px