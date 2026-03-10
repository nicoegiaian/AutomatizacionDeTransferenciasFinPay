<?php
/*require_once("../includes/constants.php");
require_once("DatabaseConnector/DatabaseConnector.php");
require_once("TableGateways/WebApiGateway.php");
require_once("TableGateways/UsuarioGateway.php");
require_once("TableGateways/TarjetaGateway.php");
require_once("AuthorizationController.php");*/
require_once("../includes/constants.php");
require_once(PRISMA_PATH . "/constantsCronTasks.php");

require_once("../api/DatabaseConnector/DatabaseConnector.php");
require_once("../api/Controller/TableGateways/WebApiGateway.php");
require_once("../api/Controller/TableGateways/UsuarioGateway.php");
require_once("../api/Controller/TableGateways/TarjetaGateway.php");
require_once("../api/Controller/AuthorizationController.php");

class WebApiController {

    private $endPoint;
	private $idUser;
	private $typeUser;
	private $amount;
	private $ticket;
	private $token;

    private $webApiGateway;
	private $usuarioGateway;
	private $tarjetaGateway;
	
	private $dbConnection;
	
    public function __construct($endPoint, $idUser, $typeUser, $amount, $ticket)
    {
        $this->endPoint = $endPoint;
		$this->idUser = $idUser;
		$this->typeUser = $typeUser;
		$this->amount = $amount;
		$this->ticket = $ticket;
		
		$this->dbConnection = (new DatabaseConnector(DB_SERVER, DB_PORT, DB_NAME, DB_USER, DB_PASSWORD))->getConnection();
		
        $this->webApiGateway = new webApiGateway("","","","");
		$this->usuarioGateway = new usuarioGateway($this->dbConnection);
		$this->tarjetaGateway = new tarjetaGateway($this->dbConnection);
    }

