<?php
// agencia/logs_reporte.php
// Consulta de logs con sus acciones y generación del PDF.
// Compartido por exportar_logs.php y cron/enviar_reporte_logs.php.
// Requiere haber cargado antes vendor/autoload.php y logs_filtros.php (formatearDetalle, $etiquetas).

// Devuelve [$logs, $accionesPorLogin] para los filtros armados en logs_filtros.php
function cargarLogsConAcciones(mysqli $conn, array $where, array $params, string $types, int $limite = 5000): array {
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
            error_log("logs_reporte acciones: " . $e->getMessage());
        }
    }
    return [$logs, $accionesPorLogin];
}

function etiquetaAccion($accion) {
    global $etiquetas;
    return $accion === 'LOGOUT' ? 'CIERRE DE SESIÓN' : ($etiquetas[$accion] ?? $accion);
}

function soloAcciones(array $lista) {
    return array_values(array_filter($lista, fn($a) => $a['accion'] !== 'LOGOUT'));
}

function fechaLog($fecha) {
    return date('d/m/Y H:i:s', strtotime($fecha));
}

function rangoTexto($inicio, $fin) {
    return $inicio === $fin
        ? date('d/m/Y', strtotime($inicio))
        : date('d/m/Y', strtotime($inicio)) . ' al ' . date('d/m/Y', strtotime($fin));
}

// Totales para el encabezado del PDF y el cuerpo del correo
function resumenLogs(array $logs, array $accionesPorLogin): array {
    $exitosos = count(array_filter($logs, fn($l) => $l['exito']));
    return [
        'registros' => count($logs),
        'exitosos'  => $exitosos,
        'fallidos'  => count($logs) - $exitosos,
        'acciones'  => array_sum(array_map(fn($l) => count(soloAcciones($l)), $accionesPorLogin)),
        'usuarios'  => count(array_unique(array_column($logs, 'correo'))),
    ];
}

// PDF: cada inicio de sesión seguido de sus acciones. Devuelve el contenido del PDF como string.
function generarPdfLogs(array $logs, array $accionesPorLogin, string $rango): string {
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
    $coloresHex = ['INSERT' => '#198754', 'UPDATE' => '#0d6efd', 'DELETE' => '#dc3545', 'LOGOUT' => '#6c757d', 'EXPORT_EXCEL' => '#0aa2c0', 'EXPORT_PDF' => '#cc9a06'];

    $resumen = resumenLogs($logs, $accionesPorLogin);
    $pdf->writeHTML('
        <h2>Logs de actividades</h2>
        <div>Periodo: <b>' . $h($rango) . '</b> &nbsp;·&nbsp; Registros: <b>' . $resumen['registros'] . '</b>
        &nbsp;·&nbsp; Exitosos: <b>' . $resumen['exitosos'] . '</b> &nbsp;·&nbsp; Fallidos: <b>' . $resumen['fallidos'] . '</b>
        &nbsp;·&nbsp; Acciones: <b>' . $resumen['acciones'] . '</b>
        &nbsp;·&nbsp; Generado: ' . date('d/m/Y H:i') . '</div><br>', true, false, true, false, '');

    if (!$logs) {
        $pdf->writeHTML('<p><i>No hay registros para los filtros seleccionados</i></p>');
    }

    foreach ($logs as $log) {
        $lista   = $accionesPorLogin[$log['id']] ?? [];
        $propias = soloAcciones($lista);
        $estado  = $log['exito']
            ? '<span style="color:#198754;"><b>OK</b></span>'
            : '<span style="color:#dc3545;"><b>FALLO</b></span> ' . $h($log['motivo_fallo']);

        $html = '<table cellpadding="3" border="0" style="background-color:#e9ecef;">
            <tr>
                <td width="13%"><b>' . fechaLog($log['fecha_intento']) . '</b></td>
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
                        <td width="12%" style="color:#6c757d;">' . fechaLog($a['fecha']) . '</td>
                        <td width="13%" style="color:' . ($coloresHex[$a['accion']] ?? '#6c757d') . ';"><b>' . $h(etiquetaAccion($a['accion'])) . '</b></td>
                        <td width="14%">' . $h($a['tabla']) . ($a['registro_id'] !== null ? ' #' . $h($a['registro_id']) : '') . '</td>
                        <td width="58%" style="font-size:7px;">' . $detalle . '</td>
                    </tr>';
                }
                $html .= '</table>';
            }
        }
        $pdf->writeHTML($html . '<br>', true, false, true, false, '');
    }

    return $pdf->Output('', 'S');
}
