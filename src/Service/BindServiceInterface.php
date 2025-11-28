<?php
// src/Service/BindServiceInterface.php

namespace App\Service;

// La interfaz debe contener todos los métodos que el TransferManager llama.
interface BindServiceInterface 
{
    // La firma (nombre y tipos de argumentos/retorno) debe coincidir con BindService.
    public function initiateDebinPull(float $monto, string $referencia): array;

    public function getDebinStatusById(string $debinId): array;

    public function transferToThirdParty(string $cbuDestino, float $monto): array;

    // Si hay más métodos públicos usados por TransferManager, deben ir aquí.
}