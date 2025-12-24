<?php
// test_email_manual.php

// Cargamos el autoloader de Composer para poder usar PHPMailer
require 'vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

$mail = new PHPMailer(true);

try {
    echo "--- INICIANDO PRUEBA DE EMAIL MANUAL ---\n";

    // Configuración de Debug
    $mail->SMTPDebug = SMTP::DEBUG_CONNECTION; // Muestra todo el diálogo cliente-servidor
    $mail->Debugoutput = function($str, $level) {
        echo "DEBUG: $str\n";
    };

    // Configuración del Servidor
    $mail->isSMTP();
    $mail->Host       = 'smtp.gmail.com';
    $mail->SMTPAuth   = true;
    $mail->Username   = 'pagosdigitales.sa@gmail.com'; // Tu usuario real
    $mail->Password   = 'lypfulkoinhgnjrt'; // <--- PONE TU CLAVE ACÁ
    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS; // TLS explícito
    $mail->Port       = 587;

    // Destinatarios
    $mail->setFrom('pagosdigitales.sa@gmail.com', 'Prueba Manual');
    $mail->addAddress('pagosdigitales.sa@gmail.com'); // Te lo auto-envías para probar

    // Contenido
    $mail->isHTML(false);
    $mail->Subject = 'Prueba Manual SMTP';
    $mail->Body    = 'Si lees esto, las credenciales funcionan correctamente.';

    $mail->send();
    echo "\n--- ÉXITO: El correo se envió correctamente. ---\n";

} catch (Exception $e) {
    echo "\n--- ERROR: No se pudo enviar el correo. ---\n";
    echo "Mailer Error: {$mail->ErrorInfo}\n";
}