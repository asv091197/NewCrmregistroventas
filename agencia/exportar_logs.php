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

$limite = 5000;
$sql  = "SELECT * FROM logs_login WHERE " . implode(' AND ', $where) . " ORDER BY fecha_intento DESC LIMIT $limite";
$stmt = $conn->prepare($sql);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$logs = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Acciones de cada login exitoso, agrupadas por login_id
$accionesPorLogin = [];
$idsExitosos = array_map('intval', array_column(array_filter($logs, fn($l) => $l['exito']), 'id'));
foreach (array_chunk($idsExitosos, 1000) as $ids) {
    try {
        $res = $conn->query("
            SELECT * FROM logs_acciones
            WHERE login_id IN (" . implode(',', $ids) . ")
            ORDER BY login_id, fecha, id
        ");
        while ($r = $res->fetch_assoc()) {
            $accionesPorLogin[$r['login_id']][] = $r;
        }
    } catch (Throwable $e) {
        error_log("exportar_logs acciones: " . $e->getMessage());
    }
}

$etiquetaAccion = fn($accion) => $accion === 'LOGOUT' ? 'CIERRE DE SESIÓN' : ($etiquetas[$accion] ?? $accion);
$soloAcciones   = fn($lista) => array_values(array_filter($lista, fn($a) => $a['accion'] !== 'LOGOUT'));
$fmt            = fn($fecha) => date('d/m/Y H:i:s', strtotime($fecha));

$rango = $filtroFechaInicio === $filtroFechaFin
    ? date('d/m/Y', strtotime($filtroFechaInicio))
    : date('d/m/Y', strtotime($filtroFechaInicio)) . ' al ' . date('d/m/Y', strtotime($filtroFechaFin));
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
// PDF: cada inicio de sesión seguido de sus acciones
// ============================================================
$pdf = new TCPDF('L', PDF_UNIT, 'LETTER', true, 'UTF-8', false);
$pdf->SetCreator('CRM Registro Ventas');
$pdf->SetTitle("Logs de actividades - $rango");
$pdf->setPrintHeader(false);
$pdf->setFooterData([0, 0, 0], [255, 255, 255]);
$pdf->SetMargins(10, 10, 10);
$pdf->SetAutoPageBreak(true, 15);
$pdf->SetFont('dejavusans', '', 8);
$pdf->AddPage();

$h = fn($v) => htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8');
$coloresHex = ['INSERT' => '#198754', 'UPDATE' => '#0d6efd', 'DELETE' => '#dc3545', 'LOGOUT' => '#6c757d'];

$totalExitosos = count(array_filter($logs, fn($l) => $l['exito']));
$totalAcciones = array_sum(array_map(fn($l) => count($soloAcciones($l)), $accionesPorLogin));
$pdf->writeHTML('
    <h2>Logs de actividades</h2>
    <div>Periodo: <b>' . $h($rango) . '</b> &nbsp;·&nbsp; Registros: <b>' . count($logs) . '</b>
    &nbsp;·&nbsp; Exitosos: <b>' . $totalExitosos . '</b> &nbsp;·&nbsp; Fallidos: <b>' . (count($logs) - $totalExitosos) . '</b>
    &nbsp;·&nbsp; Acciones: <b>' . $totalAcciones . '</b>
    &nbsp;·&nbsp; Generado: ' . date('d/m/Y H:i') . '</div><br>', true, false, true, false, '');

if (!$logs) {
    $pdf->writeHTML('<p><i>No hay registros para los filtros seleccionados</i></p>');
}

foreach ($logs as $log) {
    $lista   = $accionesPorLogin[$log['id']] ?? [];
    $propias = $soloAcciones($lista);
    $estado  = $log['exito']
        ? '<span style="color:#198754;"><b>OK</b></span>'
        : '<span style="color:#dc3545;"><b>FALLO</b></span> ' . $h($log['motivo_fallo']);

    $html = '<table cellpadding="3" border="0" style="background-color:#e9ecef;">
        <tr>
            <td width="13%"><b>' . $fmt($log['fecha_intento']) . '</b></td>
            <td width="9%">' . $h(ucfirst($log['origen'])) . '</td>
            <td width="26%">' . $h($log['correo']) . '</td>
            <td width="22%">' . $estado . '</td>
            <td width="13%">IP ' . $h($log['ip']) . '</td>
            <td width="17%">' . $h($log['dispositivo']) . ' / ' . $h($log['navegador']) . '</td>
        </tr>
    </table>';

    if ($log['exito']) {
        if (!$propias) {
            $html .= '<div style="color:#6c757d;"><i>&nbsp;&nbsp;&nbsp;No hay acciones</i></div>';
        }
        if ($lista) {
            $html .= '<table cellpadding="2" border="0">';
            foreach ($lista as $a) {
                $detalle = $a['accion'] === 'LOGOUT' ? '' : formatearDetalle($a['detalle']);
                $html .= '<tr>
                    <td width="3%"></td>
                    <td width="12%" style="color:#6c757d;">' . $fmt($a['fecha']) . '</td>
                    <td width="13%" style="color:' . ($coloresHex[$a['accion']] ?? '#6c757d') . ';"><b>' . $h($etiquetaAccion($a['accion'])) . '</b></td>
                    <td width="14%">' . $h($a['tabla']) . ($a['registro_id'] !== null ? ' #' . $h($a['registro_id']) : '') . '</td>
                    <td width="58%" style="font-size:7px;">' . $detalle . '</td>
                </tr>';
            }
            $html .= '</table>';
        }
    }
    $pdf->writeHTML($html . '<br>', true, false, true, false, '');
}

$pdf->Output($nombreArchivo . '.pdf', 'D');
