<?php
// Limpiar cualquier salida previa
while (ob_get_level()) {
    ob_end_clean();
}
ob_start();

// Habilitar visualización de errores temporalmente para diagnóstico
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Verificar si ya se han enviado headers
if (headers_sent()) {
    die('Error: Headers ya han sido enviados. Verifique que no haya espacios en blanco o caracteres antes de <?php.');
}

require __DIR__ . '/../vendor/autoload.php';

use Dompdf\Dompdf;
use Dompdf\Options;

date_default_timezone_set('America/Mexico_City');

// Iniciar sesión para verificar el rol del usuario
require_once __DIR__ . '/../session_config.php'; session_start();

// Conexión a base de datos
$host = '65.99.205.112';
$dbname = 'noreplyc_crmregistroventas';
$username = 'noreplyc_alexsanchez09';
$password = 'SWn_VrAc3K7lq;LS';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8", $username, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_TIMEOUT => 30,
        PDO::ATTR_EMULATE_PREPARES => false
    ]);
} catch (PDOException $e) {
    die("Error de conexión a la base de datos: " . $e->getMessage());
}

// ============================================================================
// VERIFICACIÓN DE PERMISOS Y RESTRICCIONES POR ROL
// ============================================================================

// Determinar si es admin
$esAdmin = isset($_SESSION['rol']) && in_array($_SESSION['rol'], ['admin', 'superadmin'], true);

// Determinar el rango de fechas del mes en curso para usuarios no admin
$fechaInicioMes = '';
$fechaFinMes = '';

if (!$esAdmin) {
    $fechaInicioMes = date('Y-m-01'); // Primer día del mes
    $fechaFinMes = date('Y-m-t'); // Último día del mes
}

// Obtener parámetros de filtrado
$fechaInicio = isset($_GET['fecha_inicio']) ? $_GET['fecha_inicio'] : '';
$fechaFin = isset($_GET['fecha_fin']) ? $_GET['fecha_fin'] : '';
$busqueda = isset($_GET['busqueda']) ? $_GET['busqueda'] : '';
$plataformaIds = isset($_GET['plataforma_id']) ? (array)$_GET['plataforma_id'] : [];

// Para usuarios no admin, aplicar restricciones de fecha
if (!$esAdmin) {
    if (empty($fechaInicio)) {
        $fechaInicio = $fechaInicioMes;
    }
    if (empty($fechaFin)) {
        $fechaFin = $fechaFinMes;
    }
    
    if (!empty($fechaInicio) && $fechaInicio < $fechaInicioMes) {
        $fechaInicio = $fechaInicioMes;
    }
    if (!empty($fechaInicio) && $fechaInicio > $fechaFinMes) {
        $fechaInicio = $fechaInicioMes;
    }
    if (!empty($fechaFin) && $fechaFin > $fechaFinMes) {
        $fechaFin = $fechaFinMes;
    }
    if (!empty($fechaFin) && $fechaFin < $fechaInicioMes) {
        $fechaFin = $fechaFinMes;
    }
    if (!empty($fechaInicio) && !empty($fechaFin) && $fechaInicio > $fechaFin) {
        $fechaInicio = $fechaInicioMes;
        $fechaFin = $fechaFinMes;
    }
}

// Construir consulta base - MODIFICADO PARA OBTENER SOLO APEMAT
$sql = "SELECT v.*, 
    CONCAT(v.cliente, ' ', v.apepat, ' ', COALESCE(v.apemat, '')) AS cliente_nombre,
    liner.apemat AS liner_apemat,
    liner.id AS liner_id,
    c1.apemat AS closer1_apemat,
    c1.id AS closer1_id,
    c2.apemat AS closer2_apemat,
    c2.id AS closer2_id,
    front.apemat AS front_apemat,
    front.id AS full_stack_id,
    CONCAT(p.nombre_ancronimo, ' (', p.plataformaspei, ')') AS plataforma_nombre_completo,
    p.metodo_pago AS metodo_pago_plataforma
    FROM ventas_facturacion v
    LEFT JOIN usuarios liner ON v.liner_id = liner.id
    LEFT JOIN usuarios c1 ON v.closer1_id = c1.id
    LEFT JOIN usuarios c2 ON v.closer2_id = c2.id
    LEFT JOIN usuarios front ON v.full_stack_id = front.id
    LEFT JOIN plataforma p ON v.plataforma_id = p.id";