    public function processRequest()
    {
		// Se obtiene el Token.
		$accessToken = new AuthorizationController(ACCESS_TOKEN_URL, CLIENT_ID, CLIENT_SECRET);
		$tokenResponse = $accessToken->getAccessToken();
		$this->token = json_decode($tokenResponse);
		
		switch ($this->endPoint) {
            case 'CreateCardHolder':
                if ($this->typeUser=="PERSON")
					$user = $this->usuarioGateway->findPerson($this->idUser);
				else
					$user = $this->usuarioGateway->findCompany($this->idUser);
				$response = $this->createCardHolder($user[0]);
				if ($response["status_code_header"]=="HTTP/1.1 201 Created") $this->usuarioGateway->updateCreacionPrisma($this->idUser);
                break;
			case 'GetCardHolderById':
				$response = $this->getCardHolderById($this->idUser);
                break;
			case 'UpdateCardHolder':
                if ($this->typeUser=="PERSON")
					$user = $this->usuarioGateway->findPerson($this->idUser);
				else
					$user = $this->usuarioGateway->findCompany($this->idUser);
				$response = $this->updateCardHolder($user[0], $this->idUser);
                break;
			case 'CreateCard':
				$user = $this->usuarioGateway->findCardFields($this->idUser);
				$response = $this->createCard($user[0]);
                break;
			case 'RetrieveCard':
				$user = $this->tarjetaGateway->find($this->idUser);
				$response = $this->retrieveCard($user[0]["CardIdPrisma"]);
                break;
			case 'RetrieveCardTransitions':
				$user = $this->tarjetaGateway->find($this->idUser);
				$response = $this->retrieveCardTransitions($user[0]["CardIdPrisma"]);
                break;
			case 'ChangeStateCard':
				$user = $this->tarjetaGateway->findBlocked($this->idUser);
				$response = $this->changeStateCard($user[0]["CardIdPrisma"]);
                break;
			case 'CashIn':
				$user = $this->tarjetaGateway->find($this->idUser);
				$response = $this->manageMoney($user[0]["CuentaEmpresaARS"], 'CashIn', $this->amount, $this->ticket);
                break;
			case 'CashOut':
				$user = $this->tarjetaGateway->find($this->idUser);
				//$user = $this->tarjetaGateway->findBlocked($this->idUser);
				$response = $this->manageMoney($user[0]["CuentaARS"], 'CashOut', $this->amount, $this->ticket);
				//$response = $this->manageMoney($user[0]["CuentaEmpresaARS"], 'CashOut', $this->amount, $this->ticket);
                break;
			case 'AccountsTransfer':
				$user = $this->tarjetaGateway->find($this->idUser);
				$response = $this->accountsTransfer($user[0]["CuentaEmpresaARS"], $user[0]["CuentaARS"], $this->amount, $this->ticket);
				//$response = $this->accountsTransfer(36356, 1800, $this->amount, $this->ticket);
                break;
			case 'AccountsTransferUser':
				$userFrom = $this->tarjetaGateway->find($this->idUser);
				$userTo = $this->tarjetaGateway->find($this->typeUser);
				$response = $this->accountsTransfer($userFrom[0]["CuentaARS"], $userTo[0]["CuentaARS"], $this->amount, $this->ticket);
                break;
			case 'GetBalance':
				// Si es numérico es un IdUsuario legacy -> buscar CardIdPrisma en tarjetas
				// Si NO es numérico es un CardHolderId de gift card nueva -> usar directo
				if (is_numeric($this->idUser)) {
					$user = $this->tarjetaGateway->find($this->idUser);
					if (empty($user) || !isset($user[0]["CardIdPrisma"])) {
						$response['status_code_header'] = 'HTTP/1.1 404 Not Found';
						$response['body'] = json_encode(['error' => 'No se encontro tarjeta activa para IdUsuario: ' . $this->idUser]);
						break;
					}
					$cardIdOrHolder = $user[0]["CardIdPrisma"];
				} else {
					$cardIdOrHolder = $this->idUser;
				}
				$response = $this->getBalance($cardIdOrHolder);
                break;
			case 'RetrieveTransactions':
				$user = $this->tarjetaGateway->find($this->idUser);
				$input = (array) json_decode(file_get_contents('php://input'), TRUE);
				if ($input["desde"]=="") $input["desde"] = date('Y-m-d', strtotime(date('Y-m-d')."- 60 days"));
				if ($input["hasta"]=="") $input["hasta"] = date('Y-m-d'); 	
				$response = $this->retrieveTransactions($user[0]["CardIdPrisma"], $input["desde"], $input["hasta"]);
                break;
			case 'RetrievesCardInfo':
				$user = $this->tarjetaGateway->find($this->idUser);
				$response = $this->retrievesCardInfo($user[0]["CardIdPrisma"]);
                break;
			case 'GetSessionId':
				$user = $this->tarjetaGateway->find($this->idUser);
				$response = $this->getSessionId($user[0]["CardIdPrisma"]);
                break;
			case 'UpdatePIN':
				$user = $this->tarjetaGateway->find($this->idUser);
				$response = $this->updatePIN($user[0]["CardIdPrisma"], $this->typeUser, $this->ticket);
                break;
			case 'CreateExchangeRate':
				$response = $this->createExchangeRate();
                break;
			case 'RetrieveFile':
				$name = $this->amount; 
				$process = $this->ticket;
				$response = $this->retrieveFile($name, $process);
                break;
            default:
                $response = $this->notFoundResponse();
                break;
        }
        header($response['status_code_header']);
        if ($response['body']) {
            echo $response['body'];
        }
    }

    private function createCardHolder($user)
    {
		$result = $this->webApiGateway->createCardHolder($this->token, $this->typeUser, $user);
        if ($result["httpCode"]==201)
			$response['status_code_header'] = 'HTTP/1.1 201 Created';
		else
			$response['status_code_header'] = 'HTTP/1.1 400 Bad request';
		
		$response['body'] = json_encode($result["response"]);
        return $response;
    }
	
	private function getCardHolderById($idUser)
    {
		$result = $this->webApiGateway->getCardHolderById($this->token, $idUser);
        if ($result["httpCode"]==200)
			$response['status_code_header'] = 'HTTP/1.1 200 OK';
		else
			$response['status_code_header'] = 'HTTP/1.1 400 Bad request';
		
		$response['body'] = json_encode($result["response"]);
        return $response;
    }
	
	private function updateCardHolder($user, $idUser)
    {
		$result = $this->webApiGateway->updateCardHolder($this->token, $this->typeUser, $user, $idUser);
        if ($result["httpCode"]==204)
			$response['status_code_header'] = 'HTTP/1.1 204 Updated';
		else
			$response['status_code_header'] = 'HTTP/1.1 400 Bad request';
		
		$response['body'] = json_encode($result["response"]);
        return $response;
    }
	
