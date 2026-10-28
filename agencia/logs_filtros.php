<?php
// agencia/logs_filtros.php
// Filtros compartidos por ver_logs.php y exportar_logs.php.
// Deja listos: $filtro*, $fechaDesde, $fechaHasta, $where, $params, $types, $colores, $etiquetas

$colores   = ['INSERT' => 'success', 'UPDATE' => 'primary', 'DELETE' => 'danger', 'LOGOUT' => 'secondary', 'EXPORT_EXCEL' => 'info', 'EXPORT_PDF' => 'warning'];
$etiquetas = ['INSERT' => 'NUEVO', 'UPDATE' => 'ACTUALIZAR', 'DELETE' => 'ELIMINAR', 'EXPORT_EXCEL' => 'DESCARGA EXCEL VOLUMEN', 'EXPORT_PDF' => 'DESCARGA PDF VOLUMEN'];

$filtroExito  = $_GET['exito'] ?? '';
$filtroOrigen = $_GET['origen'] ?? '';
$filtroCorreo = trim($_GET['correo'] ?? '');
$filtroActividad = $_GET['actividad'] ?? '';

// Rango de fechas: por defecto hoy; ?fecha= se acepta como un solo día
$hoy = date('Y-m-d');
$esFechaValida = fn($f) => is_string($f) && ($d = DateTime::createFromFormat('Y-m-d', $f)) && $d->format('Y-m-d') === $f;
$filtroFechaInicio = $_GET['fecha_inicio'] ?? $_GET['fecha'] ?? $hoy;
$filtroFechaFin    = $_GET['fecha_fin'] ?? $_GET['fecha'] ?? $hoy;
if (!$esFechaValida($filtroFechaInicio)) $filtroFechaInicio = $hoy;
if (!$esFechaValida($filtroFechaFin))    $filtroFechaFin = $hoy;
if ($filtroFechaInicio > $filtroFechaFin) {
    [$filtroFechaInicio, $filtroFechaFin] = [$filtroFechaFin, $filtroFechaInicio];
}
$fechaDesde = $filtroFechaInicio . ' 00:00:00';
$fechaHasta = date('Y-m-d', strtotime($filtroFechaFin . ' +1 day')) . ' 00:00:00';

$where  = ["fecha_intento >= ?", "fecha_intento < ?"];
$params = [$fechaDesde, $fechaHasta];
$types  = "ss";

if ($filtroExito !== '') {
    $where[]  = "exito = ?";
    $params[] = (int)$filtroExito;
    $types   .= "i";
}
if ($filtroOrigen !== '') {
    $where[]  = "origen = ?";
    $params[] = $filtroOrigen;
    $types   .= "s";
}
if ($filtroCorreo !== '') {
    $where[]  = "correo LIKE ?";
    $params[] = "%$filtroCorreo%";
    $types   .= "s";
}
// Actividad: logins exitosos con / sin acciones registradas (sin contar LOGOUT)
if ($filtroActividad === 'con' || $filtroActividad === 'sin') {
    $where[] = "exito = 1 AND " . ($filtroActividad === 'sin' ? "NOT " : "") . "EXISTS (
        SELECT 1 FROM logs_acciones la WHERE la.login_id = logs_login.id AND la.accion <> 'LOGOUT'
    )";
}

// Filtros actuales para reenviarlos en enlaces (exportar, volver, etc.)
$filtrosQuery = array_filter([
    'fecha_inicio' => $filtroFechaInicio,
    'fecha_fin'    => $filtroFechaFin,
    'origen'       => $filtroOrigen,
    'exito'        => $filtroExito,
    'actividad'    => $filtroActividad,
    'correo'       => $filtroCorreo,
], fn($v) => $v !== '');

function formatearDetalle($json) {
    $datos = json_decode($json ?? '', true);
    if (!is_array($datos)) return htmlspecialchars($json ?? '');
    $partes = [];
    foreach ($datos as $campo => $valor) {
        if (is_array($valor) && array_key_exists('antes', $valor)) {
            $partes[] = "<strong>" . htmlspecialchars($campo) . "</strong>: "
                . htmlspecialchars(var_export($valor['antes'], true)) . " &rarr; "
                . htmlspecialchars(var_export($valor['despues'], true));
        } else {
            $texto = is_scalar($valor) || $valor === null ? var_export($valor, true) : json_encode($valor, JSON_UNESCAPED_UNICODE);
            $partes[] = "<strong>" . htmlspecialchars($campo) . "</strong>: " . htmlspecialchars($texto);
        }
    }
    return implode('<br>', $partes);
}

// Misma información que formatearDetalle pero en texto plano (para Excel)
function detalleTextoPlano($json) {
    return html_entity_decode(strip_tags(str_replace('<br>', "\n", formatearDetalle($json))), ENT_QUOTES, 'UTF-8');
}
