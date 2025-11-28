<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Firebase\JWT\JWT;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use OpenApi\Attributes as OA; // <--- Importamos los atributos de OpenAPI

class JWTController extends AbstractController
{
    private string $jwtSecret;
    private string $generationPass;

    public function __construct(
        #[Autowire('%env(JWT_SECRET)%')] string $jwtSecret,
        #[Autowire('%env(JWT_GENERATION_PASS)%')] string $generationPass
    ) {
        $this->jwtSecret = $jwtSecret;
        $this->generationPass = $generationPass;
    }

    #[Route('/api/auth/token', name: 'api_get_token', methods: ['POST'])]
    
    // --- Documentación OpenAPI (Swagger) ---
    #[OA\Tag(name: 'Autenticación')] // Agrupa este endpoint en una sección "Autenticación"
    #[OA\Post(
        summary: "Obtener Token JWT para Testing",
        description: "Genera un token JWT válido por 1 hora. Requiere una clave maestra en el header."
    )]
    #[OA\Parameter(
        name: 'COBROSFLEX-AUTH-KEY',
        in: 'header',
        required: true,
        description: 'Clave maestra de seguridad (Definida en .env como JWT_GENERATION_PASS)',
        schema: new OA\Schema(type: 'string')
    )]
    #[OA\Response(
        response: 200,
        description: "Token generado exitosamente",
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: "status", type: "string", example: "success"),
                new OA\Property(property: "token", type: "string", description: "El Token JWT Bearer"),
                new OA\Property(property: "expires_in", type: "integer", example: 3600),
                new OA\Property(property: "expires_at", type: "string", format: "date-time", example: "2025-11-28 15:30:00")
            ]
        )
    )]
    #[OA\Response(
        response: 401,
        description: "Acceso denegado: La COBROSFLEX-AUTH-KEY es incorrecta o falta."
    )]
    #[OA\Response(
        response: 500,
        description: "Error interno al generar el token"
    )]
    // ---------------------------------------
    
    public function getToken(Request $request): JsonResponse
    {
        // 1. Validar Header de Seguridad
        $apiKeyReceived = $request->headers->get('COBROSFELX-AUTH-KEY');

        if ($apiKeyReceived !== $this->generationPass) {
            return $this->json([
                'status' => 'error',
                'message' => 'Acceso denegado. Clave de generación inválida.'
            ], 401);
        }

        // 2. Generar Token
        $now = time();
        $expirationTime = $now + (60 * 60); 

        $payload = [
            'iat' => $now,
            'exp' => $expirationTime,
            'nbf' => $now,
            'aud' => 'web-legada-cliente',
            'iss' => 'orquestador-symfony',
        ];

        try {
            $jwt = JWT::encode($payload, $this->jwtSecret, 'HS256');

            return $this->json([
                'status' => 'success',
                'token' => $jwt,
                'expires_in' => 3600,
                'expires_at' => date('Y-m-d H:i:s', $expirationTime)
            ]);

        } catch (\Exception $e) {
            return $this->json(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }
}