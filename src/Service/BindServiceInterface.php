<?php
// src/Service/BindServiceInterface.php

namespace App\Service;

// La interfaz debe contener todos los métodos que el TransferManager llama.
interface BindServiceInterface 
{
    // La firma (nombre y tipos de argumentos/retorno) debe coincidir con BindService.


    public function transferToThirdParty(string $cbuDestino, float $monto, ?string $cvuOrigen = null): array;

    public function getAccountBalance(string $cvu): float;

    // Si hay más métodos públicos usados por TransferManager, deben ir aquí.
}