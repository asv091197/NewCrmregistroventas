<?php
require_once __DIR__ . '/../includes/db.php';
// Limpiar cualquier salida previa
while (ob_get_level()) {
    ob_end_clean();
}
ob_start();

require __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../session_config.php';
require_once __DIR__ . '/../includes/registro_acciones.php';
session_start();

// Solo se descarga con sesión iniciada (admin o superadmin)
if (!isset($_SESSION['usuario_id']) || !in_array($_SESSION['rol'] ?? '', ['admin', 'superadmin'], true)) {
    header("Location: ./login.php");
    exit;
}

// Liberar el bloqueo de la sesión: la generación puede tardar y $_SESSION sigue disponible para la bitácora
session_write_close();

use TCPDF;

date_default_timezone_set('America/Mexico_City');

// Función para limpiar símbolos de los campos de texto
function limpiarTexto($texto)
{
    if ($texto === null || $texto === '') {
        return $texto;
    }
    return preg_replace('/[^\p{L}\p{N}\s]/u', '', $texto);
}

// Función para normalizar nombres
function normalizarNombre($nombre)
{
    return trim(strtolower($nombre));
}

// Conexión a base de datos

try {
    $pdo = conexionPDO();
} catch (PDOException $e) {
    die("Error de conexión a la base de datos: " . $e->getMessage());
}

// Obtener parámetros de filtrado
$fechaInicio = isset($_GET['fecha_inicio']) ? $_GET['fecha_inicio'] : '';
$fechaFin = isset($_GET['fecha_fin']) ? $_GET['fecha_fin'] : '';
$busqueda = isset($_GET['busqueda']) ? $_GET['busqueda'] : '';
$plataformaIds = isset($_GET['plataforma_id']) ? (array)$_GET['plataforma_id'] : [];
$tipoVenta = isset($_GET['tipo_venta']) ? $_GET['tipo_venta'] : '';
$salaId = isset($_GET['sala_id']) ? intval($_GET['sala_id']) : 0;

// Obtener nombre de la sala si se filtró por sala
$salaNombre = '';
if ($salaId > 0) {
    try {
        $stmtSala = $pdo->prepare("SELECT nombre FROM salas WHERE id = ?");
        $stmtSala->execute([$salaId]);
        $salaData = $stmtSala->fetch(PDO::FETCH_ASSOC);
        if ($salaData) {
            $salaNombre = $salaData['nombre'];
        }
    } catch (PDOException $e) {
        error_log("Error al obtener nombre de sala: " . $e->getMessage());
    }
}

// Construir consulta base
$conditions = [];
$params = [];
$whereClause = '';

if (!empty($fechaInicio)) {
    $conditions[] = "v.fecha >= ?";
    $params[] = $fechaInicio . ' 00:00:00';
}

if (!empty($fechaFin)) {
    $conditions[] = "v.fecha <= ?";
    $params[] = $fechaFin . ' 23:59:59';
}

// AGREGAR CONDICIÓN DE SALA
if (!empty($salaId) && $salaId > 0) {
    $conditions[] = "v.sala_id = ?";
    $params[] = $salaId;
}

// Manejar múltiples plataformas
if (!empty($plataformaIds)) {
    $placeholders = str_repeat('?,', count($plataformaIds) - 1) . '?';
    $conditions[] = "v.plataforma_id IN ($placeholders)";
    $params = array_merge($params, $plataformaIds);
}

// Agregar condición de tipo_venta
if (!empty($tipoVenta)) {
    $conditions[] = "v.tipo_venta = ?";
    $params[] = $tipoVenta;
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
                     fs.apemat LIKE ? OR
                     v.liner_nombre_manual LIKE ? OR
                     v.closer1_nombre_manual LIKE ? OR
                     v.closer2_nombre_manual LIKE ? OR
                     v.full_stack_nombre_manual LIKE ? OR
                     CONCAT(p.nombre_ancronimo, ' (', p.plataformaspei, ')') LIKE ?)";

    for ($i = 0; $i < 16; $i++) {
        $params[] = $searchTerm;
    }
}

if (!empty($conditions)) {
    $whereClause = " WHERE " . implode(" AND ", $conditions);
}

// CONSULTA SQL
$sql = "SELECT v.*, 
    CONCAT(v.cliente, ' ', v.apepat, ' ', COALESCE(v.apemat, '')) AS cliente_nombre,
    -- Para Liner: mostrar apemat si existe y no está vacío, sino liner_nombre_manual
    CASE 
      WHEN liner.apemat IS NOT NULL AND TRIM(liner.apemat) != '' 
      THEN liner.apemat
      ELSE COALESCE(v.liner_nombre_manual, 'No asignado')
    END AS liner_nombre,
    liner.id AS liner_id,
    -- Para Closer 1: mostrar apemat si existe y no está vacío, sino closer1_nombre_manual
    CASE 
      WHEN c1.apemat IS NOT NULL AND TRIM(c1.apemat) != '' 
      THEN c1.apemat
      ELSE COALESCE(v.closer1_nombre_manual, 'No asignado')
    END AS closer1_nombre,
    c1.id AS closer1_id,
    -- Para Closer 2: mostrar apemat si existe y no está vacío, sino closer2_nombre_manual
    CASE 
      WHEN c2.apemat IS NOT NULL AND TRIM(c2.apemat) != '' 
      THEN c2.apemat
      ELSE COALESCE(v.closer2_nombre_manual, 'No asignado')
    END AS closer2_nombre,
    c2.id AS closer2_id,
    -- Para Full Stack: mostrar apemat si existe y no está vacío, sino full_stack_nombre_manual
    CASE 
      WHEN fs.apemat IS NOT NULL AND TRIM(fs.apemat) != '' 
      THEN fs.apemat
      ELSE COALESCE(v.full_stack_nombre_manual, 'No asignado')
    END AS front_nombre,
    fs.id AS full_stack_id,
    CONCAT(p.nombre_ancronimo, ' (', p.plataformaspei, ')') AS plataforma_nombre_completo,
    p.metodo_pago AS metodo_pago_plataforma,
    -- Campos de nombres manuales
    v.liner_nombre_manual,
    v.closer1_nombre_manual,
    v.closer2_nombre_manual,
    v.full_stack_nombre_manual,
    -- Información de la sala
    s.nombre AS sala_nombre,
    s.id AS sala_id
    FROM ventas_facturacion v
    LEFT JOIN usuarios liner ON v.liner_id = liner.id
    LEFT JOIN usuarios c1 ON v.closer1_id = c1.id
    LEFT JOIN usuarios c2 ON v.closer2_id = c2.id
    LEFT JOIN usuarios fs ON v.full_stack_id = fs.id
    LEFT JOIN plataforma p ON v.plataforma_id = p.id
    LEFT JOIN salas s ON v.sala_id = s.id" . $whereClause . " ORDER BY v.fecha DESC";

try {
    $stmt = $pdo->prepare($sql);

    foreach ($params as $index => $value) {
        $stmt->bindValue($index + 1, $value, PDO::PARAM_STR);
    }

    $stmt->execute();
    $ventas = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Aplicar limpieza de símbolos a los campos de nombres
    foreach ($ventas as &$venta) {
        $venta['cliente_nombre'] = limpiarTexto($venta['cliente_nombre']);
        $venta['liner_nombre'] = limpiarTexto($venta['liner_nombre']);
        $venta['closer1_nombre'] = limpiarTexto($venta['closer1_nombre']);
        $venta['closer2_nombre'] = limpiarTexto($venta['closer2_nombre']);
        $venta['front_nombre'] = limpiarTexto($venta['front_nombre']);
        // LIMPIAR CAMPOS MANUALES
        $venta['liner_nombre_manual'] = limpiarTexto($venta['liner_nombre_manual']);
        $venta['closer1_nombre_manual'] = limpiarTexto($venta['closer1_nombre_manual']);
        $venta['closer2_nombre_manual'] = limpiarTexto($venta['closer2_nombre_manual']);
        $venta['full_stack_nombre_manual'] = limpiarTexto($venta['full_stack_nombre_manual']);
        $venta['cliente'] = limpiarTexto($venta['cliente']);
        $venta['apepat'] = limpiarTexto($venta['apepat']);
        $venta['apemat'] = limpiarTexto($venta['apemat']);
        $venta['producto'] = limpiarTexto($venta['producto']);
    }
    unset($venta);
} catch (PDOException $e) {
    die("Error en la consulta: " . $e->getMessage());
}