// Preparar condiciones WHERE y parámetros
$conditions = [];
$params = [];

// Si el usuario no es admin, forzar el filtro del mes en curso
if (!$esAdmin) {
    $conditions[] = "v.fecha >= ?";
    $conditions[] = "v.fecha <= ?";
    $params[] = $fechaInicioMes . ' 00:00:00';
    $params[] = $fechaFinMes . ' 23:59:59';
}

if (!empty($fechaInicio)) {
    $conditions[] = "v.fecha >= ?";
    $params[] = $fechaInicio . ' 00:00:00';
}

if (!empty($fechaFin)) {
    $conditions[] = "v.fecha <= ?";
    $params[] = $fechaFin . ' 23:59:59';
}

if (!empty($plataformaIds)) {
    $placeholders = str_repeat('?,', count($plataformaIds) - 1) . '?';
    $conditions[] = "v.plataforma_id IN ($placeholders)";
    $params = array_merge($params, $plataformaIds);
}

if (!empty($busqueda)) {
    $searchTerm = "%$busqueda%";
    $conditions[] = "(v.cliente LIKE ? OR 
                     v.apepat LIKE ? OR 
                     v.apemat LIKE ? OR 
                     v.producto LIKE ? OR 
                     v.celular LIKE ? OR
                     v.correo LIKE ? OR
                     CONCAT(v.cliente, ' ', v.apepat, ' ', COALESCE(v.apemat, '')) LIKE ? OR
                     liner.apemat LIKE ? OR
                     c1.apemat LIKE ? OR
                     c2.apemat LIKE ? OR
                     front.apemat LIKE ? OR
                     CONCAT(p.nombre_ancronimo, ' (', p.plataformaspei, ')') LIKE ?)";
    
    for ($i = 0; $i < 12; $i++) {
        $params[] = $searchTerm;
    }
}

if (!empty($conditions)) {
    $sql .= " WHERE " . implode(" AND ", $conditions);
}

$sql .= " ORDER BY v.fecha DESC";

