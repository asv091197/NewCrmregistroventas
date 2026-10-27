<?php
// reporte_excel_ganancia_volumen_mensual.php - VERSIÓN SIMPLIFICADA
// Solo filtro por mes y sala - SIN RESUMEN DE USUARIOS CON OVER
while (ob_get_level()) {
    ob_end_clean();
}
ob_start();

require __DIR__ . '/../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;

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
// OBTENER PARÁMETROS - SOLO MES Y SALA
// ============================================================================

// Parámetros requeridos: mes y sala
$mes_seleccionado = isset($_GET['mes']) ? $_GET['mes'] : date('Y-m');
$sala_id = isset($_GET['sala_id']) ? intval($_GET['sala_id']) : 0;

// Validar formato de mes
if (!preg_match('/^\d{4}-\d{2}$/', $mes_seleccionado)) {
    die('Error: Formato de mes inválido. Debe ser YYYY-MM');
}

// Extraer año y mes
$anio = date('Y', strtotime($mes_seleccionado . '-01'));
$mes = date('m', strtotime($mes_seleccionado . '-01'));
$mes_nombre = date('F', strtotime($mes_seleccionado . '-01'));

// Obtener nombre de la sala para mostrar en el reporte
$nombre_sala = "Todas las salas";
if ($sala_id > 0) {
    try {
        $query_sala = "SELECT nombre FROM salas WHERE id = ?";
        $stmt_sala = $pdo->prepare($query_sala);
        $stmt_sala->execute([$sala_id]);
        $result_sala = $stmt_sala->fetch(PDO::FETCH_ASSOC);

        if ($result_sala) {
            $nombre_sala = $result_sala['nombre'];
        } else {
            die('Error: Sala no encontrada.');
        }
    } catch (PDOException $e) {
        die("Error al obtener información de la sala: " . $e->getMessage());
    }
}

// ============================================================================
// CONSULTA SQL - SOLO FILTROS POR MES Y SALA
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
    WHERE YEAR(v.fecha) = ? AND MONTH(v.fecha) = ?";

$params = [$anio, $mes];

// Filtrar por sala si se seleccionó una
if ($sala_id > 0) {
    $sql .= " AND v.sala_id = ?";
    $params[] = $sala_id;
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
    die('No hay datos para el mes ' . $mes_nombre . ' ' . $anio . ' en ' . ($sala_id > 0 ? 'la sala ' . $nombre_sala : 'ninguna sala'));
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
        'salas' => [1, 2], // Ambas salas
        'metas' => [
            ['min' => 0, 'max' => null, 'porcentaje' => 1]
        ]
    ]
];

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
// CALCULAR TOTALES
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
// CREAR EXCEL
// ============================================================================

$spreadsheet = new Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();

// ============================================================================
// HOJA 1: RESUMEN GENERAL MENSUAL
// ============================================================================

$sheet->setTitle('Resumen Mensual');

// Establecer ancho de columnas
$sheet->getColumnDimension('A')->setWidth(5);
$sheet->getColumnDimension('B')->setWidth(30);
$sheet->getColumnDimension('C')->setWidth(20);
$sheet->getColumnDimension('D')->setWidth(20);
$sheet->getColumnDimension('E')->setWidth(20);
$sheet->getColumnDimension('F')->setWidth(20);
$sheet->getColumnDimension('G')->setWidth(20);

// Título principal
$sheet->mergeCells('A1:G1');
$sheet->setCellValue('A1', 'REPORTE DE VENTAS MENSUAL - GRUPO IDEAS');
$sheet->getStyle('A1')->getFont()->setBold(true)->setSize(16);
$sheet->getStyle('A1')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
$sheet->getStyle('A1')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('3498db');

$sheet->mergeCells('A2:G2');
$sheet->setCellValue('A2', 'SISTEMA DE COMISIONES Y OVER - ' . strtoupper($mes_nombre . ' ' . $anio));
$sheet->getStyle('A2')->getFont()->setBold(true)->setSize(14);
$sheet->getStyle('A2')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
$sheet->getStyle('A2')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('3498db');

