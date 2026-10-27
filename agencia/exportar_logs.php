<?php
// agencia/exportar_logs.php
// Exporta los logs de inicio de sesión (con sus acciones) a Excel o PDF usando los mismos filtros de ver_logs.php
require_once __DIR__ . '/../session_config.php'; session_start();
date_default_timezone_set('America/Mexico_City');

if (!isset($_SESSION['usuario_id'])) {
    header("Location: ../login.php");
    exit;
}
// Solo el superadmin puede exportar los logs
if (($_SESSION['rol'] ?? '') !== 'superadmin') {
    header("Location: opAdmin.php");
    exit;
}

require __DIR__ . '/../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;

set_time_limit(300);
ini_set('memory_limit', '512M');

$formato = $_GET['formato'] ?? '';
if (!in_array($formato, ['excel', 'pdf'], true)) {
    die("Formato no válido");
}

$conn = new mysqli("65.99.205.112", "noreplyc_alexsanchez09", "SWn_VrAc3K7lq;LS", "noreplyc_crmregistroventas");
if ($conn->connect_error) die("Error de conexión");
$conn->set_charset("utf8mb4");

require_once __DIR__ . '/logs_filtros.php';
require_once __DIR__ . '/logs_reporte.php';

[$logs, $accionesPorLogin] = cargarLogsConAcciones($conn, $where, $params, $types);

$etiquetaAccion = 'etiquetaAccion';
$soloAcciones   = 'soloAcciones';
$fmt            = 'fechaLog';

$rango = rangoTexto($filtroFechaInicio, $filtroFechaFin);
$nombreArchivo = 'Logs_Login_' . $filtroFechaInicio . ($filtroFechaInicio !== $filtroFechaFin ? '_a_' . $filtroFechaFin : '');

// ============================================================
// EXCEL: hoja "Logins" + hoja "Acciones"
// ============================================================
if ($formato === 'excel') {
    $spreadsheet = new Spreadsheet();
    $estiloEncabezado = [
        'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
        'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '212529']],
        'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
    ];

    // Hoja 1: un renglón por inicio de sesión
    $hoja = $spreadsheet->getActiveSheet();
    $hoja->setTitle('Logins');
    $hoja->setCellValue('A1', "Logs de actividades - $rango");
    $hoja->getStyle('A1')->getFont()->setBold(true)->setSize(14);
    $encabezados = ['Fecha','Origen', 'Estado', 'Correo', 'IP', 'Ubicación', 'Dispositivo', 'Navegador', 'Motivo fallo', 'Acciones', 'Cerró sesión', 'Última acción', 'Fecha última acción'];
    $hoja->fromArray($encabezados, null, 'A3');
    $hoja->getStyle('A3:M3')->applyFromArray($estiloEncabezado);

    $fila = 4;
    foreach ($logs as $log) {
        $lista   = $accionesPorLogin[$log['id']] ?? [];
        $propias = $soloAcciones($lista);
        $ultima  = end($propias) ?: null;
        $cerro   = (bool)array_filter($lista, fn($a) => $a['accion'] === 'LOGOUT');
        $hoja->fromArray([
            $fmt($log['fecha_intento']),
            ucfirst($log['origen']),
            $log['exito'] ? 'OK' : 'Fallo',
            $log['correo'],
            $log['ip'],
            trim(($log['pais'] ?? '') . ' ' . ($log['ciudad'] ?? '')),
            $log['dispositivo'] ?? '',
            $log['navegador'] ?? '',
            $log['motivo_fallo'] ?? '',
            $log['exito'] ? count($propias) : '',
            $log['exito'] ? ($cerro ? 'Sí' : 'No') : '',
            $ultima ? $etiquetaAccion($ultima['accion']) . ' ' . ($ultima['tabla'] ?? '') : ($log['exito'] ? 'No hay acciones' : ''),
            $ultima ? $fmt($ultima['fecha']) : '',
        ], null, "A$fila");
        if (!$log['exito']) {
            $hoja->getStyle("C$fila")->getFont()->getColor()->setRGB('DC3545');
        }
        $fila++;
    }
    if (!$logs) {
        $hoja->setCellValue('A4', 'No hay registros para los filtros seleccionados');
    }
    foreach (range('A', 'M') as $col) {
        $hoja->getColumnDimension($col)->setAutoSize(true);
    }
    $hoja->freezePane('A4');
    $hoja->setAutoFilter('A3:M' . max(3, $fila - 1));

    // Hoja 2: un renglón por acción realizada en cada sesión
    $hoja2 = $spreadsheet->createSheet();
    $hoja2->setTitle('Acciones');
    $hoja2->setCellValue('A1', "Acciones por inicio de sesión - $rango");
    $hoja2->getStyle('A1')->getFont()->setBold(true)->setSize(14);
    $hoja2->fromArray(['Correo', 'Origen', 'Fecha login', 'Fecha acción', 'Acción', 'Tabla', 'Registro', 'Detalle'], null, 'A3');
    $hoja2->getStyle('A3:H3')->applyFromArray($estiloEncabezado);

    $fila = 4;
    foreach ($logs as $log) {
        foreach ($accionesPorLogin[$log['id']] ?? [] as $a) {
            $hoja2->fromArray([
                $log['correo'],
                ucfirst($log['origen']),
                $fmt($log['fecha_intento']),
                $fmt($a['fecha']),
                $etiquetaAccion($a['accion']),
                $a['tabla'] ?? '',
                $a['registro_id'] ?? '',
                $a['accion'] === 'LOGOUT' ? '' : detalleTextoPlano($a['detalle']),
            ], null, "A$fila");
            $fila++;
        }
    }
    if ($fila === 4) {
        $hoja2->setCellValue('A4', 'No hay acciones para los filtros seleccionados');
    }
    foreach (range('A', 'G') as $col) {
        $hoja2->getColumnDimension($col)->setAutoSize(true);
    }
    $hoja2->getColumnDimension('H')->setWidth(80);
    $hoja2->getStyle('H4:H' . max(4, $fila - 1))->getAlignment()->setWrapText(true);
    $hoja2->getStyle('A4:G' . max(4, $fila - 1))->getAlignment()->setVertical(Alignment::VERTICAL_TOP);
    $hoja2->freezePane('A4');
    $hoja2->setAutoFilter('A3:H' . max(3, $fila - 1));

    $spreadsheet->setActiveSheetIndex(0);

    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment;filename="' . $nombreArchivo . '.xlsx"');
    header('Cache-Control: max-age=0');
    (new Xlsx($spreadsheet))->save('php://output');
    exit;
}

// ============================================================
// PDF: cada inicio de sesión seguido de sus acciones (ver logs_reporte.php)
// ============================================================
header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="' . $nombreArchivo . '.pdf"');
header('Cache-Control: max-age=0');
echo generarPdfLogs($logs, $accionesPorLogin, $rango);
