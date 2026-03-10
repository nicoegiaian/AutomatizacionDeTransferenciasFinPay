<?php
/**
 * Script de Sincronización Diaria e Histórica - PRISMA
 * Ubicación: /public_html/scripts/daily_prisma_sync.php
 */

// 1. Bloqueo de seguridad: CLI únicamente
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    die("Acceso denegado. Este script solo puede ejecutarse en modo batch.");
}

// 2. Ajuste de directorio
chdir(__DIR__);
require_once("../includes/constants.php");
require_once("../api/Controller/WebApiController.php");

// 3. Función auxiliar para interpretar el formato DDMMAA
function parseDDMMAA($fechaStr) {
    if (strlen($fechaStr) !== 6) return false;
    $d = substr($fechaStr, 0, 2);
    $m = substr($fechaStr, 2, 2);
    $y = '20' . substr($fechaStr, 4, 2); // Asume años 2000+
    return strtotime("$y-$m-$d");
}

// 4. Lógica de Parámetros (Cron vs Manual)
if ($argc >= 3) {
    // Modo Rango (ej: php daily_prisma_sync.php 170226 250226)
    $startTs = parseDDMMAA($argv[1]);
    $endTs = parseDDMMAA($argv[2]);
    if (!$startTs || !$endTs || $startTs > $endTs) {
        die("Error: Parámetros inválidos. Use DDMMAA DDMMAA (ej: php daily_prisma_sync.php 170226 250226)\n");
    }
    $modo = "RANGO MANUAL";
} elseif ($argc == 2) {
    // Modo Día Único (ej: php daily_prisma_sync.php 170226)
    $startTs = parseDDMMAA($argv[1]);
    $endTs = $startTs;
    if (!$startTs) {
        die("Error: Parámetro inválido. Use DDMMAA (ej: php daily_prisma_sync.php 170226)\n");
    }
    $modo = "DIA MANUAL";
} else {
    // Modo Cron Diario (Ayer)
    $startTs = strtotime("-1 day");
    $endTs = $startTs;
    $modo = "CRON DIARIO";
}

// 5. Inicialización de variables para el reporte
$resumenProceso = "";
$procesadosOk = 0;
$procesadosError = 0;
$process = "MOVEMENTS";

if (session_status() === PHP_SESSION_NONE) {
    @session_start(); 
}
$_SESSION["IdUsuario"] = 1;

// 6. Bucle de procesamiento por día
$currentTs = $startTs;
while ($currentTs <= $endTs) {
    $dia   = date("d", $currentTs);
    $mes   = date("m", $currentTs);
    $anio  = date("Y", $currentTs);
    $fechaFormateada = date("d-m-Y", $currentTs);
    
    $fileName = "consolidated_mov_" . $fechaFormateada . ".json";
    $destDirectory = PRISMA_PATH . "/" . strtolower($process) . "/$anio/$mes";
    
    if (!is_dir(PRISMA_PATH . "/" . strtolower($process) . "/$anio")) {
        mkdir(PRISMA_PATH . "/" . strtolower($process) . "/$anio", 0777, true);
    }
    if (!is_dir($destDirectory)) {
        mkdir($destDirectory, 0777, true);
    }

    try {
        $request = new WebApiController('RetrieveFile', 0, '', $fileName, $process);
        
        ob_start();
        $request->processRequest();
        ob_end_clean(); // Evitamos que el JSON ensucie la memoria/mail
        
        $resumenProceso .= "[✓] $fechaFormateada: Archivo guardado correctamente en $destDirectory/$fileName\n";
        $procesadosOk++;
    } catch (Exception $e) {
        $resumenProceso .= "[X] $fechaFormateada: ERROR - " . $e->getMessage() . "\n";
        $procesadosError++;
    }

    // Avanzamos al día siguiente
    $currentTs = strtotime("+1 day", $currentTs);
}

// 7. Envío de Notificación Unificada
$to = "nicolas.egiaian@gmail.com";

if ($startTs === $endTs) {
    $fechaUnica = date("d-m-Y", $startTs);
    $subject = "[".($procesadosError == 0 ? "EXITO" : "ERROR")."] Sync Prisma - $fechaUnica ($modo)";
} else {
    $fechaDesde = date("d-m-Y", $startTs);
    $fechaHasta = date("d-m-Y", $endTs);
    $subject = "[".($procesadosError == 0 ? "EXITO" : "ERROR")."] Sync Prisma Rango: $fechaDesde al $fechaHasta";
}

$headers = "From: OrquestadorDePagos@gmail.com\r\n"; 
$headers .= "Reply-To: OrquestadorDePagos@gmail.com\r\n";
$headers .= "Content-Type: text/plain; charset=UTF-8\r\n";

$body = "Reporte de proceso Batch ($modo):\n\n";
$body .= "Resumen general:\n";
$body .= "- Descargas exitosas: $procesadosOk\n";
$body .= "- Descargas fallidas: $procesadosError\n\n";
$body .= "Detalle de operaciones:\n";
$body .= $resumenProceso;

mail($to, $subject, $body, $headers);

echo "Proceso finalizado. OK: $procesadosOk, Errores: $procesadosError. Correo enviado a $to.\n";
?>