// Información de filtros
$sheet->mergeCells('A3:G3');
$sheet->setCellValue('A3', 'Sala: ' . $nombre_sala . ' | Mes: ' . $mes_nombre . ' ' . $anio);
$sheet->getStyle('A3')->getFont()->setItalic(true);
$sheet->getStyle('A3')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

$sheet->mergeCells('A4:G4');
$sheet->setCellValue('A4', 'Fecha de generación: ' . date('d/m/Y H:i:s'));
$sheet->getStyle('A4')->getFont()->setItalic(true);
$sheet->getStyle('A4')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

// Espacio
$sheet->setCellValue('A5', '');

// Título de totales
$sheet->mergeCells('A6:G6');
$sheet->setCellValue('A6', $sala_id > 0 ? 'TOTALES MENSUALES DE LA SALA: ' . strtoupper($nombre_sala) : 'TOTALES MENSUALES GENERALES');
$sheet->getStyle('A6')->getFont()->setBold(true)->setSize(12);
$sheet->getStyle('A6')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
$sheet->getStyle('A6')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('2c3e50');
$sheet->getStyle('A6')->getFont()->getColor()->setARGB('FFFFFF');

// Tabla de totales
$sheet->setCellValue('A7', '#');
$sheet->setCellValue('B7', 'Concepto');
$sheet->setCellValue('C7', 'Monto');

$sheet->getStyle('A7:C7')->getFont()->setBold(true);
$sheet->getStyle('A7:C7')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('DDDDDD');
$sheet->getStyle('A7:C7')->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);

// Datos de totales
$dataTotales = [
    ['Número de Ventas', $totalesGenerales['ventas_count']],
    ['Venta Bruto Total', $totalesGenerales['venta_bruto']],
    ['Venta Neto Total', $totalesGenerales['venta_neto']],
    ['Comisión Liner Total', $totalesGenerales['comision_liner']],
    ['Comisión Closer 1 Total', $totalesGenerales['comision_closer1']],
    ['Comisión Closer 2 Total', $totalesGenerales['comision_closer2']],
    ['Comisión Front Total', $totalesGenerales['comision_front']],
    ['Suma Comisiones Total', $totalesGenerales['suma_comisiones']]
];

$row = 7;
foreach ($dataTotales as $index => $rowData) {
    $row++;
    $sheet->setCellValue('A' . $row, $index + 1);
    $sheet->setCellValue('B' . $row, $rowData[0]);
    $sheet->setCellValue('C' . $row, $rowData[1]);

    // Formato de moneda para montos
    if (in_array($index, [1, 2, 3, 4, 5, 6, 7])) {
        $sheet->getStyle('C' . $row)->getNumberFormat()->setFormatCode('"$"#,##0.00');
    }

    // Colores alternados
    $fillColor = $index % 2 == 0 ? 'F8F9F9' : 'ECF0F1';
    $sheet->getStyle('A' . $row . ':C' . $row)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB($fillColor);
    $sheet->getStyle('A' . $row . ':C' . $row)->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
}

// ============================================================================
// HOJA 2: DETALLE DE VENTAS
// ============================================================================

$sheetVentas = $spreadsheet->createSheet();
$sheetVentas->setTitle('Detalle Ventas');

// Establecer ancho de columnas
$sheetVentas->getColumnDimension('A')->setWidth(12); // Fecha
$sheetVentas->getColumnDimension('B')->setWidth(25); // Cliente
$sheetVentas->getColumnDimension('C')->setWidth(15); // Venta Bruto
$sheetVentas->getColumnDimension('D')->setWidth(15); // Venta Neto
$sheetVentas->getColumnDimension('E')->setWidth(15); // Liner
$sheetVentas->getColumnDimension('F')->setWidth(15); // Closer 1
$sheetVentas->getColumnDimension('G')->setWidth(15); // Closer 2
$sheetVentas->getColumnDimension('H')->setWidth(15); // Front
$sheetVentas->getColumnDimension('I')->setWidth(25); // Plataforma
$sheetVentas->getColumnDimension('J')->setWidth(10); // Tipo
$sheetVentas->getColumnDimension('K')->setWidth(15); // Sala

