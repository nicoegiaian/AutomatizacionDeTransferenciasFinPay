<?php
// src/Service/MockBindService.php

namespace App\Service;

// Debe implementar los mismos métodos públicos que BindService
class MockBindService implements BindServiceInterface  // Es buena práctica crear una interfaz
{
    private string $scenario;
    // Sobrescribimos el constructor para que no necesite las credenciales reales
    public function __construct(string $scenario)
    {
        // El httpClient es null, las credenciales son vacías.
        $this->scenario = $scenario;
    }

    // SCENARIO 1: Simulación de PULL exitoso
    public function initiateDebinPull(float $monto, string $referencia): array
    {
        // El escenario define el resultado del PULL
        if ($this->scenario === 'PULL_RECHAZADO') {
            return ['idComprobante' => 'MOCK-' . $referencia, 'estadoId' => '3'];
        }
        if ($this->scenario === 'PULL_PROCESAR') {
            return ['idComprobante' => 'MOCK-' . $referencia, 'estadoId' => '1'];
        }
        if ($this->scenario === 'PULL_CONSULTAR') {
            return ['idComprobante' => 'MOCK-' . $referencia, 'estadoId' => '4'];
        }
        if ($this->scenario === 'PULL_AUDITAR') {
            return ['idComprobante' => 'MOCK-' . $referencia, 'estadoId' => '5'];
        }
        // Comportamiento por defecto
        return ['idComprobante' => 'MOCK-' . $referencia, 'estadoId' => '2'];
    }

    // ... (El resto de métodos usa $this->scenario para decidir el resultado)


    // SCENARIO 2: Simulación de Monitoreo Fallido
    public function getDebinStatusById(string $debinId): array
    {
        // Simular que el DEBIN fue RECHAZADO por el banco.
        return ['id' => $debinId, 'descripcion' => 'MOCK FAIL', 'estado' => 'UNKNOWN_FOREVER'];
    }
    
    // SCENARIO 3: Simulación de Transferencia PUSH Exitosa
    public function transferToThirdParty(string $cbuDestino, float $monto): array
    {
        // Devolvemos la respuesta de éxito de BIND para la transferencia PUSH
        return [
            'comprobanteId' => 'PUSH-MOCK-OK-' . time(),
            'estado' => 'COMPLETADA',
            'coelsaId' => 'COELSA-MOCK-123'
        ];
    }
    
    // ... otros métodos
}