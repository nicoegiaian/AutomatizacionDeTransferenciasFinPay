<?php
// src/Service/BindService.php

namespace App\Service;

use Symfony\Contracts\HttpClient\HttpClientInterface;



class BindService implements BindServiceInterface
{
    private string $clientId;
    private string $clientSecret;
    private string $apiUrl;
    private HttpClientInterface $httpClient;
    private const TRANSFER_ENDPOINT = '/walletentidad-operaciones/v1/api/v1.201/transferir';
    private const ACCOUNT_INFO_ENDPOINT = '/walletentidad-cuenta/v1/api/v1.201/SaldoActualByCVU';
    private const MOVIMIENTOS_ENDPOINT = '/walletentidad-operaciones/v1/api/v1.201/MovimientosOperaciones';
    private string $tokenUrl;
    private string $scope;
    private ?string $accessToken = null;
    private string $cvuOrigen;  
    
    public function __construct(
        HttpClientInterface $httpClient, 
        string $BIND_CLIENT_ID, 
        string $BIND_CLIENT_SECRET, 
        string $BIND_API_URL,
        string $BIND_CVU_ORIGEN,
        string $BIND_TOKEN_URL,
        string $BIND_SCOPE
    ) {
        $this->httpClient = $httpClient;
        $this->clientId = $BIND_CLIENT_ID;
        $this->clientSecret = $BIND_CLIENT_SECRET;
        $this->apiUrl = $BIND_API_URL;
        $this->cvuOrigen = $BIND_CVU_ORIGEN;
        $this->tokenUrl = $BIND_TOKEN_URL;
        $this->scope = $BIND_SCOPE;
    }

    /**
     * Obtiene el saldo de una cuenta dada su CVU.
     * @param string $cvu El CVU a consultar.
     * @return float El saldo disponible.
     * @throws \RuntimeException Si falla la consulta.
     */
    public function getAccountBalance(string $cvu): float
    {
        $token = $this->getAccessToken();
        
        // La URL se construye concatenando el endpoint al API URL base
        // Nota: Algunos endpoints de BIND requieren pasar el CVU como query param o en el path.
        // Basado en 'CuentaCVUByCbuCvuOrAlias', suele ser GET con query param.
        
        $url = $this->apiUrl . self::ACCOUNT_INFO_ENDPOINT . '/' . $cvu;

        try {
            $response = $this->httpClient->request('GET', $url, [
                'headers' => [
                    'Authorization' => 'Bearer ' . $token,
                    'Content-Type' => 'application/json'
                ],
                'timeout' => 30 // 30 segundos timeout para consultas de saldo
            ]);

            $statusCode = $response->getStatusCode();
            $rawBody = $response->getContent(false);

            if (empty(trim($rawBody))) {
                throw new \RuntimeException("BIND Saldo Error (HTTP {$statusCode}): La respuesta está vacía para CVU {$cvu}");
            }

            $data = json_decode($rawBody, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new \RuntimeException("BIND Saldo Error (HTTP {$statusCode}): Respuesta no es JSON válido. Body: " . substr($rawBody, 0, 500));
            }

            if ($statusCode !== 200) {
                 $errorMsg = $data['mensaje'] ?? json_encode($data);
                 throw new \RuntimeException("Error al consultar saldo en BIND: $errorMsg");
            }

            // Mapeo de respuesta BIND (Ajustar según respuesta real: suele ser 'saldo' o 'balance')
            // Asumimos que la respuesta trae el objeto de la cuenta
            if (isset($data['saldo'])) {
                return (float) $data['saldo'];
            }
             
            if (isset($data['cuenta']['saldo'])) {
                return (float) $data['cuenta']['saldo'];
            }

            throw new \RuntimeException("Campo de saldo no encontrado en respuesta BIND.");

        } catch (\Exception $e) {
            throw new \RuntimeException("Fallo conexión BIND (Saldo): " . $e->getMessage());
        }
    }


