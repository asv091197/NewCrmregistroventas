<?php
// reporte_pdf_ganancia_volumen_anual.php
// Limpiar cualquier salida previa
while (ob_get_level()) {
    ob_end_clean();
}
ob_start();

require __DIR__ . '/../vendor/autoload.php';

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
// OBTENER PARÁMETROS
// ============================================================================

$año_seleccionado = isset($_GET['año']) ? intval($_GET['año']) : date('Y');
$sala = isset($_GET['sala']) ? $_GET['sala'] : 'todas';
$salaId = 0;

// Determinar sala a usar
if ($sala !== 'todas' && is_numeric($sala)) {
    $salaId = intval($sala);
}

// Validar año
if ($año_seleccionado < 2020 || $año_seleccionado > date('Y') + 1) {
    $año_seleccionado = date('Y');
}

// Obtener nombre de la sala para mostrar en el reporte
$nombre_sala = "Todas las salas";
if ($salaId > 0) {
    try {
        $query_sala = "SELECT nombre FROM salas WHERE id = ?";
        $stmt_sala = $pdo->prepare($query_sala);
        $stmt_sala->execute([$salaId]);
        $result_sala = $stmt_sala->fetch(PDO::FETCH_ASSOC);

        if ($result_sala) {
            $nombre_sala = $result_sala['nombre'];
        } else {
            $nombre_sala = "Sala ID: " . $salaId;
        }
    } catch (PDOException $e) {
        $nombre_sala = "Sala ID: " . $salaId;
    }
}

// ============================================================================
// CONSULTA SQL PARA VENTAS DEL AÑO
// ============================================================================

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
    LEFT JOIN salas s ON v.sala_id = s.id
    WHERE YEAR(v.fecha) = ?";

$params = [$año_seleccionado];

// Filtro por sala
if ($salaId > 0) {
    $sql .= " AND v.sala_id = ?";
    $params[] = $salaId;
}

$sql .= " ORDER BY v.fecha DESC";

