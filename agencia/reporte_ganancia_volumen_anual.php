<?php
require_once __DIR__ . '/../includes/db.php';
// reporte_ganancia_volumen_anual.php
// Limpiar cualquier salida previa
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
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;

date_default_timezone_set('America/Mexico_City');

// Solo se descarga con sesión iniciada (admin o superadmin)
require_once __DIR__ . '/../session_config.php'; session_start();
if (!isset($_SESSION['usuario_id']) || !in_array($_SESSION['rol'] ?? '', ['admin', 'superadmin'], true)) {
    header("Location: ./login.php");
    exit;
}
session_write_close();

// Función para limpiar símbolos de los campos de texto
function limpiarTexto($texto) {
    if ($texto === null || $texto === '') {
        return $texto;
    }
    return preg_replace('/[^\p{L}\p{N}\s]/u', '', $texto);
}

// Función para normalizar nombres
function normalizarNombre($nombre) {
    return trim(strtolower($nombre));
}

// Nombre del participante de un rol: primero el nombre manual, si no el calculado (null si no hay)
function nombreParticipante($venta, $campoManual, $campoNombre) {
    if (!empty($venta[$campoManual]) && trim($venta[$campoManual]) !== '') {
        return trim($venta[$campoManual]);
    }
    if (!empty($venta[$campoNombre]) && $venta[$campoNombre] !== 'No asignado') {
        return trim($venta[$campoNombre]);
    }
    return null;
}

// Roles de cada venta: [etiqueta, campo nombre manual, campo nombre, campo ganancia, divisor ganancia volumen]
$rolesVenta = [
    ['Liner', 'liner_nombre_manual', 'liner_nombre', 'ganancia_liner', 2],
    ['Closer 1', 'closer1_nombre_manual', 'closer1_nombre', 'ganancia_closer1', 2],
    ['Closer 2', 'closer2_nombre_manual', 'closer2_nombre', 'ganancia_closer2', 3],
    ['Front', 'full_stack_nombre_manual', 'front_nombre', 'ganancia_fullstack', 1],
];

// Conexión a base de datos

try {
    $pdo = conexionPDO();
} catch (PDOException $e) {
    die("Error de conexión a la base de datos: " . $e->getMessage());
}

// ============================================================================
// OBTENER PARÁMETROS
// ============================================================================

$año_seleccionado = isset($_GET['año']) ? $_GET['año'] : date('Y');
$sala = isset($_GET['sala']) ? $_GET['sala'] : 'todas';

// Validar año
if (!preg_match('/^\d{4}$/', $año_seleccionado)) {
    die('Error: Formato de año inválido. Debe ser YYYY');
}

// Determinar sala a usar
$salaSeleccionada = 0; // 0 = todas las salas
if ($sala !== 'todas' && is_numeric($sala)) {
    $salaSeleccionada = intval($sala);
}

// Obtener nombre de la sala para mostrar en el reporte
$nombre_sala = "Todas las salas";
if ($salaSeleccionada > 0) {
    try {
        $query_sala = "SELECT nombre FROM salas WHERE id = ?";
        $stmt_sala = $pdo->prepare($query_sala);
        $stmt_sala->execute([$salaSeleccionada]);
        $result_sala = $stmt_sala->fetch(PDO::FETCH_ASSOC);
        
        if ($result_sala) {
            $nombre_sala = $result_sala['nombre'];
        } else {
            $nombre_sala = "Sala ID: " . $salaSeleccionada;
        }
    } catch (PDOException $e) {
        $nombre_sala = "Sala ID: " . $salaSeleccionada;
    }
}

// ============================================================================
// CONSULTA SQL ANUAL
// ============================================================================

// Construir consulta base para el año seleccionado
$sql = "SELECT v.*, 
    CONCAT(v.cliente, ' ', v.apepat, ' ', COALESCE(v.apemat, '')) AS cliente_nombre,
    CASE 
      WHEN liner.apemat IS NOT NULL AND TRIM(liner.apemat) != '' 
      THEN liner.apemat
      ELSE COALESCE(v.liner_nombre_manual, 'No asignado')
    END AS liner_nombre,
    liner.id AS liner_id,
    CASE 
      WHEN c1.apemat IS NOT NULL AND TRIM(c1.apemat) != '' 
      THEN c1.apemat
      ELSE COALESCE(v.closer1_nombre_manual, 'No asignado')
    END AS closer1_nombre,
    c1.id AS closer1_id,
    CASE 
      WHEN c2.apemat IS NOT NULL AND TRIM(c2.apemat) != '' 
      THEN c2.apemat
      ELSE COALESCE(v.closer2_nombre_manual, 'No asignado')
    END AS closer2_nombre,
    c2.id AS closer2_id,
    CASE 
      WHEN fs.apemat IS NOT NULL AND TRIM(fs.apemat) != '' 
      THEN fs.apemat
      ELSE COALESCE(v.full_stack_nombre_manual, 'No asignado')
    END AS front_nombre,
    fs.id AS full_stack_id,
    CONCAT(p.nombre_ancronimo, ' (', p.plataformaspei, ')') AS plataforma_nombre_completo,
    p.metodo_pago AS metodo_pago_plataforma,
    v.liner_nombre_manual,
    v.closer1_nombre_manual,
    v.closer2_nombre_manual,
    v.full_stack_nombre_manual,
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
if ($salaSeleccionada > 0) {
    $sql .= " AND v.sala_id = ?";
    $params[] = $salaSeleccionada;
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
    die('No hay datos para generar el reporte del año ' . $año_seleccionado);
}

