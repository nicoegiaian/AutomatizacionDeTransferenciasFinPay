<?php
error_reporting(0);
date_default_timezone_set("America/Argentina/Buenos_Aires");
header('Content-Type: text/html; charset=utf-8');

//defines de las Tablas de Bases de Datos - ORIGEN
define("DB_SERVER", "localhost");
define("DB_PORT", "3306");
define("DB_NAME", "u156146850_prepdigitales");
define("DB_USER", "u156146850_prepdigitales");
define("DB_PASSWORD", "MPmO6c8wDBI3");
define("START_YEAR", 2024);

//defines de las Tablas de Bases de Datos - DESTINO (Migración)
define("DB_SERVER_DEST", "localhost");
define("DB_PORT_DEST", "3306");
define("DB_NAME_DEST", "u156146850_portal_pd");
define("DB_USER_DEST", "u156146850_portal_pd");
define("DB_PASSWORD_DEST", "53;N(ll3<4d;");
//defines del Servidor SMTP.
define("SMTP_SERVER", "smtp.hostinger.com");
define("SMTP_USERNAME", "notificaciones@pagosdigitales.com.ar");
define("SMTP_PASSWORD", "6lmR.17777ncs");
define("SMTP_SECURE", "ssl");
define("SMTP_PORT", 465);

//defines de la API PMC SANDBOX.
/*define("ACCESS_TOKEN_URL_PMC", "https://api-homo.prismamediosdepago.com/v1/oauth/accesstoken?grant_type=client_credentials");
define("API_KEY_PUBLICA_PMC", "a5507109-95f6-4964-a2e7-5511cbf4d26b");
define("API_KEY_PRIVADA_PMC", "ab1a6987-6325-4fc6-b057-f071d49bd2fd");
define("API_URL_PMC", "https://api-homo.prismamediosdepago.com");*/
define("ACCESS_TOKEN_URL_PMC", "https://api.prismamediosdepago.com/v1/oauth/accesstoken?grant_type=client_credentials");
define("API_KEY_PUBLICA_PMC", "a9ff6ea5-b694-4aef-bf5c-ede93352337f");
define("API_KEY_PRIVADA_PMC", "9a063d97-5ce6-4487-aeba-d1781fc22721");
define("API_URL_PMC", "https://api.prismamediosdepago.com");
define("CONSUMER_ID", "MBAD");
define("CHANNEL", "X");
define("TERMINAL", "PEMB111100000000");
define("ORIGIN", "1");
define("COMPANIES_PATH", "/home/u156146850/domains/prepagasdigitales.com.ar/pmc");
define("PRISMA_PATH", "/home/u156146850/domains/prepagasdigitales.com.ar/prisma");
define("COMISION_MP", 0.0699);

define('SCRIPT_ROOT', "https://" . $_SERVER["HTTP_HOST"] . "/");
?>