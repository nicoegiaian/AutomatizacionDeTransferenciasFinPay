```mermaid
classDiagram
    direction LR
    class Request_FinPay {
        POST /transfers/execute
        +string fecha_liquidacion "DDMMAA"
    }

    class Response_FinPay {
        200 OK
        +string status "success|error|info"
        +string message
        +string debin_id "Opcional"
    }

    class Payload_BIND_Request {
        POST /DebinRecurrenteCredito
        +float monto
        +string moneda "032"
        +string concepto "PAGO_BATERIAS"
        +string referencia
        +string subscriptionId
    }

    Request_FinPay --> Response_FinPay : Genera
    Request_FinPay ..> Payload_BIND_Request : Se transforma en