// ============================================================================
// CONFIGURACIÓN DE OVER
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

    // Sala 1 - NUVIA
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

    // Ambos salas - GERENTE
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

// Función para calcular OVER (las metas que no existen en la configuración cuentan como 0%)
function calcularOver($ventaNetoTotal, $config) {
    $porcentaje = 0;
    $totalOver = 0;
    
    if ($ventaNetoTotal <= 300000) {
        $porcentaje = $config['metas'][0]['porcentaje'] ?? 0;
        $totalOver = $ventaNetoTotal * $porcentaje / 100;
    } elseif ($ventaNetoTotal <= 350000) {
        $porcentaje = $config['metas'][1]['porcentaje'] ?? 0;
        $totalOver = (300000 * ($config['metas'][0]['porcentaje'] ?? 0) / 100) + 
                    (($ventaNetoTotal - 300000) * $porcentaje / 100);
    } else {
        $porcentaje = $config['metas'][2]['porcentaje'] ?? 0;
        $totalOver = (300000 * ($config['metas'][0]['porcentaje'] ?? 0) / 100) + 
                    (50000 * ($config['metas'][1]['porcentaje'] ?? 0) / 100) + 
                    (($ventaNetoTotal - 350000) * $porcentaje / 100);
    }
    
    return ['porcentaje' => $porcentaje, 'totalOver' => $totalOver];
}

// ============================================================================
// CALCULAR TOTALES ANUALES
// ============================================================================

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

// ============================================================================
// CALCULAR VENTAS POR MES PARA GRÁFICA
// ============================================================================

$ventasPorMes = [];
for ($mes = 1; $mes <= 12; $mes++) {
    $ventasPorMes[$mes] = [
        'venta_bruto' => 0,
        'venta_neto' => 0,
        'ventas_count' => 0
    ];
}

foreach ($ventas as $venta) {
    $mes = (int)date('m', strtotime($venta['fecha']));
    $ventasPorMes[$mes]['venta_bruto'] += (float)$venta['precio'];
    $ventasPorMes[$mes]['venta_neto'] += (float)$venta['precio_neto'];
    $ventasPorMes[$mes]['ventas_count']++;
}

// ============================================================================
// CREAR EXCEL
// ============================================================================

$spreadsheet = new Spreadsheet();

// Estilos comunes
$headerStyle = [
    'font' => [
        'bold' => true,
        'color' => ['rgb' => 'FFFFFF']
    ],
    'fill' => [
        'fillType' => Fill::FILL_SOLID,
        'startColor' => ['rgb' => '3498DB']
    ],
    'alignment' => [
        'horizontal' => Alignment::HORIZONTAL_CENTER,
        'vertical' => Alignment::VERTICAL_CENTER,
        'wrapText' => true
    ],
    'borders' => [
        'allBorders' => [
            'borderStyle' => Border::BORDER_THIN,
            'color' => ['rgb' => '000000']
        ]
    ]
];

$titleStyle = [
    'font' => [
        'bold' => true,
        'size' => 14,
        'color' => ['rgb' => 'FFFFFF']
    ],
    'fill' => [
        'fillType' => Fill::FILL_SOLID,
        'startColor' => ['rgb' => '2C3E50']
    ],
    'alignment' => [
        'horizontal' => Alignment::HORIZONTAL_CENTER,
        'vertical' => Alignment::VERTICAL_CENTER
    ]
];

$subtitleStyle = [
    'font' => [
        'bold' => true,
        'size' => 12,
        'color' => ['rgb' => 'FFFFFF']
    ],
    'fill' => [
        'fillType' => Fill::FILL_SOLID,
        'startColor' => ['rgb' => '34495E']
    ],
    'alignment' => [
        'horizontal' => Alignment::HORIZONTAL_CENTER,
        'vertical' => Alignment::VERTICAL_CENTER
    ]
];

$moneyStyle = [
    'numberFormat' => [
        'formatCode' => '"$"#,##0.00'
    ]
];

