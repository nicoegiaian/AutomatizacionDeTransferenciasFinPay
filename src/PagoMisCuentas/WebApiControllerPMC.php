<?php
/*require_once("../includes/constants.php");
require_once("DatabaseConnector/DatabaseConnector.php");
require_once("TableGateways/WebApiGateway.php");
require_once("TableGateways/UsuarioGateway.php");
require_once("TableGateways/TarjetaGateway.php");
require_once("AuthorizationController.php");*/
require_once("../includes/constants.php");
require_once("../api/DatabaseConnector/DatabaseConnector.php");
require_once("../api/Controller/TableGateways/WebApiGatewayPMC.php");
require_once("../api/Controller/TableGateways/UsuarioGateway.php");
require_once("../api/Controller/TableGateways/TarjetaGateway.php");
require_once("../api/Controller/AuthorizationControllerPMC.php");

class WebApiControllerPMC {

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
		
        $this->webApiGatewayPMC = new webApiGatewayPMC("","","","");
		$this->usuarioGateway = new usuarioGateway($this->dbConnection);
		$this->tarjetaGateway = new tarjetaGateway($this->dbConnection);
    }

    public function processRequest()
    {
        // Se obtiene el Token.
		$accessToken = new AuthorizationControllerPMC(ACCESS_TOKEN_URL_PMC, API_KEY_PUBLICA_PMC, API_KEY_PRIVADA_PMC);
		$this->token = json_decode($accessToken->getAccessToken());
		
		switch ($this->endPoint) {
			case 'Login':
				$user = $this->usuarioGateway->findPerson($this->idUser);
				if ($user[0]["card_number"]==""){ // Si no existe el usuario entonces hay que registrarlo
					$response = $this->createUser($user[0]);
					if ($response["status_code_header"]=="HTTP/1.1 200 OK"){
						$userResponse = json_decode($response["body"]);					
						$this->usuarioGateway->updateCreacionPMC($this->idUser, $userResponse);
						$user[0]["card_number"] = $userResponse->client->cards[0]->number;
					}
				}
				$response = $this->login($user[0]);
                break;
			case 'GetCompanies':
				$input = (array) json_decode(file_get_contents('php://input'), TRUE);
				$dateCompaniesFile = date("Y-m-d", filemtime(COMPANIES_PATH."/companies.json"));
				// El archivo de compañías se actualiza una vez por día. Mejorar porque demora 16 segundos en el servidor
				if ($dateCompaniesFile < date("Y-m-d")){
					$response = $this->getCompanies($input["categoria"], $input["compania"]);
					if ($response["status_code_header"]=="HTTP/1.1 200 OK") file_put_contents(COMPANIES_PATH."/companies.json", json_decode($response['body']));	
				}
				$response["status_code_header"] = "HTTP/1.1 200 OK";
				$aCompanies = json_decode(file_get_contents(COMPANIES_PATH."/companies.json"), true);
				foreach($aCompanies as $i => $company) {
					if(strtolower(substr($company['name'], 0, strlen($input["busqueda"])))==strtolower($input["busqueda"])){
						$companies[] = $company;
					}
				}
				$response["body"] = json_encode($companies);
                break;
			case 'DebtsDetails':
				$user = $this->usuarioGateway->findPerson($this->idUser);
				$response = $this->debtsDetails($user[0]);
                break;
			case 'Subscriptions':
				$input = (array) json_decode(file_get_contents('php://input'), TRUE);
				$user = $this->usuarioGateway->findPerson($this->idUser);
				$response = $this->subscriptions($user[0], $input["clavePagoElectronico"], $input["empresa"], $input["tipoSuscripcion"], $input["nombreEmpresa"]);
                break;
			case 'InvoicesDetails':
				$input = (array) json_decode(file_get_contents('php://input'), TRUE);
				
				// Empresas que NO informan base de facturas (paymentType 1) y type T o
				// Empresas que informan base de facturas (paymentType 3) => consultar facturas pendientes de pago
				if (($input["paymentType"]=="1" && $input["type"]=="T") || ($input["paymentType"]=="3")){
					$user = $this->usuarioGateway->findPerson($this->idUser);
					$response = $this->invoicesDetails($user[0], $input["clavePagoElectronico"], $input["empresa"]);	
				}
				else{
					// Factura vacía para cargar monto y pagar
					$response['status_code_header'] = 'HTTP/1.1 200 OK';
					$response['body'] = '{"invoices":[{"invoice_id":"","currency":"ARS","amount":"","due_date":""}]}';
				}
				
                break;
			case 'PaymentDetails':
				$input = (array) json_decode(file_get_contents('php://input'), TRUE);
				$user = $this->usuarioGateway->findPerson($this->idUser);
				$response = $this->paymentDetails($user[0], $input["empresa"], $input["type"]);
                break;
			case 'AgentsPayments':
				$input = (array) json_decode(file_get_contents('php://input'), TRUE);
				$input['factura'] = str_replace("SUSALDOENPESOS", "SU SALDO EN PESOS", $input['factura']);
				
				if ($input["fechaVencimiento"]==""){
					$timestamp = new DateTime();
					$timestamp = $timestamp->format("Y-m-d\TH:i:s.u\Z");
					
					$invoice = array("invoice_id" => $input["factura"],
									 "company_id" => $input["empresa"],
									 "category_id" => $input["categoria"],
									 "customer_id" => $input["clavePagoElectronico"],
									 "amount" => floatval(number_format($input["monto"], 2, ".", "")),
									 "due_date" => $timestamp,
									 "currency" => "ARS",
									 "additional_data" => "",
									 "manually_added" => $input["agregadoManualmente"],
									);
				}
				else{
					$invoice = array("invoice_id" => $input["factura"],
									 "company_id" => $input["empresa"],
									 "category_id" => $input["categoria"],
									 "customer_id" => $input["clavePagoElectronico"],
									 "amount" => floatval(number_format($input["monto"], 2, ".", "")),
									 "due_date" => $input["fechaVencimiento"],
									 "currency" => "ARS",
									 "additional_data" => "",
									 "manually_added" => $input["agregadoManualmente"],
									);
				}
				
				$user = $this->usuarioGateway->findPerson($this->idUser);
				$response = $this->agentsPayments($user[0], $input["monto"], $invoice);
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

    private function createUser($user)
    {
		$result = $this->webApiGatewayPMC->createUser($this->token, $user);
        if ($result["httpCode"]==200)
			$response['status_code_header'] = 'HTTP/1.1 200 OK';
		else
			$response['status_code_header'] = 'HTTP/1.1 400 Bad request';
		
		$response['body'] = $result["response"];
        return $response;
    }
	
	private function login($user)
    {
		$result = $this->webApiGatewayPMC->login($this->token, $user);
        if ($result["httpCode"]==200){
			$response['status_code_header'] = 'HTTP/1.1 200 OK';
			$login = json_decode($result["response"], true);
			$_SESSION["timestamp"] = $login["timestamp"]; // Se guarda el timestamp en la sesión para las llamadas a la Web API
		}
		else
			$response['status_code_header'] = 'HTTP/1.1 400 Bad request';
		
		$response['body'] = json_encode($result["response"], JSON_UNESCAPED_UNICODE);
		return $response;
    }
	
	private function getCompanies($category, $company)
    {
		$result = $this->webApiGatewayPMC->getCompanies($this->token, $category, $company);
        if ($result["httpCode"]==200)
			$response['status_code_header'] = 'HTTP/1.1 200 OK';
		else
			$response['status_code_header'] = 'HTTP/1.1 400 Bad request';
		
		$response['body'] = json_encode($result["response"]);
        return $response;
    }
	
	private function debtsDetails($user)
    {
        //$_SESSION["timestamp"] = "24082622383503";
		$result = $this->webApiGatewayPMC->debtsDetails($this->token, $user);
        if ($result["httpCode"]==200)
			$response['status_code_header'] = 'HTTP/1.1 200 OK';
		else
			$response['status_code_header'] = 'HTTP/1.1 400 Bad request';
		
		$response['body'] = json_encode($result["response"], JSON_UNESCAPED_UNICODE);
        return $response;
    }
	
	private function subscriptions($user, $client, $company, $subscriptionType, $description)
    {
        //$_SESSION["timestamp"] = "24082818222590";
		$result = $this->webApiGatewayPMC->subscriptions($this->token, $user, $client, $company, $subscriptionType, $description);
        if ($result["httpCode"]==200)
			$response['status_code_header'] = 'HTTP/1.1 200 OK';
		else
			$response['status_code_header'] = 'HTTP/1.1 400 Bad request';
		
		$response['body'] = json_encode($result["response"], JSON_UNESCAPED_UNICODE);
        return $response;
    }
	
	private function invoicesDetails($user, $client, $company)
    {
        //$_SESSION["timestamp"] = "24082622383503";
		$result = $this->webApiGatewayPMC->invoicesDetails($this->token, $user, $client, $company);
        if ($result["httpCode"]==200)
			$response['status_code_header'] = 'HTTP/1.1 200 OK';
		else
			$response['status_code_header'] = 'HTTP/1.1 400 Bad request';
		
		$response['body'] = json_encode($result["response"], JSON_UNESCAPED_UNICODE);
        return $response;
    }
	
	private function paymentDetails($user, $company, $type)
    {
        //$_SESSION["timestamp"] = "24082622383503";
		$result = $this->webApiGatewayPMC->paymentDetails($this->token, $user, $company, $type);
        if ($result["httpCode"]==200)
			$response['status_code_header'] = 'HTTP/1.1 200 OK';
		else
			$response['status_code_header'] = 'HTTP/1.1 400 Bad request';
		
		$response['body'] = json_encode($result["response"], JSON_UNESCAPED_UNICODE);
        return $response;
    }
	
	private function agentsPayments($user, $amount, $invoice)
    {
        //$_SESSION["timestamp"] = "24082622383503";
		$result = $this->webApiGatewayPMC->agentsPayments($this->token, $user, $amount, $invoice);
        if ($result["httpCode"]==200)
			$response['status_code_header'] = 'HTTP/1.1 200 OK';
		else
			$response['status_code_header'] = 'HTTP/1.1 400 Bad request';
		
		$response['body'] = json_encode($result["response"], JSON_UNESCAPED_UNICODE);
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