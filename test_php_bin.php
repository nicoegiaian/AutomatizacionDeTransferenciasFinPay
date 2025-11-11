<?php
// test_php_bin.php

$logFile = __DIR__ . '/test_cron_log.txt';
$timestamp = date('Y-m-d H:i:s');

// Intenta escribir en un archivo para confirmar la ejecución
if (file_put_contents($logFile, "Script ejecutado correctamente el: " . $timestamp . "\n", FILE_APPEND) !== false) {
    // Si la escritura es exitosa, el script corrió.
    // Opcional: podrías agregar aquí la llamada a la función mail()
    // mail('tu_correo@ejemplo.com', 'PHP Bin Test OK', 'El script se ejecutó a las ' . $timestamp);
    echo "Prueba completada, revisa el archivo de log.\n";
} else {
    echo "ERROR: El script corrió, pero falló al escribir en el log. Revisa permisos.\n";
}
?>