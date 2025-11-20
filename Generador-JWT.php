<?php
// Generador-JWT.php

// 1. Cargar el autoloader de Composer para acceder a la librería Firebase\JWT
// Asegúrate de que esta ruta sea correcta para tu entorno (depende de dónde ejecutes el script).
require 'vendor/autoload.php';

use Firebase\JWT\JWT;

// 2. Definir la CLAVE SECRETA (Debe ser IDÉNTICA a la de tu archivo .env de Symfony)
// Nota: En un entorno de producción, esta clave DEBE ser leída de un archivo de configuración seguro.
// Para esta prueba, asegúrate de que el valor coincida con tu JWT_SECRET.
$secretKey = '2af38f5679d1f614f8ed9bfd9241b58e'; // <--- USA TU CLAVE REAL AQUÍ


// 3. Definir la Carga Útil (Payload)
$now = time();
$expirationTime = $now + (60 * 60); // El token será válido por 1 hora (60 minutos * 60 segundos)

$payload = [
    // (iat) Issued At: Momento en que el token fue emitido
    'iat' => $now, 
    // (exp) Expiration Time: Momento en que el token expira (es inválido)
    'exp' => $expirationTime,
    // (nbf) Not Before: Momento en que el token es válido
    'nbf' => $now,
    
    // (Opcional, para identificar la aplicación cliente)
    'aud' => 'web-legada-cliente', 
    'iss' => 'orquestador-symfony',
];

// 4. Generar el JWT
$jwt = JWT::encode(
    $payload,      // Los datos
    $secretKey,    // La clave secreta para firmar
    'HS256'        // El algoritmo de cifrado (debe coincidir con el usado en el Back)
);

echo "JWT Generado:\n";
echo $jwt . "\n";
echo "Válido hasta: " . date('Y-m-d H:i:s', $expirationTime) . "\n";

?>