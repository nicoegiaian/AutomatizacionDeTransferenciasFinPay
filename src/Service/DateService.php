<?php
// src/Service/DateService.php

namespace App\Service;

use DateTime;
use DateTimeZone;
use Exception;

// Importa las constantes directamente (asumiendo que constants.php está en la misma carpeta del proyecto o en un lugar accesible)

class DateService
{
    private static array $FERIADOS = []; // Usaremos una propiedad estática
    private string $projectLegacyDir;

    /**
     * Devuelve la fecha de proceso (el día anterior) en formato DDMMAAAA.
     * @param string $fechaBaseStr Fecha Y-m-d recibida por el orquestador.
     * @return DateTime Objeto DateTime para el día de proceso.
     */
    public function __construct(string $projectLegacyDir)
    {
        $this->projectLegacyDir = $projectLegacyDir;
        // 2. Cargamos las constantes SOLO después de tener la ruta.
        $this->loadConstants(); 
    }

    private function loadConstants(): void
    {
        // Concatenamos la ruta inyectada con el nombre del archivo
        $constantsPath = $this->projectLegacyDir . 'constants.php'; 
        
        if (!file_exists($constantsPath)) {
            // Este throw ahora es vital para detener el sistema si el archivo central falta
            throw new Exception("ERROR FATAL: No se encontró el archivo de constantes en la ruta: " . $constantsPath);
        }

        // Cargamos el archivo. Sus defines estarán disponibles globalmente.
        require_once $constantsPath;
        
        // 3. Asignamos la constante global al servicio
        if (defined('FERIADOS')) {
            self::$FERIADOS = \FERIADOS;
        } else {
            throw new Exception("La constante FERIADOS no está definida en el archivo: " . $constantsPath);
        }
    }

    public function getDiaProceso(string $fechaBaseStr): DateTime
    {
        // Se asume que la fecha base es YYYY-MM-DD
        $dt = DateTime::createFromFormat('Y-m-d', $fechaBaseStr, new DateTimeZone('America/Argentina/Buenos_Aires'));
        // Requerimiento 1: Invocamos a los procesos con la fecha del día ANTERIOR.
        $dt->modify('-1 day');
        return $dt;
    }

    /**
     * Verifica si una fecha dada es Sábado, Domingo, o Feriado.
     * @param DateTime $date
     * @return bool
     */
    public function esDiaHabil(DateTime $date): bool
    {
        $diaSemana = (int)$date->format('N'); // 6=Sábado, 7=Domingo
        if ($diaSemana >= 6) {
            return false;
        }

        // Revisar si es feriado en los formatos que usa la constante
        $formatos = ['Ymd', 'ymd', 'dmY', 'dmy'];
        foreach ($formatos as $format) {
            if (in_array($date->format($format), self::$FERIADOS)) {
                return false;
            }
        }
        return true;
    }
}