    /**
     * Obtiene el token de acceso necesario para la API de BIND.
     * @return string El token de acceso.
     * @throws \Exception Si la autenticación falla.
     */
    private function getAccessToken(): string
    {
        // 1. Caching simple: Si ya tenemos un token en memoria, lo devolvemos.
        if ($this->accessToken) {
            return $this->accessToken;
        }

        // 2. Parámetros para la solicitud del token (Grant Type: client_credentials)
        $payload = [
            'grant_type' => 'client_credentials',
            'client_id' => $this->clientId,
            'client_secret' => $this->clientSecret,
            'scope' => $this->scope,
        ];
        
        try {
            // 3. Realizar la solicitud POST al endpoint de autenticación
            // BIND utiliza un flujo estándar donde los parámetros van en el body (x-www-form-urlencoded)
            $response = $this->httpClient->request('POST', $this->tokenUrl, [
                'body' => $payload,
                'headers' => [
                    'Content-Type' => 'application/x-www-form-urlencoded',
                ],
                'timeout' => 30 // 30 segundos timeout para autenticación
            ]);
            
            $statusCode = $response->getStatusCode();
            $rawBody = $response->getContent(false);

            if (empty(trim($rawBody))) {
                throw new \RuntimeException("BIND Auth Error (HTTP {$statusCode}): La respuesta está vacía");
            }

            $data = json_decode($rawBody, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new \RuntimeException("BIND Auth Error (HTTP {$statusCode}): Respuesta no es JSON válido. Body: " . substr($rawBody, 0, 500));
            }
            
            if (!isset($data['access_token'])) {
                 // Manejo de error si el body no contiene el token (ej. 400 Bad Request)
                 $error = $data['error_description'] ?? 'Error desconocido en respuesta BIND Auth';
                 throw new \RuntimeException("Fallo de autenticación BIND (HTTP {$statusCode}): " . $error);
            }

            // 4. Almacenar el token para el cache y retornarlo
            $this->accessToken = $data['access_token'];
            return $this->accessToken;

        } catch (\Exception $e) {
            // Captura errores de red, JSON parsing, errores HTTP (manejados por toArray), etc.
            throw new \RuntimeException("Fallo de conexión en el endpoint de autenticación BIND: " . $e->getMessage(), $e->getCode(), $e);
        }
    }
    
    /**
     * Realiza la transferencia o Simula según $isRealTransfer.
     * @param bool $isRealTransfer Si es FALSE, simula y devuelve array con estado 'SIMULATED'.
     */
    public function transferToThirdParty(string $cbuDestino, float $monto, ?string $cvuOrigen = null, bool $isRealTransfer = false, ?string $cuitDestino = null): array
    {
        $token = $this->getAccessToken(); 
        $origenAUtilizar = $cvuOrigen ?? $this->cvuOrigen;
        $referencia = 'TRF-' . time() . '-' . rand(1000, 9999);

        // cuit_destino es obligatorio desde 28/04/2026 (BIND changelog).
        // Sanitizamos: solo dígitos, entre 10 y 11 chars (CUIT/CUIL AR).
        $cuitSanitizado = $cuitDestino !== null ? preg_replace('/\D+/', '', $cuitDestino) : '';
        if ($cuitSanitizado === '' || strlen($cuitSanitizado) < 10 || strlen($cuitSanitizado) > 11) {
            throw new \RuntimeException(
                "BIND transferToThirdParty: cuit_destino requerido y debe tener 10-11 dígitos. Recibido: " . var_export($cuitDestino, true) . " (CBU destino: $cbuDestino)"
            );
        }

        $payload = [
            'cvu_Origen' => $origenAUtilizar, 
            'cbu_cvu_destino' => $cbuDestino,
            'cuit_destino' => $cuitSanitizado,
            'importe' => $monto,
            'referencia' => $referencia,
            'concepto' => 'VAR',
        ];

        // LOGICA CENTRAL DE SIMULACIÓN
        if (!$isRealTransfer) {
            // En lugar de Exception, devolvemos una estructura de éxito simulado
            return [
                'estado' => 'SIMULATED', // Flag clave para quien llama
                'mensaje' => 'Transferencia simulada correctamente (DRY RUN)',
                'comprobanteId' => 'SIM-' . time() . '-DRY',
                'audit_cvu_origen' => $origenAUtilizar, // Para que el log registre "origen simulado"
                'payload_debug' => $payload // Para que el caller pueda loguear qué hubiera mandado
            ];
        }
        
        // MODO REAL
        $url = $this->apiUrl . self::TRANSFER_ENDPOINT;

        $response = $this->httpClient->request('POST', $url, [
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
                'Content-Type' => 'application/json'
            ],
            'json' => $payload,
            'timeout' => 60 // 60 segundos timeout para transferencias (crítico)
        ]);