$totalStyle = [
    'font' => [
        'bold' => true,
        'color' => ['rgb' => 'FFFFFF']
    ],
    'fill' => [
        'fillType' => Fill::FILL_SOLID,
        'startColor' => ['rgb' => '27AE60']
    ],
    'alignment' => [
        'horizontal' => Alignment::HORIZONTAL_RIGHT
    ],
    'borders' => [
        'allBorders' => [
            'borderStyle' => Border::BORDER_THIN,
            'color' => ['rgb' => '000000']
        ]
    ]
];

$borderStyle = [
    'borders' => [
        'allBorders' => [
            'borderStyle' => Border::BORDER_THIN,
            'color' => ['rgb' => '000000']
        ]
    ]
];

// Configurar estilo de fuente por defecto
$spreadsheet->getDefaultStyle()->getFont()->setName('Arial')->setSize(10);

// ============================================================================
// HOJA 1: RESUMEN ANUAL Y DETALLE DE VENTAS
// ============================================================================

$sheet = $spreadsheet->getActiveSheet();
$sheet->setTitle('Resumen Anual');

// Título principal
$sheet->mergeCells('A1:L1');
$sheet->setCellValue('A1', 'REPORTE DE VENTAS ANUAL - GRUPO IDEAS - GANANCIA VOLUMEN');
$sheet->getStyle('A1')->applyFromArray($titleStyle);
$sheet->getRowDimension(1)->setRowHeight(25);

// Información de filtros
$sheet->mergeCells('A2:L2');
$filtroTexto = "Año: " . $año_seleccionado;
if ($salaSeleccionada > 0) {
    $filtroTexto .= " | Sala: " . $nombre_sala;
} else {
    $filtroTexto .= " | Todas las Salas";
}
$sheet->setCellValue('A2', 'Filtro aplicado: ' . $filtroTexto);
$sheet->getStyle('A2')->getFont()->setItalic(true);
$sheet->mergeCells('A3:L3');
$sheet->setCellValue('A3', 'Fecha de generación: ' . date('d/m/Y H:i:s'));
$sheet->getStyle('A3')->getFont()->setItalic(true);

// Totales Generales Anuales
$sheet->mergeCells('A5:L5');
$sheet->setCellValue('A5', 'TOTALES GENERALES ANUALES');
$sheet->getStyle('A5')->applyFromArray($subtitleStyle);
$sheet->getRowDimension(5)->setRowHeight(20);

// Cabecera de totales
$sheet->setCellValue('A6', 'Concepto');
$sheet->setCellValue('B6', 'Monto');
$sheet->getStyle('A6:B6')->applyFromArray($headerStyle);
$sheet->getRowDimension(6)->setRowHeight(20);

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
foreach ($dataTotales as $index => $data) {
    $sheet->setCellValue('A' . $row, $data[0]);
    $sheet->setCellValue('B' . $row, $data[1]);
    // La primera fila es un conteo, el resto son montos
    if ($index > 0) {
        $sheet->getStyle('B' . $row)->applyFromArray($moneyStyle);
    }
    $sheet->getStyle('A' . $row . ':B' . $row)->applyFromArray($borderStyle);
    
    // Alternar colores de fila
    if ($index % 2 == 0) {
        $sheet->getStyle('A' . $row . ':B' . $row)->getFill()
            ->setFillType(Fill::FILL_SOLID)
            ->getStartColor()->setRGB('F8F9FA');
    }
    $row++;
}

// Resumen Mensual
$row += 2;
$sheet->mergeCells('A' . $row . ':L' . $row);
$sheet->setCellValue('A' . $row, 'RESUMEN MENSUAL');
$sheet->getStyle('A' . $row)->applyFromArray($subtitleStyle);
$sheet->getRowDimension($row)->setRowHeight(20);

$row++;
$mesHeaders = ['Mes', 'Ventas', 'Venta Bruto', 'Venta Neto'];
$col = 0;
foreach ($mesHeaders as $header) {
    $columnLetter = Coordinate::stringFromColumnIndex($col + 1);
    $sheet->setCellValue($columnLetter . $row, $header);
    $col++;
}
$sheet->getStyle('A' . $row . ':D' . $row)->applyFromArray($headerStyle);
$sheet->getRowDimension($row)->setRowHeight(20);

$nombresMeses = ['Enero', 'Febrero', 'Marzo', 'Abril', 'Mayo', 'Junio', 'Julio', 'Agosto', 'Septiembre', 'Octubre', 'Noviembre', 'Diciembre'];

$row++;
$startResumenRow = $row;
for ($mes = 1; $mes <= 12; $mes++) {
    $sheet->setCellValue('A' . $row, $nombresMeses[$mes - 1]);
    $sheet->setCellValue('B' . $row, $ventasPorMes[$mes]['ventas_count']);
    $sheet->setCellValue('C' . $row, $ventasPorMes[$mes]['venta_bruto']);
    $sheet->setCellValue('D' . $row, $ventasPorMes[$mes]['venta_neto']);
    
    $sheet->getStyle('C' . $row)->applyFromArray($moneyStyle);
    $sheet->getStyle('D' . $row)->applyFromArray($moneyStyle);
    $sheet->getStyle('A' . $row . ':D' . $row)->applyFromArray($borderStyle);
    
    if ($row % 2 == 0) {
        $sheet->getStyle('A' . $row . ':D' . $row)->getFill()
            ->setFillType(Fill::FILL_SOLID)
            ->getStartColor()->setRGB('F8F9FA');
    }
    $row++;
}