// Título
$sheetVentas->mergeCells('A1:K1');
$sheetVentas->setCellValue('A1', 'DETALLE DE VENTAS MENSUALES' . ($sala_id > 0 ? ' - SALA: ' . strtoupper($nombre_sala) : ' - TODAS LAS SALAS'));
$sheetVentas->getStyle('A1')->getFont()->setBold(true)->setSize(14);
$sheetVentas->getStyle('A1')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
$sheetVentas->getStyle('A1')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('2c3e50');
$sheetVentas->getStyle('A1')->getFont()->getColor()->setARGB('FFFFFF');

$sheetVentas->mergeCells('A2:K2');
$sheetVentas->setCellValue('A2', 'Mes: ' . $mes_nombre . ' ' . $anio);
$sheetVentas->getStyle('A2')->getFont()->setItalic(true);
$sheetVentas->getStyle('A2')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

// Headers
$headers = ['Fecha', 'Cliente', 'Venta Bruto', 'Venta Neto', 'Liner', 'Closer 1', 'Closer 2', 'Front', 'Plataforma', 'Tipo', 'Sala'];
$col = 'A';
foreach ($headers as $header) {
    $sheetVentas->setCellValue($col . '3', $header);
    $col++;
}

// Estilo de headers
$sheetVentas->getStyle('A3:K3')->getFont()->setBold(true);
$sheetVentas->getStyle('A3:K3')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('DDDDDD');
$sheetVentas->getStyle('A3:K3')->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
$sheetVentas->getStyle('A3:K3')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

// Datos de ventas
$rowVentas = 4;
foreach ($ventas as $index => $venta) {
    $sheetVentas->setCellValue('A' . $rowVentas, date('d/m/Y', strtotime($venta['fecha'])));
    $sheetVentas->setCellValue('B' . $rowVentas, $venta['cliente_nombre']);
    $sheetVentas->setCellValue('C' . $rowVentas, $venta['precio']);
    $sheetVentas->setCellValue('D' . $rowVentas, $venta['precio_neto']);
    $sheetVentas->setCellValue('E' . $rowVentas, $venta['liner_nombre']);
    $sheetVentas->setCellValue('F' . $rowVentas, $venta['closer1_nombre']);
    $sheetVentas->setCellValue('G' . $rowVentas, $venta['closer2_nombre']);
    $sheetVentas->setCellValue('H' . $rowVentas, $venta['front_nombre']);
    $sheetVentas->setCellValue('I' . $rowVentas, $venta['plataforma_nombre_completo']);
    $sheetVentas->setCellValue('J' . $rowVentas, $venta['tipo_venta']);
    $sheetVentas->setCellValue('K' . $rowVentas, $venta['sala_nombre']);

    // Formato de moneda
    $sheetVentas->getStyle('C' . $rowVentas . ':D' . $rowVentas)->getNumberFormat()->setFormatCode('"$"#,##0.00');

    // Colores alternados
    $fillColor = $index % 2 == 0 ? 'F8F9F9' : 'ECF0F1';
    $sheetVentas->getStyle('A' . $rowVentas . ':K' . $rowVentas)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB($fillColor);
    $sheetVentas->getStyle('A' . $rowVentas . ':K' . $rowVentas)->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);

    $rowVentas++;
}

// Congelar paneles (headers visibles)
$sheetVentas->freezePane('A4');

// ============================================================================
// HOJA 3: SISTEMA DE OVER
// ============================================================================

$sheetOver = $spreadsheet->createSheet();
$sheetOver->setTitle('Sistema OVER');

// Establecer ancho de columnas
$sheetOver->getColumnDimension('A')->setWidth(25); // Personal
$sheetOver->getColumnDimension('B')->setWidth(20); // Volumen
$sheetOver->getColumnDimension('C')->setWidth(15); // Porcentaje
$sheetOver->getColumnDimension('D')->setWidth(20); // Total Over

// Título
$sheetOver->mergeCells('A1:D1');
$sheetOver->setCellValue('A1', 'SISTEMA DE OVER MENSUAL - BONOS POR META' . ($sala_id > 0 ? ' - SALA: ' . strtoupper($nombre_sala) : ' - TODAS LAS SALAS'));
$sheetOver->getStyle('A1')->getFont()->setBold(true)->setSize(14);
$sheetOver->getStyle('A1')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
$sheetOver->getStyle('A1')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('8e44ad');
$sheetOver->getStyle('A1')->getFont()->getColor()->setARGB('FFFFFF');