// Validar que hay datos
if (empty($ventas)) {
    die('No hay datos para generar el reporte con los filtros aplicados.');
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
// SISTEMA DE OVER (BONOS POR META) - CONFIGURACIÓN POR SALA
// ============================================================================

$configOver = [
    // Sala 1 - OSCAR
    'XIMENA' => [
        'nombre' => 'XIMENA',
        'salas' => [1], // Solo sala 1
        'metas' => [
            ['min' => 0, 'max' => 300000, 'porcentaje' => 0.00],
            ['min' => 300001, 'max' => 350000, 'porcentaje' => 3.00],
            ['min' => 350001, 'max' => 425000, 'porcentaje' => 4.00],
            ['min' => 425001, 'max' => null, 'porcentaje' => 5.00]
        ]
    ],

    // Sala 2 - SUPERVISOR
    'SUPERVISOR' => [
        'nombre' => 'SUPERVISOR',
        'salas' => [2], // Solo sala 2
        'metas' => [
            ['min' => 0, 'max' => 300000, 'porcentaje' => 0.00],
            ['min' => 300001, 'max' => 350000, 'porcentaje' => 2.00],
            ['min' => 350001, 'max' => 425000, 'porcentaje' => 2.50],
            ['min' => 425001, 'max' => null, 'porcentaje' => 3.00]
        ]
    ],

    // Sala 2 - NUVIA
    'NUVIA' => [
        'nombre' => 'NUVIA',
        'salas' => [1], // Solo sala 2
        'metas' => [
            ['min' => 0, 'max' => 300000, 'porcentaje' => 0.50],
            ['min' => 300001, 'max' => 350000, 'porcentaje' => 0.75],
            ['min' => 350001, 'max' => null, 'porcentaje' => 1.00]
        ]
    ],

    // Ambos salas - CINTHYA
    'CINTHYA' => [
        'nombre' => 'CINTHYA',
        'salas' => [1, 2], // Ambas salas
        'metas' => [
            ['min' => 0, 'max' => 300000, 'porcentaje' => 3.50],
            ['min' => 300001, 'max' => 350000, 'porcentaje' => 4.50],
            ['min' => 350001, 'max' => null, 'porcentaje' => 5.50]
        ]
    ],
    'LUIS' => [
        'nombre' => 'LUIS',
        'salas' => [1, 2], // Ambas salas
        'metas' => [
            ['min' => 0, 'max' => null, 'porcentaje' => 1.25]
        ]
    ],

    // Ambos salas - XIMENA
    'GERENTE' => [
        'nombre' => 'GERENTE',
        'salas' => [1, 2], // Ambas salas
        'metas' => [
            ['min' => 0, 'max' => 300000, 'porcentaje' => 0.00],
            ['min' => 300001, 'max' => 350000, 'porcentaje' => 3.00],
            ['min' => 350001, 'max' => 425000, 'porcentaje' => 4.00],
            ['min' => 425001, 'max' => null, 'porcentaje' => 5.00]
        ]
    ],

    // Ambos salas - NATHALY
    'NATHALY' => [
        'nombre' => 'NATHALY',
        'salas' => [1, 2], // Ambas salas
        'metas' => [
            ['min' => 0, 'max' => 300000, 'porcentaje' => 0.75],
            ['min' => 300001, 'max' => 350000, 'porcentaje' => 1.00],
            ['min' => 350001, 'max' => null, 'porcentaje' => 1.50]
        ]
    ],

    // Ambos salas - ROY
    'ROY' => [
        'nombre' => 'ROY',
        'salas' => [1, 2], // Ambas salas
        'metas' => [
            ['min' => 0, 'max' => 300000, 'porcentaje' => 0.50],
            ['min' => 300001, 'max' => 350000, 'porcentaje' => 0.75],
            ['min' => 350001, 'max' => null, 'porcentaje' => 1.00]
        ]
    ],

    'MAGALY' => [
        'nombre' => 'MAGALY',
        'salas' => [1, 2], // Ambas salas
        'metas' => [
            ['min' => 0, 'max' => null, 'porcentaje' => 1.25]
        ]
    ]
];

// Función para formatear moneda
function formatoMoneda($valor)
{
    return '$' . number_format($valor, 2);
}

// Función para calcular OVER
function calcularOver($ventaNetoTotal, $config)
{
    $porcentaje = 0;
    $totalOver = 0;

    if ($ventaNetoTotal <= 300000) {
        $porcentaje = $config['metas'][0]['porcentaje'];
        $totalOver = $ventaNetoTotal * $porcentaje / 100;
    } elseif ($ventaNetoTotal <= 350000) {
        $porcentaje = $config['metas'][1]['porcentaje'];
        $totalOver = (300000 * $config['metas'][0]['porcentaje'] / 100) +
            (($ventaNetoTotal - 300000) * $porcentaje / 100);
    } else {
        $porcentaje = $config['metas'][2]['porcentaje'];
        $totalOver = (300000 * $config['metas'][0]['porcentaje'] / 100) +
            (50000 * $config['metas'][1]['porcentaje'] / 100) +
            (($ventaNetoTotal - 350000) * $porcentaje / 100);
    }

    return ['porcentaje' => $porcentaje, 'totalOver' => $totalOver];
}

// ============================================================================
// CALCULAR VENTAS POR SALA PARA OVER CORRECTO
// ============================================================================

// Calcular ventas por sala para OVER correcto (siempre se calcula)
$ventasPorSalaDetalle = [];
foreach ($ventas as $venta) {
    $salaIdVenta = $venta['sala_id'] ?? 0;
    $salaNombreVenta = $venta['sala_nombre'] ?? 'Sin Sala';

    if (!isset($ventasPorSalaDetalle[$salaIdVenta])) {
        $ventasPorSalaDetalle[$salaIdVenta] = [
            'nombre' => $salaNombreVenta,
            'ventas' => [],
            'totales' => [
                'venta_bruto' => 0,
                'venta_neto' => 0,
                'comision_liner' => 0,
                'comision_closer1' => 0,
                'comision_closer2' => 0,
                'comision_front' => 0,
                'suma_comisiones' => 0,
                'ventas_count' => 0
            ]
        ];
    }

    $ventasPorSalaDetalle[$salaIdVenta]['ventas'][] = $venta;

    // Sumar totales
    $ventasPorSalaDetalle[$salaIdVenta]['totales']['venta_bruto'] += (float)$venta['precio'];
    $ventasPorSalaDetalle[$salaIdVenta]['totales']['venta_neto'] += (float)$venta['precio_neto'];
    $ventasPorSalaDetalle[$salaIdVenta]['totales']['comision_liner'] += (float)($venta['ganancia_liner'] ?? 0);
    $ventasPorSalaDetalle[$salaIdVenta]['totales']['comision_closer1'] += (float)($venta['ganancia_closer1'] ?? 0);
    $ventasPorSalaDetalle[$salaIdVenta]['totales']['comision_closer2'] += (float)($venta['ganancia_closer2'] ?? 0);
    $ventasPorSalaDetalle[$salaIdVenta]['totales']['comision_front'] += (float)($venta['ganancia_fullstack'] ?? 0);
    $ventasPorSalaDetalle[$salaIdVenta]['totales']['ventas_count']++;
}

// Calcular suma de comisiones para cada sala
foreach ($ventasPorSalaDetalle as $idSala => &$datosSala) {
    $datosSala['totales']['suma_comisiones'] =
        $datosSala['totales']['comision_liner'] +
        $datosSala['totales']['comision_closer1'] +
        $datosSala['totales']['comision_closer2'] +
        $datosSala['totales']['comision_front'];
}
unset($datosSala);

// ============================================================================
// CREAR PDF
// ============================================================================

// Crear nuevo documento PDF
$pdf = new TCPDF('L', PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);

// Información del documento
$pdf->SetCreator('Sistema CRM - Grupo Ideas');
$pdf->SetAuthor('Sistema CRM');
$pdf->SetTitle('Reporte de Ventas - Comisiones');
$pdf->SetSubject('Reporte de Ventas');
$pdf->SetKeywords('ventas, reporte, pdf, crm, comisiones');

// Configurar márgenes
$pdf->SetMargins(10, 15, 10);
$pdf->SetHeaderMargin(5);
$pdf->SetFooterMargin(10);

// Saltos de página automáticos
$pdf->SetAutoPageBreak(TRUE, 15);

// Agregar página
$pdf->AddPage();

// ============================================================================
// PÁGINA 1: RESUMEN GENERAL
// ============================================================================

// Título principal
$pdf->SetFont('helvetica', 'B', 16);
$pdf->SetFillColor(52, 152, 219);
$pdf->SetTextColor(255, 255, 255);

if ($salaId > 0) {
    $tituloPrincipal = 'REPORTE DE VENTAS - GRUPO IDEAS - SALA: ' . strtoupper($salaNombre);
} else {
    $tituloPrincipal = 'REPORTE DE VENTAS - GRUPO IDEAS - TODAS LAS SALAS';
}

if (!empty($plataformasNombres)) {
    if (count($plataformasNombres) > 1) {
        $tituloPrincipal .= ' - PLATAFORMAS: ' . implode(', ', $plataformasNombres);
    } else {
        $tituloPrincipal .= ' - PLATAFORMA: ' . implode(', ', $plataformasNombres);
    }
}

$pdf->Cell(0, 12, $tituloPrincipal, 0, 1, 'C', true);
$pdf->Cell(0, 12, 'SISTEMA DE COMISIONES Y OVER', 0, 1, 'C', true);

// Información de filtros
$pdf->SetFont('helvetica', 'I', 10);
$pdf->SetTextColor(0, 0, 0);
$filtroTexto = 'Todos los registros';
if (!empty($fechaInicio) && !empty($fechaFin)) {
    $filtroTexto = "Desde: " . date('d/m/Y', strtotime($fechaInicio)) . " Hasta: " . date('d/m/Y', strtotime($fechaFin));
} elseif (!empty($fechaInicio)) {
    $filtroTexto = "Desde: " . date('d/m/Y', strtotime($fechaInicio));
} elseif (!empty($fechaFin)) {
    $filtroTexto = "Hasta: " . date('d/m/Y', strtotime($fechaFin));
}

// AGREGAR SALA AL TEXTO DEL FILTRO
if ($salaId > 0) {
    $filtroTexto .= empty($filtroTexto) ? "Sala: " . $salaNombre : " | Sala: " . $salaNombre;
} else {
    $filtroTexto .= empty($filtroTexto) ? "Todas las Salas" : " | Todas las Salas";
}

// Agregar tipo_venta al texto del filtro
if (!empty($tipoVenta)) {
    $tipoVentaTexto = $tipoVenta === 'nuevo' ? 'Nuevo' : 'Repack';
    $filtroTexto .= empty($filtroTexto) ? "Tipo: " . $tipoVentaTexto : " | Tipo: " . $tipoVentaTexto;
}

if (!empty($busqueda)) {
    $filtroTexto .= empty($filtroTexto) ? "Búsqueda: " . htmlspecialchars($busqueda) : " | Búsqueda: " . htmlspecialchars($busqueda);
}

if (!empty($plataformasNombres)) {
    $filtroTexto .= empty($filtroTexto) ? "Plataformas: " . implode(', ', $plataformasNombres) : " | Plataformas: " . implode(', ', $plataformasNombres);
}

$pdf->Cell(0, 8, 'Filtro aplicado: ' . $filtroTexto, 0, 1, 'C');
$pdf->Cell(0, 8, 'Fecha de generación: ' . date('d/m/Y H:i:s'), 0, 1, 'C');

// Espacio
$pdf->Ln(5);

// ============================================================================
// RESUMEN GENERAL (TODO JUNTO)
// ============================================================================

// Calcular totales generales
$totalesGenerales = [
    'venta_bruto' => 0,
    'venta_neto' => 0,
    'comision_liner' => 0,
    'comision_closer1' => 0,
    'comision_closer2' => 0,
    'comision_front' => 0,
    'suma_comisiones' => 0,
    'ventas_count' => count($ventas)
];

foreach ($ventas as $venta) {
    $totalesGenerales['venta_bruto'] += (float)$venta['precio'];
    $totalesGenerales['venta_neto'] += (float)$venta['precio_neto'];
    $totalesGenerales['comision_liner'] += (float)($venta['ganancia_liner'] ?? 0);
    $totalesGenerales['comision_closer1'] += (float)($venta['ganancia_closer1'] ?? 0);
    $totalesGenerales['comision_closer2'] += (float)($venta['ganancia_closer2'] ?? 0);
    $totalesGenerales['comision_front'] += (float)($venta['ganancia_fullstack'] ?? 0);
}

$totalesGenerales['suma_comisiones'] =
    $totalesGenerales['comision_liner'] +
    $totalesGenerales['comision_closer1'] +
    $totalesGenerales['comision_closer2'] +
    $totalesGenerales['comision_front'];

// Tabla de totales generales
$pdf->SetFont('helvetica', 'B', 12);
$pdf->SetFillColor(52, 73, 94);
$pdf->SetTextColor(255, 255, 255);

if ($salaId > 0) {
    $pdf->Cell(0, 8, 'TOTALES DE LA SALA: ' . strtoupper($salaNombre), 0, 1, 'C', true);
} else {
    $pdf->Cell(0, 8, 'TOTALES GENERALES - TODAS LAS SALAS', 0, 1, 'C', true);
}

$pdf->SetFont('helvetica', '', 10);
$pdf->SetTextColor(0, 0, 0);

// Tabla de totales
$header = ['Concepto', 'Monto'];
$data = [
    ['Número de Ventas', (string)$totalesGenerales['ventas_count']],
   ['Venta Bruto Total', formatoMoneda($totalesGenerales['venta_bruto'])],
    ['Venta Neto Total', formatoMoneda($totalesGenerales['venta_neto'])],
    ['Comisión Liner Total', formatoMoneda($totalesGenerales['comision_liner'])],
    ['Comisión Closer 1 Total', formatoMoneda($totalesGenerales['comision_closer1'])],
    ['Comisión Closer 2 Total', formatoMoneda($totalesGenerales['comision_closer2'])],
    ['Comisión Front Total', formatoMoneda($totalesGenerales['comision_front'])],
    ['Suma Comisiones Total', formatoMoneda($totalesGenerales['suma_comisiones'])]
];

// Crear tabla de totales
$pdf->SetFont('helvetica', 'B', 10);
$pdf->SetFillColor(220, 220, 220);
$pdf->Cell(140, 8, $header[0], 1, 0, 'C', true);
$pdf->Cell(50, 8, $header[1], 1, 1, 'C', true);

$pdf->SetFont('helvetica', '', 9);
$fill = false;
foreach ($data as $row) {
    $pdf->SetFillColor($fill ? 240 : 255, $fill ? 240 : 255, $fill ? 240 : 255);
    $pdf->Cell(140, 8, $row[0], 1, 0, 'L', $fill);
    $pdf->Cell(50, 8, $row[1], 1, 1, 'R', $fill);
    $fill = !$fill;
}

$pdf->Ln(10);

// ============================================================================
// TABLA DETALLADA DE VENTAS (TODO JUNTO)
// ============================================================================

$pdf->SetFont('helvetica', 'B', 12);
$pdf->SetFillColor(44, 62, 80);
$pdf->SetTextColor(255, 255, 255);

if ($salaId > 0) {
    $pdf->Cell(0, 10, 'DETALLE DE VENTAS - SALA: ' . strtoupper($salaNombre), 0, 1, 'C', true);
} else {
    $pdf->Cell(0, 10, 'DETALLE DE VENTAS - TODAS LAS SALAS', 0, 1, 'C', true);
}

// Headers de la tabla
$headers = ['Fecha', 'Cliente', 'Venta Bruto', 'Venta Neto', 'Liner', 'Closer 1', 'Closer 2', 'Front', 'Plataforma', 'Tipo', 'Sala'];

$pdf->SetFont('helvetica', 'B', 7);
$pdf->SetTextColor(0, 0, 0);
$pdf->SetFillColor(220, 220, 220);

// Ajustar anchos de columnas para orientación landscape
$widths = [18, 35, 20, 20, 25, 25, 25, 25, 35, 15, 15];

// Dibujar headers
for ($i = 0; $i < count($headers); $i++) {
    $pdf->Cell($widths[$i], 8, $headers[$i], 1, 0, 'C', true);
}
$pdf->Ln();

// Datos de ventas
$pdf->SetFont('helvetica', '', 6);
$fill = false;
$contador = 0;

foreach ($ventas as $venta) {
    // Verificar si necesita nueva página
    if ($contador > 0 && $contador % 25 == 0) {
        $pdf->AddPage();
        // Redibujar headers
        $pdf->SetFont('helvetica', 'B', 7);
        $pdf->SetFillColor(220, 220, 220);
        for ($i = 0; $i < count($headers); $i++) {
            $pdf->Cell($widths[$i], 8, $headers[$i], 1, 0, 'C', true);
        }
        $pdf->Ln();
        $pdf->SetFont('helvetica', '', 6);
    }

    $pdf->SetFillColor($fill ? 240 : 255, $fill ? 240 : 255, $fill ? 240 : 255);

    // Fecha
    $pdf->Cell($widths[0], 6, date('d/m/Y', strtotime($venta['fecha'])), 1, 0, 'C', $fill);

    // Cliente (recortar si es muy largo)
    $cliente = substr($venta['cliente_nombre'], 0, 20);
    $pdf->Cell($widths[1], 6, $cliente, 1, 0, 'L', $fill);

    // Venta Bruto
    $pdf->Cell($widths[2], 6, formatoMoneda($venta['precio']), 1, 0, 'R', $fill);

    // Venta Neto
    $pdf->Cell($widths[3], 6, formatoMoneda($venta['precio_neto']), 1, 0, 'R', $fill);

    // Liner
    $liner = substr($venta['liner_nombre'] ?? 'N/A', 0, 12);
    $pdf->Cell($widths[4], 6, $liner, 1, 0, 'L', $fill);

    // Closer 1
    $closer1 = substr($venta['closer1_nombre'] ?? 'N/A', 0, 12);
    $pdf->Cell($widths[5], 6, $closer1, 1, 0, 'L', $fill);

    // Closer 2
    $closer2 = substr($venta['closer2_nombre'] ?? 'N/A', 0, 12);
    $pdf->Cell($widths[6], 6, $closer2, 1, 0, 'L', $fill);

    // Front
    $front = substr($venta['front_nombre'] ?? 'N/A', 0, 12);
    $pdf->Cell($widths[7], 6, $front, 1, 0, 'L', $fill);

    // Plataforma
    $plataforma = substr($venta['plataforma_nombre_completo'] ?? 'N/A', 0, 20);
    $pdf->Cell($widths[8], 6, $plataforma, 1, 0, 'L', $fill);

    // Tipo Venta
    $tipo = $venta['tipo_venta'] ?? 'N/A';
    $pdf->Cell($widths[9], 6, $tipo, 1, 0, 'C', $fill);

    // Sala
    $sala = $venta['sala_nombre'] ?? 'N/A';
    $pdf->Cell($widths[10], 6, $sala, 1, 1, 'C', $fill);

    $fill = !$fill;
    $contador++;
}

// ============================================================================
// SISTEMA DE OVER - CON SUMA POR PERSONA
// ============================================================================

$pdf->AddPage();

// Título del sistema OVER
$pdf->SetFont('helvetica', 'B', 16);
$pdf->SetFillColor(142, 68, 173);
$pdf->SetTextColor(255, 255, 255);

if ($salaId > 0) {
    $tituloOver = 'SISTEMA DE OVER - BONOS POR META - SALA: ' . strtoupper($salaNombre);
} else {
    $tituloOver = 'SISTEMA DE OVER - BONOS POR META - TODAS LAS SALAS';
}
$pdf->Cell(0, 12, $tituloOver, 0, 1, 'C', true);

$pdf->SetFont('helvetica', 'I', 10);
$pdf->SetTextColor(0, 0, 0);

if ($salaId == 0) {
    $pdf->Cell(0, 8, 'NOTA: El OVER se calcula por separado para cada sala según la configuración y se suma por persona', 0, 1, 'C');
}

$pdf->Ln(5);

// ============================================================================
// CÁLCULO DE OVER - CON SUMA POR PERSONA
// ============================================================================

$pdf->SetFont('helvetica', 'B', 12);
$pdf->SetFillColor(52, 73, 94);
$pdf->SetTextColor(255, 255, 255);

if ($salaId > 0) {
    $pdf->Cell(0, 10, 'CÁLCULO DE OVER PARA LA SALA: ' . strtoupper($salaNombre), 0, 1, 'C', true);
} else {
    $pdf->Cell(0, 10, 'CÁLCULO DE OVER POR PERSONAL', 0, 1, 'C', true);
}

// Headers de la tabla OVER
if ($salaId > 0) {
    $overHeaders = ['Personal', 'Vol Sala (Neto)', 'Porcentaje', 'Total Over'];
} else {
    $overHeaders = ['Personal', 'Vol Total (Neto)', 'Porcentaje', 'Total Over'];
}

$pdf->SetFont('helvetica', 'B', 9);
$pdf->SetTextColor(0, 0, 0);
$pdf->SetFillColor(200, 200, 200);

if ($salaId > 0) {
    $pdf->Cell(50, 8, $overHeaders[0], 1, 0, 'C', true);
    $pdf->Cell(40, 8, $overHeaders[1], 1, 0, 'C', true);
    $pdf->Cell(30, 8, $overHeaders[2], 1, 0, 'C', true);
    $pdf->Cell(40, 8, $overHeaders[3], 1, 1, 'C', true);
} else {
    $pdf->Cell(50, 8, $overHeaders[0], 1, 0, 'C', true);
    $pdf->Cell(40, 8, $overHeaders[1], 1, 0, 'C', true);
    $pdf->Cell(30, 8, $overHeaders[2], 1, 0, 'C', true);
    $pdf->Cell(40, 8, $overHeaders[3], 1, 1, 'C', true);
}

// Datos de OVER
$pdf->SetFont('helvetica', '', 9);
$fill = false;
$totalOverGeneral = 0;

// ============================================================================
// LÓGICA DE CÁLCULO DE OVER CON SUMA
// ============================================================================

if ($salaId > 0) {
    // SI HAY FILTRO POR SALA ESPECÍFICA
    $ventaNetoSala = $totalesGenerales['venta_neto'];

    foreach ($configOver as $persona => $config) {
        // Verificar si esta configuración aplica a la sala actual
        if (in_array($salaId, $config['salas'])) {
            $calculos = calcularOver($ventaNetoSala, $config);
            $totalOverGeneral += $calculos['totalOver'];

            $pdf->SetFillColor($fill ? 240 : 255, $fill ? 240 : 255, $fill ? 240 : 255);

            $pdf->Cell(50, 8, $config['nombre'], 1, 0, 'L', $fill);
            $pdf->Cell(40, 8, formatoMoneda($ventaNetoSala), 1, 0, 'R', $fill);
            $pdf->Cell(30, 8, $calculos['porcentaje'] . '%', 1, 0, 'C', $fill);
            $pdf->Cell(40, 8, formatoMoneda($calculos['totalOver']), 1, 1, 'R', $fill);

            $fill = !$fill;
        }
    }
} else {
    // SI NO HAY FILTRO (TODAS LAS SALAS JUNTAS) - MOSTRAR SUMA POR PERSONA
    $overPorPersona = [];

    foreach ($configOver as $persona => $config) {
        $overPorPersona[$persona] = [
            'nombre' => $config['nombre'],
            'total_over' => 0,
            'volumen_total' => 0,
            'detalle_salas' => []
        ];

        // Calcular OVER para cada sala donde aplica esta persona
        foreach ($config['salas'] as $salaIdConfig) {
            // Buscar los datos de esta sala en el detalle
            if (isset($ventasPorSalaDetalle[$salaIdConfig])) {
                $datosSalaConfig = $ventasPorSalaDetalle[$salaIdConfig];
                $ventaNetoSala = $datosSalaConfig['totales']['venta_neto'];

                $calculos = calcularOver($ventaNetoSala, $config);
                $totalOverPersonaSala = $calculos['totalOver'];

                // Acumular para esta persona
                $overPorPersona[$persona]['total_over'] += $totalOverPersonaSala;
                $overPorPersona[$persona]['volumen_total'] += $ventaNetoSala;
                $overPorPersona[$persona]['detalle_salas'][] = [
                    'sala_id' => $salaIdConfig,
                    'sala_nombre' => $datosSalaConfig['nombre'],
                    'venta_neto' => $ventaNetoSala,
                    'porcentaje' => $calculos['porcentaje'],
                    'over_sala' => $totalOverPersonaSala
                ];
            }
        }

        // Solo mostrar si tiene algún OVER
        if ($overPorPersona[$persona]['total_over'] > 0 || $overPorPersona[$persona]['volumen_total'] > 0) {
            $totalOverGeneral += $overPorPersona[$persona]['total_over'];

            $pdf->SetFillColor($fill ? 240 : 255, $fill ? 240 : 255, $fill ? 240 : 255);

            $pdf->Cell(50, 8, $overPorPersona[$persona]['nombre'], 1, 0, 'L', $fill);
            $pdf->Cell(40, 8, formatoMoneda($overPorPersona[$persona]['volumen_total']), 1, 0, 'R', $fill);

            // Calcular porcentaje promedio ponderado
            $porcentajePromedio = $overPorPersona[$persona]['volumen_total'] > 0 ?
                ($overPorPersona[$persona]['total_over'] / $overPorPersona[$persona]['volumen_total']) * 100 : 0;
            $pdf->Cell(30, 8, number_format($porcentajePromedio, 2) . '%', 1, 0, 'C', $fill);

            $pdf->Cell(40, 8, formatoMoneda($overPorPersona[$persona]['total_over']), 1, 1, 'R', $fill);

            $fill = !$fill;
        }
    }
}

// Total general de OVER
$pdf->SetFont('helvetica', 'B', 10);
$pdf->SetFillColor(39, 174, 96);
$pdf->SetTextColor(255, 255, 255);

if ($salaId > 0) {
    $pdf->Cell(120, 8, 'TOTAL OVER PARA LA SALA:', 1, 0, 'R', true);
    $pdf->Cell(40, 8, formatoMoneda($totalOverGeneral), 1, 1, 'R', true);
} else {
    $pdf->Cell(120, 8, 'TOTAL OVER GENERAL:', 1, 0, 'R', true);
    $pdf->Cell(40, 8, formatoMoneda($totalOverGeneral), 1, 1, 'R', true);
}

// Si es reporte GENERAL, mostrar detalle por persona
if ($salaId == 0 && isset($overPorPersona) && !empty($overPorPersona)) {
    $pdf->Ln(5);

    $pdf->SetFont('helvetica', 'B', 10);
    $pdf->SetFillColor(52, 73, 94);
    $pdf->SetTextColor(255, 255, 255);
    $pdf->Cell(0, 8, 'DETALLE DE OVER POR PERSONA Y SALA', 0, 1, 'C', true);

    foreach ($overPorPersona as $persona => $datosPersona) {
        if (!empty($datosPersona['detalle_salas'])) {
            $pdf->SetFont('helvetica', 'B', 9);
            $pdf->SetTextColor(0, 0, 0);
            $pdf->Cell(0, 6, 'Persona: ' . $datosPersona['nombre'] . ' - Total OVER: ' . formatoMoneda($datosPersona['total_over']), 0, 1);

            $pdf->SetFont('helvetica', 'B', 8);
            $pdf->SetFillColor(220, 220, 220);
            $pdf->Cell(60, 6, 'Sala', 1, 0, 'C', true);
            $pdf->Cell(40, 6, 'Volumen Sala', 1, 0, 'C', true);
            $pdf->Cell(30, 6, 'Porcentaje', 1, 0, 'C', true);
            $pdf->Cell(40, 6, 'Over Sala', 1, 1, 'C', true);

            $pdf->SetFont('helvetica', '', 8);
            $fill = false;

            foreach ($datosPersona['detalle_salas'] as $detalleSala) {
                $pdf->SetFillColor($fill ? 240 : 255, $fill ? 240 : 255, $fill ? 240 : 255);

                $pdf->Cell(60, 6, $detalleSala['sala_nombre'] . ' (Sala ' . $detalleSala['sala_id'] . ')', 1, 0, 'L', $fill);
                $pdf->Cell(40, 6, formatoMoneda($detalleSala['venta_neto']), 1, 0, 'R', $fill);
                $pdf->Cell(30, 6, $detalleSala['porcentaje'] . '%', 1, 0, 'C', $fill);
                $pdf->Cell(40, 6, formatoMoneda($detalleSala['over_sala']), 1, 1, 'R', $fill);

                $fill = !$fill;
            }

            $pdf->Ln(2);
        }
    }
}

$pdf->Ln(10);

// ============================================================================
// RESUMEN POR USUARIO (TODO JUNTO)
// ============================================================================

// Procesar usuarios con ganancias
$usuariosConGanancias = [];
foreach ($ventas as $venta) {
    // Procesar Liner
    $nombreLiner = !empty($venta['liner_nombre_manual']) && trim($venta['liner_nombre_manual']) !== ''
        ? trim($venta['liner_nombre_manual'])
        : (!empty($venta['liner_nombre']) && $venta['liner_nombre'] !== 'No asignado'
            ? trim($venta['liner_nombre'])
            : null);

    if ($nombreLiner && (float)($venta['ganancia_liner'] ?? 0) > 0) {
        $claveUsuario = normalizarNombre($nombreLiner);
        if (!isset($usuariosConGanancias[$claveUsuario])) {
            $usuariosConGanancias[$claveUsuario] = [
                'nombre' => $nombreLiner,
                'ganancia_total' => 0,
                'ventas_count' => 0,
                'roles' => [],
                'tiene_over' => false,
                'over_config' => null,
                'over_por_sala' => []
            ];
        }
        $ganancia = (float)($venta['ganancia_liner'] ?? 0);
        $usuariosConGanancias[$claveUsuario]['ganancia_total'] += $ganancia;
        $usuariosConGanancias[$claveUsuario]['ventas_count']++;
        if (!in_array('Liner', $usuariosConGanancias[$claveUsuario]['roles'])) {
            $usuariosConGanancias[$claveUsuario]['roles'][] = 'Liner';
        }
    }

    // Procesar Closer 1
    $nombreCloser1 = !empty($venta['closer1_nombre_manual']) && trim($venta['closer1_nombre_manual']) !== ''
        ? trim($venta['closer1_nombre_manual'])
        : (!empty($venta['closer1_nombre']) && $venta['closer1_nombre'] !== 'No asignado'
            ? trim($venta['closer1_nombre'])
            : null);

    if ($nombreCloser1 && (float)($venta['ganancia_closer1'] ?? 0) > 0) {
        $claveUsuario = normalizarNombre($nombreCloser1);
        if (!isset($usuariosConGanancias[$claveUsuario])) {
            $usuariosConGanancias[$claveUsuario] = [
                'nombre' => $nombreCloser1,
                'ganancia_total' => 0,
                'ventas_count' => 0,
                'roles' => [],
                'tiene_over' => false,
                'over_config' => null,
                'over_por_sala' => []
            ];
        }
        $ganancia = (float)($venta['ganancia_closer1'] ?? 0);
        $usuariosConGanancias[$claveUsuario]['ganancia_total'] += $ganancia;
        $usuariosConGanancias[$claveUsuario]['ventas_count']++;
        if (!in_array('Closer 1', $usuariosConGanancias[$claveUsuario]['roles'])) {
            $usuariosConGanancias[$claveUsuario]['roles'][] = 'Closer 1';
        }
    }

    // Procesar Closer 2
    $nombreCloser2 = !empty($venta['closer2_nombre_manual']) && trim($venta['closer2_nombre_manual']) !== ''
        ? trim($venta['closer2_nombre_manual'])
        : (!empty($venta['closer2_nombre']) && $venta['closer2_nombre'] !== 'No asignado'
            ? trim($venta['closer2_nombre'])
            : null);

    if ($nombreCloser2 && (float)($venta['ganancia_closer2'] ?? 0) > 0) {
        $claveUsuario = normalizarNombre($nombreCloser2);
        if (!isset($usuariosConGanancias[$claveUsuario])) {
            $usuariosConGanancias[$claveUsuario] = [
                'nombre' => $nombreCloser2,
                'ganancia_total' => 0,
                'ventas_count' => 0,
                'roles' => [],
                'tiene_over' => false,
                'over_config' => null,
                'over_por_sala' => []
            ];
        }
        $ganancia = (float)($venta['ganancia_closer2'] ?? 0);
        $usuariosConGanancias[$claveUsuario]['ganancia_total'] += $ganancia;
        $usuariosConGanancias[$claveUsuario]['ventas_count']++;
        if (!in_array('Closer 2', $usuariosConGanancias[$claveUsuario]['roles'])) {
            $usuariosConGanancias[$claveUsuario]['roles'][] = 'Closer 2';
        }
    }

    // Procesar Front
    $nombreFront = !empty($venta['full_stack_nombre_manual']) && trim($venta['full_stack_nombre_manual']) !== ''
        ? trim($venta['full_stack_nombre_manual'])
        : (!empty($venta['front_nombre']) && $venta['front_nombre'] !== 'No asignado'
            ? trim($venta['front_nombre'])
            : null);

    if ($nombreFront && (float)($venta['ganancia_fullstack'] ?? 0) > 0) {
        $claveUsuario = normalizarNombre($nombreFront);
        if (!isset($usuariosConGanancias[$claveUsuario])) {
            $usuariosConGanancias[$claveUsuario] = [
                'nombre' => $nombreFront,
                'ganancia_total' => 0,
                'ventas_count' => 0,
                'roles' => [],
                'tiene_over' => false,
                'over_config' => null,
                'over_por_sala' => []
            ];
        }
        $ganancia = (float)($venta['ganancia_fullstack'] ?? 0);
        $usuariosConGanancias[$claveUsuario]['ganancia_total'] += $ganancia;
        $usuariosConGanancias[$claveUsuario]['ventas_count']++;
        if (!in_array('Front', $usuariosConGanancias[$claveUsuario]['roles'])) {
            $usuariosConGanancias[$claveUsuario]['roles'][] = 'Front';
        }
    }
}

// Identificar usuarios OVER y calcular OVER por sala
foreach ($usuariosConGanancias as $clave => $usuario) {
    $nombreUsuario = trim($usuario['nombre']);

    // Buscar coincidencia PARCIAL en la configuración de OVER
    foreach ($configOver as $key => $config) {
        if (stripos($nombreUsuario, $config['nombre']) !== false) {
            $usuariosConGanancias[$clave]['tiene_over'] = true;
            $usuariosConGanancias[$clave]['over_config'] = $config;

            // Calcular OVER por sala para este usuario
            if ($salaId > 0) {
                // Para sala específica: calcular OVER para esta sala
                if (in_array($salaId, $config['salas'])) {
                    $calculos = calcularOver($totalesGenerales['venta_neto'], $config);
                    $usuariosConGanancias[$clave]['over_por_sala'][$salaId] = [
                        'sala_id' => $salaId,
                        'sala_nombre' => $salaNombre,
                        'venta_neto' => $totalesGenerales['venta_neto'],
                        'porcentaje' => $calculos['porcentaje'],
                        'total_over' => $calculos['totalOver']
                    ];
                }
            } else {
                // Para reporte GENERAL: calcular OVER por cada sala aplicable
                foreach ($config['salas'] as $salaIdConfig) {
                    if (isset($ventasPorSalaDetalle[$salaIdConfig])) {
                        $datosSalaConfig = $ventasPorSalaDetalle[$salaIdConfig];
                        $ventaNetoSala = $datosSalaConfig['totales']['venta_neto'];
                        $calculos = calcularOver($ventaNetoSala, $config);

                        $usuariosConGanancias[$clave]['over_por_sala'][$salaIdConfig] = [
                            'sala_id' => $salaIdConfig,
                            'sala_nombre' => $datosSalaConfig['nombre'],
                            'venta_neto' => $ventaNetoSala,
                            'porcentaje' => $calculos['porcentaje'],
                            'total_over' => $calculos['totalOver']
                        ];
                    }
                }
            }
            break;
        }
    }
}

// Ordenar por ganancia total descendente
uasort($usuariosConGanancias, function ($a, $b) {
    return $b['ganancia_total'] <=> $a['ganancia_total'];
});

if (!empty($usuariosConGanancias)) {
    $pdf->AddPage();

    // Título de resumen por usuario
    $pdf->SetFont('helvetica', 'B', 16);
    $pdf->SetFillColor(231, 76, 60);
    $pdf->SetTextColor(255, 255, 255);

    if ($salaId > 0) {
        $tituloUsuarios = 'RESUMEN DE COMISIONES POR USUARIO - SALA: ' . strtoupper($salaNombre);
    } else {
        $tituloUsuarios = 'RESUMEN DE COMISIONES POR USUARIO - TODAS LAS SALAS';
    }

    $pdf->Cell(0, 12, $tituloUsuarios, 0, 1, 'C', true);

    // Headers de la tabla de usuarios
    $userHeaders = ['Usuario', 'Roles', 'Total Ventas', 'Ganancia Total', 'Over', 'Total General'];

    $pdf->SetFont('helvetica', 'B', 9);
    $pdf->SetTextColor(0, 0, 0);
    $pdf->SetFillColor(200, 200, 200);

    $pdf->Cell(60, 8, $userHeaders[0], 1, 0, 'C', true);
    $pdf->Cell(40, 8, $userHeaders[1], 1, 0, 'C', true);
    $pdf->Cell(25, 8, $userHeaders[2], 1, 0, 'C', true);
    $pdf->Cell(30, 8, $userHeaders[3], 1, 0, 'C', true);
    $pdf->Cell(30, 8, $userHeaders[4], 1, 0, 'C', true);
    $pdf->Cell(35, 8, $userHeaders[5], 1, 1, 'C', true);

    // Datos de usuarios
    $pdf->SetFont('helvetica', '', 8);
    $fill = false;
    $totalGananciaUsuarios = 0;
    $totalOverUsuarios = 0;

    foreach ($usuariosConGanancias as $usuario) {
        // Calcular OVER total para este usuario
        $overUsuarioTotal = 0;
        if ($usuario['tiene_over'] && !empty($usuario['over_por_sala'])) {
            foreach ($usuario['over_por_sala'] as $overSala) {
                $overUsuarioTotal += $overSala['total_over'];
            }
        }

        $totalGeneral = $usuario['ganancia_total'] + $overUsuarioTotal;
        $totalGananciaUsuarios += $usuario['ganancia_total'];
        $totalOverUsuarios += $overUsuarioTotal;

        $pdf->SetFillColor($fill ? 240 : 255, $fill ? 240 : 255, $fill ? 240 : 255);

        // Usuario
        $nombre = substr($usuario['nombre'], 0, 30);
        $pdf->Cell(60, 8, $nombre, 1, 0, 'L', $fill);

        // Roles
        $roles = substr(implode(', ', $usuario['roles']), 0, 20);
        $pdf->Cell(40, 8, $roles, 1, 0, 'L', $fill);

        // Total Ventas
        $pdf->Cell(25, 8, $usuario['ventas_count'], 1, 0, 'C', $fill);

        // Ganancia Total
        $pdf->Cell(30, 8, formatoMoneda($usuario['ganancia_total']), 1, 0, 'R', $fill);

        // Over
        $pdf->Cell(30, 8, $usuario['tiene_over'] ? formatoMoneda($overUsuarioTotal) : 'N/A', 1, 0, 'R', $fill);

        // Total General
        $pdf->Cell(35, 8, formatoMoneda($totalGeneral), 1, 1, 'R', $fill);

        $fill = !$fill;
    }

    // Total general de usuarios
    $pdf->SetFont('helvetica', 'B', 9);
    $pdf->SetFillColor(52, 152, 219);
    $pdf->SetTextColor(255, 255, 255);
    $pdf->Cell(155, 8, 'TOTAL GENERAL USUARIOS:', 1, 0, 'R', true);
    $pdf->Cell(35, 8, formatoMoneda($totalGananciaUsuarios), 1, 1, 'R', true);

    // Detalle de OVER por usuario (solo para usuarios con OVER)
    $usuariosConOver = array_filter($usuariosConGanancias, function ($usuario) {
        return $usuario['tiene_over'] && !empty($usuario['over_por_sala']);
    });

    if (!empty($usuariosConOver)) {
        $pdf->Ln(5);

        $pdf->SetFont('helvetica', 'B', 10);
        $pdf->SetFillColor(142, 68, 173);
        $pdf->SetTextColor(255, 255, 255);

        if ($salaId > 0) {
            $pdf->Cell(0, 8, 'DETALLE DE OVER POR USUARIO - SALA: ' . strtoupper($salaNombre), 0, 1, 'C', true);
        } else {
            $pdf->Cell(0, 8, 'DETALLE DE OVER POR USUARIO Y SALA', 0, 1, 'C', true);
        }

        foreach ($usuariosConOver as $usuario) {
            $pdf->SetFont('helvetica', 'B', 9);
            $pdf->SetTextColor(0, 0, 0);
            $pdf->Cell(0, 6, 'Usuario: ' . $usuario['nombre'], 0, 1);

            $pdf->SetFont('helvetica', 'B', 8);
            $pdf->SetFillColor(220, 220, 220);

            if ($salaId > 0) {
                $pdf->Cell(60, 6, 'Sala', 1, 0, 'C', true);
                $pdf->Cell(40, 6, 'Venta Neto Sala', 1, 0, 'C', true);
                $pdf->Cell(30, 6, 'Porcentaje', 1, 0, 'C', true);
                $pdf->Cell(40, 6, 'Over Sala', 1, 1, 'C', true);
            } else {
                $pdf->Cell(60, 6, 'Sala', 1, 0, 'C', true);
                $pdf->Cell(40, 6, 'Venta Neto Sala', 1, 0, 'C', true);
                $pdf->Cell(30, 6, 'Porcentaje', 1, 0, 'C', true);
                $pdf->Cell(40, 6, 'Over Sala', 1, 1, 'C', true);
            }

            $pdf->SetFont('helvetica', '', 8);
            $fill = false;
            $totalOverUsuario = 0;

            foreach ($usuario['over_por_sala'] as $overSala) {
                $pdf->SetFillColor($fill ? 240 : 255, $fill ? 240 : 255, $fill ? 240 : 255);

                $pdf->Cell(60, 6, $overSala['sala_nombre'], 1, 0, 'L', $fill);
                $pdf->Cell(40, 6, formatoMoneda($overSala['venta_neto']), 1, 0, 'R', $fill);
                $pdf->Cell(30, 6, $overSala['porcentaje'] . '%', 1, 0, 'C', $fill);
                $pdf->Cell(40, 6, formatoMoneda($overSala['total_over']), 1, 1, 'R', $fill);

                $totalOverUsuario += $overSala['total_over'];
                $fill = !$fill;
            }

            $pdf->SetFont('helvetica', 'B', 8);
            $pdf->SetFillColor(200, 230, 200);
            $pdf->Cell(130, 6, 'TOTAL OVER ' . $usuario['nombre'] . ':', 1, 0, 'R', true);
            $pdf->Cell(40, 6, formatoMoneda($totalOverUsuario), 1, 1, 'R', true);

            $pdf->Ln(2);
        }
    }

    // ============================================================================
    // GANANCIA VOLUMEN
    // ============================================================================

    $pdf->AddPage();

    // Título de ganancia volumen
    $pdf->SetFont('helvetica', 'B', 16);
    $pdf->SetFillColor(155, 89, 182);
    $pdf->SetTextColor(255, 255, 255);

    if ($salaId > 0) {
        $tituloVolumen = 'GANANCIA VOLUMEN POR USUARIO Y ROL - SALA: ' . strtoupper($salaNombre);
    } else {
        $tituloVolumen = 'GANANCIA VOLUMEN POR USUARIO Y ROL - TODAS LAS SALAS';
    }

    $pdf->Cell(0, 12, $tituloVolumen, 0, 1, 'C', true);

    $pdf->SetFont('helvetica', 'I', 10);
    $pdf->SetTextColor(0, 0, 0);
    $pdf->Cell(0, 8, 'Base: Venta Bruta | Liner=Venta Bruta/2 | Closer 1=Venta Bruta/2 | Closer 2=Venta Bruta/3 | Front=Venta Bruta', 0, 1, 'C');

    $pdf->Ln(5);

    // Calcular ganancia volumen por usuario
    $gananciaVolumenUsuarios = [];
    foreach ($usuariosConGanancias as $clave => $usuario) {
        $gananciaVolumenUsuarios[$clave] = [
            'nombre' => $usuario['nombre'],
            'ganancia_volumen' => 0,
            'ganancia_por_rol' => [
                'Liner' => 0,
                'Closer 1' => 0,
                'Closer 2' => 0,
                'Front' => 0
            ]
        ];
    }

    // Procesar ganancia volumen
    foreach ($ventas as $venta) {
        $ventaBruta = (float)$venta['precio'];

        // Liner
        $nombreLiner = !empty($venta['liner_nombre_manual']) && trim($venta['liner_nombre_manual']) !== ''
            ? trim($venta['liner_nombre_manual'])
            : (!empty($venta['liner_nombre']) && $venta['liner_nombre'] !== 'No asignado'
                ? trim($venta['liner_nombre'])
                : null);

        if ($nombreLiner) {
            $claveUsuario = normalizarNombre($nombreLiner);
            if (isset($gananciaVolumenUsuarios[$claveUsuario])) {
                $gananciaVolumen = $ventaBruta / 2;
                $gananciaVolumenUsuarios[$claveUsuario]['ganancia_volumen'] += $gananciaVolumen;
                $gananciaVolumenUsuarios[$claveUsuario]['ganancia_por_rol']['Liner'] += $gananciaVolumen;
            }
        }

        // Closer 1
        $nombreCloser1 = !empty($venta['closer1_nombre_manual']) && trim($venta['closer1_nombre_manual']) !== ''
            ? trim($venta['closer1_nombre_manual'])
            : (!empty($venta['closer1_nombre']) && $venta['closer1_nombre'] !== 'No asignado'
                ? trim($venta['closer1_nombre'])
                : null);

        if ($nombreCloser1) {
            $claveUsuario = normalizarNombre($nombreCloser1);
            if (isset($gananciaVolumenUsuarios[$claveUsuario])) {
                $gananciaVolumen = $ventaBruta / 2;
                $gananciaVolumenUsuarios[$claveUsuario]['ganancia_volumen'] += $gananciaVolumen;
                $gananciaVolumenUsuarios[$claveUsuario]['ganancia_por_rol']['Closer 1'] += $gananciaVolumen;
            }
        }

        // Closer 2
        $nombreCloser2 = !empty($venta['closer2_nombre_manual']) && trim($venta['closer2_nombre_manual']) !== ''
            ? trim($venta['closer2_nombre_manual'])
            : (!empty($venta['closer2_nombre']) && $venta['closer2_nombre'] !== 'No asignado'
                ? trim($venta['closer2_nombre'])
                : null);

        if ($nombreCloser2) {
            $claveUsuario = normalizarNombre($nombreCloser2);
            if (isset($gananciaVolumenUsuarios[$claveUsuario])) {
                $gananciaVolumen = $ventaBruta / 3;
                $gananciaVolumenUsuarios[$claveUsuario]['ganancia_volumen'] += $gananciaVolumen;
                $gananciaVolumenUsuarios[$claveUsuario]['ganancia_por_rol']['Closer 2'] += $gananciaVolumen;
            }
        }

        // Front
        $nombreFront = !empty($venta['full_stack_nombre_manual']) && trim($venta['full_stack_nombre_manual']) !== ''
            ? trim($venta['full_stack_nombre_manual'])
            : (!empty($venta['front_nombre']) && $venta['front_nombre'] !== 'No asignado'
                ? trim($venta['front_nombre'])
                : null);

        if ($nombreFront) {
            $claveUsuario = normalizarNombre($nombreFront);
            if (isset($gananciaVolumenUsuarios[$claveUsuario])) {
                $gananciaVolumen = $ventaBruta;
                $gananciaVolumenUsuarios[$claveUsuario]['ganancia_volumen'] += $gananciaVolumen;
                $gananciaVolumenUsuarios[$claveUsuario]['ganancia_por_rol']['Front'] += $gananciaVolumen;
            }
        }
    }

    // Ordenar por ganancia volumen descendente
    uasort($gananciaVolumenUsuarios, function ($a, $b) {
        return $b['ganancia_volumen'] <=> $a['ganancia_volumen'];
    });

    // Headers de ganancia volumen
    $volumenHeaders = ['Usuario', 'G.V. Liner', 'G.V. Closer 1', 'G.V. Closer 2', 'G.V. Front', 'Total Usuario'];

    $pdf->SetFont('helvetica', 'B', 8);
    $pdf->SetTextColor(0, 0, 0);
    $pdf->SetFillColor(200, 200, 200);

    $pdf->Cell(50, 8, $volumenHeaders[0], 1, 0, 'C', true);
    $pdf->Cell(30, 8, $volumenHeaders[1], 1, 0, 'C', true);
    $pdf->Cell(30, 8, $volumenHeaders[2], 1, 0, 'C', true);
    $pdf->Cell(30, 8, $volumenHeaders[3], 1, 0, 'C', true);
    $pdf->Cell(30, 8, $volumenHeaders[4], 1, 0, 'C', true);
    $pdf->Cell(30, 8, $volumenHeaders[5], 1, 1, 'C', true);

    // Datos de ganancia volumen
    $pdf->SetFont('helvetica', '', 7);
    $fill = false;
    $totalVolumenGeneral = 0;

    foreach ($gananciaVolumenUsuarios as $usuario) {
        $totalUsuario = $usuario['ganancia_por_rol']['Liner'] +
            $usuario['ganancia_por_rol']['Closer 1'] +
            $usuario['ganancia_por_rol']['Closer 2'] +
            $usuario['ganancia_por_rol']['Front'];

        $totalVolumenGeneral += $totalUsuario;

        $pdf->SetFillColor($fill ? 240 : 255, $fill ? 240 : 255, $fill ? 240 : 255);

        // Usuario
        $nombre = substr($usuario['nombre'], 0, 25);
        $pdf->Cell(50, 8, $nombre, 1, 0, 'L', $fill);

        // G.V. Liner
        $pdf->Cell(30, 8, formatoMoneda($usuario['ganancia_por_rol']['Liner']), 1, 0, 'R', $fill);

        // G.V. Closer 1
        $pdf->Cell(30, 8, formatoMoneda($usuario['ganancia_por_rol']['Closer 1']), 1, 0, 'R', $fill);

        // G.V. Closer 2
        $pdf->Cell(30, 8, formatoMoneda($usuario['ganancia_por_rol']['Closer 2']), 1, 0, 'R', $fill);

        // G.V. Front
        $pdf->Cell(30, 8, formatoMoneda($usuario['ganancia_por_rol']['Front']), 1, 0, 'R', $fill);

        // Total Usuario
        $pdf->Cell(30, 8, formatoMoneda($totalUsuario), 1, 1, 'R', $fill);

        $fill = !$fill;
    }

    // Total general de ganancia volumen
    $pdf->SetFont('helvetica', 'B', 8);
    $pdf->SetFillColor(39, 174, 96);
    $pdf->SetTextColor(255, 255, 255);
    $pdf->Cell(170, 8, 'TOTAL GANANCIA VOLUMEN GENERAL:', 1, 0, 'R', true);
    $pdf->Cell(30, 8, formatoMoneda($totalVolumenGeneral), 1, 1, 'R', true);
}

// ============================================================================
// RESUMEN POR PLATAFORMA (SOLO SI HAY FILTRO POR PLATAFORMA)
// ============================================================================

if (!empty($plataformaIds) && !empty($ventas)) {
    $pdf->AddPage();

    // Título de resumen por plataforma
    $pdf->SetFont('helvetica', 'B', 16);
    $pdf->SetFillColor(52, 152, 219);
    $pdf->SetTextColor(255, 255, 255);

    if ($salaId > 0) {
        $tituloPlataforma = 'RESUMEN POR PLATAFORMA - SALA: ' . strtoupper($salaNombre);
    } else {
        $tituloPlataforma = 'RESUMEN POR PLATAFORMA - TODAS LAS SALAS';
    }

    $pdf->Cell(0, 12, $tituloPlataforma, 0, 1, 'C', true);

    // Agrupar ventas por plataforma
    $ventasPorPlataforma = [];
    foreach ($ventas as $venta) {
        $plataformaId = $venta['plataforma_id'];
        $plataformaNombre = $venta['plataforma_nombre_completo'] ?? 'N/A';

        if (!isset($ventasPorPlataforma[$plataformaId])) {
            $ventasPorPlataforma[$plataformaId] = [
                'nombre' => $plataformaNombre,
                'ventas' => [],
                'totales' => [
                    'venta_bruto' => 0,
                    'venta_neto' => 0,
                    'ventas_count' => 0
                ]
            ];
        }

        $ventasPorPlataforma[$plataformaId]['ventas'][] = $venta;
        $ventasPorPlataforma[$plataformaId]['totales']['venta_bruto'] += (float)$venta['precio'];
        $ventasPorPlataforma[$plataformaId]['totales']['venta_neto'] += (float)$venta['precio_neto'];
        $ventasPorPlataforma[$plataformaId]['totales']['ventas_count']++;
    }

    // Para cada plataforma
    foreach ($ventasPorPlataforma as $plataformaId => $plataformaData) {
        $pdf->SetFont('helvetica', 'B', 14);
        $pdf->SetFillColor(41, 128, 185);
        $pdf->SetTextColor(255, 255, 255);
        $pdf->Cell(0, 10, 'PLATAFORMA: ' . strtoupper($plataformaData['nombre']), 0, 1, 'C', true);

        $pdf->Ln(5);

        // Totales de la plataforma
        $pdf->SetFont('helvetica', 'B', 12);
        $pdf->SetFillColor(52, 73, 94);
        $pdf->SetTextColor(255, 255, 255);
        $pdf->Cell(0, 8, 'TOTALES DE LA PLATAFORMA', 0, 1, 'C', true);

        $pdf->SetFont('helvetica', '', 10);
        $pdf->SetTextColor(0, 0, 0);

        // Tabla de totales
        $header = ['Concepto', 'Monto'];
        $data = [
            ['Número de Ventas', number_format($plataformaData['totales']['ventas_count'], 0)],
            ['Venta Bruto Total', formatoMoneda($plataformaData['totales']['venta_bruto'])],
            ['Venta Neto Total', formatoMoneda($plataformaData['totales']['venta_neto'])]
        ];

        // Crear tabla de totales
        $pdf->SetFont('helvetica', 'B', 10);
        $pdf->SetFillColor(220, 220, 220);
        $pdf->Cell(140, 8, $header[0], 1, 0, 'C', true);
        $pdf->Cell(50, 8, $header[1], 1, 1, 'C', true);

        $pdf->SetFont('helvetica', '', 9);
        $fill = false;
        foreach ($data as $row) {
            $pdf->SetFillColor($fill ? 240 : 255, $fill ? 240 : 255, $fill ? 240 : 255);
            $pdf->Cell(140, 8, $row[0], 1, 0, 'L', $fill);
            $pdf->Cell(50, 8, $row[1], 1, 1, 'R', $fill);
            $fill = !$fill;
        }

        $pdf->Ln(10);

        // Tabla detallada de ventas de la plataforma (primeras 20 ventas)
        if (!empty($plataformaData['ventas'])) {
            $pdf->SetFont('helvetica', 'B', 12);
            $pdf->SetFillColor(44, 62, 80);
            $pdf->SetTextColor(255, 255, 255);
            $pdf->Cell(0, 10, 'ÚLTIMAS VENTAS - ' . strtoupper($plataformaData['nombre']), 0, 1, 'C', true);

            // Headers de la tabla
            $headers = ['Fecha', 'Cliente', 'Venta Bruto', 'Venta Neto', 'Tipo', 'Sala'];

            $pdf->SetFont('helvetica', 'B', 8);
            $pdf->SetTextColor(0, 0, 0);
            $pdf->SetFillColor(220, 220, 220);

            // Ajustar anchos de columnas
            $widths = [20, 50, 30, 30, 20, 30];

            // Dibujar headers
            for ($i = 0; $i < count($headers); $i++) {
                $pdf->Cell($widths[$i], 8, $headers[$i], 1, 0, 'C', true);
            }
            $pdf->Ln();

            // Datos de ventas (limitar a 20)
            $pdf->SetFont('helvetica', '', 7);
            $fill = false;
            $contador = 0;

            $ventasMostrar = array_slice($plataformaData['ventas'], 0, 20);
            foreach ($ventasMostrar as $venta) {
                $pdf->SetFillColor($fill ? 240 : 255, $fill ? 240 : 255, $fill ? 240 : 255);

                // Fecha
                $pdf->Cell($widths[0], 6, date('d/m/Y', strtotime($venta['fecha'])), 1, 0, 'C', $fill);

                // Cliente (recortar si es muy largo)
                $cliente = substr($venta['cliente_nombre'], 0, 30);
                $pdf->Cell($widths[1], 6, $cliente, 1, 0, 'L', $fill);

                // Venta Bruto
                $pdf->Cell($widths[2], 6, formatoMoneda($venta['precio']), 1, 0, 'R', $fill);

                // Venta Neto
                $pdf->Cell($widths[3], 6, formatoMoneda($venta['precio_neto']), 1, 0, 'R', $fill);

                // Tipo Venta
                $tipo = $venta['tipo_venta'] ?? 'N/A';
                $pdf->Cell($widths[4], 6, $tipo, 1, 0, 'C', $fill);

                // Sala
                $sala = $venta['sala_nombre'] ?? 'N/A';
                $pdf->Cell($widths[5], 6, $sala, 1, 1, 'C', $fill);

                $fill = !$fill;
                $contador++;
            }

            if (count($plataformaData['ventas']) > 20) {
                $pdf->SetFont('helvetica', 'I', 8);
                $pdf->Cell(0, 8, '... y ' . (count($plataformaData['ventas']) - 20) . ' ventas más', 0, 1, 'C');
            }
        }

        $pdf->Ln(15);
    }
}

// ============================================================================
// GENERAR Y DESCARGAR PDF
// ============================================================================

// Limpiar buffer
while (ob_get_level()) {
    ob_end_clean();
}

// Headers para descarga PDF
header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="Reporte_Ventas_Comisiones_' . date('Y-m-d_His') . '.pdf"');
header('Cache-Control: max-age=0');
header('Content-Transfer-Encoding: binary');
header('Pragma: public');
header('Expires: 0');

registrarAccion($pdo, 'Exportar PDF', 'ventas_facturacion', null, [
    'filtros' => $_GET,
    'ventas_count' => count($ventas ?? []),
]);

// Salida del PDF
$pdf->Output('reporte_ventas.pdf', 'D');
exit;