// Detalle de Ventas Anual
$row += 2;
$sheet->mergeCells('A' . $row . ':L' . $row);
$sheet->setCellValue('A' . $row, 'DETALLE DE VENTAS - AÑO ' . $año_seleccionado);
$sheet->getStyle('A' . $row)->applyFromArray($subtitleStyle);
$sheet->getRowDimension($row)->setRowHeight(20);

$row++;
$headers = ['Fecha', 'Cliente', 'Venta Bruto', 'Venta Neto', 'Liner', 'Closer 1', 'Closer 2', 'Front', 'Plataforma', 'Tipo', 'Sala', 'Producto'];
$col = 0;
foreach ($headers as $header) {
    $columnLetter = Coordinate::stringFromColumnIndex($col + 1);
    $sheet->setCellValue($columnLetter . $row, $header);
    $col++;
}
$sheet->getStyle('A' . $row . ':L' . $row)->applyFromArray($headerStyle);
$sheet->getRowDimension($row)->setRowHeight(20);

// Autoajustar anchos de columnas
foreach (range('A', 'L') as $column) {
    $sheet->getColumnDimension($column)->setAutoSize(true);
}

// Datos de ventas
$row++;
$startDataRow = $row;
foreach ($ventas as $venta) {
    $sheet->setCellValue('A' . $row, date('d/m/Y', strtotime($venta['fecha'])));
    $sheet->setCellValue('B' . $row, $venta['cliente_nombre']);
    $sheet->setCellValue('C' . $row, $venta['precio']);
    $sheet->setCellValue('D' . $row, $venta['precio_neto']);
    $sheet->setCellValue('E' . $row, $venta['liner_nombre'] ?? 'N/A');
    $sheet->setCellValue('F' . $row, $venta['closer1_nombre'] ?? 'N/A');
    $sheet->setCellValue('G' . $row, $venta['closer2_nombre'] ?? 'N/A');
    $sheet->setCellValue('H' . $row, $venta['front_nombre'] ?? 'N/A');
    $sheet->setCellValue('I' . $row, $venta['plataforma_nombre_completo'] ?? 'N/A');
    $sheet->setCellValue('J' . $row, $venta['tipo_venta'] ?? 'N/A');
    $sheet->setCellValue('K' . $row, $venta['sala_nombre'] ?? 'N/A');
    $sheet->setCellValue('L' . $row, $venta['producto'] ?? 'N/A');
    
    // Aplicar formato de moneda a las columnas C y D
    $sheet->getStyle('C' . $row)->applyFromArray($moneyStyle);
    $sheet->getStyle('D' . $row)->applyFromArray($moneyStyle);
    
    // Aplicar bordes
    $sheet->getStyle('A' . $row . ':L' . $row)->applyFromArray($borderStyle);
    
    // Alternar colores de fila
    if ($row % 2 == 0) {
        $sheet->getStyle('A' . $row . ':L' . $row)->getFill()
            ->setFillType(Fill::FILL_SOLID)
            ->getStartColor()->setRGB('F8F9FA');
    }
    
    $row++;
}

// Aplicar wrap text a las columnas de texto largo
$sheet->getStyle('B:B')->getAlignment()->setWrapText(true);
$sheet->getStyle('E:H')->getAlignment()->setWrapText(true);
$sheet->getStyle('I:I')->getAlignment()->setWrapText(true);
$sheet->getStyle('L:L')->getAlignment()->setWrapText(true);

// ============================================================================
// HOJA 2: SISTEMA DE OVER ANUAL
// ============================================================================

$sheet2 = $spreadsheet->createSheet();
$sheet2->setTitle('Sistema OVER Anual');

// Título OVER
$sheet2->mergeCells('A1:D1');
$tituloOver = $salaSeleccionada > 0 
    ? 'SISTEMA DE OVER ANUAL - BONOS POR META - SALA: ' . strtoupper($nombre_sala) . ' - AÑO: ' . $año_seleccionado
    : 'SISTEMA DE OVER ANUAL - BONOS POR META - TODAS LAS SALAS - AÑO: ' . $año_seleccionado;
$sheet2->setCellValue('A1', $tituloOver);
$sheet2->getStyle('A1')->applyFromArray($titleStyle);
$sheet2->getRowDimension(1)->setRowHeight(25);