$sheetOver->mergeCells('A2:D2');
$sheetOver->setCellValue('A2', 'Período: ' . $mes_nombre . ' ' . $anio);
$sheetOver->getStyle('A2')->getFont()->setItalic(true);
$sheetOver->getStyle('A2')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

if ($sala_id == 0) {
    $sheetOver->mergeCells('A3:D3');
    $sheetOver->setCellValue('A3', 'NOTA: El OVER se calcula por separado para cada sala según la configuración y se suma por persona');
    $sheetOver->getStyle('A3')->getFont()->setItalic(true)->setSize(10);
    $sheetOver->getStyle('A3')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    $startRow = 5;
} else {
    $startRow = 4;
}

// Headers
$sheetOver->setCellValue('A' . $startRow, 'Personal');
$sheetOver->setCellValue('B' . $startRow, $sala_id > 0 ? 'Vol Sala (Neto)' : 'Vol Total (Neto)');
$sheetOver->setCellValue('C' . $startRow, 'Porcentaje');
$sheetOver->setCellValue('D' . $startRow, 'Total Over');

// Estilo de headers
$sheetOver->getStyle('A' . $startRow . ':D' . $startRow)->getFont()->setBold(true);
$sheetOver->getStyle('A' . $startRow . ':D' . $startRow)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('C8C8C8');
$sheetOver->getStyle('A' . $startRow . ':D' . $startRow)->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
$sheetOver->getStyle('A' . $startRow . ':D' . $startRow)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

// Cálculo de OVER
$rowOver = $startRow + 1;
$totalOverGeneral = 0;

if ($sala_id > 0) {
    // Para sala específica
    $ventaNetoSala = $totalesGenerales['venta_neto'];

    foreach ($configOver as $persona => $config) {
        if (in_array($sala_id, $config['salas'])) {
            $calculos = calcularOver($ventaNetoSala, $config);
            $totalOverGeneral += $calculos['totalOver'];

            $sheetOver->setCellValue('A' . $rowOver, $config['nombre']);
            $sheetOver->setCellValue('B' . $rowOver, $ventaNetoSala);
            $sheetOver->setCellValue('C' . $rowOver, $calculos['porcentaje'] . '%');
            $sheetOver->setCellValue('D' . $rowOver, $calculos['totalOver']);

            $sheetOver->getStyle('B' . $rowOver)->getNumberFormat()->setFormatCode('"$"#,##0.00');
            $sheetOver->getStyle('D' . $rowOver)->getNumberFormat()->setFormatCode('"$"#,##0.00');

            // Colores alternados
            $fillColor = ($rowOver - $startRow) % 2 == 0 ? 'F8F9F9' : 'ECF0F1';
            $sheetOver->getStyle('A' . $rowOver . ':D' . $rowOver)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB($fillColor);
            $sheetOver->getStyle('A' . $rowOver . ':D' . $rowOver)->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);

            $rowOver++;
        }
    }
} else {
    // Para todas las salas
    $overPorPersona = [];

    foreach ($configOver as $persona => $config) {
        $overPorPersona[$persona] = [
            'nombre' => $config['nombre'],
            'total_over' => 0,
            'volumen_total' => 0,
            'detalle_salas' => []
        ];

        foreach ($config['salas'] as $salaIdConfig) {
            if (isset($ventasPorSalaDetalle[$salaIdConfig])) {
                $datosSalaConfig = $ventasPorSalaDetalle[$salaIdConfig];
                $ventaNetoSala = $datosSalaConfig['totales']['venta_neto'];
                $calculos = calcularOver($ventaNetoSala, $config);
                $totalOverPersonaSala = $calculos['totalOver'];

                $overPorPersona[$persona]['total_over'] += $totalOverPersonaSala;
                $overPorPersona[$persona]['volumen_total'] += $ventaNetoSala;
            }
        }

        if ($overPorPersona[$persona]['total_over'] > 0 || $overPorPersona[$persona]['volumen_total'] > 0) {
            $totalOverGeneral += $overPorPersona[$persona]['total_over'];

            $porcentajePromedio = $overPorPersona[$persona]['volumen_total'] > 0 ?
                ($overPorPersona[$persona]['total_over'] / $overPorPersona[$persona]['volumen_total']) * 100 : 0;

            $sheetOver->setCellValue('A' . $rowOver, $overPorPersona[$persona]['nombre']);
            $sheetOver->setCellValue('B' . $rowOver, $overPorPersona[$persona]['volumen_total']);
            $sheetOver->setCellValue('C' . $rowOver, number_format($porcentajePromedio, 2) . '%');
            $sheetOver->setCellValue('D' . $rowOver, $overPorPersona[$persona]['total_over']);

            $sheetOver->getStyle('B' . $rowOver)->getNumberFormat()->setFormatCode('"$"#,##0.00');
            $sheetOver->getStyle('D' . $rowOver)->getNumberFormat()->setFormatCode('"$"#,##0.00');

            // Colores alternados
            $fillColor = ($rowOver - $startRow) % 2 == 0 ? 'F8F9F9' : 'ECF0F1';
            $sheetOver->getStyle('A' . $rowOver . ':D' . $rowOver)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB($fillColor);
            $sheetOver->getStyle('A' . $rowOver . ':D' . $rowOver)->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);

            $rowOver++;
        }
    }
}