	private function createCard($user)
    {
        $result = $this->webApiGateway->createCard($this->token, $user);
        if ($result["httpCode"]==201){
			$response['status_code_header'] = 'HTTP/1.1 201 Created';
			$this->tarjetaGateway->insert($result["response"], $user);
		}
		else
			$response['status_code_header'] = 'HTTP/1.1 400 Bad request';
		
		$response['body'] = json_encode($result["response"]);
        return $response;
    }
	
	private function retrieveCard($cardId)
    {
        $result = $this->webApiGateway->retrieveCard($this->token, $cardId);
        if ($result["httpCode"]==200)
			$response['status_code_header'] = 'HTTP/1.1 200 OK';
		else
			$response['status_code_header'] = 'HTTP/1.1 400 Bad request';
		
		$response['body'] = json_encode($result["response"]);
        return $response;
    }
	
	private function retrieveCardTransitions($cardId)
    {
        $result = $this->webApiGateway->retrieveCardTransitions($this->token, $cardId);
        if ($result["httpCode"]==200)
			$response['status_code_header'] = 'HTTP/1.1 200 OK';
		else
			$response['status_code_header'] = 'HTTP/1.1 400 Bad request';
		
		$response['body'] = json_encode($result["response"]);
        return $response;
    }
	
	private function changeStateCard($cardId)
    {
        $result = $this->webApiGateway->changeStateCard($this->token, $cardId);
        if ($result["httpCode"]==204)
			$response['status_code_header'] = 'HTTP/1.1 204 Updated';
		else
			$response['status_code_header'] = 'HTTP/1.1 400 Bad request';
		
		$response['body'] = json_encode($result["response"]);
        return $response;
    }
	
	private function manageMoney($accountId, $entryType, $amount, $ticket)
    {
        $result = $this->webApiGateway->manageMoney($this->token, $accountId, $entryType, $amount, $ticket);
        if ($result["httpCode"]==200)
			$response['status_code_header'] = 'HTTP/1.1 200 OK';
		else
			$response['status_code_header'] = 'HTTP/1.1 400 Bad request';
		
		$response['body'] = json_encode($result["response"]);
        return $response;
    }
	
	public function accountsTransfer($accountDebit, $accountCredit, $amount, $ticket)
    {
        $result = $this->webApiGateway->accountsTransfer($this->token, $accountDebit, $accountCredit, $amount, $ticket);
        if ($result["httpCode"]==200)
			$response['status_code_header'] = 'HTTP/1.1 200 OK';
		else
			$response['status_code_header'] = 'HTTP/1.1 400 Bad request';
		
		$response['body'] = json_encode($result["response"]);
        return $response;
    }
	
	private function getBalance($cardId)
    {
        $result = $this->webApiGateway->getBalance($this->token, $cardId);
        if ($result["httpCode"]==200)
			$response['status_code_header'] = 'HTTP/1.1 200 OK';
		else
			$response['status_code_header'] = 'HTTP/1.1 400 Bad request';
		
		$balance = json_decode($result["response"]);
				
		$response['body'] = json_encode(array("balance" => number_format($balance->balances[0]->available, 2, ",", ".")));
        return $response;
    }
	