        $statusCode = $response->getStatusCode();
        $rawBody = $response->getContent(false);
        
        if (empty(trim($rawBody))) {
            throw new \RuntimeException(
                "BIND API Error (HTTP {$statusCode}): La respuesta está vacía. " .
                "Payload enviado: " . json_encode($payload, JSON_UNESCAPED_UNICODE)
            );
        }

        $data = json_decode($rawBody, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \RuntimeException(
                "BIND API Error (HTTP {$statusCode}): Respuesta no es JSON válido. Body: " . substr($rawBody, 0, 500)
            );
        }
        
        if ($statusCode !== 201 && $statusCode !== 200) {
            $errorDetalle = $data['mensaje'] ?? $data['errores'][0]['detalle'] ?? $rawBody;
            throw new \RuntimeException("BIND API Error (HTTP {$statusCode}): " . $errorDetalle);
        }
        
        $data['audit_cvu_origen'] = $origenAUtilizar;
        $data['estado'] = 'COMPLETED';
        return $data;
    }

    /**
     * Consulta los movimientos de operaciones en BIND para un rango de fechas.
     * @return array Lista de movimientos.
     */
    public function getMovimientos(string $fechaDesde, string $fechaHasta, int $pageNumber = 0, int $pageSize = 500): array
    {
        $token = $this->getAccessToken();
        $url = $this->apiUrl . self::MOVIMIENTOS_ENDPOINT;

        // Agregar hora si solo viene la fecha (YYYY-MM-DD)
        if (strlen($fechaDesde) === 10) {
            $fechaDesde .= 'T00:00:00';
        }
        if (strlen($fechaHasta) === 10) {
            $fechaHasta .= 'T23:59:59';
        }

        $response = $this->httpClient->request('GET', $url, [
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
                'Content-Type' => 'application/json'
            ],
            'query' => [
                'fechaDesde' => $fechaDesde,
                'fechaHasta' => $fechaHasta,
                'pageNumber' => $pageNumber,
                'pageSize' => $pageSize,
            ],
            'timeout' => 30
        ]);

        $statusCode = $response->getStatusCode();
        $rawBody = $response->getContent(false);

        if (empty(trim($rawBody))) {
            // BIND devuelve body vacío cuando no hay movimientos en el rango
            return [
                'totalPages' => 0,
                'totalRecords' => 0,
                'movimientosOperaciones' => [],
            ];
        }

        $data = json_decode($rawBody, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \RuntimeException(
                "BIND Movimientos Error (HTTP {$statusCode}): Respuesta no es JSON válido. Body: " . substr($rawBody, 0, 500)
            );
        }

        if ($statusCode !== 200) {
            $errorMsg = $data['mensaje'] ?? json_encode($data);
            throw new \RuntimeException("Error al consultar movimientos BIND (HTTP {$statusCode}): $errorMsg");
        }

        return $data;
    }

    /**
     * Verifica si ya existe una transferencia realizada hoy para un CBU y monto dados.
     * Recorre todas las páginas de movimientos si es necesario.
     * @return array|null Retorna el movimiento encontrado o null si no existe.
     */
    public function existeTransferenciaPrevia(string $cbuDestino, float $monto, string $fecha): ?array
    {
        $pageNumber = 0;
        $pageSize = 500;

        do {
            $movimientos = $this->getMovimientos($fecha, $fecha, $pageNumber, $pageSize);

            $items = $movimientos['movimientosOperaciones'] ?? [];
            $totalPages = $movimientos['totalPages'] ?? 1;

            foreach ($items as $mov) {
                $cbuMov = $mov['cbuCvuContraparte'] ?? null;
                $montoMov = isset($mov['importe']) ? (float)$mov['importe'] : null;

                if ($cbuMov === $cbuDestino && $montoMov !== null && abs($montoMov - $monto) < 0.01) {
                    return $mov;
                }
            }

            $pageNumber++;
        } while ($pageNumber < $totalPages);

        return null;
    }
}