// Información adicional
$sheet2->mergeCells('A2:D2');
if ($salaSeleccionada == 0) {
    $sheet2->setCellValue('A2', 'NOTA: El OVER se calcula por separado para cada sala según la configuración y se suma por persona');
}
$sheet2->getStyle('A2')->getFont()->setItalic(true);

// Cabecera OVER
$row = 4;
if ($salaSeleccionada > 0) {
    $overHeaders = ['Personal', 'Vol Anual (Neto)', 'Porcentaje', 'Total Over'];
} else {
    $overHeaders = ['Personal', 'Vol Total (Neto)', 'Porcentaje', 'Total Over'];
}

$sheet2->setCellValue('A' . $row, $overHeaders[0]);
$sheet2->setCellValue('B' . $row, $overHeaders[1]);
$sheet2->setCellValue('C' . $row, $overHeaders[2]);
$sheet2->setCellValue('D' . $row, $overHeaders[3]);
$sheet2->getStyle('A' . $row . ':D' . $row)->applyFromArray($headerStyle);
$sheet2->getRowDimension($row)->setRowHeight(20);

// Calcular OVER Anual
$row++;
$startOverRow = $row;
$totalOverGeneral = 0;

if ($salaSeleccionada > 0) {
    // PARA SALA ESPECÍFICA
    $ventaNetoSala = $totalesGenerales['venta_neto'];
    
    foreach ($configOver as $persona => $config) {
        if (in_array($salaSeleccionada, $config['salas'])) {
            $calculos = calcularOver($ventaNetoSala, $config);
            $totalOverGeneral += $calculos['totalOver'];
            
            $sheet2->setCellValue('A' . $row, $config['nombre']);
            $sheet2->setCellValue('B' . $row, $ventaNetoSala);
            $sheet2->setCellValue('C' . $row, $calculos['porcentaje'] . '%');
            $sheet2->setCellValue('D' . $row, $calculos['totalOver']);
            
            $sheet2->getStyle('B' . $row)->applyFromArray($moneyStyle);
            $sheet2->getStyle('D' . $row)->applyFromArray($moneyStyle);
            
            // Alternar colores
            if ($row % 2 == 0) {
                $sheet2->getStyle('A' . $row . ':D' . $row)->getFill()
                    ->setFillType(Fill::FILL_SOLID)
                    ->getStartColor()->setRGB('F8F9FA');
            }
            
            $row++;
        }
    }
} else {
    // PARA TODAS LAS SALAS - CALCULAR SUMA POR PERSONA
    $overPorPersona = [];
    
    // Calcular ventas por sala primero
    $ventasPorSalaDetalle = [];
    foreach ($ventas as $venta) {
        $salaIdVenta = $venta['sala_id'] ?? 0;
        $salaNombreVenta = $venta['sala_nombre'] ?? 'Sin Sala';
        
        if (!isset($ventasPorSalaDetalle[$salaIdVenta])) {
            $ventasPorSalaDetalle[$salaIdVenta] = [
                'nombre' => $salaNombreVenta,
                'venta_neto' => 0
            ];
        }
        
        $ventasPorSalaDetalle[$salaIdVenta]['venta_neto'] += (float)$venta['precio_neto'];
    }
    
    foreach ($configOver as $persona => $config) {
        $overPorPersona[$persona] = [
            'nombre' => $config['nombre'],
            'total_over' => 0,
            'volumen_total' => 0
        ];
        
        // Calcular OVER para cada sala donde aplica esta persona
        foreach ($config['salas'] as $salaIdConfig) {
            if (isset($ventasPorSalaDetalle[$salaIdConfig])) {
                $ventaNetoSala = $ventasPorSalaDetalle[$salaIdConfig]['venta_neto'];
                $calculos = calcularOver($ventaNetoSala, $config);
                $totalOverPersonaSala = $calculos['totalOver'];
                
                $overPorPersona[$persona]['total_over'] += $totalOverPersonaSala;
                $overPorPersona[$persona]['volumen_total'] += $ventaNetoSala;
            }
        }
        
        // Solo mostrar si tiene algún OVER
        if ($overPorPersona[$persona]['total_over'] > 0 || $overPorPersona[$persona]['volumen_total'] > 0) {
            $totalOverGeneral += $overPorPersona[$persona]['total_over'];
            
            $sheet2->setCellValue('A' . $row, $overPorPersona[$persona]['nombre']);
            $sheet2->setCellValue('B' . $row, $overPorPersona[$persona]['volumen_total']);
            
            // Calcular porcentaje promedio ponderado
            $porcentajePromedio = $overPorPersona[$persona]['volumen_total'] > 0 ? 
                ($overPorPersona[$persona]['total_over'] / $overPorPersona[$persona]['volumen_total']) * 100 : 0;
            $sheet2->setCellValue('C' . $row, number_format($porcentajePromedio, 2) . '%');
            
            $sheet2->setCellValue('D' . $row, $overPorPersona[$persona]['total_over']);
            
            $sheet2->getStyle('B' . $row)->applyFromArray($moneyStyle);
            $sheet2->getStyle('D' . $row)->applyFromArray($moneyStyle);
            
            // Alternar colores
            if ($row % 2 == 0) {
                $sheet2->getStyle('A' . $row . ':D' . $row)->getFill()
                    ->setFillType(Fill::FILL_SOLID)
                    ->getStartColor()->setRGB('F8F9FA');
            }
            
            $row++;
        }
    }
}