// Total general de OVER
$sheetOver->setCellValue('C' . $rowOver, 'TOTAL OVER MENSUAL:');
$sheetOver->setCellValue('D' . $rowOver, $totalOverGeneral);

$sheetOver->getStyle('C' . $rowOver . ':D' . $rowOver)->getFont()->setBold(true);
$sheetOver->getStyle('C' . $rowOver . ':D' . $rowOver)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('27ae60');
$sheetOver->getStyle('C' . $rowOver . ':D' . $rowOver)->getFont()->getColor()->setARGB('FFFFFF');
$sheetOver->getStyle('C' . $rowOver . ':D' . $rowOver)->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
$sheetOver->getStyle('D' . $rowOver)->getNumberFormat()->setFormatCode('"$"#,##0.00');

// ============================================================================
// HOJA 4: GANANCIA VOLUMEN
// ============================================================================

$sheetVolumen = $spreadsheet->createSheet();
$sheetVolumen->setTitle('Ganancia Volumen');

// Establecer ancho de columnas
$sheetVolumen->getColumnDimension('A')->setWidth(30); // Usuario
$sheetVolumen->getColumnDimension('B')->setWidth(20); // G.V. Liner
$sheetVolumen->getColumnDimension('C')->setWidth(20); // G.V. Closer 1
$sheetVolumen->getColumnDimension('D')->setWidth(20); // G.V. Closer 2
$sheetVolumen->getColumnDimension('E')->setWidth(20); // G.V. Front
$sheetVolumen->getColumnDimension('F')->setWidth(20); // Total Usuario

// Título
$sheetVolumen->mergeCells('A1:F1');
$sheetVolumen->setCellValue('A1', 'GANANCIA VOLUMEN MENSUAL POR USUARIO Y ROL' . ($sala_id > 0 ? ' - SALA: ' . strtoupper($nombre_sala) : ' - TODAS LAS SALAS'));
$sheetVolumen->getStyle('A1')->getFont()->setBold(true)->setSize(14);
$sheetVolumen->getStyle('A1')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
$sheetVolumen->getStyle('A1')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('9b59b6');
$sheetVolumen->getStyle('A1')->getFont()->getColor()->setARGB('FFFFFF');

$sheetVolumen->mergeCells('A2:F2');
$sheetVolumen->setCellValue('A2', 'Mes: ' . $mes_nombre . ' ' . $anio);
$sheetVolumen->getStyle('A2')->getFont()->setItalic(true);
$sheetVolumen->getStyle('A2')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

// Nota sobre cálculo
$sheetVolumen->mergeCells('A3:F3');
$sheetVolumen->setCellValue('A3', 'Base: Venta Bruta | Liner=Venta Bruta/2 | Closer 1=Venta Bruta/2 | Closer 2=Venta Bruta/3 | Front=Venta Bruta');
$sheetVolumen->getStyle('A3')->getFont()->setItalic(true)->setSize(10);
$sheetVolumen->getStyle('A3')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

