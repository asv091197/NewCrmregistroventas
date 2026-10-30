<?php
require_once __DIR__ . '/../includes/db.php';
// cron/enviar_reporte_logs.php
// Envía por correo el PDF de logs de actividades de los últimos 7 días (de viernes a jueves).
//
// Uso (solo línea de comandos / cron del hosting):
//   php enviar_reporte_logs.php                         -> envía el reporte a los destinatarios configurados
//   php enviar_reporte_logs.php --para=otro@correo.com  -> envía a otro correo (útil para probar)
//
// Ejemplo de cron en cPanel (cada viernes a las 00:00):
//   0 0 * * 5 /usr/local/bin/php /home/crmregis/public_html/cron/enviar_reporte_logs.php

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Solo se puede ejecutar desde la línea de comandos.\n");
}

date_default_timezone_set('America/Mexico_City');
set_time_limit(0);
ini_set('memory_limit', '512M');

$destinatarios = ['a.sanchez@grupoideasmx.com', 'gerencia@grupoideasmx.com'];

$opciones = getopt('', ['para:']);
if (!empty($opciones['para'])) {
    $destinatarios = array_map('trim', explode(',', $opciones['para']));
}
foreach ($destinatarios as $correo) {
    if (!filter_var($correo, FILTER_VALIDATE_EMAIL)) {
        exit("Correo no válido: $correo\n");
    }
}

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../libs/PHPMailer/Exception.php';
require __DIR__ . '/../libs/PHPMailer/PHPMailer.php';
require __DIR__ . '/../libs/PHPMailer/SMTP.php';

use PHPMailer\PHPMailer\PHPMailer;

// Datos SMTP: primero el .env fuera de public_html (/home/usuario/.env), si no existe usa facturacion/.env
$rutaEnv = file_exists(__DIR__ . '/../../.env') ? __DIR__ . '/../../.env' : __DIR__ . '/../facturacion/.env';
$env = [];
foreach (@file($rutaEnv, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $linea) {
    $linea = trim($linea);
    if ($linea === '' || $linea[0] === '#' || !str_contains($linea, '=')) continue;
    [$clave, $valor] = explode('=', $linea, 2);
    $env[trim($clave)] = trim(trim($valor), '"\'');
}
foreach (['SMTP_HOST', 'SMTP_USERNAME', 'SMTP_PASSWORD', 'SMTP_PORT'] as $clave) {
    if (empty($env[$clave])) {
        fwrite(STDERR, "Falta $clave en $rutaEnv\n");
        exit(1);
    }
}

// Periodo: los 7 días anteriores a hoy (si corre el viernes 00:00 -> viernes pasado a jueves)
$_GET = [
    'fecha_inicio' => date('Y-m-d', strtotime('-7 days')),
    'fecha_fin'    => date('Y-m-d', strtotime('-1 day')),
];

try {
    $conn = conexionPDO();
} catch (PDOException $e) {
    fwrite(STDERR, "Error de conexión: " . $e->getMessage() . "\n");
    exit(1);
}

require __DIR__ . '/../agencia/logs_filtros.php';
require __DIR__ . '/../agencia/logs_reporte.php';

echo "[" . date('Y-m-d H:i:s') . "] Reporte de logs del $filtroFechaInicio al $filtroFechaFin\n";

[$logs, $accionesPorLogin] = cargarLogsConAcciones($conn, $where, $params);
$rango   = rangoTexto($filtroFechaInicio, $filtroFechaFin);
$resumen = resumenLogs($logs, $accionesPorLogin);
$pdf     = generarPdfLogs($logs, $accionesPorLogin, $rango);
$archivo = "Logs_Actividades_{$filtroFechaInicio}_a_{$filtroFechaFin}.pdf";

$cuerpo = '
<div style="font-family:Arial,sans-serif;font-size:14px;color:#212529;">
    <h2 style="margin-bottom:4px;">Reporte semanal de logs de actividades</h2>
    <p style="margin-top:0;color:#6c757d;">Generado automáticamente el ' . date('d/m/Y H:i') . '</p>

    <h3 style="margin-bottom:4px;">Filtros aplicados</h3>
    <table cellpadding="4" style="border-collapse:collapse;">
        <tr><td><b>Periodo</b></td><td>' . htmlspecialchars($rango) . '</td></tr>
        <tr><td><b>Origen</b></td><td>Todos (Agencia y Facturación)</td></tr>
        <tr><td><b>Estado</b></td><td>Todos (Exitosos y Fallidos)</td></tr>
        <tr><td><b>Actividad</b></td><td>Todos (Con y sin actividad)</td></tr>
        <tr><td><b>Correo</b></td><td>Todos</td></tr>
    </table>

    <h3 style="margin-bottom:4px;">Resumen</h3>
    <table cellpadding="4" style="border-collapse:collapse;">
        <tr><td><b>Inicios de sesión</b></td><td>' . $resumen['registros'] . '</td></tr>
        <tr><td><b>Exitosos</b></td><td style="color:#198754;">' . $resumen['exitosos'] . '</td></tr>
        <tr><td><b>Fallidos</b></td><td style="color:#dc3545;">' . $resumen['fallidos'] . '</td></tr>
        <tr><td><b>Acciones realizadas</b></td><td>' . $resumen['acciones'] . '</td></tr>
        <tr><td><b>Usuarios distintos</b></td><td>' . $resumen['usuarios'] . '</td></tr>
    </table>

    <p>El detalle de cada inicio de sesión y sus acciones va en el PDF adjunto.</p>
</div>';

$mail = new PHPMailer(true);
try {
    $mail->isSMTP();
    $mail->Host       = $env['SMTP_HOST'];
    $mail->SMTPAuth   = true;
    $mail->Username   = $env['SMTP_USERNAME'];
    $mail->Password   = $env['SMTP_PASSWORD'];
    $mail->Port       = (int)$env['SMTP_PORT'];
    $mail->SMTPSecure = $mail->Port === 465 ? PHPMailer::ENCRYPTION_SMTPS : PHPMailer::ENCRYPTION_STARTTLS;
    $mail->CharSet    = 'UTF-8';

    $mail->setFrom($env['SMTP_USERNAME'], 'CRM Registro Ventas');
    foreach ($destinatarios as $correo) {
        $mail->addAddress($correo);
    }
    $mail->isHTML(true);
    $mail->Subject = "Reporte semanal de logs de actividades ($rango)";
    $mail->Body    = $cuerpo;
    $mail->AltBody = "Reporte semanal de logs de actividades ($rango)\n"
        . "Inicios de sesión: {$resumen['registros']} | Exitosos: {$resumen['exitosos']} | Fallidos: {$resumen['fallidos']} | Acciones: {$resumen['acciones']}\n"
        . "El detalle va en el PDF adjunto.";
    $mail->addStringAttachment($pdf, $archivo, PHPMailer::ENCODING_BASE64, 'application/pdf');

    $mail->send();
} catch (Throwable $e) {
    fwrite(STDERR, "Error al enviar el correo: " . $mail->ErrorInfo . " " . $e->getMessage() . "\n");
    exit(1);
}

echo "Enviado a " . implode(', ', $destinatarios) . " ({$resumen['registros']} inicios de sesión, {$resumen['acciones']} acciones, PDF " . round(strlen($pdf) / 1024) . " KB)\n";