// Aplicar bordes a los datos
if ($row > $startOverRow) {
    $sheet2->getStyle('A' . $startOverRow . ':D' . ($row-1))->applyFromArray($borderStyle);
}

// Total OVER
$sheet2->setCellValue('C' . $row, 'TOTAL OVER:');
$sheet2->setCellValue('D' . $row, $totalOverGeneral);
$sheet2->getStyle('C' . $row . ':D' . $row)->applyFromArray($totalStyle);
$sheet2->getStyle('D' . $row)->applyFromArray($moneyStyle);

// Autoajustar columnas
foreach (range('A', 'D') as $column) {
    $sheet2->getColumnDimension($column)->setAutoSize(true);
}

// ============================================================================
// HOJA 3: COMISIONES POR USUARIO ANUAL
// ============================================================================

$sheet3 = $spreadsheet->createSheet();
$sheet3->setTitle('Comisiones Anuales');

// Procesar usuarios con ganancias (por rol, solo si la ganancia del rol es mayor a 0)
$usuariosConGanancias = [];
foreach ($ventas as $venta) {
    foreach ($rolesVenta as [$rol, $campoManual, $campoNombre, $campoGanancia]) {
        $nombreRol = nombreParticipante($venta, $campoManual, $campoNombre);
        $ganancia = (float)($venta[$campoGanancia] ?? 0);
        if (!$nombreRol || $ganancia <= 0) {
            continue;
        }
        $claveUsuario = normalizarNombre($nombreRol);
        if (!isset($usuariosConGanancias[$claveUsuario])) {
            $usuariosConGanancias[$claveUsuario] = [
                'nombre' => $nombreRol,
                'ganancia_total' => 0,
                'ventas_count' => 0,
                'roles' => []
            ];
        }
        $usuariosConGanancias[$claveUsuario]['ganancia_total'] += $ganancia;
        $usuariosConGanancias[$claveUsuario]['ventas_count']++;
        if (!in_array($rol, $usuariosConGanancias[$claveUsuario]['roles'])) {
            $usuariosConGanancias[$claveUsuario]['roles'][] = $rol;
        }
    }
}

// Ordenar por ganancia total descendente
uasort($usuariosConGanancias, function($a, $b) {
    return $b['ganancia_total'] <=> $a['ganancia_total'];
});

// Título comisiones por usuario anual
$sheet3->mergeCells('A1:F1');
$tituloUsuarios = $salaSeleccionada > 0 
    ? 'RESUMEN DE COMISIONES POR USUARIO - SALA: ' . strtoupper($nombre_sala) . ' - AÑO: ' . $año_seleccionado
    : 'RESUMEN DE COMISIONES POR USUARIO - TODAS LAS SALAS - AÑO: ' . $año_seleccionado;
$sheet3->setCellValue('A1', $tituloUsuarios);
$sheet3->getStyle('A1')->applyFromArray($titleStyle);
$sheet3->getRowDimension(1)->setRowHeight(25);

// Cabecera usuarios
$row = 3;
$userHeaders = ['Usuario', 'Roles', 'Total Ventas', 'Ganancia Total', 'Over', 'Total General'];
$sheet3->setCellValue('A' . $row, $userHeaders[0]);
$sheet3->setCellValue('B' . $row, $userHeaders[1]);
$sheet3->setCellValue('C' . $row, $userHeaders[2]);
$sheet3->setCellValue('D' . $row, $userHeaders[3]);
$sheet3->setCellValue('E' . $row, $userHeaders[4]);
$sheet3->setCellValue('F' . $row, $userHeaders[5]);
$sheet3->getStyle('A' . $row . ':F' . $row)->applyFromArray($headerStyle);
$sheet3->getRowDimension($row)->setRowHeight(20);

// Datos usuarios
$row++;
$startUserRow = $row;
$totalGananciaUsuarios = 0;