// Headers
$sheetVolumen->setCellValue('A5', 'Usuario');
$sheetVolumen->setCellValue('B5', 'G.V. Liner');
$sheetVolumen->setCellValue('C5', 'G.V. Closer 1');
$sheetVolumen->setCellValue('D5', 'G.V. Closer 2');
$sheetVolumen->setCellValue('E5', 'G.V. Front');
$sheetVolumen->setCellValue('F5', 'Total Usuario');

// Estilo de headers
$sheetVolumen->getStyle('A5:F5')->getFont()->setBold(true);
$sheetVolumen->getStyle('A5:F5')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('C8C8C8');
$sheetVolumen->getStyle('A5:F5')->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
$sheetVolumen->getStyle('A5:F5')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

// Obtener lista de todos los usuarios únicos
$usuariosUnicos = [];
foreach ($ventas as $venta) {
    // Liner
    if (!empty($venta['liner_nombre']) && $venta['liner_nombre'] !== 'No asignado') {
        $usuariosUnicos[$venta['liner_nombre']] = true;
    }

    // Closer 1
    if (!empty($venta['closer1_nombre']) && $venta['closer1_nombre'] !== 'No asignado') {
        $usuariosUnicos[$venta['closer1_nombre']] = true;
    }

    // Closer 2
    if (!empty($venta['closer2_nombre']) && $venta['closer2_nombre'] !== 'No asignado') {
        $usuariosUnicos[$venta['closer2_nombre']] = true;
    }

    // Front
    if (!empty($venta['front_nombre']) && $venta['front_nombre'] !== 'No asignado') {
        $usuariosUnicos[$venta['front_nombre']] = true;
    }
}

// Calcular ganancia volumen por usuario
$gananciaVolumenUsuarios = [];
foreach (array_keys($usuariosUnicos) as $nombreUsuario) {
    $gananciaVolumenUsuarios[$nombreUsuario] = [
        'nombre' => $nombreUsuario,
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
    if (!empty($venta['liner_nombre']) && $venta['liner_nombre'] !== 'No asignado') {
        $nombreLiner = trim($venta['liner_nombre']);
        if (isset($gananciaVolumenUsuarios[$nombreLiner])) {
            $gananciaVolumen = $ventaBruta / 2;
            $gananciaVolumenUsuarios[$nombreLiner]['ganancia_por_rol']['Liner'] += $gananciaVolumen;
        }
    }

    // Closer 1
    if (!empty($venta['closer1_nombre']) && $venta['closer1_nombre'] !== 'No asignado') {
        $nombreCloser1 = trim($venta['closer1_nombre']);
        if (isset($gananciaVolumenUsuarios[$nombreCloser1])) {
            $gananciaVolumen = $ventaBruta / 2;
            $gananciaVolumenUsuarios[$nombreCloser1]['ganancia_por_rol']['Closer 1'] += $gananciaVolumen;
        }
    }

    // Closer 2
    if (!empty($venta['closer2_nombre']) && $venta['closer2_nombre'] !== 'No asignado') {
        $nombreCloser2 = trim($venta['closer2_nombre']);
        if (isset($gananciaVolumenUsuarios[$nombreCloser2])) {
            $gananciaVolumen = $ventaBruta / 3;
            $gananciaVolumenUsuarios[$nombreCloser2]['ganancia_por_rol']['Closer 2'] += $gananciaVolumen;
        }
    }

    // Front
    if (!empty($venta['front_nombre']) && $venta['front_nombre'] !== 'No asignado') {
        $nombreFront = trim($venta['front_nombre']);
        if (isset($gananciaVolumenUsuarios[$nombreFront])) {
            $gananciaVolumen = $ventaBruta;
            $gananciaVolumenUsuarios[$nombreFront]['ganancia_por_rol']['Front'] += $gananciaVolumen;
        }
    }
}

// Filtrar usuarios que tienen al menos algún volumen
$gananciaVolumenUsuarios = array_filter($gananciaVolumenUsuarios, function ($usuario) {
    $total = $usuario['ganancia_por_rol']['Liner'] +
        $usuario['ganancia_por_rol']['Closer 1'] +
        $usuario['ganancia_por_rol']['Closer 2'] +
        $usuario['ganancia_por_rol']['Front'];
    return $total > 0;
});

// Ordenar por total descendente
uasort($gananciaVolumenUsuarios, function ($a, $b) {
    $totalA = $a['ganancia_por_rol']['Liner'] + $a['ganancia_por_rol']['Closer 1'] + $a['ganancia_por_rol']['Closer 2'] + $a['ganancia_por_rol']['Front'];
    $totalB = $b['ganancia_por_rol']['Liner'] + $b['ganancia_por_rol']['Closer 1'] + $b['ganancia_por_rol']['Closer 2'] + $b['ganancia_por_rol']['Front'];
    return $totalB <=> $totalA;
});

// Datos de ganancia volumen
$rowVolumen = 6;
$totalVolumenGeneral = 0;

foreach ($gananciaVolumenUsuarios as $usuario) {
    $totalUsuario = $usuario['ganancia_por_rol']['Liner'] +
        $usuario['ganancia_por_rol']['Closer 1'] +
        $usuario['ganancia_por_rol']['Closer 2'] +
        $usuario['ganancia_por_rol']['Front'];

    $totalVolumenGeneral += $totalUsuario;

    $sheetVolumen->setCellValue('A' . $rowVolumen, $usuario['nombre']);
    $sheetVolumen->setCellValue('B' . $rowVolumen, $usuario['ganancia_por_rol']['Liner']);
    $sheetVolumen->setCellValue('C' . $rowVolumen, $usuario['ganancia_por_rol']['Closer 1']);
    $sheetVolumen->setCellValue('D' . $rowVolumen, $usuario['ganancia_por_rol']['Closer 2']);
    $sheetVolumen->setCellValue('E' . $rowVolumen, $usuario['ganancia_por_rol']['Front']);
    $sheetVolumen->setCellValue('F' . $rowVolumen, $totalUsuario);

    // Formato de moneda
    $sheetVolumen->getStyle('B' . $rowVolumen . ':F' . $rowVolumen)->getNumberFormat()->setFormatCode('"$"#,##0.00');

    // Colores alternados
    $fillColor = ($rowVolumen - 5) % 2 == 0 ? 'F8F9F9' : 'ECF0F1';
    $sheetVolumen->getStyle('A' . $rowVolumen . ':F' . $rowVolumen)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB($fillColor);
    $sheetVolumen->getStyle('A' . $rowVolumen . ':F' . $rowVolumen)->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);

    $rowVolumen++;
}

