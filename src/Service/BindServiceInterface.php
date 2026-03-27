<?php
// src/Service/BindServiceInterface.php

namespace App\Service;

// La interfaz debe contener todos los métodos que el TransferManager llama.
interface BindServiceInterface 
{
    // La firma (nombre y tipos de argumentos/retorno) debe coincidir con BindService.


    public function transferToThirdParty(string $cbuDestino, float $monto, ?string $cvuOrigen = null): array;

    public function getAccountBalance(string $cvu): float;

    public function getMovimientos(string $fechaDesde, string $fechaHasta, int $pageNumber = 0, int $pageSize = 500): array;

    public function existeTransferenciaPrevia(string $cbuDestino, float $monto, string $fecha): ?array;
}