foreach ($usuariosConGanancias as $usuario) {
    // Identificar si tiene OVER
    $tieneOver = false;
    $overUsuarioTotal = 0;
    $nombreUsuario = trim($usuario['nombre']);
    
    foreach ($configOver as $key => $config) {
        if (stripos($nombreUsuario, $config['nombre']) !== false) {
            $tieneOver = true;
            // Calcular OVER para este usuario
            if ($salaSeleccionada > 0) {
                if (in_array($salaSeleccionada, $config['salas'])) {
                    $calculos = calcularOver($totalesGenerales['venta_neto'], $config);
                    $overUsuarioTotal = $calculos['totalOver'];
                }
            } else {
                // Para todas las salas, sumar OVER de todas las salas aplicables
                foreach ($config['salas'] as $salaIdConfig) {
                    if (isset($ventasPorSalaDetalle[$salaIdConfig])) {
                        $calculos = calcularOver($ventasPorSalaDetalle[$salaIdConfig]['venta_neto'], $config);
                        $overUsuarioTotal += $calculos['totalOver'];
                    }
                }
            }
            break;
        }
    }
    
    $totalGeneral = $usuario['ganancia_total'] + $overUsuarioTotal;
    $totalGananciaUsuarios += $usuario['ganancia_total'];
    
    $sheet3->setCellValue('A' . $row, $usuario['nombre']);
    $sheet3->setCellValue('B' . $row, implode(', ', $usuario['roles']));
    $sheet3->setCellValue('C' . $row, $usuario['ventas_count']);
    $sheet3->setCellValue('D' . $row, $usuario['ganancia_total']);
    $sheet3->setCellValue('E' . $row, $tieneOver ? $overUsuarioTotal : 'N/A');
    $sheet3->setCellValue('F' . $row, $totalGeneral);
    
    // Formato moneda
    $sheet3->getStyle('D' . $row)->applyFromArray($moneyStyle);
    if ($tieneOver) {
        $sheet3->getStyle('E' . $row)->applyFromArray($moneyStyle);
    }
    $sheet3->getStyle('F' . $row)->applyFromArray($moneyStyle);
    
    // Alternar colores
    if ($row % 2 == 0) {
        $sheet3->getStyle('A' . $row . ':F' . $row)->getFill()
            ->setFillType(Fill::FILL_SOLID)
            ->getStartColor()->setRGB('F8F9FA');
    }
    
    $row++;
}

// Aplicar bordes
if ($row > $startUserRow) {
    $sheet3->getStyle('A' . $startUserRow . ':F' . ($row-1))->applyFromArray($borderStyle);
}

// Total usuarios
$sheet3->setCellValue('C' . $row, 'TOTAL GENERAL:');
$sheet3->setCellValue('D' . $row, $totalGananciaUsuarios);
$sheet3->setCellValue('E' . $row, '');
$sheet3->setCellValue('F' . $row, $totalGananciaUsuarios + $totalOverGeneral);
$sheet3->getStyle('C' . $row . ':F' . $row)->applyFromArray($totalStyle);
$sheet3->getStyle('D' . $row)->applyFromArray($moneyStyle);
$sheet3->getStyle('F' . $row)->applyFromArray($moneyStyle);

// Autoajustar columnas
foreach (range('A', 'F') as $column) {
    $sheet3->getColumnDimension($column)->setAutoSize(true);
}
$sheet3->getColumnDimension('B')->setWidth(30);
$sheet3->getStyle('B:B')->getAlignment()->setWrapText(true);

// ============================================================================
// HOJA 4: GANANCIA VOLUMEN ANUAL
// ============================================================================

$sheet4 = $spreadsheet->createSheet();
$sheet4->setTitle('Ganancia Volumen Anual');

// Título ganancia volumen anual
$sheet4->mergeCells('A1:F1');
$tituloVolumen = $salaSeleccionada > 0 
    ? 'GANANCIA VOLUMEN POR USUARIO Y ROL - SALA: ' . strtoupper($nombre_sala) . ' - AÑO: ' . $año_seleccionado
    : 'GANANCIA VOLUMEN POR USUARIO Y ROL - TODAS LAS SALAS - AÑO: ' . $año_seleccionado;
$sheet4->setCellValue('A1', $tituloVolumen);
$sheet4->getStyle('A1')->applyFromArray($titleStyle);
$sheet4->getRowDimension(1)->setRowHeight(25);

// Información
$sheet4->mergeCells('A2:F2');
$sheet4->setCellValue('A2', 'Base: Venta Bruta | Liner=Venta Bruta/2 | Closer 1=Venta Bruta/2 | Closer 2=Venta Bruta/3 | Front=Venta Bruta');
$sheet4->getStyle('A2')->getFont()->setItalic(true);

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

// Procesar ganancia volumen (solo usuarios con ganancias)
foreach ($ventas as $venta) {
    $ventaBruta = (float)$venta['precio'];
    foreach ($rolesVenta as [$rol, $campoManual, $campoNombre, , $divisor]) {
        $nombreRol = nombreParticipante($venta, $campoManual, $campoNombre);
        if (!$nombreRol) {
            continue;
        }
        $claveUsuario = normalizarNombre($nombreRol);
        if (isset($gananciaVolumenUsuarios[$claveUsuario])) {
            $gananciaVolumen = $ventaBruta / $divisor;
            $gananciaVolumenUsuarios[$claveUsuario]['ganancia_volumen'] += $gananciaVolumen;
            $gananciaVolumenUsuarios[$claveUsuario]['ganancia_por_rol'][$rol] += $gananciaVolumen;
        }
    }
}