	private function retrieveTransactions($cardId, $startDate, $endDate)
    {
		$month = array("enero", "febrero", "marzo", "abril", "mayo", "junio", "julio", "agosto", "septiembre", "octubre", "noviembre", "diciembre");
		$result = $this->webApiGateway->retrieveTransactions($this->token, $cardId, $startDate, $endDate);
		
		//echo $result["response"];
        
		if ($result["httpCode"]==200){
			$response['status_code_header'] = 'HTTP/1.1 200 OK';
			$transactions = json_decode($result["response"]);
			
			foreach ($transactions->data as $transaction) {
				if ($transaction->authorization->info->transaction->type=="COMPLETION") continue; // Para evitar duplicados solo se muestra PURCHASE
				if ($transaction->authorization->info->transaction->type=="UNKNOWN") continue; // No se muestran los consumos desconocidos
				
				$type = $transaction->authorization->info->transaction->type;
				$descripcion = trim($transaction->authorization->info->merchant->name);
				switch($type){
					case "TRANSFER_CREDIT" : 
						$monto = "&nbsp;$ " . number_format($transaction->authorization->info->amounts->original->amount, 2, ",", ".");
						$descripcion = "Carga saldo";
						break;
					case "CASH_OUT" : $monto = "-$ " . number_format($transaction->authorization->info->amounts->original->amount, 2, ",", "."); break;
					case "REFUND" : 
						$monto = "&nbsp;$ " . number_format($transaction->authorization->info->amounts->original->amount, 2, ",", "."); 
						$descripcion = "Dev. " . $descripcion;
						break;
					case "REVERSAL" :
						if ($transaction->authorization->info->amounts->original->amount < 0){ // Anulación extracción efectivo
							$monto = "&nbsp;$ " . number_format($transaction->authorization->info->amounts->original->amount * (-1), 2, ",", "."); 
							switch($transaction->authorization->info->amounts->original->amount){
								case 1400 : $descripcion = "Rev. Com. Ext. Ef. " . $descripcion; break;
								case 294  : $descripcion = "Rev. IVA Ext. Ef. " . $descripcion; break;
								default   : $descripcion = "Rev. Ext. Ef. " . $descripcion; break;
							}
						}
						else{
							$monto = "&nbsp;$ " . number_format($transaction->authorization->info->amounts->billing->amount, 2, ",", "."); 
							$descripcion = "Rev. " . $descripcion;
						}
						break;
					case "WITHDRAWAL" :
						$monto = "-$ " . number_format($transaction->authorization->info->amounts->original->amount, 2, ",", ".");
						switch($transaction->authorization->info->amounts->original->amount){
								case 1400 : $descripcion = "Com. Ext. Ef. " . $descripcion; break; // Comisión extracción efectivo
								case 294  : $descripcion = "IVA Ext. Ef. " . $descripcion; break;  // IVA comisión extracción efectivo
								default   : $descripcion = "Ext. Ef. " . $descripcion; break;      // Extracción efectivo
						}	
						break;
					case "TRANSFER" :
						$monto = "$ " . number_format($transaction->authorization->info->amounts->billing->amount, 2, ",", "."); break;
					default : $monto = "-$ " . number_format($transaction->authorization->info->amounts->billing->amount, 2, ",", "."); break;
				}
				
				$received_timestamp = $transaction->authorization->info->transaction->received_timestamp;
				if (count($transaction->authorization->info->fee) > 0) // Transacción en USD, se incluye el tipo de cambio
					$descripcion .= " (USD " . number_format($transaction->authorization->info->amounts->original->amount, 2, ",", ".") . " TC " . number_format($transaction->authorization->info->amounts->billing->exchange_rate, 2, ",", ".") . ")";
				$aTransaction[] = array("Descripcion" => $descripcion, 
										"Fecha" => date("d", strtotime($received_timestamp)) . " de " . $month[date("n", strtotime($received_timestamp))-1] . " " . date("Y", strtotime($received_timestamp)),
										"Monto" => $monto);
				
				if (count($transaction->authorization->info->fee) > 0){ // Hay impuestos para mostrar (PAIS, RG 4815, IVA, Percep. IIBB CABA y Percep. IIBB PBA)
					foreach ($transaction->authorization->info->fee as $fee) {
						$descripcion = "";
						$monto = 0;
						$signo = ($type=="REVERSAL") ? "" : "-";
						
						if ($fee->type=="989" || $fee->type=="993"){ // Impuesto PAIS
							$descripcion = "Impuesto PAIS TC " . number_format($transaction->authorization->info->amounts->tax_base->exchange_rate, 2, ",", ".");
							$monto = $signo . "$ " . number_format($fee->amount, 2, ",", ".");
						}
						
						if ($fee->type=="594"){ // Percepción RG 4815
							$descripcion = "Percepción RG 4815 TC " . number_format($transaction->authorization->info->amounts->tax_base->exchange_rate, 2, ",", ".");
							$monto = $signo. "$ " . number_format($fee->amount, 2, ",", ".");
						}
						
						if ($fee->type=="956"){ // IVA
							$descripcion = "IVA TC " . number_format($transaction->authorization->info->amounts->tax_base->exchange_rate, 2, ",", ".");
							$monto = $signo . "$ " . number_format($fee->amount, 2, ",", ".");
						}
						
						if ($fee->type=="982"){ // Percep. IIBB CABA
							$descripcion = "Percep. IIBB CABA TC " . number_format($transaction->authorization->info->amounts->tax_base->exchange_rate, 2, ",", ".");
							$monto = $signo . "$ " . number_format($fee->amount, 2, ",", ".");
						}
						
						if ($fee->type=="964"){ // Percep. IIBB PBA
							$descripcion = "Percep. IIBB PBA TC " . number_format($transaction->authorization->info->amounts->tax_base->exchange_rate, 2, ",", ".");
							$monto = $signo . "$ " . number_format($fee->amount, 2, ",", ".");
						}
						
						if ($descripcion!=""){
							$aTransaction[] = array("Descripcion" => $descripcion, 
													"Fecha" => date("d", strtotime($received_timestamp)) . " de " . $month[date("n", strtotime($received_timestamp))-1] . " " . date("Y", strtotime($received_timestamp)),
													"Monto" => $monto);
						}
					}
				}
				//"fee":[{"type":"989","code":"939","amount":7537104},{"type":"594","code":"217","amount":7537104}]
			}
			
			$response['body'] = json_encode($aTransaction);
		}
		else{
			$response['status_code_header'] = 'HTTP/1.1 400 Bad request';
			$response['body'] = json_encode($result["response"]);
		}
		
        return $response;
    }
	