try {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $ventas = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Aplicar limpieza de símbolos a los campos de nombres
    foreach ($ventas as &$venta) {
        $venta['cliente_nombre'] = limpiarTexto($venta['cliente_nombre']);
        $venta['liner_nombre'] = limpiarTexto($venta['liner_nombre']);
        $venta['closer1_nombre'] = limpiarTexto($venta['closer1_nombre']);
        $venta['closer2_nombre'] = limpiarTexto($venta['closer2_nombre']);
        $venta['front_nombre'] = limpiarTexto($venta['front_nombre']);
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
    die('No hay datos para generar el reporte del año ' . $año_seleccionado . 
        ($salaId > 0 ? ' para la sala ' . $nombre_sala : '') . '.');
}

// ============================================================================
// SISTEMA DE OVER (BONOS POR META) - CONFIGURACIÓN POR SALA
// ============================================================================

$configOver = [
    // Sala 1 - OSCAR
    'XIMENA' => [
        'nombre' => 'XIMENA',
        'salas' => [1],
        'metas' => [
            ['min' => 0, 'max' => 300000, 'porcentaje' => 0.00],
            ['min' => 300001, 'max' => 350000, 'porcentaje' => 2.00],
            ['min' => 350001, 'max' => 425000, 'porcentaje' => 2.50],
            ['min' => 425001, 'max' => null, 'porcentaje' => 3.00]
        ]
    ],

    // Sala 2 - SUPERVISOR
    'SUPERVISOR' => [
        'nombre' => 'SUPERVISOR',
        'salas' => [2],
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
        'salas' => [1],
        'metas' => [
            ['min' => 0, 'max' => 300000, 'porcentaje' => 0.50],
            ['min' => 300001, 'max' => 350000, 'porcentaje' => 0.75],
            ['min' => 350001, 'max' => null, 'porcentaje' => 1.00]
        ]
    ],

    // Ambos salas - CINTHYA
    'CINTHYA' => [
        'nombre' => 'CINTHYA',
        'salas' => [1, 2],
        'metas' => [
            ['min' => 0, 'max' => 300000, 'porcentaje' => 3.50],
            ['min' => 300001, 'max' => 350000, 'porcentaje' => 4.50],
            ['min' => 350001, 'max' => null, 'porcentaje' => 5.50]
        ]
    ],

    // Ambos salas - XIMENA
    'GERENTE' => [
        'nombre' => 'GERENTE',
        'salas' => [1, 2],
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
        'salas' => [1, 2],
        'metas' => [
            ['min' => 0, 'max' => 300000, 'porcentaje' => 0.75],
            ['min' => 300001, 'max' => 350000, 'porcentaje' => 1.00],
            ['min' => 350001, 'max' => null, 'porcentaje' => 1.50]
        ]
    ],

    // Ambos salas - ROY
    'ROY' => [
        'nombre' => 'ROY',
        'salas' => [1, 2],
        'metas' => [
            ['min' => 0, 'max' => 300000, 'porcentaje' => 0.50],
            ['min' => 300001, 'max' => 350000, 'porcentaje' => 0.75],
            ['min' => 350001, 'max' => null, 'porcentaje' => 1.00]
        ]
    ],

    'ALBERTO' => [
        'nombre' => 'ALBERTO',
        'salas' => [1, 2],
        'metas' => [
            ['min' => 0, 'max' => null, 'porcentaje' => 1]
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

// Calcular ventas por sala para OVER correcto
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
$pdf->SetTitle('Reporte Anual de Ventas - ' . $año_seleccionado . ' - ' . $nombre_sala);
$pdf->SetSubject('Reporte Anual de Ventas');
$pdf->SetKeywords('ventas, reporte, pdf, crm, comisiones, sala, anual, ' . $año_seleccionado . ', ' . $nombre_sala);

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
    $tituloPrincipal = 'REPORTE ANUAL DE VENTAS - GRUPO IDEAS - AÑO: ' . $año_seleccionado . ' - SALA: ' . strtoupper($nombre_sala);
} else {
    $tituloPrincipal = 'REPORTE ANUAL DE VENTAS - GRUPO IDEAS - AÑO: ' . $año_seleccionado . ' - TODAS LAS SALAS';
}

$pdf->Cell(0, 12, $tituloPrincipal, 0, 1, 'C', true);
$pdf->Cell(0, 12, 'SISTEMA DE COMISIONES Y OVER - GANANCIA VOLUMEN', 0, 1, 'C', true);

// Información de filtros
$pdf->SetFont('helvetica', 'I', 10);
$pdf->SetTextColor(0, 0, 0);

$filtroTexto = "Año: " . $año_seleccionado;
if ($salaId > 0) {
    $filtroTexto .= " | Sala: " . $nombre_sala;
} else {
    $filtroTexto .= " | Todas las Salas";
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
    $pdf->Cell(0, 8, 'TOTALES DEL AÑO ' . $año_seleccionado . ' - SALA: ' . strtoupper($nombre_sala), 0, 1, 'C', true);
} else {
    $pdf->Cell(0, 8, 'TOTALES GENERALES DEL AÑO ' . $año_seleccionado . ' - TODAS LAS SALAS', 0, 1, 'C', true);
}

$pdf->SetFont('helvetica', '', 10);
$pdf->SetTextColor(0, 0, 0);

// Tabla de totales
$header = ['Concepto', 'Monto'];
$data = [
    ['Número de Ventas', number_format($totalesGenerales['ventas_count'], 0)],
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
// TABLA DETALLADA DE VENTAS
// ============================================================================

$pdf->SetFont('helvetica', 'B', 12);
$pdf->SetFillColor(44, 62, 80);
$pdf->SetTextColor(255, 255, 255);

if ($salaId > 0) {
    $pdf->Cell(0, 10, 'DETALLE DE VENTAS - AÑO ' . $año_seleccionado . ' - SALA: ' . strtoupper($nombre_sala), 0, 1, 'C', true);
} else {
    $pdf->Cell(0, 10, 'DETALLE DE VENTAS - AÑO ' . $año_seleccionado . ' - TODAS LAS SALAS', 0, 1, 'C', true);
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
    $tituloOver = 'SISTEMA DE OVER - BONOS POR META - AÑO ' . $año_seleccionado . ' - SALA: ' . strtoupper($nombre_sala);
} else {
    $tituloOver = 'SISTEMA DE OVER - BONOS POR META - AÑO ' . $año_seleccionado . ' - TODAS LAS SALAS';
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
    $pdf->Cell(0, 10, 'CÁLCULO DE OVER PARA EL AÑO ' . $año_seleccionado . ' - SALA: ' . strtoupper($nombre_sala), 0, 1, 'C', true);
} else {
    $pdf->Cell(0, 10, 'CÁLCULO DE OVER POR PERSONAL - AÑO ' . $año_seleccionado, 0, 1, 'C', true);
}

// Headers de la tabla OVER
if ($salaId > 0) {
    $overHeaders = ['Personal', 'Vol Anual (Neto)', 'Porcentaje', 'Total Over'];
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

// LÓGICA DE CÁLCULO DE OVER CON SUMA
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
    $pdf->Cell(120, 8, 'TOTAL OVER PARA EL AÑO ' . $año_seleccionado . ':', 1, 0, 'R', true);
    $pdf->Cell(40, 8, formatoMoneda($totalOverGeneral), 1, 1, 'R', true);
} else {
    $pdf->Cell(120, 8, 'TOTAL OVER GENERAL AÑO ' . $año_seleccionado . ':', 1, 0, 'R', true);
    $pdf->Cell(40, 8, formatoMoneda($totalOverGeneral), 1, 1, 'R', true);
}

// Si es reporte GENERAL, mostrar detalle por persona
if ($salaId == 0 && isset($overPorPersona) && !empty($overPorPersona)) {
    $pdf->Ln(5);

    $pdf->SetFont('helvetica', 'B', 10);
    $pdf->SetFillColor(52, 73, 94);
    $pdf->SetTextColor(255, 255, 255);
    $pdf->Cell(0, 8, 'DETALLE DE OVER POR PERSONA Y SALA - AÑO ' . $año_seleccionado, 0, 1, 'C', true);

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
// RESUMEN POR USUARIO
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
                        'sala_nombre' => $nombre_sala,
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
        $tituloUsuarios = 'RESUMEN DE COMISIONES POR USUARIO - AÑO ' . $año_seleccionado . ' - SALA: ' . strtoupper($nombre_sala);
    } else {
        $tituloUsuarios = 'RESUMEN DE COMISIONES POR USUARIO - AÑO ' . $año_seleccionado . ' - TODAS LAS SALAS';
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
            $pdf->Cell(0, 8, 'DETALLE DE OVER POR USUARIO - AÑO ' . $año_seleccionado . ' - SALA: ' . strtoupper($nombre_sala), 0, 1, 'C', true);
        } else {
            $pdf->Cell(0, 8, 'DETALLE DE OVER POR USUARIO Y SALA - AÑO ' . $año_seleccionado, 0, 1, 'C', true);
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
        $tituloVolumen = 'GANANCIA VOLUMEN POR USUARIO Y ROL - AÑO ' . $año_seleccionado . ' - SALA: ' . strtoupper($nombre_sala);
    } else {
        $tituloVolumen = 'GANANCIA VOLUMEN POR USUARIO Y ROL - AÑO ' . $año_seleccionado . ' - TODAS LAS SALAS';
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
    $pdf->Cell(170, 8, 'TOTAL GANANCIA VOLUMEN GENERAL AÑO ' . $año_seleccionado . ':', 1, 0, 'R', true);
    $pdf->Cell(30, 8, formatoMoneda($totalVolumenGeneral), 1, 1, 'R', true);
}

// ============================================================================
// RESUMEN POR MES (SOLO PARA REPORTE ANUAL)
// ============================================================================

$pdf->AddPage();

// Título de resumen por mes
$pdf->SetFont('helvetica', 'B', 16);
$pdf->SetFillColor(52, 152, 219);
$pdf->SetTextColor(255, 255, 255);

if ($salaId > 0) {
    $tituloMensual = 'RESUMEN MENSUAL - AÑO ' . $año_seleccionado . ' - SALA: ' . strtoupper($nombre_sala);
} else {
    $tituloMensual = 'RESUMEN MENSUAL - AÑO ' . $año_seleccionado . ' - TODAS LAS SALAS';
}

$pdf->Cell(0, 12, $tituloMensual, 0, 1, 'C', true);

// Agrupar ventas por mes
$ventasPorMes = [];
$nombresMeses = [
    1 => 'Enero', 2 => 'Febrero', 3 => 'Marzo', 4 => 'Abril',
    5 => 'Mayo', 6 => 'Junio', 7 => 'Julio', 8 => 'Agosto',
    9 => 'Septiembre', 10 => 'Octubre', 11 => 'Noviembre', 12 => 'Diciembre'
];

foreach ($ventas as $venta) {
    $mes = (int)date('n', strtotime($venta['fecha']));
    
    if (!isset($ventasPorMes[$mes])) {
        $ventasPorMes[$mes] = [
            'mes_nombre' => $nombresMeses[$mes],
            'ventas_count' => 0,
            'venta_bruto' => 0,
            'venta_neto' => 0,
            'comision_liner' => 0,
            'comision_closer1' => 0,
            'comision_closer2' => 0,
            'comision_front' => 0
        ];
    }
    
    $ventasPorMes[$mes]['ventas_count']++;
    $ventasPorMes[$mes]['venta_bruto'] += (float)$venta['precio'];
    $ventasPorMes[$mes]['venta_neto'] += (float)$venta['precio_neto'];
    $ventasPorMes[$mes]['comision_liner'] += (float)($venta['ganancia_liner'] ?? 0);
    $ventasPorMes[$mes]['comision_closer1'] += (float)($venta['ganancia_closer1'] ?? 0);
    $ventasPorMes[$mes]['comision_closer2'] += (float)($venta['ganancia_closer2'] ?? 0);
    $ventasPorMes[$mes]['comision_front'] += (float)($venta['ganancia_fullstack'] ?? 0);
}

// Ordenar por mes
ksort($ventasPorMes);

// Headers de la tabla mensual
$mensualHeaders = ['Mes', 'Ventas', 'Venta Bruto', 'Venta Neto', 'Comisión Liner', 'Comisión Closer 1', 'Comisión Closer 2', 'Comisión Front', 'Total Comisiones'];

$pdf->SetFont('helvetica', 'B', 8);
$pdf->SetTextColor(0, 0, 0);
$pdf->SetFillColor(220, 220, 220);

// Anchos de columnas
$mensualWidths = [25, 12, 22, 22, 22, 22, 22, 22, 22];

// Dibujar headers
for ($i = 0; $i < count($mensualHeaders); $i++) {
    $pdf->Cell($mensualWidths[$i], 8, $mensualHeaders[$i], 1, 0, 'C', true);
}
$pdf->Ln();

// Datos mensuales
$pdf->SetFont('helvetica', '', 8);
$fill = false;

foreach ($ventasPorMes as $mes => $datos) {
    $totalComisiones = $datos['comision_liner'] + $datos['comision_closer1'] + 
                       $datos['comision_closer2'] + $datos['comision_front'];
    
    $pdf->SetFillColor($fill ? 240 : 255, $fill ? 240 : 255, $fill ? 240 : 255);
    
    $pdf->Cell($mensualWidths[0], 7, $datos['mes_nombre'], 1, 0, 'C', $fill);
    $pdf->Cell($mensualWidths[1], 7, number_format($datos['ventas_count'], 0), 1, 0, 'C', $fill);
    $pdf->Cell($mensualWidths[2], 7, formatoMoneda($datos['venta_bruto']), 1, 0, 'R', $fill);
    $pdf->Cell($mensualWidths[3], 7, formatoMoneda($datos['venta_neto']), 1, 0, 'R', $fill);
    $pdf->Cell($mensualWidths[4], 7, formatoMoneda($datos['comision_liner']), 1, 0, 'R', $fill);
    $pdf->Cell($mensualWidths[5], 7, formatoMoneda($datos['comision_closer1']), 1, 0, 'R', $fill);
    $pdf->Cell($mensualWidths[6], 7, formatoMoneda($datos['comision_closer2']), 1, 0, 'R', $fill);
    $pdf->Cell($mensualWidths[7], 7, formatoMoneda($datos['comision_front']), 1, 0, 'R', $fill);
    $pdf->Cell($mensualWidths[8], 7, formatoMoneda($totalComisiones), 1, 1, 'R', $fill);
    
    $fill = !$fill;
}

// Totales anuales
$pdf->SetFont('helvetica', 'B', 8);
$pdf->SetFillColor(52, 152, 219);
$pdf->SetTextColor(255, 255, 255);

$totalComisionesAnual = $totalesGenerales['suma_comisiones'];

$pdf->Cell($mensualWidths[0] + $mensualWidths[1], 8, 'TOTAL ANUAL:', 1, 0, 'R', true);
$pdf->Cell($mensualWidths[2], 8, formatoMoneda($totalesGenerales['venta_bruto']), 1, 0, 'R', true);
$pdf->Cell($mensualWidths[3], 8, formatoMoneda($totalesGenerales['venta_neto']), 1, 0, 'R', true);
$pdf->Cell($mensualWidths[4], 8, formatoMoneda($totalesGenerales['comision_liner']), 1, 0, 'R', true);
$pdf->Cell($mensualWidths[5], 8, formatoMoneda($totalesGenerales['comision_closer1']), 1, 0, 'R', true);
$pdf->Cell($mensualWidths[6], 8, formatoMoneda($totalesGenerales['comision_closer2']), 1, 0, 'R', true);
$pdf->Cell($mensualWidths[7], 8, formatoMoneda($totalesGenerales['comision_front']), 1, 0, 'R', true);
$pdf->Cell($mensualWidths[8], 8, formatoMoneda($totalComisionesAnual), 1, 1, 'R', true);

// ============================================================================
// GENERAR Y DESCARGAR PDF
// ============================================================================

// Limpiar buffer
while (ob_get_level()) {
    ob_end_clean();
}

// Generar nombre del archivo
$sala_nombre_archivo = ($salaId == 0) ? 'Todas_Salas' : str_replace(' ', '_', $nombre_sala);
$nombre_archivo = 'Reporte_Anual_Ventas_' . $año_seleccionado . '_Sala_' . $sala_nombre_archivo . '.pdf';

// Headers para descarga PDF
header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="' . $nombre_archivo . '"');
header('Cache-Control: max-age=0');
header('Content-Transfer-Encoding: binary');
header('Pragma: public');
header('Expires: 0');

// Salida del PDF
$pdf->Output($nombre_archivo, 'D');
exit;