// Ordenar por ganancia volumen descendente
uasort($gananciaVolumenUsuarios, function($a, $b) {
    return $b['ganancia_volumen'] <=> $a['ganancia_volumen'];
});

// Cabecera ganancia volumen
$row = 4;
$volumenHeaders = ['Usuario', 'G.V. Liner', 'G.V. Closer 1', 'G.V. Closer 2', 'G.V. Front', 'Total Usuario'];
$sheet4->setCellValue('A' . $row, $volumenHeaders[0]);
$sheet4->setCellValue('B' . $row, $volumenHeaders[1]);
$sheet4->setCellValue('C' . $row, $volumenHeaders[2]);
$sheet4->setCellValue('D' . $row, $volumenHeaders[3]);
$sheet4->setCellValue('E' . $row, $volumenHeaders[4]);
$sheet4->setCellValue('F' . $row, $volumenHeaders[5]);
$sheet4->getStyle('A' . $row . ':F' . $row)->applyFromArray($headerStyle);
$sheet4->getRowDimension($row)->setRowHeight(20);

// Datos ganancia volumen
$row++;
$startVolumenRow = $row;
$totalVolumenGeneral = 0;

foreach ($gananciaVolumenUsuarios as $usuario) {
    $totalUsuario = $usuario['ganancia_por_rol']['Liner'] + 
                   $usuario['ganancia_por_rol']['Closer 1'] + 
                   $usuario['ganancia_por_rol']['Closer 2'] + 
                   $usuario['ganancia_por_rol']['Front'];
    
    $totalVolumenGeneral += $totalUsuario;
    
    $sheet4->setCellValue('A' . $row, $usuario['nombre']);
    $sheet4->setCellValue('B' . $row, $usuario['ganancia_por_rol']['Liner']);
    $sheet4->setCellValue('C' . $row, $usuario['ganancia_por_rol']['Closer 1']);
    $sheet4->setCellValue('D' . $row, $usuario['ganancia_por_rol']['Closer 2']);
    $sheet4->setCellValue('E' . $row, $usuario['ganancia_por_rol']['Front']);
    $sheet4->setCellValue('F' . $row, $totalUsuario);
    
    // Formato moneda
    $sheet4->getStyle('B' . $row)->applyFromArray($moneyStyle);
    $sheet4->getStyle('C' . $row)->applyFromArray($moneyStyle);
    $sheet4->getStyle('D' . $row)->applyFromArray($moneyStyle);
    $sheet4->getStyle('E' . $row)->applyFromArray($moneyStyle);
    $sheet4->getStyle('F' . $row)->applyFromArray($moneyStyle);
    
    // Alternar colores
    if ($row % 2 == 0) {
        $sheet4->getStyle('A' . $row . ':F' . $row)->getFill()
            ->setFillType(Fill::FILL_SOLID)
            ->getStartColor()->setRGB('F8F9FA');
    }
    
    $row++;
}

// Aplicar bordes
if ($row > $startVolumenRow) {
    $sheet4->getStyle('A' . $startVolumenRow . ':F' . ($row-1))->applyFromArray($borderStyle);
}

// Total ganancia volumen
$sheet4->setCellValue('E' . $row, 'TOTAL GENERAL:');
$sheet4->setCellValue('F' . $row, $totalVolumenGeneral);
$sheet4->getStyle('E' . $row . ':F' . $row)->applyFromArray($totalStyle);
$sheet4->getStyle('F' . $row)->applyFromArray($moneyStyle);

// Autoajustar columnas
foreach (range('A', 'F') as $column) {
    $sheet4->getColumnDimension($column)->setAutoSize(true);
}

// ============================================================================
// GENERAR Y DESCARGAR EXCEL
// ============================================================================

// Regresar a la primera hoja
$spreadsheet->setActiveSheetIndex(0);

// Limpiar buffer
while (ob_get_level()) {
    ob_end_clean();
}

// Generar nombre del archivo
$sala_nombre_archivo = ($salaSeleccionada == 0) ? 'Todas_Salas' : str_replace(' ', '_', $nombre_sala);
$nombre_archivo = 'Reporte_Ganancia_Volumen_Anual_' . $año_seleccionado . '_Sala_' . $sala_nombre_archivo . '.xlsx';

// Headers para descarga Excel
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment;filename="' . $nombre_archivo . '"');
header('Cache-Control: max-age=0');
header('Content-Transfer-Encoding: binary');
header('Pragma: public');
header('Expires: 0');

// Crear writer y salida
$writer = new Xlsx($spreadsheet);
$writer->save('php://output');
exit;
?>