	private function retrievesCardInfo($cardId)
    {
        $result = $this->webApiGateway->retrievesCardInfo($this->token, $cardId);
        if ($result["httpCode"]==200)
			$response['status_code_header'] = 'HTTP/1.1 200 OK';
		else
			$response['status_code_header'] = 'HTTP/1.1 400 Bad request';
		
		$response['body'] = json_encode($result["response"]);
        return $response;
    }
	
	private function getSessionId($cardId)
    {
        $result = $this->webApiGateway->getSessionId($this->token, $cardId);
        if ($result["httpCode"]==200)
			$response['status_code_header'] = 'HTTP/1.1 200 OK';
		else
			$response['status_code_header'] = 'HTTP/1.1 400 Bad request';
		
		$response['body'] = json_encode($result["response"]);
        return $response;
    }
	
	private function updatePIN($cardId, $sessionId, $pin)
    {
        $result = $this->webApiGateway->updatePIN($this->token, $sessionId, $pin);
        if ($result["httpCode"]==200)
			$response['status_code_header'] = 'HTTP/1.1 200 OK';
		else
			$response['status_code_header'] = 'HTTP/1.1 400 Bad request';
		
		$response['body'] = json_encode($result["response"]);
        return $response;
    }
	
	private function createExchangeRate()
    {
        $result = $this->webApiGateway->createExchangeRate($this->token);
        if ($result["httpCode"]==201)
			$response['status_code_header'] = 'HTTP/1.1 201 OK';
		else
			$response['status_code_header'] = 'HTTP/1.1 400 Bad request';
		
		$response['body'] = json_encode($result["response"]);
        return $response;
    }
	
	private function retrieveFile($name, $process)
	{		
		$result = $this->webApiGateway->retrieveFile($this->token, $name, $process);
        if ($result["httpCode"]==200){
			$archivo = json_decode($result["response"]);
			$response['status_code_header'] = 'HTTP/1.1 201 OK';
			$result["response"] = file_get_contents($archivo->url);
			if (!is_dir(PRISMA_PATH."/".strtolower($process)."/". date("Y"))) mkdir(PRISMA_PATH."/".strtolower($process)."/".date("Y"), 0777); // Crea la carpeta del año
			if (!is_dir(PRISMA_PATH."/".strtolower($process)."/". date("Y")."/".date("m"))) mkdir(PRISMA_PATH."/".strtolower($process)."/". date("Y")."/".date("m"), 0777); // Crea la carpeta del mes
			file_put_contents(PRISMA_PATH."/".strtolower($process)."/".date("Y")."/".date("m")."/".$name, $result['response']);
		}
		else
			$response['status_code_header'] = 'HTTP/1.1 400 Bad request';
		
		$response['body'] = json_encode($result["response"]);
        return $response;
	}
	
    private function unprocessableEntityResponse()
    {
        $response['status_code_header'] = 'HTTP/1.1 422 Unprocessable Entity';
        $response['body'] = json_encode([
            'error' => 'Invalid input'
        ]);
        return $response;
    }

    private function notFoundResponse()
    {
        $response['status_code_header'] = 'HTTP/1.1 404 Not Found';
        $response['body'] = null;
        return $response;
    }
}
?>