// Total general de ganancia volumen
$sheetVolumen->setCellValue('E' . $rowVolumen, 'TOTAL GANANCIA VOLUMEN:');
$sheetVolumen->setCellValue('F' . $rowVolumen, $totalVolumenGeneral);

$sheetVolumen->getStyle('E' . $rowVolumen . ':F' . $rowVolumen)->getFont()->setBold(true);
$sheetVolumen->getStyle('E' . $rowVolumen . ':F' . $rowVolumen)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('27ae60');
$sheetVolumen->getStyle('E' . $rowVolumen . ':F' . $rowVolumen)->getFont()->getColor()->setARGB('FFFFFF');
$sheetVolumen->getStyle('E' . $rowVolumen . ':F' . $rowVolumen)->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
$sheetVolumen->getStyle('F' . $rowVolumen)->getNumberFormat()->setFormatCode('"$"#,##0.00');

// ============================================================================
// CONFIGURAR ORDEN DE HOJAS
// ============================================================================

$spreadsheet->setActiveSheetIndex(0);

// ============================================================================
// GENERAR Y DESCARGAR EXCEL
// ============================================================================

// Limpiar buffer
while (ob_get_level()) {
    ob_end_clean();
}

// Generar nombre del archivo
$sala_nombre_archivo = ($sala_id == 0) ? 'Todas_Salas' : str_replace(' ', '_', $nombre_sala);
$nombre_archivo = 'Reporte_Ventas_Mensual_' . $mes_seleccionado . '_Sala_' . $sala_nombre_archivo . '.xlsx';

// Headers para descarga Excel
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment;filename="' . $nombre_archivo . '"');
header('Cache-Control: max-age=0');
header('Content-Transfer-Encoding: binary');
header('Pragma: public');
header('Expires: 0');

// Crear writer y guardar en salida
$writer = new Xlsx($spreadsheet);
$writer->save('php://output');
exit;
