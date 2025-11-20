```mermaid
classDiagram
    class TransferController {
        +executeTransfer(Request, TransferManager)
    }

    class JwtTokenSubscriber {
        +onRequestEvent(RequestEvent)
        -validateToken()
    }

    class TransferManager {
        +executeDebinPull(fecha)
        +getPendingDebins()
        -checkExistingDebin()
    }

    class BindServiceInterface {
        <<Interface>>
        +initiateDebinPull()
        +transferToThirdParty()
    }

    class BindService {
        -httpClient
        -credentials
        +initiateDebinPull()
        +getDebinStatusById()
    }

    class Notifier {
        +sendFailureEmail()
    }

    %% Relaciones
    TransferController ..> TransferManager : Usa
    JwtTokenSubscriber --|> TransferController : Intercepta Request
    TransferManager ..> BindServiceInterface : Inyecta
    TransferManager ..> Notifier : Inyecta
    BindService --|> BindServiceInterface : Implementa