try {
    $stmt = $pdo->prepare($sql);
    
    foreach ($params as $index => $value) {
        $stmt->bindValue($index + 1, $value, PDO::PARAM_STR);
    }
    
    $stmt->execute();
    $ventas = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch (PDOException $e) {
    die("Error en la consulta: " . $e->getMessage());
}

// Validar que hay datos
if (empty($ventas)) {
    die('No hay datos para generar el reporte con los filtros aplicados.');
}

// Función para limpiar símbolos del apellido materno
function limpiarApemat($apemat) {
    if (empty($apemat) || $apemat === 'No asignado') {
        return 'No asignado';
    }
    
    // Lista de símbolos a remover
    $simbolos = ['@', '#', '$', '%', '&', '*', '~', '^', '`', '=', '+', '|', '\\', '/', ';', ':', '"', "'", '<', '>', '{', '}', '[', ']'];
    
    // Remover símbolos
    $apematLimpio = str_replace($simbolos, '', $apemat);
    
    // Remover múltiples espacios
    $apematLimpio = preg_replace('/\s+/', ' ', $apematLimpio);
    
    // Trim espacios al inicio y final
    $apematLimpio = trim($apematLimpio);
    
    return $apematLimpio;
}

// Obtener los nombres de las plataformas filtradas
$plataformasNombres = [];
$plataformasInfo = [];
if (!empty($plataformaIds)) {
    try {
        $placeholders = str_repeat('?,', count($plataformaIds) - 1) . '?';
        $stmtPlataformas = $pdo->prepare("SELECT id, nombre_ancronimo, plataformaspei FROM plataforma WHERE id IN ($placeholders)");
        $stmtPlataformas->execute($plataformaIds);
        $plataformasInfo = $stmtPlataformas->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($plataformasInfo as $plataforma) {
            $plataformasNombres[] = $plataforma['nombre_ancronimo'] . ' (' . $plataforma['plataformaspei'] . ')';
        }
    } catch (PDOException $e) {
        error_log("Error al obtener nombres de plataformas: " . $e->getMessage());
    }
}

// Verificar si existen campos de comisión
$tieneComisiones = false;
if (count($ventas) > 0) {
    $tieneComisiones = array_key_exists('ganancia_liner', $ventas[0]) || 
                        array_key_exists('ganancia_closer1', $ventas[0]) || 
                        array_key_exists('ganancia_closer2', $ventas[0]) ||
                        array_key_exists('ganancia_fullstack', $ventas[0]);
}

// ============================================================================
// GENERAR HTML PARA PDF
// ============================================================================

$esFiltroPlataforma = !empty($plataformaIds);
$multiplesPlataformas = count($plataformaIds) > 1;

// Construir el HTML
$html = '<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Reporte de Ventas - PDF</title>
    <style>
        body { 
            font-family: Arial, sans-serif; 
            font-size: 18px;  /* AUMENTADO de 14px a 18px */
            margin: 0;
            padding: 20px;    /* AUMENTADO padding */
            color: #333;
            line-height: 1.5; /* MEJORADA legibilidad */
        }
        .header { 
            text-align: center; 
            margin-bottom: 25px; /* AUMENTADO margen */
            border-bottom: 4px solid #3498DB; /* AUMENTADO grosor */
            padding-bottom: 20px; /* AUMENTADO padding */
        }
        .title { 
            font-size: 28px;  /* AUMENTADO de 22px a 28px */
            font-weight: bold; 
            color: #3498DB;
            margin-bottom: 12px; /* AUMENTADO margen */
        }
        .subtitle { 
            font-size: 20px;  /* AUMENTADO de 16px a 20px */
            color: #666;
            margin-bottom: 15px; /* AUMENTADO margen */
        }
        .filtro-info { 
            font-size: 16px;  /* AUMENTADO de 13px a 16px */
            color: #666; 
            text-align: center;
            margin-bottom: 20px; /* AUMENTADO margen */
            font-style: italic;
            padding: 12px; /* AUMENTADO padding */
            background-color: #f8f9fa;
            border-radius: 8px;
            border: 1px solid #ddd;
        }
        .instrucciones { 
            font-size: 16px;  /* AUMENTADO de 13px a 16px */
            font-weight: bold; 
            text-align: center;
            background-color: #E8F4FD;
            padding: 15px; /* AUMENTADO de 10px a 15px */
            border-radius: 8px;
            margin-bottom: 20px;
            color: #0C63E4;
            border: 2px solid #3498DB;
        }
        .instrucciones.restringido { 
            background-color: #FDEBD0;
            color: #E74C3C;
            border: 2px solid #E74C3C;
        }
        table { 
            width: 100%; 
            border-collapse: collapse; 
            margin-bottom: 25px;
            font-size: 16px;  /* AUMENTADO de 12px a 16px */
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        }
        th { 
            background-color: #2C3E50; 
            color: white; 
            font-weight: bold; 
            padding: 15px 10px; /* AUMENTADO de 10px 6px a 15px 10px */
            text-align: center;
            border: 3px solid #ddd; /* AUMENTADO grosor */
            font-size: 17px; /* AUMENTADO de 13px a 17px */
        }
        td { 
            padding: 12px 8px;  /* AUMENTADO de 8px 5px a 12px 8px */
            border: 2px solid #ddd; /* AUMENTADO grosor */
            text-align: left;
            font-size: 16px; /* AUMENTADO tamaño fuente */
        }
        .plataforma-header {
            background-color: #8E44AD;
            color: white;
            font-weight: bold;
            padding: 18px; /* AUMENTADO de 12px a 18px */
            text-align: center;
            margin: 20px 0 12px 0;
            border-radius: 8px;
            font-size: 22px; /* AUMENTADO de 16px a 22px */
            border: 2px solid #6C3483;
        }
        .total-row { 
            background-color: #27AE60; 
            color: white; 
            font-weight: bold;
            font-size: 18px; /* AUMENTADO de 13px a 18px */
        }
        .gran-total { 
            background-color: #E74C3C; 
            color: white; 
            font-weight: bold;
            font-size: 20px; /* AUMENTADO de 16px a 20px */
        }
        .text-right { text-align: right; }
        .text-center { text-align: center; }
        .text-bold { font-weight: bold; }
        .moneda { 
            text-align: right; 
            font-family: "Courier New", monospace;
            font-weight: bold;
        }
        .page-break { 
            page-break-after: always;
            margin-top: 30px;
        }
        .resumen-totales {
            background-color: #f8f9fa;
            padding: 20px; /* AUMENTADO de 15px a 20px */
            border-radius: 10px;
            margin-top: 25px;
            border-left: 6px solid #3498DB; /* AUMENTADO grosor */
            border: 2px solid #3498DB;
        }
        .resumen-totales h3 {
            font-size: 22px; /* AUMENTADO de 18px a 22px */
            margin-top: 0;
            margin-bottom: 15px;
            color: #2C3E50;
            text-align: center;
        }
        .apemat-limpio {
            font-weight: normal;
            font-size: 16px; /* TAMAÑO consistente */
        }
        /* MEJORAS PARA MEJOR LEGIBILIDAD */
        .numero-grande {
            font-size: 17px; /* AUMENTADO de 13px a 17px */
            font-weight: bold;
        }
        .texto-destacado {
            font-size: 17px; /* AUMENTADO de 14px a 17px */
            font-weight: bold;
        }
        .fila-destacada {
            background-color: #f8f9fa;
        }
        .fila-destacada:nth-child(even) {
            background-color: #e9ecef;
        }
    </style>
</head>
<body>';

// Título principal
$html .= '<div class="header">
    <div class="title">REPORTE DE VENTAS - GRUPO IDEAS</div>
    <div class="subtitle">Resumen General - ' . date('d/m/Y H:i:s') . '</div>';

// Información sobre restricciones para usuarios no admin
if (!$esAdmin) {
    $html .= '<div class="subtitle" style="color: #E74C3C;">MES ACTUAL: ' . date('F Y') . '</div>';
}

$html .= '</div>';

// Información de filtros aplicados
$filtroTexto = 'Todos los registros';
if (!empty($fechaInicio) && !empty($fechaFin)) {
    $filtroTexto = "Desde: " . date('d/m/Y', strtotime($fechaInicio)) . " Hasta: " . date('d/m/Y', strtotime($fechaFin));
} elseif (!empty($fechaInicio)) {
    $filtroTexto = "Desde: " . date('d/m/Y', strtotime($fechaInicio));
} elseif (!empty($fechaFin)) {
    $filtroTexto = "Hasta: " . date('d/m/Y', strtotime($fechaFin));
}

if (!empty($busqueda)) {
    $filtroTexto .= empty($filtroTexto) ? "Búsqueda: " . htmlspecialchars($busqueda) : " | Búsqueda: " . htmlspecialchars($busqueda);
}

if (!empty($plataformasNombres)) {
    $filtroTexto .= empty($filtroTexto) ? "Plataformas: " . implode(', ', $plataformasNombres) : " | Plataformas: " . implode(', ', $plataformasNombres);
}

// Agregar información sobre restricciones para usuarios no admin
if (!$esAdmin) {
    $filtroTexto .= " | Rango del mes: " . date('d/m/Y', strtotime($fechaInicioMes)) . " al " . date('d/m/Y', strtotime($fechaFinMes));
}

$html .= '<div class="filtro-info">Filtro aplicado: ' . $filtroTexto . '</div>';

// Instrucciones y modo de usuario
$instrucciones = 'REPORTE EN PDF - ';
if (!$esAdmin) {
    $instrucciones .= '[MODO RESTRINGIDO: Solo datos del mes actual]';
    $html .= '<div class="instrucciones restringido">' . $instrucciones . '</div>';
} else {
    $instrucciones .= '[MODO ADMIN: Acceso completo]';
    $html .= '<div class="instrucciones">' . $instrucciones . '</div>';
}

if ($multiplesPlataformas) {
    $html .= '<div class="instrucciones">TABLAS SEPARADAS POR PLATAFORMA - TOTALES POR DÍA</div>';
}

// ============================================================================
// CONSTRUIR TABLA DE DATOS
// ============================================================================

if ($esFiltroPlataforma) {
    if ($multiplesPlataformas) {
        // MÚLTIPLES PLATAFORMAS: TABLAS SEPARADAS
        $ventasPorPlataforma = [];
        foreach ($ventas as $venta) {
            $plataformaId = $venta['plataforma_id'];
            $plataformaNombre = $venta['plataforma_nombre_completo'] ?? 'N/A';
            $fecha = date('Y-m-d', strtotime($venta['fecha']));
            
            if (!isset($ventasPorPlataforma[$plataformaId])) {
                $ventasPorPlataforma[$plataformaId] = [
                    'nombre' => $plataformaNombre,
                    'ventas_por_fecha' => [],
                    'total_bruto' => 0
                ];
            }
            
            if (!isset($ventasPorPlataforma[$plataformaId]['ventas_por_fecha'][$fecha])) {
                $ventasPorPlataforma[$plataformaId]['ventas_por_fecha'][$fecha] = [
                    'fecha' => $fecha,
                    'total_dia' => 0,
                    'ventas' => []
                ];
            }
            
            $ventasPorPlataforma[$plataformaId]['ventas_por_fecha'][$fecha]['total_dia'] += (float)$venta['precio'];
            $ventasPorPlataforma[$plataformaId]['ventas_por_fecha'][$fecha]['ventas'][] = $venta;
            $ventasPorPlataforma[$plataformaId]['total_bruto'] += (float)$venta['precio'];
        }
        
        // Ordenar plataformas por nombre
        uasort($ventasPorPlataforma, function($a, $b) {
            return strcmp($a['nombre'], $b['nombre']);
        });
        
        $totalesPlataforma = [];
        $contadorTablas = 0;
        
        foreach ($ventasPorPlataforma as $plataformaId => $plataformaData) {
            $contadorTablas++;
            $plataformaNombre = $plataformaData['nombre'];
            
            // TÍTULO DE LA PLATAFORMA
            $html .= '<div class="plataforma-header">PLATAFORMA: ' . strtoupper($plataformaNombre) . '</div>';
            
            // TABLA DE LA PLATAFORMA
            $html .= '<table>
                <thead>
                    <tr>
                        <th>Fecha</th>
                        <th>Venta Bruto</th>
                        <th>Plataforma</th>
                    </tr>
                </thead>
                <tbody>';
            
            // Ordenar fechas descendente
            $fechas = $plataformaData['ventas_por_fecha'];
            krsort($fechas);
            
            $totalPlataforma = 0;
            $filaContador = 0;
            foreach ($fechas as $fechaData) {
                $filaContador++;
                $fechaFormateada = date('d/m/Y', strtotime($fechaData['fecha']));
                $totalDia = $fechaData['total_dia'];
                
                $claseFila = $filaContador % 2 === 0 ? 'fila-destacada' : '';
                
                $html .= '<tr class="' . $claseFila . '">
                    <td class="texto-destacado">' . $fechaFormateada . '</td>
                    <td class="moneda numero-grande">$' . number_format($totalDia, 2) . '</td>
                    <td>' . $plataformaNombre . '</td>
                </tr>';
                
                $totalPlataforma += $totalDia;
            }
            
            // TOTAL DE LA PLATAFORMA
            $html .= '<tr class="total-row">
                <td class="text-bold">TOTAL ' . $plataformaNombre . ':</td>
                <td class="moneda text-bold numero-grande">$' . number_format($totalPlataforma, 2) . '</td>
                <td></td>
            </tr>';
            
            $html .= '</tbody></table>';
            
            $totalesPlataforma[$plataformaNombre] = $totalPlataforma;
            
            // Agregar salto de página cada tabla (por el tamaño grande)
            if ($contadorTablas < count($ventasPorPlataforma)) {
                $html .= '<div class="page-break"></div>';
            }
        }
        
        // GRAN TOTAL GENERAL
        if (!empty($totalesPlataforma)) {
            $granTotal = array_sum($totalesPlataforma);
            
            $html .= '<table>
                <tr class="gran-total">
                    <td class="text-bold">TOTAL GENERAL DE TODAS LAS PLATAFORMAS</td>
                    <td class="moneda text-bold numero-grande">$' . number_format($granTotal, 2) . '</td>
                    <td></td>
                </tr>
            </table>';
        }
        
    } else {
        // UNA SOLA PLATAFORMA: TABLA SIMPLE
        $ventasAgrupadas = [];
        foreach ($ventas as $venta) {
            $fecha = date('Y-m-d', strtotime($venta['fecha']));
            if (!isset($ventasAgrupadas[$fecha])) {
                $ventasAgrupadas[$fecha] = [
                    'fecha' => $fecha,
                    'total_bruto' => 0,
                    'plataforma' => $venta['plataforma_nombre_completo'] ?? 'N/A'
                ];
            }
            $ventasAgrupadas[$fecha]['total_bruto'] += (float)$venta['precio'];
        }
        
        // Ordenar por fecha descendente
        krsort($ventasAgrupadas);
        
        $html .= '<table>
            <thead>
                <tr>
                    <th>Fecha</th>
                    <th>Venta Bruto</th>
                    <th>Plataforma</th>
                </tr>
            </thead>
            <tbody>';
        
        $totalGeneral = 0;
        $filaContador = 0;
        foreach ($ventasAgrupadas as $grupo) {
            $filaContador++;
            $fechaFormateada = date('d/m/Y', strtotime($grupo['fecha']));
            $totalGeneral += $grupo['total_bruto'];
            
            $claseFila = $filaContador % 2 === 0 ? 'fila-destacada' : '';
            
            $html .= '<tr class="' . $claseFila . '">
                <td class="texto-destacado">' . $fechaFormateada . '</td>
                <td class="moneda numero-grande">$' . number_format($grupo['total_bruto'], 2) . '</td>
                <td>' . $grupo['plataforma'] . '</td>
            </tr>';
        }
        
        // TOTAL
        $html .= '<tr class="total-row">
            <td class="text-bold">TOTALES:</td>
            <td class="moneda text-bold numero-grande">$' . number_format($totalGeneral, 2) . '</td>
            <td></td>
        </tr>';
        
        $html .= '</tbody></table>';
    }
} else {
    // REPORTE COMPLETO CON TODOS LOS DETALLES - SOLO APEMAT LIMPIO
    $html .= '<table>
        <thead>
            <tr>
                <th>Fecha</th>
                <th>Venta Bruto</th>
                <th>Venta Neto</th>
                <th>Cliente</th>
                <th>Liner</th>
                <th>Comisión Liner</th>
                <th>Closer 1</th>
                <th>Comisión Closer 1</th>
                <th>Closer 2</th>
                <th>Comisión Closer 2</th>
                <th>Front</th>
                <th>Comisión Front</th>
                <th>Plataforma</th>
                <th>Suma Comisión</th>
            </tr>
        </thead>
        <tbody>';
    
    $totalBruto = 0;
    $totalNeto = 0;
    $totalComisiones = 0;
    $contadorFilas = 0;
    
    foreach ($ventas as $venta) {
        $contadorFilas++;
        $fechaFormateada = date('d/m/Y', strtotime($venta['fecha']));
        
        $ventaBruto = (float)$venta['precio'];
        $ventaNeto = (float)$venta['precio_neto'];
        $comisionLiner = $tieneComisiones ? (float)($venta['ganancia_liner'] ?? 0) : 0;
        $comisionCloser1 = $tieneComisiones ? (float)($venta['ganancia_closer1'] ?? 0) : 0;
        $comisionCloser2 = $tieneComisiones ? (float)($venta['ganancia_closer2'] ?? 0) : 0;
        $comisionFront = $tieneComisiones ? (float)($venta['ganancia_fullstack'] ?? 0) : 0;
        $sumaComision = $comisionLiner + $comisionCloser1 + $comisionCloser2 + $comisionFront;
        
        // LIMPIAR SOLO APEMAT DE SÍMBOLOS
        $linerApemat = limpiarApemat($venta['liner_apemat'] ?? 'No asignado');
        $closer1Apemat = limpiarApemat($venta['closer1_apemat'] ?? 'No asignado');
        $closer2Apemat = limpiarApemat($venta['closer2_apemat'] ?? 'No asignado');
        $frontApemat = limpiarApemat($venta['front_apemat'] ?? 'No asignado');
        
        $totalBruto += $ventaBruto;
        $totalNeto += $ventaNeto;
        $totalComisiones += $sumaComision;
        
        $claseFila = $contadorFilas % 2 === 0 ? 'fila-destacada' : '';
        
        $html .= '<tr class="' . $claseFila . '">
            <td class="texto-destacado">' . $fechaFormateada . '</td>
            <td class="moneda numero-grande">$' . number_format($ventaBruto, 2) . '</td>
            <td class="moneda numero-grande">$' . number_format($ventaNeto, 2) . '</td>
            <td>' . htmlspecialchars($venta['cliente_nombre']) . '</td>
            <td class="apemat-limpio">' . htmlspecialchars($linerApemat) . '</td>
            <td class="moneda numero-grande">$' . number_format($comisionLiner, 2) . '</td>
            <td class="apemat-limpio">' . htmlspecialchars($closer1Apemat) . '</td>
            <td class="moneda numero-grande">$' . number_format($comisionCloser1, 2) . '</td>
            <td class="apemat-limpio">' . htmlspecialchars($closer2Apemat) . '</td>
            <td class="moneda numero-grande">$' . number_format($comisionCloser2, 2) . '</td>
            <td class="apemat-limpio">' . htmlspecialchars($frontApemat) . '</td>
            <td class="moneda numero-grande">$' . number_format($comisionFront, 2) . '</td>
            <td>' . htmlspecialchars($venta['plataforma_nombre_completo'] ?? 'N/A') . '</td>
            <td class="moneda numero-grande">$' . number_format($sumaComision, 2) . '</td>
        </tr>';
        
        // Agregar salto de página cada 15 filas (reducido por letras más grandes)
        if ($contadorFilas % 15 === 0) {
            $html .= '</tbody></table><div class="page-break"></div>';
            $html .= '<table>
                <thead>
                    <tr>
                        <th>Fecha</th>
                        <th>Venta Bruto</th>
                        <th>Venta Neto</th>
                        <th>Cliente</th>
                        <th>Liner</th>
                        <th>Comisión Liner</th>
                        <th>Closer 1</th>
                        <th>Comisión Closer 1</th>
                        <th>Closer 2</th>
                        <th>Comisión Closer 2</th>
                        <th>Front</th>
                        <th>Comisión Front</th>
                        <th>Plataforma</th>
                        <th>Suma Comisión</th>
                    </tr>
                </thead>
                <tbody>';
        }
    }
    
    $html .= '</tbody></table>';
    
    // RESUMEN DE TOTALES
    $html .= '<div class="resumen-totales">
        <h3>RESUMEN DE TOTALES</h3>
        <table style="width: 70%; margin: 0 auto; font-size: 18px;">
            <tr>
                <td class="text-bold" style="padding: 15px;">Total Ventas Brutas:</td>
                <td class="moneda text-bold numero-grande" style="padding: 15px;">$' . number_format($totalBruto, 2) . '</td>
            </tr>
            <tr>
                <td class="text-bold" style="padding: 15px;">Total Ventas Netas:</td>
                <td class="moneda text-bold numero-grande" style="padding: 15px;">$' . number_format($totalNeto, 2) . '</td>
            </tr>
            <tr>
                <td class="text-bold" style="padding: 15px;">Total Comisiones:</td>
                <td class="moneda text-bold numero-grande" style="padding: 15px;">$' . number_format($totalComisiones, 2) . '</td>
            </tr>
            <tr>
                <td class="text-bold" style="padding: 15px;">Total Registros:</td>
                <td class="text-bold numero-grande" style="padding: 15px;">' . $contadorFilas . '</td>
            </tr>
        </table>
    </div>';
}

$html .= '</body></html>';

// ============================================================================
// GENERAR PDF
// ============================================================================

try {
    // Configurar DomPDF
    $options = new Options();
    $options->set('isRemoteEnabled', true);
    $options->set('isHtml5ParserEnabled', true);
    $options->set('defaultFont', 'Arial');
    $options->set('dpi', 150);
    
    $dompdf = new Dompdf($options);
    
    // Cargar HTML
    $dompdf->loadHtml($html);
    
    // Configurar papel y orientación
    $dompdf->setPaper('letter', 'landscape');
    
    // Renderizar PDF
    $dompdf->render();
    
    // Generar nombre de archivo
    $fileName = 'Reporte_Ventas_' . date('Y-m-d_His') . '.pdf';
    
    // Limpiar buffer completamente
    while (ob_get_level()) {
        ob_end_clean();
    }
    
    // Headers para descarga PDF
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment;filename="' . $fileName . '"');
    header('Cache-Control: max-age=0');
    header('Content-Transfer-Encoding: binary');
    header('Pragma: public');
    header('Expires: 0');
    
    // Enviar PDF
    echo $dompdf->output();
    exit;
    
} catch (Exception $e) {
    // Headers para error en HTML
    header('Content-Type: text/html; charset=utf-8');
    die('Error al generar el archivo PDF: ' . $e->getMessage());
}
?>