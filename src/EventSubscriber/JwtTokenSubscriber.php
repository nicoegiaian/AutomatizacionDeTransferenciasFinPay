<?php

namespace App\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface; 
use Firebase\JWT\JWT; 
use Firebase\JWT\Key;
use Symfony\Component\HttpFoundation\JsonResponse;

class JwtTokenSubscriber implements EventSubscriberInterface
{
    private string $jwtSecret;

    public function __construct(string $jwtSecret) 
    {
        $this->jwtSecret = $jwtSecret; // Asignamos el valor inyectado
    }

    public function onRequestEvent(RequestEvent $event): void
    {
        $request = $event->getRequest();

        // 1. Solo aplica el filtro al endpoint de transferencias
        if ($request->getPathInfo() !== '/transfers/execute') {
            return;
        }
    
        // 2. Obtener el Token del Header (Authorization: Bearer <token>)
        $authorizationHeader = $request->headers->get('Authorization');
        if (!$authorizationHeader || !str_starts_with($authorizationHeader, 'Bearer ')) {
            $response = new JsonResponse(['status' => 'error', 'message' => 'JWT not provided.'], 401);
            $event->setResponse($response);
            return;
        }
        
        // Extraer el token puro
        $jwt = substr($authorizationHeader, 7); 
        $secret = $this->jwtSecret; // Usamos el valor inyectado

        try {
            // 3. Validación: Verifica la firma y la expiración. Si falla, lanza una excepción.
            // JWT::decode(token, key, algorithms)
            JWT::decode($jwt, new Key($secret, 'HS256'));
    
        } catch (\Exception $e) {
            // 4. Fallo de Seguridad: Detener el flujo y responder con error 403/401
            $response = new JsonResponse([
                'status' => 'error', 
                'message' => 'Invalid or expired JWT: ' . $e->getMessage()
            ], 403);
            $event->setResponse($response);
        }
    }

    public static function getSubscribedEvents(): array
    {
        return [
            RequestEvent::class => 'onRequestEvent',
        ];
    }
}
