<?php

// src/Controller/TransferController.php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use App\Service\TransferManager;

class TransferController extends AbstractController
{
    // Usamos el atributo #[Route] para definir el endpoint (la URL)
    #[Route('/transfers/execute', name: 'app_execute_transfer', methods: ['POST'])]
    public function executeTransfer(Request $request, TransferManager $transferManager): JsonResponse
    {
        // El Controller solo verifica que el Body de la petición exista y luego delega.
        $data = json_decode($request->getContent(), true);

        if (!$data) {
            return $this->json(['status' => 'error', 'message' => 'Invalid JSON body.'], 400);
        }

        // 1. Validar la entrada (Asumo que la fecha viene en 'fecha_liquidacion')
        $fechaLiquidacionDDMMAA = $data['fecha_liquidacion'] ?? null;
        if (!$fechaLiquidacionDDMMAA) {
            return $this->json(['status' => 'error', 'message' => 'Missing fecha_liquidacion in request body.'], 400);
        }

        try {
            // 2. Aquí llamamos al TransferManager Service (Lógica del PULL)
            // Se inicia el DEBIN PULL y se registra en la tabla 'debin_seguimiento'.
            $result = $transferManager->executeDebinPull($fechaLiquidacionDDMMAA);
            
            // 3. Devolvemos la respuesta
            if ($result['status'] === 'debin_initiated') {
                return $this->json([
                    'status' => 'success', 
                    'message' => 'DEBIN PULL iniciado exitosamente. Monitoreo asíncrono en curso.',
                    'debin_id' => $result['id_debin']
                ]);
            }
            
            // Caso donde no hay montos a liquidar (desde el Manager)
            if ($result['status'] === 'info') {
                 return $this->json(['status' => 'info', 'message' => $result['message']]);
            }

            // Para cualquier otro resultado (aunque idealmente no debería ocurrir)
            return $this->json($result, 500);

        } catch (\Exception $e) {
            // Manejo de excepciones (ej. fallo de conexión a BIND en la llamada inicial)
            return $this->json([
                'status' => 'error', 
                'message' => 'Fallo al iniciar el DEBIN PULL con BIND o BD.',
                'details' => $e->getMessage()
            ], 500);
        }
    }
}
