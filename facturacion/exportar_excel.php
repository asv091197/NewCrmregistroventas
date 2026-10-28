<?php
//exportar_Excel.php 
// Aumentar límites para manejar grandes volúmenes de datos
ini_set('memory_limit', '2048M');        // Aumentar a 2GB (ajusta según necesidad)
ini_set('max_execution_time', 600);      // 10 minutos
ini_set('max_input_time', 600);          // 10 minutos
set_time_limit(0);                       // Sin límite de tiempo

// Deshabilitar caché de output
ini_set('output_buffering', 'off');
ini_set('zlib.output_compression', 'off');

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

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;

date_default_timezone_set('America/Mexico_City');

// Función para limpiar símbolos de los campos de texto
function limpiarTexto($texto)
{
    if ($texto === null || $texto === '') {
        return $texto;
    }
    return preg_replace('/[^\p{L}\p{N}\s]/u', '', $texto);
}

// Función para normalizar nombres (quitar espacios, convertir a minúsculas para comparar)
function normalizarNombre($nombre)
{
    return trim(strtolower($nombre));
}

// Función para obtener configuración de OVER por sala
function obtenerConfigOverPorSala($configOver, $salaId)
{
    $configPorSala = [];

    foreach ($configOver as $persona => $config) {
        if (in_array($salaId, $config['salas'])) {
            $configPorSala[$persona] = $config;
        }
    }

    return $configPorSala;
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

// CONSULTA SQL CON LA ESTRUCTURA SOLICITADA
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

    // Aplicar limpieza de símbolos a los campos de nombres (incluyendo manuales)
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
    unset($venta); // Romper la referencia

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
            ['min' => 0, 'max' => null, 'porcentaje' => 2.50]
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

// ============================================================================
// DEFINIR ESTILOS SEGUROS (EVITAR PROBLEMAS XML)
// ============================================================================

// Función para aplicar estilos de forma segura
function applySafeStyle($sheet, $range, $styleType)
{
    switch ($styleType) {
        case 'title':
            $sheet->getStyle($range)->getFont()->setBold(true)->setSize(16);
            $sheet->getStyle($range)->getFont()->getColor()->setRGB('FFFFFF');
            $sheet->getStyle($range)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('3498DB');
            $sheet->getStyle($range)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER)->setVertical(Alignment::VERTICAL_CENTER);
            break;

        case 'header':
            $sheet->getStyle($range)->getFont()->setBold(true);
            $sheet->getStyle($range)->getFont()->getColor()->setRGB('FFFFFF');
            $sheet->getStyle($range)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('2C3E50');
            $sheet->getStyle($range)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER)->setVertical(Alignment::VERTICAL_CENTER);
            // Bordes simples
            $sheet->getStyle($range)->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
            break;

        case 'total':
            $sheet->getStyle($range)->getFont()->setBold(true);
            $sheet->getStyle($range)->getFont()->getColor()->setRGB('FFFFFF');
            $sheet->getStyle($range)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('27AE60');
            $sheet->getStyle($range)->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
            break;

        case 'granTotal':
            $sheet->getStyle($range)->getFont()->setBold(true);
            $sheet->getStyle($range)->getFont()->getColor()->setRGB('FFFFFF');
            $sheet->getStyle($range)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('E74C3C');
            $sheet->getStyle($range)->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
            break;

        case 'formula':
            $sheet->getStyle($range)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('D4F4D4');
            break;

        case 'editable':
            $sheet->getStyle($range)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('FFFF99');
            break;

        case 'plataformaHeader':
            $sheet->getStyle($range)->getFont()->setBold(true)->setSize(14);
            $sheet->getStyle($range)->getFont()->getColor()->setRGB('FFFFFF');
            $sheet->getStyle($range)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('8E44AD');
            $sheet->getStyle($range)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER)->setVertical(Alignment::VERTICAL_CENTER);
            break;
    }
}

// ============================================================================
// DEFINIR HEADERS SEGÚN FILTRO POR PLATAFORMA Y NÚMERO DE PLATAFORMAS
// ============================================================================

$esFiltroPlataforma = !empty($plataformaIds);
$multiplesPlataformas = count($plataformaIds) > 1;

// Definir headers según el tipo de filtro
if ($esFiltroPlataforma) {
    // HEADERS SIMPLIFICADOS PARA FILTRO POR PLATAFORMA
    $headers = [
        'Fecha',
        'Venta Bruto',
        'Plataforma',
        'Tipo Venta',
        'Sala'
    ];
    $ultimaColumna = 'E';
} else {
    // HEADERS COMPLETOS PARA OTROS FILTROS
    $headers = [
        'Fecha',
        'Venta Bruto',
        'Venta Neto',
        'Cliente',
        'Liner',
        'Comisión Liner',
        'Closer 1',
        'Comisión Closer 1',
        'Closer 2',
        'Comisión Closer 2',
        'Front',
        'Comision Front',
        'Plataforma',
        'Tipo Venta',
        'Sala',
        'Suma Comisión'
    ];
    $ultimaColumna = 'P';
}

// Crear un nuevo libro de Excel
$spreadsheet = new Spreadsheet();

// Configurar propiedades del documento
$spreadsheet->getProperties()
    ->setCreator('Sistema CRM - Grupo Ideas')
    ->setLastModifiedBy('Sistema CRM')
    ->setTitle('Reporte de Ventas - Comisiones (Totalmente Editable)')
    ->setSubject('Reporte de Ventas')
    ->setDescription('Reporte generado automáticamente por el sistema CRM')
    ->setKeywords('ventas reporte excel crm comisiones')
    ->setCategory('Reporte');

// Configurar estilo por defecto para evitar problemas
$spreadsheet->getDefaultStyle()->getFont()->setName('Arial');
$spreadsheet->getDefaultStyle()->getFont()->setSize(10);

// ============================================================================
// HOJA PRINCIPAL - RESUMEN GENERAL CON FÓRMULAS
// ============================================================================

$sheet = $spreadsheet->getActiveSheet();
$sheet->setTitle('Resumen General');

// Configurar página
$sheet->getPageSetup()
    ->setOrientation(PageSetup::ORIENTATION_LANDSCAPE)
    ->setPaperSize(PageSetup::PAPERSIZE_A4)
    ->setFitToWidth(1)
    ->setFitToHeight(0);

// Título del reporte
$sheet->mergeCells('A1:' . $ultimaColumna . '2');
$tituloReporte = 'REPORTE DE VENTAS - GRUPO IDEAS - RESUMEN GENERAL (EDITABLE)';

// AGREGAR NOMBRE DE LA SALA SI SE FILTRÓ POR SALA
if (!empty($salaNombre)) {
    $tituloReporte .= ' - SALA: ' . strtoupper($salaNombre);
}

if (!empty($plataformasNombres)) {
    if ($multiplesPlataformas) {
        $tituloReporte .= ' - PLATAFORMAS SEPARADAS: ' . implode(', ', $plataformasNombres);
    } else {
        $tituloReporte .= ' - PLATAFORMA: ' . implode(', ', $plataformasNombres);
    }
}

$sheet->setCellValue('A1', $tituloReporte);
applySafeStyle($sheet, 'A1:' . $ultimaColumna . '2', 'title');

// Información de filtros aplicados
$filtroTexto = 'Todos los registros';
if (!empty($fechaInicio) && !empty($fechaFin)) {
    $filtroTexto = "Desde: " . date('d/m/Y', strtotime($fechaInicio)) . " Hasta: " . date('d/m/Y', strtotime($fechaFin));
} elseif (!empty($fechaInicio)) {
    $filtroTexto = "Desde: " . date('d/m/Y', strtotime($fechaInicio));
} elseif (!empty($fechaFin)) {
    $filtroTexto = "Hasta: " . date('d/m/Y', strtotime($fechaFin));
}

// AGREGAR SALA AL TEXTO DEL FILTRO
if (!empty($salaNombre)) {
    $filtroTexto .= empty($filtroTexto) ? "Sala: " . $salaNombre : " | Sala: " . $salaNombre;
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

$sheet->mergeCells('A3:' . $ultimaColumna . '3');
$sheet->setCellValue('A3', 'Filtro aplicado: ' . $filtroTexto . ' - Fecha de generación: ' . date('d/m/Y H:i:s'));
$sheet->getStyle('A3:' . $ultimaColumna . '3')->getFont()->setItalic(true)->setSize(10);
$sheet->getStyle('A3:' . $ultimaColumna . '3')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

// Nota sobre editabilidad
$sheet->mergeCells('A4:' . $ultimaColumna . '4');
$instrucciones = 'INSTRUCCIONES: ';
if (!$esFiltroPlataforma) {
    $instrucciones .= '1) Campos amarillos = editables | ';
    $instrucciones .= '2) Campos verdes = fórmulas automáticas | ';
}
if ($multiplesPlataformas) {
    $instrucciones .= 'TABLAS SEPARADAS POR PLATAFORMA | ';
}
$instrucciones .= '3) Use los filtros en los encabezados (flechas ▼) - LOS TOTALES SE ACTUALIZAN AUTOMÁTICAMENTE | ';
$instrucciones .= '4) F9 para actualizar cálculos';
if (!$esFiltroPlataforma) {
    $instrucciones .= ' | 5) Los cambios se reflejan automáticamente en las otras hojas';
}

$sheet->setCellValue('A4', $instrucciones);
$sheet->getStyle('A4:' . $ultimaColumna . '4')->getFont()->setBold(true)->setSize(10);
$sheet->getStyle('A4:' . $ultimaColumna . '4')->getFont()->getColor()->setRGB('E74C3C');
$sheet->getStyle('A4:' . $ultimaColumna . '4')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('FDEBD0');
$sheet->getStyle('A4:' . $ultimaColumna . '4')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

// Mapear cada venta a su fila para la vinculación posterior
$ventasMap = [];

// Llenar datos en hoja principal
$row = 6;

if ($esFiltroPlataforma) {
    if ($multiplesPlataformas) {
        // ============================================================================
        // MÚLTIPLES PLATAFORMAS: MOSTRAR TABLAS SEPARADAS POR PLATAFORMA CON SUMA POR DÍA
        // ============================================================================

        // Agrupar ventas por plataforma y fecha
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

            // Agrupar por fecha dentro de cada plataforma
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
        uasort($ventasPorPlataforma, function ($a, $b) {
            return strcmp($a['nombre'], $b['nombre']);
        });

        $totalesPlataforma = [];

        foreach ($ventasPorPlataforma as $plataformaId => $plataformaData) {
            $plataformaNombre = $plataformaData['nombre'];

            // TÍTULO DE LA PLATAFORMA
            $sheet->mergeCells('A' . $row . ':E' . $row);
            $sheet->setCellValue('A' . $row, 'PLATAFORMA: ' . strtoupper($plataformaNombre));
            applySafeStyle($sheet, 'A' . $row . ':E' . $row, 'plataformaHeader');
            $row++;

            // HEADERS DE LA TABLA
            $sheet->fromArray($headers, NULL, 'A' . $row);
            applySafeStyle($sheet, 'A' . $row . ':E' . $row, 'header');
            $row++;

            // DATOS DE LA PLATAFORMA - AGRUPADOS POR FECHA
            $filaInicioPlataforma = $row;
            $totalPlataforma = 0;

            // Ordenar fechas descendente dentro de la plataforma
            $fechas = $plataformaData['ventas_por_fecha'];
            krsort($fechas); // Ordenar por fecha descendente

            foreach ($fechas as $fechaData) {
                $fechaFormateada = date('d/m/Y', strtotime($fechaData['fecha']));
                $totalDia = $fechaData['total_dia'];

                $sheet->setCellValue('A' . $row, $fechaFormateada);                    // Fecha
                $sheet->setCellValue('B' . $row, $totalDia);                          // Venta Bruto (SUMA DEL DÍA)
                $sheet->setCellValue('C' . $row, $plataformaNombre);                  // Plataforma
                $sheet->setCellValue('D' . $row, $venta['tipo_venta'] ?? 'N/A');      // Tipo Venta
                $sheet->setCellValue('E' . $row, $venta['sala_nombre'] ?? 'N/A');     // Sala

                $totalPlataforma += $totalDia;

                // Almacenar referencia de la primera venta del día para el mapa
                if (!empty($fechaData['ventas'])) {
                    $ventasMap[$fechaData['ventas'][0]['id']] = $row;
                }

                $row++;
            }

            $filaFinPlataforma = $row - 1;

            // TOTAL DE LA PLATAFORMA
            if ($filaFinPlataforma >= $filaInicioPlataforma) {
                $sheet->setCellValue('A' . $row, 'TOTAL ' . $plataformaNombre . ':');
                $sheet->setCellValue('B' . $row, $totalPlataforma);
                $sheet->mergeCells('A' . $row . ':A' . $row);
                applySafeStyle($sheet, 'A' . $row . ':E' . $row, 'total');
                $sheet->getStyle('B' . $row)->getNumberFormat()->setFormatCode('"$"#,##0.00');

                $totalesPlataforma[$plataformaNombre] = $totalPlataforma;

                $row++;
            }

            // ESPACIO ENTRE PLATAFORMAS
            $row += 2;
        }

        // GRAN TOTAL GENERAL
        if (!empty($totalesPlataforma)) {
            $granTotal = array_sum($totalesPlataforma);

            $sheet->mergeCells('A' . $row . ':E' . $row);
            $sheet->setCellValue('A' . $row, 'TOTAL GENERAL DE TODAS LAS PLATAFORMAS');
            $sheet->getStyle('A' . $row . ':E' . $row)->getFont()->setBold(true)->setSize(12);
            $row++;

            $sheet->setCellValue('A' . $row, 'TOTAL GENERAL:');
            $sheet->setCellValue('B' . $row, $granTotal);
            $sheet->mergeCells('A' . $row . ':A' . $row);
            applySafeStyle($sheet, 'A' . $row . ':E' . $row, 'granTotal');
            $sheet->getStyle('B' . $row)->getNumberFormat()->setFormatCode('"$"#,##0.00');
        }
    } else {
        // ============================================================================
        // UNA SOLA PLATAFORMA: MANTENER COMPORTAMIENTO ORIGINAL (AGRUPADO POR FECHA)
        // ============================================================================

        $ventasAgrupadas = [];
        foreach ($ventas as $venta) {
            $fecha = date('Y-m-d', strtotime($venta['fecha']));
            if (!isset($ventasAgrupadas[$fecha])) {
                $ventasAgrupadas[$fecha] = [
                    'fecha' => $fecha,
                    'total_bruto' => 0,
                    'plataforma' => $venta['plataforma_nombre_completo'] ?? 'N/A',
                    'sala' => $venta['sala_nombre'] ?? 'N/A',
                    'ventas' => [] // Para mantener referencia a las ventas individuales
                ];
            }
            $ventasAgrupadas[$fecha]['total_bruto'] += (float)$venta['precio'];
            $ventasAgrupadas[$fecha]['ventas'][] = $venta;
        }

        // HEADERS
        $sheet->fromArray($headers, NULL, 'A' . $row);
        applySafeStyle($sheet, 'A' . $row . ':E' . $row, 'header');
        $row++;

        // Ordenar por fecha descendente
        krsort($ventasAgrupadas);

        foreach ($ventasAgrupadas as $grupo) {
            $fechaFormateada = date('d/m/Y', strtotime($grupo['fecha']));

            $sheet->setCellValue('A' . $row, $fechaFormateada);                    // Fecha
            $sheet->setCellValue('B' . $row, $grupo['total_bruto']);               // Venta Bruto (total por fecha)
            $sheet->setCellValue('C' . $row, $grupo['plataforma']);                // Plataforma
            $sheet->setCellValue('D' . $row, $venta['tipo_venta'] ?? 'N/A');       // Tipo Venta
            $sheet->setCellValue('E' . $row, $grupo['sala']);                      // Sala

            // Almacenar referencia a la primera venta del grupo para el mapa
            if (!empty($grupo['ventas'])) {
                $ventasMap[$grupo['ventas'][0]['id']] = $row;
            }

            $row++;
        }

        // Fila de totales para una sola plataforma
        if ($row > 6) {
            $sheet->mergeCells('A' . $row . ':A' . $row);
            $sheet->setCellValue('A' . $row, 'TOTALES (automáticos):');

            // Solo Venta Bruto para plataforma
            $sheet->setCellValue('B' . $row, "=SUBTOTAL(9,B6:B" . ($row - 1) . ")");

            applySafeStyle($sheet, 'A' . $row . ':E' . $row, 'total');
            $sheet->getStyle('B' . $row)->getNumberFormat()->setFormatCode('"$"#,##0.00');
        }
    }
} else {
    // PARA OTROS FILTROS: MOSTRAR TODAS LAS VENTAS INDIVIDUALMENTE
    $sheet->fromArray($headers, NULL, 'A' . $row);
    applySafeStyle($sheet, 'A' . $row . ':' . $ultimaColumna . $row, 'header');
    $row++;

    foreach ($ventas as $venta) {
        // Almacenar el ID de la venta y su fila en un mapa para vincularlo más tarde
        $ventasMap[$venta['id']] = $row;

        $fechaFormateada = date('d/m/Y', strtotime($venta['fecha']));

        $sheet->setCellValue('A' . $row, $fechaFormateada);                    // Fecha
        $sheet->setCellValue('B' . $row, (float)$venta['precio']);             // Venta Bruto
        $sheet->setCellValue('C' . $row, (float)$venta['precio_neto']);        // Venta Neto
        $sheet->setCellValue('D' . $row, $venta['cliente_nombre']);            // Cliente
        $sheet->setCellValue('E' . $row, $venta['liner_nombre'] ?? 'No asignado'); // Liner (solo apellido materno)

        // Comisión Liner
        $comisionLiner = $tieneComisiones ? (float)($venta['ganancia_liner'] ?? 0) : 0;
        $sheet->setCellValue('F' . $row, $comisionLiner);                      // Comisión Liner

        $sheet->setCellValue('G' . $row, $venta['closer1_nombre'] ?? 'No asignado'); // Closer 1 (solo apellido materno)

        // Comisión Closer 1
        $comisionCloser1 = $tieneComisiones ? (float)($venta['ganancia_closer1'] ?? 0) : 0;
        $sheet->setCellValue('H' . $row, $comisionCloser1);                    // Comisión Closer 1

        $sheet->setCellValue('I' . $row, $venta['closer2_nombre'] ?? 'No asignado'); // Closer 2 (solo apellido materno)

        // Comisión Closer 2
        $comisionCloser2 = $tieneComisiones ? (float)($venta['ganancia_closer2'] ?? 0) : 0;
        $sheet->setCellValue('J' . $row, $comisionCloser2);                    // Comisión Closer 2

        // Front
        $sheet->setCellValue('K' . $row, $venta['front_nombre'] ?? 'No asignado'); // Front

        // Comisión Front
        $comisionFront = $tieneComisiones ? (float)($venta['ganancia_fullstack'] ?? 0) : 0;
        $sheet->setCellValue('L' . $row, $comisionFront);                      // Comisión Front

        $sheet->setCellValue('M' . $row, $venta['plataforma_nombre_completo'] ?? 'N/A'); // Plataforma
        $sheet->setCellValue('N' . $row, $venta['tipo_venta'] ?? 'N/A');       // Tipo Venta
        $sheet->setCellValue('O' . $row, $venta['sala_nombre'] ?? 'N/A');      // Sala

        // Suma Comisión (FÓRMULA)
        $sheet->setCellValue('P' . $row, "=F{$row}+H{$row}+J{$row}+L{$row}");  // Suma Comisión (incluye Front)

        $row++;
    }
}

// ============================================================================
// NUEVA SECCIÓN: TOTALES GENERALES PARA TODAS LAS COLUMNAS IMPORTANTES
// ============================================================================

if (!$esFiltroPlataforma && $row > 6) {
    $filaTotalesGenerales = $row + 2;

    // Título de totales generales
    $sheet->mergeCells('A' . $filaTotalesGenerales . ':' . $ultimaColumna . $filaTotalesGenerales);
    $sheet->setCellValue('A' . $filaTotalesGenerales, 'TOTALES GENERALES (Se actualizan automáticamente con filtros)');
    $sheet->getStyle('A' . $filaTotalesGenerales . ':' . $ultimaColumna . $filaTotalesGenerales)->getFont()->setBold(true)->setSize(14);
    $sheet->getStyle('A' . $filaTotalesGenerales . ':' . $ultimaColumna . $filaTotalesGenerales)->getFont()->getColor()->setRGB('FFFFFF');
    $sheet->getStyle('A' . $filaTotalesGenerales . ':' . $ultimaColumna . $filaTotalesGenerales)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('2980B9');
    $sheet->getStyle('A' . $filaTotalesGenerales . ':' . $ultimaColumna . $filaTotalesGenerales)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    $filaTotalesGenerales++;

    // Crear tabla de totales generales
    $totalesHeaders = ['Concepto', 'Total General', 'Con Filtros Aplicados'];
    $sheet->fromArray($totalesHeaders, NULL, 'A' . $filaTotalesGenerales);
    applySafeStyle($sheet, 'A' . $filaTotalesGenerales . ':C' . $filaTotalesGenerales, 'header');
    $filaTotalesGenerales++;

    // Venta Bruto
    $sheet->setCellValue('A' . $filaTotalesGenerales, 'VENTA BRUTO TOTAL:');
    $sheet->setCellValue('B' . $filaTotalesGenerales, "=SUM(B6:B" . ($row - 1) . ")"); // Total general
    $sheet->setCellValue('C' . $filaTotalesGenerales, "=SUBTOTAL(9,B6:B" . ($row - 1) . ")"); // Con filtros
    $filaTotalesGenerales++;

    // Venta Neto
    $sheet->setCellValue('A' . $filaTotalesGenerales, 'VENTA NETO TOTAL:');
    $sheet->setCellValue('B' . $filaTotalesGenerales, "=SUM(C6:C" . ($row - 1) . ")"); // Total general
    $sheet->setCellValue('C' . $filaTotalesGenerales, "=SUBTOTAL(9,C6:C" . ($row - 1) . ")"); // Con filtros
    $filaTotalesGenerales++;

    // Comisión Liner
    $sheet->setCellValue('A' . $filaTotalesGenerales, 'COMISIÓN LINER TOTAL:');
    $sheet->setCellValue('B' . $filaTotalesGenerales, "=SUM(F6:F" . ($row - 1) . ")"); // Total general
    $sheet->setCellValue('C' . $filaTotalesGenerales, "=SUBTOTAL(9,F6:F" . ($row - 1) . ")"); // Con filtros
    $filaTotalesGenerales++;

    // Comisión Closer 1
    $sheet->setCellValue('A' . $filaTotalesGenerales, 'COMISIÓN CLOSER 1 TOTAL:');
    $sheet->setCellValue('B' . $filaTotalesGenerales, "=SUM(H6:H" . ($row - 1) . ")"); // Total general
    $sheet->setCellValue('C' . $filaTotalesGenerales, "=SUBTOTAL(9,H6:H" . ($row - 1) . ")"); // Con filtros
    $filaTotalesGenerales++;

    // Comisión Closer 2
    $sheet->setCellValue('A' . $filaTotalesGenerales, 'COMISIÓN CLOSER 2 TOTAL:');
    $sheet->setCellValue('B' . $filaTotalesGenerales, "=SUM(J6:J" . ($row - 1) . ")"); // Total general
    $sheet->setCellValue('C' . $filaTotalesGenerales, "=SUBTOTAL(9,J6:J" . ($row - 1) . ")"); // Con filtros
    $filaTotalesGenerales++;

    // Comisión Front
    $sheet->setCellValue('A' . $filaTotalesGenerales, 'COMISIÓN FRONT TOTAL:');
    $sheet->setCellValue('B' . $filaTotalesGenerales, "=SUM(L6:L" . ($row - 1) . ")"); // Total general
    $sheet->setCellValue('C' . $filaTotalesGenerales, "=SUBTOTAL(9,L6:L" . ($row - 1) . ")"); // Con filtros
    $filaTotalesGenerales++;

    // Suma Comisión
    $sheet->setCellValue('A' . $filaTotalesGenerales, 'SUMA COMISIÓN TOTAL:');
    $sheet->setCellValue('B' . $filaTotalesGenerales, "=SUM(P6:P" . ($row - 1) . ")"); // Total general
    $sheet->setCellValue('C' . $filaTotalesGenerales, "=SUBTOTAL(9,P6:P" . ($row - 1) . ")"); // Con filtros
    $filaTotalesGenerales++;

    // VENTA NETO TOTAL (PARA CÁLCULO OVER) - NUEVA FILA ESPECIAL
    $sheet->setCellValue('A' . $filaTotalesGenerales, 'VENTA NETO TOTAL (PARA OVER):');
    $sheet->setCellValue('B' . $filaTotalesGenerales, "=SUM(C6:C" . ($row - 1) . ")"); // Total general
    $sheet->setCellValue('C' . $filaTotalesGenerales, "=SUBTOTAL(9,C6:C" . ($row - 1) . ")"); // Con filtros
    $sheet->getStyle('A' . $filaTotalesGenerales . ':C' . $filaTotalesGenerales)->getFont()->setBold(true);
    $sheet->getStyle('A' . $filaTotalesGenerales . ':C' . $filaTotalesGenerales)->getFont()->getColor()->setRGB('FFFFFF');
    $sheet->getStyle('A' . $filaTotalesGenerales . ':C' . $filaTotalesGenerales)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('9B59B6');
    $sheet->getStyle('A' . $filaTotalesGenerales . ':C' . $filaTotalesGenerales)->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
    $filaTotalesGenerales++;

    // Total General de Todas las Columnas
    $sheet->setCellValue('A' . $filaTotalesGenerales, 'TOTAL GENERAL (Bruto + Neto + Comisiones):');
    $sheet->setCellValue('B' . $filaTotalesGenerales, "=B" . ($filaTotalesGenerales - 8) . "+B" . ($filaTotalesGenerales - 7) . "+B" . ($filaTotalesGenerales - 6) . "+B" . ($filaTotalesGenerales - 5) . "+B" . ($filaTotalesGenerales - 4) . "+B" . ($filaTotalesGenerales - 3) . "+B" . ($filaTotalesGenerales - 2) . "+B" . ($filaTotalesGenerales - 1));
    $sheet->setCellValue('C' . $filaTotalesGenerales, "=C" . ($filaTotalesGenerales - 8) . "+C" . ($filaTotalesGenerales - 7) . "+C" . ($filaTotalesGenerales - 6) . "+C" . ($filaTotalesGenerales - 5) . "+C" . ($filaTotalesGenerales - 4) . "+C" . ($filaTotalesGenerales - 3) . "+C" . ($filaTotalesGenerales - 2) . "+C" . ($filaTotalesGenerales - 1));

    // Aplicar estilos a la sección de totales generales
    $inicioTotales = $filaTotalesGenerales - 9;
    $finTotales = $filaTotalesGenerales;

    $sheet->getStyle('B' . $inicioTotales . ':C' . $finTotales)->getNumberFormat()->setFormatCode('"$"#,##0.00');
    $sheet->getStyle('A' . $inicioTotales . ':C' . $finTotales)->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);

    // Resaltar la fila de total general
    applySafeStyle($sheet, 'A' . $filaTotalesGenerales . ':C' . $filaTotalesGenerales, 'granTotal');

    // Aplicar estilo de fórmulas a las columnas de "Con Filtros Aplicados"
    applySafeStyle($sheet, 'C' . ($inicioTotales + 1) . ':C' . $finTotales, 'formula');
}

// AGREGAR AUTOFILTER Y APLICAR ESTILOS
if ($row > 6) {
    if ($esFiltroPlataforma && $multiplesPlataformas) {
        // Para múltiples plataformas, no aplicar autofilter global (cada tabla tiene su propia estructura)
        // Pero aplicar formato numérico a todas las columnas de venta bruto
        $sheet->getStyle('B6:B' . ($row - 1))->getNumberFormat()->setFormatCode('"$"#,##0.00');
    } else {
        // Aplicar AutoFilter a toda la tabla (encabezados y datos)
        $sheet->setAutoFilter('A5:' . $ultimaColumna . ($row - 1));

        if (!$esFiltroPlataforma) {
            // Campos editables (amarillo) - solo para reporte completo
            applySafeStyle($sheet, 'B6:B' . ($row - 1), 'editable');   // Venta Bruto
            applySafeStyle($sheet, 'C6:C' . ($row - 1), 'editable');   // Venta Neto
            applySafeStyle($sheet, 'F6:F' . ($row - 1), 'editable');   // Comisión Liner
            applySafeStyle($sheet, 'H6:H' . ($row - 1), 'editable');   // Comisión Closer 1
            applySafeStyle($sheet, 'J6:J' . ($row - 1), 'editable');   // Comisión Closer 2
            applySafeStyle($sheet, 'L6:L' . ($row - 1), 'editable');   // Comisión Front

            // Campos con fórmulas (verde) - según el nuevo orden
            applySafeStyle($sheet, 'P6:P' . ($row - 1), 'formula');    // Suma Comisión
        }

        // Formato de números
        if ($esFiltroPlataforma) {
            $sheet->getStyle('B6:B' . ($row - 1))->getNumberFormat()->setFormatCode('"$"#,##0.00');
        } else {
            $numericColumns = ['B', 'C', 'F', 'H', 'J', 'L', 'P'];
            foreach ($numericColumns as $column) {
                $sheet->getStyle($column . '6:' . $column . ($row - 1))
                    ->getNumberFormat()
                    ->setFormatCode('"$"#,##0.00');
            }
        }
    }
} else {
    $sheet->setCellValue('A6', 'No hay ventas para el período seleccionado');
    $sheet->mergeCells('A6:' . $ultimaColumna . '6');
    $sheet->getStyle('A6:' . $ultimaColumna . '6')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
}

// Autoajustar columnas
foreach (range('A', $ultimaColumna) as $columnID) {
    $sheet->getColumnDimension($columnID)->setAutoSize(true);
}
$sheet->freezePane('A6');

// ============================================================================
// CREAR HOJA ESPECÍFICA PARA PLATAFORMA CUANDO SE FILTRA POR PLATAFORMA
// ============================================================================

if ($esFiltroPlataforma && !empty($plataformasInfo) && !empty($ventas)) {
    try {
        $plataformaSheet = new \PhpOffice\PhpSpreadsheet\Worksheet\Worksheet($spreadsheet, 'Detalle Plataformas');
        $spreadsheet->addSheet($plataformaSheet);
        $spreadsheet->setActiveSheetIndex($spreadsheet->getIndex($plataformaSheet));
        $plataformaSheet = $spreadsheet->getActiveSheet();

        $plataformaSheet->getPageSetup()
            ->setOrientation(PageSetup::ORIENTATION_LANDSCAPE)
            ->setPaperSize(PageSetup::PAPERSIZE_A4)
            ->setFitToWidth(1)
            ->setFitToHeight(0);

        // Título específico para las plataformas
        $plataformaSheet->mergeCells('A1:P2');
        $tituloPlataforma = 'DETALLE COMPLETO DE VENTAS - PLATAFORMAS: ' . strtoupper(implode(', ', $plataformasNombres));

        // AGREGAR NOMBRE DE LA SALA SI SE FILTRÓ POR SALA
        if (!empty($salaNombre)) {
            $tituloPlataforma .= ' - SALA: ' . strtoupper($salaNombre);
        }

        $plataformaSheet->setCellValue('A1', $tituloPlataforma);
        applySafeStyle($plataformaSheet, 'A1:P2', 'title');

        $plataformaSheet->mergeCells('A3:P3');
        $plataformaSheet->setCellValue('A3', 'Filtro aplicado: ' . $filtroTexto . ' - Fecha de generación: ' . date('d/m/Y H:i:s'));
        $plataformaSheet->getStyle('A3:P3')->getFont()->setItalic(true)->setSize(10);
        $plataformaSheet->getStyle('A3:P3')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

        $plataformaSheet->mergeCells('A4:P4');
        $plataformaSheet->setCellValue('A4', 'INFORMACIÓN DETALLADA DE TODAS LAS VENTAS DE LAS PLATAFORMAS SELECCIONADAS - LOS DATOS SON DE SOLO LECTURA');
        $plataformaSheet->getStyle('A4:P4')->getFont()->setBold(true)->setSize(10);
        $plataformaSheet->getStyle('A4:P4')->getFont()->getColor()->setRGB('E74C3C');
        $plataformaSheet->getStyle('A4:P4')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('FDEBD0');
        $plataformaSheet->getStyle('A4:P4')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

        // Headers completos para el detalle de plataforma (con Sala)
        $plataformaHeaders = [
            'Fecha',
            'Venta Bruto',
            'Venta Neto',
            'Cliente',
            'Liner',
            'Comisión Liner',
            'Closer 1',
            'Comisión Closer 1',
            'Closer 2',
            'Comisión Closer 2',
            'Front',
            'Comision Front',
            'Plataforma',
            'Tipo Venta',
            'Sala',
            'Suma Comisión'
        ];
        $plataformaSheet->fromArray($plataformaHeaders, NULL, 'A5');
        applySafeStyle($plataformaSheet, 'A5:P5', 'header');

        // Llenar datos completos de todas las ventas de las plataformas
        $plataformaRow = 6;
        foreach ($ventas as $venta) {
            $fechaFormateada = date('d/m/Y', strtotime($venta['fecha']));

            $plataformaSheet->setCellValue('A' . $plataformaRow, $fechaFormateada);
            $plataformaSheet->setCellValue('B' . $plataformaRow, (float)$venta['precio']);
            $plataformaSheet->setCellValue('C' . $plataformaRow, (float)$venta['precio_neto']);
            $plataformaSheet->setCellValue('D' . $plataformaRow, $venta['cliente_nombre']);
            $plataformaSheet->setCellValue('E' . $plataformaRow, $venta['liner_nombre'] ?? 'No asignado');
            $plataformaSheet->setCellValue('F' . $plataformaRow, $tieneComisiones ? (float)($venta['ganancia_liner'] ?? 0) : 0);
            $plataformaSheet->setCellValue('G' . $plataformaRow, $venta['closer1_nombre'] ?? 'No asignado');
            $plataformaSheet->setCellValue('H' . $plataformaRow, $tieneComisiones ? (float)($venta['ganancia_closer1'] ?? 0) : 0);
            $plataformaSheet->setCellValue('I' . $plataformaRow, $venta['closer2_nombre'] ?? 'No asignado');
            $plataformaSheet->setCellValue('J' . $plataformaRow, $tieneComisiones ? (float)($venta['ganancia_closer2'] ?? 0) : 0);
            $plataformaSheet->setCellValue('K' . $plataformaRow, $venta['front_nombre'] ?? 'No asignado');
            $plataformaSheet->setCellValue('L' . $plataformaRow, $tieneComisiones ? (float)($venta['ganancia_fullstack'] ?? 0) : 0);
            $plataformaSheet->setCellValue('M' . $plataformaRow, $venta['plataforma_nombre_completo'] ?? 'N/A');
            $plataformaSheet->setCellValue('N' . $plataformaRow, $venta['tipo_venta'] ?? 'N/A');
            $plataformaSheet->setCellValue('O' . $plataformaRow, $venta['sala_nombre'] ?? 'N/A'); // Sala
            $plataformaSheet->setCellValue('P' . $plataformaRow, "=F{$plataformaRow}+H{$plataformaRow}+J{$plataformaRow}+L{$plataformaRow}");

            $plataformaRow++;
        }

        // Aplicar AutoFilter y estilos
        if ($plataformaRow > 6) {
            $plataformaSheet->setAutoFilter('A5:P' . ($plataformaRow - 1));
            applySafeStyle($plataformaSheet, 'P6:P' . ($plataformaRow - 1), 'formula');

            // Formato numérico
            $numericCols = ['B', 'C', 'F', 'H', 'J', 'L', 'P'];
            foreach ($numericCols as $col) {
                $plataformaSheet->getStyle($col . '6:' . $col . ($plataformaRow - 1))
                    ->getNumberFormat()
                    ->setFormatCode('"$"#,##0.00');
            }

            // Totales
            $plataformaSheet->setCellValue('A' . $plataformaRow, 'TOTALES:');
            $plataformaSheet->setCellValue('B' . $plataformaRow, "=SUBTOTAL(9,B6:B" . ($plataformaRow - 1) . ")");
            $plataformaSheet->setCellValue('C' . $plataformaRow, "=SUBTOTAL(9,C6:C" . ($plataformaRow - 1) . ")");
            $plataformaSheet->setCellValue('F' . $plataformaRow, "=SUBTOTAL(9,F6:F" . ($plataformaRow - 1) . ")");
            $plataformaSheet->setCellValue('H' . $plataformaRow, "=SUBTOTAL(9,H6:H" . ($plataformaRow - 1) . ")");
            $plataformaSheet->setCellValue('J' . $plataformaRow, "=SUBTOTAL(9,J6:J" . ($plataformaRow - 1) . ")");
            $plataformaSheet->setCellValue('L' . $plataformaRow, "=SUBTOTAL(9,L6:L" . ($plataformaRow - 1) . ")");
            $plataformaSheet->setCellValue('P' . $plataformaRow, "=SUBTOTAL(9,P6:P" . ($plataformaRow - 1) . ")");

            applySafeStyle($plataformaSheet, 'A' . $plataformaRow . ':P' . $plataformaRow, 'total');
            foreach ($numericCols as $col) {
                $plataformaSheet->getStyle($col . $plataformaRow)->getNumberFormat()->setFormatCode('"$"#,##0.00');
            }
        }

        // Autoajustar columnas
        foreach (range('A', 'P') as $col) {
            $plataformaSheet->getColumnDimension($col)->setAutoSize(true);
        }
        $plataformaSheet->freezePane('A6');
    } catch (Exception $e) {
        error_log("Error al crear hoja de detalle de plataformas: " . $e->getMessage());
    }
}

// ============================================================================
// PROCESAR USUARIOS CON NOMBRES MANUALES PARA RESUMEN COMISIONES
// CON AGREGACIÓN POR NOMBRE (SUMAR LOS MISMOS NOMBRES EN UNO SOLO)
// ============================================================================

$usuariosComisiones = [];

if ($tieneComisiones && !empty($ventas)) {
    foreach ($ventas as $venta) {
        // Determinar el nombre a usar para cada rol (PRIMERO nombre manual, luego nombre del sistema)

        // LINER
        $nombreLiner = !empty($venta['liner_nombre_manual']) && trim($venta['liner_nombre_manual']) !== ''
            ? trim($venta['liner_nombre_manual'])
            : (!empty($venta['liner_nombre']) && $venta['liner_nombre'] !== 'No asignado'
                ? trim($venta['liner_nombre'])
                : null);

        if ($nombreLiner && (float)($venta['ganancia_liner'] ?? 0) > 0) {
            // Usar el nombre NORMALIZADO como clave para agrupar por nombre
            $claveUsuario = normalizarNombre($nombreLiner);

            if (!isset($usuariosComisiones[$claveUsuario])) {
                $usuariosComisiones[$claveUsuario] = [
                    'nombre' => $nombreLiner, // Nombre original (con mayúsculas y formato)
                    'nombre_normalizado' => $claveUsuario, // Para comparación
                    'nombre_manual' => !empty($venta['liner_nombre_manual']) ? trim($venta['liner_nombre_manual']) : '',
                    'tipo_nombre' => !empty($venta['liner_nombre_manual']) ? 'Manual' : 'Sistema',
                    'nombre_limpio' => substr(preg_replace('/[^\w\sáéíóúÁÉÍÓÚñÑ]/u', '', $nombreLiner), 0, 25),
                    'comision_total' => 0,
                    'over' => 0,
                    'descuento' => 0,
                    'sueldo' => 0,
                    'spiff' => 0,
                    'tiene_over' => false,
                    'es_over' => false,
                    'sala_id' => $venta['sala_id'] ?? null,
                    'over_config' => null,
                    'over_nombre_exacto' => null,
                    'roles' => [] // Para trackear roles
                ];
            }
            // SUMAR la comisión al usuario existente
            $usuariosComisiones[$claveUsuario]['comision_total'] += (float)($venta['ganancia_liner'] ?? 0);

            // Agregar rol si no existe
            if (!in_array('Liner', $usuariosComisiones[$claveUsuario]['roles'])) {
                $usuariosComisiones[$claveUsuario]['roles'][] = 'Liner';
            }
        }

        // CLOSER 1
        $nombreCloser1 = !empty($venta['closer1_nombre_manual']) && trim($venta['closer1_nombre_manual']) !== ''
            ? trim($venta['closer1_nombre_manual'])
            : (!empty($venta['closer1_nombre']) && $venta['closer1_nombre'] !== 'No asignado'
                ? trim($venta['closer1_nombre'])
                : null);

        if ($nombreCloser1 && (float)($venta['ganancia_closer1'] ?? 0) > 0) {
            $claveUsuario = normalizarNombre($nombreCloser1);

            if (!isset($usuariosComisiones[$claveUsuario])) {
                $usuariosComisiones[$claveUsuario] = [
                    'nombre' => $nombreCloser1,
                    'nombre_normalizado' => $claveUsuario,
                    'nombre_manual' => !empty($venta['closer1_nombre_manual']) ? trim($venta['closer1_nombre_manual']) : '',
                    'tipo_nombre' => !empty($venta['closer1_nombre_manual']) ? 'Manual' : 'Sistema',
                    'nombre_limpio' => substr(preg_replace('/[^\w\sáéíóúÁÉÍÓÚñÑ]/u', '', $nombreCloser1), 0, 25),
                    'comision_total' => 0,
                    'over' => 0,
                    'descuento' => 0,
                    'sueldo' => 0,
                    'spiff' => 0,
                    'tiene_over' => false,
                    'es_over' => false,
                    'sala_id' => $venta['sala_id'] ?? null,
                    'over_config' => null,
                    'over_nombre_exacto' => null,
                    'roles' => []
                ];
            }
            // SUMAR la comisión
            $usuariosComisiones[$claveUsuario]['comision_total'] += (float)($venta['ganancia_closer1'] ?? 0);

            if (!in_array('Closer 1', $usuariosComisiones[$claveUsuario]['roles'])) {
                $usuariosComisiones[$claveUsuario]['roles'][] = 'Closer 1';
            }
        }

        // CLOSER 2
        $nombreCloser2 = !empty($venta['closer2_nombre_manual']) && trim($venta['closer2_nombre_manual']) !== ''
            ? trim($venta['closer2_nombre_manual'])
            : (!empty($venta['closer2_nombre']) && $venta['closer2_nombre'] !== 'No asignado'
                ? trim($venta['closer2_nombre'])
                : null);

        if ($nombreCloser2 && (float)($venta['ganancia_closer2'] ?? 0) > 0) {
            $claveUsuario = normalizarNombre($nombreCloser2);

            if (!isset($usuariosComisiones[$claveUsuario])) {
                $usuariosComisiones[$claveUsuario] = [
                    'nombre' => $nombreCloser2,
                    'nombre_normalizado' => $claveUsuario,
                    'nombre_manual' => !empty($venta['closer2_nombre_manual']) ? trim($venta['closer2_nombre_manual']) : '',
                    'tipo_nombre' => !empty($venta['closer2_nombre_manual']) ? 'Manual' : 'Sistema',
                    'nombre_limpio' => substr(preg_replace('/[^\w\sáéíóúÁÉÍÓÚñÑ]/u', '', $nombreCloser2), 0, 25),
                    'comision_total' => 0,
                    'over' => 0,
                    'descuento' => 0,
                    'sueldo' => 0,
                    'spiff' => 0,
                    'tiene_over' => false,
                    'es_over' => false,
                    'sala_id' => $venta['sala_id'] ?? null,
                    'over_config' => null,
                    'over_nombre_exacto' => null,
                    'roles' => []
                ];
            }
            // SUMAR la comisión
            $usuariosComisiones[$claveUsuario]['comision_total'] += (float)($venta['ganancia_closer2'] ?? 0);

            if (!in_array('Closer 2', $usuariosComisiones[$claveUsuario]['roles'])) {
                $usuariosComisiones[$claveUsuario]['roles'][] = 'Closer 2';
            }
        }

        // FRONT
        $nombreFront = !empty($venta['full_stack_nombre_manual']) && trim($venta['full_stack_nombre_manual']) !== ''
            ? trim($venta['full_stack_nombre_manual'])
            : (!empty($venta['front_nombre']) && $venta['front_nombre'] !== 'No asignado'
                ? trim($venta['front_nombre'])
                : null);

        if ($nombreFront && (float)($venta['ganancia_fullstack'] ?? 0) > 0) {
            $claveUsuario = normalizarNombre($nombreFront);

            if (!isset($usuariosComisiones[$claveUsuario])) {
                $usuariosComisiones[$claveUsuario] = [
                    'nombre' => $nombreFront,
                    'nombre_normalizado' => $claveUsuario,
                    'nombre_manual' => !empty($venta['full_stack_nombre_manual']) ? trim($venta['full_stack_nombre_manual']) : '',
                    'tipo_nombre' => !empty($venta['full_stack_nombre_manual']) ? 'Manual' : 'Sistema',
                    'nombre_limpio' => substr(preg_replace('/[^\w\sáéíóúÁÉÍÓÚñÑ]/u', '', $nombreFront), 0, 25),
                    'comision_total' => 0,
                    'over' => 0,
                    'descuento' => 0,
                    'sueldo' => 0,
                    'spiff' => 0,
                    'tiene_over' => false,
                    'es_over' => false,
                    'sala_id' => $venta['sala_id'] ?? null,
                    'over_config' => null,
                    'over_nombre_exacto' => null,
                    'roles' => []
                ];
            }
            // SUMAR la comisión
            $usuariosComisiones[$claveUsuario]['comision_total'] += (float)($venta['ganancia_fullstack'] ?? 0);

            if (!in_array('Front', $usuariosComisiones[$claveUsuario]['roles'])) {
                $usuariosComisiones[$claveUsuario]['roles'][] = 'Front';
            }
        }
    }

    // ============================================================================
    // IDENTIFICAR USUARIOS OVER Y NO-OVER SEGÚN LA CONFIGURACIÓN POR SALA
    // ============================================================================

    foreach ($usuariosComisiones as $clave => $usuario) {
        $nombreUsuario = trim($usuario['nombre']);
        $salaUsuario = $usuario['sala_id'];

        // Buscar coincidencia PARCIAL en la configuración de OVER POR SALA
        foreach ($configOver as $key => $config) {
            if (stripos($nombreUsuario, $config['nombre']) !== false) {
                // Verificar si el usuario pertenece a una sala válida para este OVER
                if (in_array($salaUsuario, $config['salas'])) {
                    $usuariosComisiones[$clave]['tiene_over'] = true;
                    $usuariosComisiones[$clave]['es_over'] = true;
                    $usuariosComisiones[$clave]['over_config'] = $config;
                    $usuariosComisiones[$clave]['over_nombre_exacto'] = $config['nombre'];
                    break;
                }
            }
        }
    }

    // Contar usuarios no-over
    $usuariosNoOver = array_filter($usuariosComisiones, function ($usuario) {
        return !$usuario['es_over'];
    });
    $totalUsuariosNoOver = count($usuariosNoOver);

    // Ordenar por comisión total descendente
    uasort($usuariosComisiones, function ($a, $b) {
        return $b['comision_total'] <=> $a['comision_total'];
    });
}

// ============================================================================
// NUEVA HOJA: RESUMEN GENERAL PLATAFORMA - ESTRUCTURA SIMPLIFICADA
// ============================================================================

try {
    $resumenPlataformaSheet = new \PhpOffice\PhpSpreadsheet\Worksheet\Worksheet($spreadsheet, 'Resumen General Plataforma');

    // Insertar la nueva hoja en la posición 1 (justo después de Resumen General que está en posición 0)
    $spreadsheet->addSheet($resumenPlataformaSheet, 1);

    // Activar la nueva hoja
    $spreadsheet->setActiveSheetIndex(1);
    $resumenPlataformaSheet = $spreadsheet->getActiveSheet();

    $resumenPlataformaSheet->getPageSetup()
        ->setOrientation(PageSetup::ORIENTATION_LANDSCAPE)
        ->setPaperSize(PageSetup::PAPERSIZE_A4)
        ->setFitToWidth(1)
        ->setFitToHeight(0);

    // Título del reporte
    $resumenPlataformaSheet->mergeCells('A1:E2');
    $tituloResumenPlataforma = 'RESUMEN GENERAL PLATAFORMA';

    // AGREGAR NOMBRE DE LA SALA SI SE FILTRÓ POR SALA
    if (!empty($salaNombre)) {
        $tituloResumenPlataforma .= ' - SALA: ' . strtoupper($salaNombre);
    }

    if (!empty($plataformasNombres)) {
        if ($multiplesPlataformas) {
            $tituloResumenPlataforma .= ' - PLATAFORMAS: ' . implode(', ', $plataformasNombres);
        } else {
            $tituloResumenPlataforma .= ' - PLATAFORMA: ' . implode(', ', $plataformasNombres);
        }
    }

    $resumenPlataformaSheet->setCellValue('A1', $tituloResumenPlataforma);
    applySafeStyle($resumenPlataformaSheet, 'A1:E2', 'title');

    // Información de filtros aplicados
    $resumenPlataformaSheet->mergeCells('A3:E3');
    $resumenPlataformaSheet->setCellValue('A3', 'Filtro aplicado: ' . $filtroTexto . ' - Fecha de generación: ' . date('d/m/Y H:i:s'));
    $resumenPlataformaSheet->getStyle('A3:E3')->getFont()->setItalic(true)->setSize(10);
    $resumenPlataformaSheet->getStyle('A3:E3')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

    // Nota sobre la hoja
    $resumenPlataformaSheet->mergeCells('A4:E4');
    $resumenPlataformaSheet->setCellValue('A4', 'RESUMEN SIMPLIFICADO: Muestra TODOS los registros individuales');
    $resumenPlataformaSheet->getStyle('A4:E4')->getFont()->setBold(true)->setSize(10);
    $resumenPlataformaSheet->getStyle('A4:E4')->getFont()->getColor()->setRGB('E74C3C');
    $resumenPlataformaSheet->getStyle('A4:E4')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('FDEBD0');
    $resumenPlataformaSheet->getStyle('A4:E4')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

    // Headers simplificados (con Sala)
    $headersResumenPlataforma = ['Fecha', 'Venta Bruta', 'Plataforma', 'Tipo Venta', 'Sala'];
    $resumenPlataformaSheet->fromArray($headersResumenPlataforma, NULL, 'A5');
    applySafeStyle($resumenPlataformaSheet, 'A5:E5', 'header');

    // Procesar datos - MOSTRAR TODOS LOS REGISTROS INDIVIDUALES
    $resumenPlataformaRow = 6;

    // Ordenar ventas por fecha descendente
    usort($ventas, function ($a, $b) {
        return strtotime($b['fecha']) - strtotime($a['fecha']);
    });

    // MOSTRAR TODAS LAS VENTAS INDIVIDUALMENTE - SIN AGRUPAR
    foreach ($ventas as $venta) {
        $fechaFormateada = date('d/m/Y', strtotime($venta['fecha']));
        $ventaBruta = (float)$venta['precio'];
        $plataformaNombre = $venta['plataforma_nombre_completo'] ?? 'N/A';
        $tipoVenta = $venta['tipo_venta'] ?? 'N/A';
        $salaNombreVenta = $venta['sala_nombre'] ?? 'N/A';

        $resumenPlataformaSheet->setCellValue('A' . $resumenPlataformaRow, $fechaFormateada);     // Fecha
        $resumenPlataformaSheet->setCellValue('B' . $resumenPlataformaRow, $ventaBruta);          // Venta Bruta
        $resumenPlataformaSheet->setCellValue('C' . $resumenPlataformaRow, $plataformaNombre);    // Plataforma
        $resumenPlataformaSheet->setCellValue('D' . $resumenPlataformaRow, $tipoVenta);           // Tipo Venta
        $resumenPlataformaSheet->setCellValue('E' . $resumenPlataformaRow, $salaNombreVenta);     // Sala

        $resumenPlataformaRow++;
    }

    // Fila de totales
    if ($resumenPlataformaRow > 6) {
        $resumenPlataformaSheet->mergeCells('A' . $resumenPlataformaRow . ':A' . $resumenPlataformaRow);
        $resumenPlataformaSheet->setCellValue('A' . $resumenPlataformaRow, 'TOTALES (automáticos):');

        // Total de ventas brutas
        $resumenPlataformaSheet->setCellValue('B' . $resumenPlataformaRow, "=SUBTOTAL(9,B6:B" . ($resumenPlataformaRow - 1) . ")");

        applySafeStyle($resumenPlataformaSheet, 'A' . $resumenPlataformaRow . ':E' . $resumenPlataformaRow, 'total');
        $resumenPlataformaSheet->getStyle('B' . $resumenPlataformaRow)->getNumberFormat()->setFormatCode('"$"#,##0.00');
    }

    // Aplicar AutoFilter y estilos
    if ($resumenPlataformaRow > 6) {
        $resumenPlataformaSheet->setAutoFilter('A5:E' . ($resumenPlataformaRow - 1));

        // Formato numérico para columna de venta bruta
        $resumenPlataformaSheet->getStyle('B6:B' . ($resumenPlataformaRow - 1))->getNumberFormat()->setFormatCode('"$"#,##0.00');

        // Aplicar bordes a todos los datos
        $resumenPlataformaSheet->getStyle('A5:E' . ($resumenPlataformaRow - 1))->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
    } else {
        $resumenPlataformaSheet->setCellValue('A6', 'No hay ventas para el período seleccionado');
        $resumenPlataformaSheet->mergeCells('A6:E6');
        $resumenPlataformaSheet->getStyle('A6:E6')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    }

    // Autoajustar columnas
    foreach (range('A', 'E') as $columnID) {
        $resumenPlataformaSheet->getColumnDimension($columnID)->setAutoSize(true);
    }
    $resumenPlataformaSheet->freezePane('A6');
} catch (Exception $e) {
    error_log("Error al crear hoja Resumen General Plataforma: " . $e->getMessage());
}

// ============================================================================
// PROCESAR USUARIOS CON GANANCIAS PARA HOJAS INDIVIDUALES - CON AGREGACIÓN POR NOMBRE
// ============================================================================

$usuariosConGanancias = [];
$rangosUsuarios = []; // Para almacenar rangos de filas por usuario

if ($tieneComisiones && !empty($ventas)) {
    foreach ($ventas as $venta) {
        // Procesar Liner - USAR NOMBRE MANUAL SI ESTÁ DISPONIBLE
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
                    'titulo_limpio' => substr(preg_replace('/[^\w\sáéíóúÁÉÍÓÚñÑ]/u', '', $nombreLiner), 0, 25),
                    'nombre_manual' => !empty($venta['liner_nombre_manual']) ? trim($venta['liner_nombre_manual']) : '',
                    'tipo_nombre' => !empty($venta['liner_nombre_manual']) ? 'manual' : 'sistema',
                    'roles' => [],
                    'ventas_count' => 0,
                    'ganancia_total' => 0,
                    'ganancia_por_rol' => ['Liner' => 0, 'Closer 1' => 0, 'Closer 2' => 0, 'Front' => 0],
                    'ventas' => [],
                    'tiene_over' => false,
                    'over_config' => null,
                    'over_nombre_exacto' => null,
                    'sala_id' => $venta['sala_id'] ?? null
                ];
            }
            $gananciaLiner = (float)($venta['ganancia_liner'] ?? 0);
            if ($gananciaLiner > 0) {
                $usuariosConGanancias[$claveUsuario]['ventas_count']++;
                $usuariosConGanancias[$claveUsuario]['ganancia_total'] += $gananciaLiner;
                $usuariosConGanancias[$claveUsuario]['ganancia_por_rol']['Liner'] += $gananciaLiner;
                if (!in_array('Liner', $usuariosConGanancias[$claveUsuario]['roles'])) {
                    $usuariosConGanancias[$claveUsuario]['roles'][] = 'Liner';
                }
                $usuariosConGanancias[$claveUsuario]['ventas'][] = [
                    'id' => $venta['id'],
                    'fecha' => $venta['fecha'],
                    'cliente_nombre' => $venta['cliente_nombre'],
                    'rol_en_venta' => 'Liner',
                    'ganancia_en_venta' => $gananciaLiner,
                    'plataforma_nombre_completo' => $venta['plataforma_nombre_completo'] ?? 'N/A',
                    'precio_neto' => $venta['precio_neto'],
                    'venta_bruto' => $venta['precio'],
                    'tipo_venta' => $venta['tipo_venta'],
                    'sala_id' => $venta['sala_id'] ?? null,
                    'sala_nombre' => $venta['sala_nombre'] ?? 'N/A'
                ];
            }
        }

        // Procesar Closer 1 - USAR NOMBRE MANUAL SI ESTÁ DISPONIBLE
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
                    'titulo_limpio' => substr(preg_replace('/[^\w\sáéíóúÁÉÍÓÚñÑ]/u', '', $nombreCloser1), 0, 25),
                    'nombre_manual' => !empty($venta['closer1_nombre_manual']) ? trim($venta['closer1_nombre_manual']) : '',
                    'tipo_nombre' => !empty($venta['closer1_nombre_manual']) ? 'manual' : 'sistema',
                    'roles' => [],
                    'ventas_count' => 0,
                    'ganancia_total' => 0,
                    'ganancia_por_rol' => ['Liner' => 0, 'Closer 1' => 0, 'Closer 2' => 0, 'Front' => 0],
                    'ventas' => [],
                    'tiene_over' => false,
                    'over_config' => null,
                    'over_nombre_exacto' => null,
                    'sala_id' => $venta['sala_id'] ?? null
                ];
            }
            $gananciaCloser1 = (float)($venta['ganancia_closer1'] ?? 0);
            if ($gananciaCloser1 > 0) {
                $usuariosConGanancias[$claveUsuario]['ventas_count']++;
                $usuariosConGanancias[$claveUsuario]['ganancia_total'] += $gananciaCloser1;
                $usuariosConGanancias[$claveUsuario]['ganancia_por_rol']['Closer 1'] += $gananciaCloser1;
                if (!in_array('Closer 1', $usuariosConGanancias[$claveUsuario]['roles'])) {
                    $usuariosConGanancias[$claveUsuario]['roles'][] = 'Closer 1';
                }
                $usuariosConGanancias[$claveUsuario]['ventas'][] = [
                    'id' => $venta['id'],
                    'fecha' => $venta['fecha'],
                    'cliente_nombre' => $venta['cliente_nombre'],
                    'rol_en_venta' => 'Closer 1',
                    'ganancia_en_venta' => $gananciaCloser1,
                    'plataforma_nombre_completo' => $venta['plataforma_nombre_completo'] ?? 'N/A',
                    'precio_neto' => $venta['precio_neto'],
                    'venta_bruto' => $venta['precio'],
                    'tipo_venta' => $venta['tipo_venta'],
                    'sala_id' => $venta['sala_id'] ?? null,
                    'sala_nombre' => $venta['sala_nombre'] ?? 'N/A'
                ];
            }
        }

        // Procesar Closer 2 - USAR NOMBRE MANUAL SI ESTÁ DISPONIBLE
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
                    'titulo_limpio' => substr(preg_replace('/[^\w\sáéíóúÁÉÍÓÚñÑ]/u', '', $nombreCloser2), 0, 25),
                    'nombre_manual' => !empty($venta['closer2_nombre_manual']) ? trim($venta['closer2_nombre_manual']) : '',
                    'tipo_nombre' => !empty($venta['closer2_nombre_manual']) ? 'manual' : 'sistema',
                    'roles' => [],
                    'ventas_count' => 0,
                    'ganancia_total' => 0,
                    'ganancia_por_rol' => ['Liner' => 0, 'Closer 1' => 0, 'Closer 2' => 0, 'Front' => 0],
                    'ventas' => [],
                    'tiene_over' => false,
                    'over_config' => null,
                    'over_nombre_exacto' => null,
                    'sala_id' => $venta['sala_id'] ?? null
                ];
            }
            $gananciaCloser2 = (float)($venta['ganancia_closer2'] ?? 0);
            if ($gananciaCloser2 > 0) {
                $usuariosConGanancias[$claveUsuario]['ventas_count']++;
                $usuariosConGanancias[$claveUsuario]['ganancia_total'] += $gananciaCloser2;
                $usuariosConGanancias[$claveUsuario]['ganancia_por_rol']['Closer 2'] += $gananciaCloser2;
                if (!in_array('Closer 2', $usuariosConGanancias[$claveUsuario]['roles'])) {
                    $usuariosConGanancias[$claveUsuario]['roles'][] = 'Closer 2';
                }
                $usuariosConGanancias[$claveUsuario]['ventas'][] = [
                    'id' => $venta['id'],
                    'fecha' => $venta['fecha'],
                    'cliente_nombre' => $venta['cliente_nombre'],
                    'rol_en_venta' => 'Closer 2',
                    'ganancia_en_venta' => $gananciaCloser2,
                    'plataforma_nombre_completo' => $venta['plataforma_nombre_completo'] ?? 'N/A',
                    'precio_neto' => $venta['precio_neto'],
                    'venta_bruto' => $venta['precio'],
                    'tipo_venta' => $venta['tipo_venta'],
                    'sala_id' => $venta['sala_id'] ?? null,
                    'sala_nombre' => $venta['sala_nombre'] ?? 'N/A'
                ];
            }
        }

        // Procesar Front - USAR NOMBRE MANUAL SI ESTÁ DISPONIBLE
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
                    'titulo_limpio' => substr(preg_replace('/[^\w\sáéíóúÁÉÍÓÚñÑ]/u', '', $nombreFront), 0, 25),
                    'nombre_manual' => !empty($venta['full_stack_nombre_manual']) ? trim($venta['full_stack_nombre_manual']) : '',
                    'tipo_nombre' => !empty($venta['full_stack_nombre_manual']) ? 'manual' : 'sistema',
                    'roles' => [],
                    'ventas_count' => 0,
                    'ganancia_total' => 0,
                    'ganancia_por_rol' => ['Liner' => 0, 'Closer 1' => 0, 'Closer 2' => 0, 'Front' => 0],
                    'ventas' => [],
                    'tiene_over' => false,
                    'over_config' => null,
                    'over_nombre_exacto' => null,
                    'sala_id' => $venta['sala_id'] ?? null
                ];
            }
            $gananciaFront = (float)($venta['ganancia_fullstack'] ?? 0);
            if ($gananciaFront > 0) {
                $usuariosConGanancias[$claveUsuario]['ventas_count']++;
                $usuariosConGanancias[$claveUsuario]['ganancia_total'] += $gananciaFront;
                $usuariosConGanancias[$claveUsuario]['ganancia_por_rol']['Front'] += $gananciaFront;
                if (!in_array('Front', $usuariosConGanancias[$claveUsuario]['roles'])) {
                    $usuariosConGanancias[$claveUsuario]['roles'][] = 'Front';
                }
                $usuariosConGanancias[$claveUsuario]['ventas'][] = [
                    'id' => $venta['id'],
                    'fecha' => $venta['fecha'],
                    'cliente_nombre' => $venta['cliente_nombre'],
                    'rol_en_venta' => 'Front',
                    'ganancia_en_venta' => $gananciaFront,
                    'plataforma_nombre_completo' => $venta['plataforma_nombre_completo'] ?? 'N/A',
                    'precio_neto' => $venta['precio_neto'],
                    'venta_bruto' => $venta['precio'],
                    'tipo_venta' => $venta['tipo_venta'],
                    'sala_id' => $venta['sala_id'] ?? null,
                    'sala_nombre' => $venta['sala_nombre'] ?? 'N/A'
                ];
            }
        }
    }
}

// VERIFICAR QUÉ USUARIOS TIENEN OVER SEGÚN LA CONFIGURACIÓN POR SALA
foreach ($usuariosConGanancias as $clave => $usuario) {
    $nombreUsuario = trim($usuario['nombre']);
    $salaUsuario = $usuario['sala_id'];

    // Buscar coincidencia PARCIAL en la configuración de OVER
    foreach ($configOver as $key => $config) {
        // Convertir a mayúsculas y buscar si el nombre de configuración está contenido en el nombre del usuario
        if (stripos($nombreUsuario, $config['nombre']) !== false) {
            // Verificar si el usuario pertenece a una sala válida para este OVER
            if (in_array($salaUsuario, $config['salas'])) {
                $usuariosConGanancias[$clave]['tiene_over'] = true;
                $usuariosConGanancias[$clave]['over_config'] = $config;
                $usuariosConGanancias[$clave]['over_nombre_exacto'] = $config['nombre'];
                break;
            } else {
                // Si no pertenece a la sala adecuada, no tiene OVER
                $usuariosConGanancias[$clave]['tiene_over'] = false;
                $usuariosConGanancias[$clave]['over_config'] = null;
                $usuariosConGanancias[$clave]['over_nombre_exacto'] = null;
            }
        }
    }
}

// ============================================================================
// HOJAS POR USUARIO - CON COLUMNA DE GANANCIA EDITABLE
// ============================================================================

// Crear hojas por usuario usando los datos de $usuariosConGanancias
if (!empty($usuariosConGanancias)) {
    uasort($usuariosConGanancias, function ($a, $b) {
        return $b['ganancia_total'] <=> $a['ganancia_total'];
    });

    foreach ($usuariosConGanancias as $clave => $usuario) {
        try {
            $tituloHoja = $usuario['titulo_limpio'];
            $tituloHoja = str_replace(['*', ':', '/', '\\', '?', '[', ']'], '', $tituloHoja);
            if (empty(trim($tituloHoja))) $tituloHoja = 'Usuario_' . $usuario['ventas_count'];
            $tituloHoja = substr($tituloHoja, 0, 31);

            $userSheet = new \PhpOffice\PhpSpreadsheet\Worksheet\Worksheet($spreadsheet, $tituloHoja);
            $spreadsheet->addSheet($userSheet);
            $spreadsheet->setActiveSheetIndex($spreadsheet->getIndex($userSheet));
            $userSheet = $spreadsheet->getActiveSheet();

            $userSheet->getPageSetup()
                ->setOrientation(PageSetup::ORIENTATION_LANDSCAPE)
                ->setPaperSize(PageSetup::PAPERSIZE_A4)
                ->setFitToWidth(1)
                ->setFitToHeight(0);

            // ============================================================================
            // ENCABEZADOS MEJORADOS
            // ============================================================================

            $userSheet->mergeCells('A1:J2');
            $tituloUsuario = 'DETALLE DE GANANCIAS POR ROL - ' . strtoupper($usuario['nombre']) . ' (Sala ' . ($usuario['sala_id'] ?? 'N/A') . ')';

            // AGREGAR NOMBRE DE LA SALA SI SE FILTRÓ POR SALA
            if (!empty($salaNombre) && isset($usuario['sala_id']) && $usuario['sala_id'] == $salaId) {
                $tituloUsuario .= ' - SALA: ' . strtoupper($salaNombre);
            }

            $userSheet->setCellValue('A1', $tituloUsuario);
            applySafeStyle($userSheet, 'A1:J2', 'title');

            $userSheet->mergeCells('A3:J3');
            $userSheet->setCellValue('A3', 'BASE: VENTA BRUTA | Filtro: ' . $filtroTexto . ' | Generado: ' . date('d/m/Y H:i:s'));
            $userSheet->getStyle('A3:J3')->getFont()->setItalic(true)->setSize(10);
            $userSheet->getStyle('A3:J3')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

            $userSheet->mergeCells('A4:J4');
            $explicacionGanancias = 'SISTEMA DE GANANCIAS: Las comisiones se calculan sobre la VENTA BRUTA. ';
            $explicacionGanancias .= 'EDITABLE: Columna "Ganancia por Rol" (amarillo) - Modifique los valores y presione F9 para actualizar todas las hojas.';
            if ($esFiltroPlataforma) {
                $explicacionGanancias .= ' | PLATAFORMAS: ' . implode(', ', $plataformasNombres);
            }

            $userSheet->setCellValue('A4', $explicacionGanancias);
            $userSheet->getStyle('A4:J4')->getFont()->setBold(true)->setSize(10);
            $userSheet->getStyle('A4:J4')->getFont()->getColor()->setRGB('E74C3C');
            $userSheet->getStyle('A4:J4')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('FDEBD0');
            $userSheet->getStyle('A4:J4')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

            // ============================================================================
            // TABLA DE DETALLE DE VENTAS
            // ============================================================================

            $detalleStartRow = 6;

            $userHeaders = [
                'Fecha',
                'Cliente',
                'Rol en Venta',
                'Ganancia por Rol (EDITABLE)',
                'Ganancia Volumen (AUTOMÁTICA)',
                'Venta Bruta',
                'Venta Neto',
                'Plataforma',
                'Tipo Venta',
                'Sala'
            ];
            $userSheet->fromArray($userHeaders, NULL, 'A' . $detalleStartRow);
            applySafeStyle($userSheet, 'A' . $detalleStartRow . ':J' . $detalleStartRow, 'header');

            $userRow = $detalleStartRow + 1;
            usort($usuario['ventas'], function ($a, $b) {
                return strtotime($b['fecha']) - strtotime($a['fecha']);
            });

            foreach ($usuario['ventas'] as $venta) {
                $fechaFormateada = date('d/m/Y', strtotime($venta['fecha']));
                $salaNombreVenta = $venta['sala_nombre'] ?? ($venta['sala_id'] ? 'Sala ' . $venta['sala_id'] : 'N/A');
                $filaActual = $userRow;

                $userSheet->setCellValue('A' . $userRow, $fechaFormateada);
                $userSheet->setCellValue('B' . $userRow, $venta['cliente_nombre']);
                $userSheet->setCellValue('C' . $userRow, $venta['rol_en_venta']);
                
                // MODIFICACIÓN: La ganancia por rol será EDITABLE (valor directo)
                $userSheet->setCellValue('D' . $userRow, $venta['ganancia_en_venta']);
                
                // FÓRMULA PARA GANANCIA VOLUMEN SEGÚN EL ROL (referencia a la celda D)
                $formulaVolumenVenta = '';
                switch ($venta['rol_en_venta']) {
                    case 'Liner':
                    case 'Closer 1':
                        $formulaVolumenVenta = "=F{$userRow}/2";
                        break;
                    case 'Closer 2':
                        $formulaVolumenVenta = "=F{$userRow}/3";
                        break;
                    case 'Front':
                        $formulaVolumenVenta = "=F{$userRow}";
                        break;
                    default:
                        $formulaVolumenVenta = "=F{$userRow}";
                }
                $userSheet->setCellValue('E' . $userRow, $formulaVolumenVenta);
                
                $userSheet->setCellValue('F' . $userRow, $venta['venta_bruto']);
                $userSheet->setCellValue('G' . $userRow, $venta['precio_neto']);
                $userSheet->setCellValue('H' . $userRow, $venta['plataforma_nombre_completo'] ?? 'N/A');
                $userSheet->setCellValue('I' . $userRow, $venta['tipo_venta'] ?? 'N/A');
                $userSheet->setCellValue('J' . $userRow, $salaNombreVenta);
                
                $userRow++;
            }

            // DEFINIR FILA DONDE TERMINAN LOS DATOS
            $filaFinDatos = $userRow - 1;
            $filaInicioDatos = $detalleStartRow + 1;
            
            // Guardar rango para este usuario
            $rangosUsuarios[$usuario['titulo_limpio']] = [
                'inicio' => $filaInicioDatos,
                'fin' => $filaFinDatos,
                'nombre_hoja' => $tituloHoja
            ];

            // APLICAR ESTILOS A LA TABLA DE DATOS
            if ($userRow > ($detalleStartRow + 1)) {
                // Hacer EDITABLE la columna D (Ganancia por Rol) - fondo amarillo
                applySafeStyle($userSheet, 'D' . $filaInicioDatos . ':D' . $filaFinDatos, 'editable');
                
                // Las columnas con fórmulas (E) mantienen estilo fórmula (verde)
                applySafeStyle($userSheet, 'E' . $filaInicioDatos . ':E' . $filaFinDatos, 'formula');
                
                // Hacer editables también Venta Bruta y Venta Neto
                applySafeStyle($userSheet, 'F' . $filaInicioDatos . ':G' . $filaFinDatos, 'editable');
            }

            // ============================================================================
            // RESUMEN DEL USUARIO CON FÓRMULAS DINÁMICAS
            // ============================================================================

            $resumenStartRow = $userRow + 2;

            $userSheet->setCellValue('A' . $resumenStartRow, 'RESUMEN DE GANANCIAS POR ROL');
            $userSheet->getStyle('A' . $resumenStartRow)->getFont()->setBold(true)->setSize(12);

            $userSheet->setCellValue('A' . ($resumenStartRow + 1), 'Nombre:');
            $userSheet->setCellValue('B' . ($resumenStartRow + 1), $usuario['nombre']);

            $userSheet->setCellValue('A' . ($resumenStartRow + 2), 'Roles:');
            $userSheet->setCellValue('B' . ($resumenStartRow + 2), implode(', ', $usuario['roles']));

            $userSheet->setCellValue('A' . ($resumenStartRow + 3), 'Sala:');
            $userSheet->setCellValue('B' . ($resumenStartRow + 3), 'Sala ' . ($usuario['sala_id'] ?? 'N/A'));

            // TOTAL DE VENTAS - FÓRMULA COUNTA
            $userSheet->setCellValue('A' . ($resumenStartRow + 4), 'Total de Ventas:');
            $userSheet->setCellValue('B' . ($resumenStartRow + 4), "=COUNTA(A" . $filaInicioDatos . ":A" . $filaFinDatos . ")");
            applySafeStyle($userSheet, 'B' . ($resumenStartRow + 4), 'formula');

            // VENTA BRUTA TOTAL - FÓRMULA SUMA
            $userSheet->setCellValue('A' . ($resumenStartRow + 5), 'Venta Bruta Total:');
            $userSheet->setCellValue('B' . ($resumenStartRow + 5), "=SUM(F" . $filaInicioDatos . ":F" . $filaFinDatos . ")");
            $userSheet->getStyle('B' . ($resumenStartRow + 5))->getNumberFormat()->setFormatCode('"$"#,##0.00');
            applySafeStyle($userSheet, 'B' . ($resumenStartRow + 5), 'formula');

            // GANANCIA TOTAL - FÓRMULA SUMA (de la columna D editable)
            $userSheet->setCellValue('A' . ($resumenStartRow + 6), 'Ganancia Total:');
            $userSheet->setCellValue('B' . ($resumenStartRow + 6), "=SUM(D" . $filaInicioDatos . ":D" . $filaFinDatos . ")");
            $userSheet->getStyle('B' . ($resumenStartRow + 6))->getNumberFormat()->setFormatCode('"$"#,##0.00');
            applySafeStyle($userSheet, 'B' . ($resumenStartRow + 6), 'formula');

            // ============================================================================
            // DISTRIBUCIÓN DE GANANCIAS POR ROL CON FÓRMULAS
            // ============================================================================

            $distribucionStartRow = $resumenStartRow;

            $userSheet->setCellValue('D' . $distribucionStartRow, 'DISTRIBUCIÓN DE GANANCIAS POR ROL:');
            $userSheet->getStyle('D' . $distribucionStartRow)->getFont()->setBold(true);

            $userSheet->setCellValue('D' . ($distribucionStartRow + 1), 'ROL');
            $userSheet->setCellValue('E' . ($distribucionStartRow + 1), 'GANANCIA TOTAL');
            $userSheet->setCellValue('F' . ($distribucionStartRow + 1), 'GANANCIA VOLUMEN');
            applySafeStyle($userSheet, 'D' . ($distribucionStartRow + 1) . ':F' . ($distribucionStartRow + 1), 'header');

            $rolRow = $distribucionStartRow + 2;
            $rolesDisponibles = ['Liner', 'Closer 1', 'Closer 2', 'Front'];

            foreach ($rolesDisponibles as $rol) {
                $tieneVentasEnRol = false;
                foreach ($usuario['ventas'] as $venta) {
                    if ($venta['rol_en_venta'] === $rol) {
                        $tieneVentasEnRol = true;
                        break;
                    }
                }

                if ($tieneVentasEnRol) {
                    $userSheet->setCellValue('D' . $rolRow, $rol);

                    // FÓRMULA PARA VENTA BRUTA TOTAL POR ROL (filtra por rol en columna C)
                    $userSheet->setCellValue('E' . $rolRow, "=SUMIF(C" . $filaInicioDatos . ":C" . $filaFinDatos . ",\"" . $rol . "\",F" . $filaInicioDatos . ":F" . $filaFinDatos . ")");

                    // FÓRMULA PARA GANANCIA VOLUMEN POR ROL (filtra por rol en columna C)
                    $userSheet->setCellValue('F' . $rolRow, "=SUMIF(C" . $filaInicioDatos . ":C" . $filaFinDatos . ",\"" . $rol . "\",E" . $filaInicioDatos . ":E" . $filaFinDatos . ")");

                    $userSheet->getStyle('E' . $rolRow)->getNumberFormat()->setFormatCode('"$"#,##0.00');
                    $userSheet->getStyle('F' . $rolRow)->getNumberFormat()->setFormatCode('"$"#,##0.00');
                    applySafeStyle($userSheet, 'E' . $rolRow, 'formula');
                    applySafeStyle($userSheet, 'F' . $rolRow, 'formula');

                    $rolRow++;
                }
            }

            // Total de la distribución
            if ($rolRow > ($distribucionStartRow + 2)) {
                $filaTotalRol = $rolRow;
                $userSheet->setCellValue('D' . $filaTotalRol, 'TOTAL:');
                $userSheet->setCellValue('E' . $filaTotalRol, "=SUM(E" . ($distribucionStartRow + 2) . ":E" . ($rolRow - 1) . ")");
                $userSheet->setCellValue('F' . $filaTotalRol, "=SUM(F" . ($distribucionStartRow + 2) . ":F" . ($rolRow - 1) . ")");
                applySafeStyle($userSheet, 'D' . $filaTotalRol . ':F' . $filaTotalRol, 'total');
                $userSheet->getStyle('E' . $filaTotalRol)->getNumberFormat()->setFormatCode('"$"#,##0.00');
                $userSheet->getStyle('F' . $filaTotalRol)->getNumberFormat()->setFormatCode('"$"#,##0.00');
                applySafeStyle($userSheet, 'E' . $filaTotalRol, 'formula');
                applySafeStyle($userSheet, 'F' . $filaTotalRol, 'formula');
            }

            // ============================================================================
            // SECCIÓN DE OVER
            // ============================================================================

            if ($usuario['tiene_over'] && $usuario['sala_id'] && $usuario['over_config'] && in_array($usuario['sala_id'], $usuario['over_config']['salas'] ?? [])) {
                $overUsuarioRow = max($rolRow + 2, $resumenStartRow + 10);
                
                $userSheet->setCellValue('D' . $overUsuarioRow, 'SISTEMA OVER - SALA ' . $usuario['sala_id']);
                $userSheet->getStyle('D' . $overUsuarioRow)->getFont()->setBold(true);
                $userSheet->getStyle('D' . $overUsuarioRow)->getFont()->getColor()->setRGB('E74C3C');
                
                $overUsuarioRow++;
                $userSheet->setCellValue('D' . $overUsuarioRow, 'GANANCIA TOTAL (Comisiones):');
                $userSheet->setCellValue('E' . $overUsuarioRow, "=B" . ($resumenStartRow + 6));
                $userSheet->getStyle('E' . $overUsuarioRow)->getNumberFormat()->setFormatCode('"$"#,##0.00');
                
                $overUsuarioRow++;
                $userSheet->setCellValue('D' . $overUsuarioRow, 'OVER:');
                $userSheet->setCellValue('E' . $overUsuarioRow, "=VLOOKUP(A" . ($resumenStartRow + 1) . ",'Resumen Comisiones'!A:C,3,FALSE)");
                $userSheet->getStyle('E' . $overUsuarioRow)->getNumberFormat()->setFormatCode('"$"#,##0.00');
                applySafeStyle($userSheet, 'E' . $overUsuarioRow, 'formula');
                
                $overUsuarioRow++;
                $userSheet->setCellValue('D' . $overUsuarioRow, 'SUMA TOTAL (Ganancia + OVER):');
                $userSheet->setCellValue('E' . $overUsuarioRow, "=E" . ($overUsuarioRow - 2) . "+E" . ($overUsuarioRow - 1));
                $userSheet->getStyle('E' . $overUsuarioRow)->getNumberFormat()->setFormatCode('"$"#,##0.00');
                applySafeStyle($userSheet, 'E' . $overUsuarioRow, 'formula');
                $userSheet->getStyle('D' . $overUsuarioRow . ':E' . $overUsuarioRow)->getFont()->setBold(true);
                $userSheet->getStyle('D' . $overUsuarioRow . ':E' . $overUsuarioRow)->getFont()->getColor()->setRGB('FFFFFF');
                $userSheet->getStyle('D' . $overUsuarioRow . ':E' . $overUsuarioRow)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('27AE60');
            }

            // TOTALES GENERALES DE LA TABLA
            if ($userRow > ($detalleStartRow + 1)) {
                $userSheet->setAutoFilter('A' . $detalleStartRow . ':J' . $filaFinDatos);
                
                // Formato numérico
                $userSheet->getStyle('D' . $filaInicioDatos . ':E' . $filaFinDatos)->getNumberFormat()->setFormatCode('"$"#,##0.00');
                $userSheet->getStyle('F' . $filaInicioDatos . ':G' . $filaFinDatos)->getNumberFormat()->setFormatCode('"$"#,##0.00');
                
                // Fila de totales al final de la tabla
                $userSheet->setCellValue('A' . $userRow, 'TOTALES:');
                $userSheet->mergeCells('A' . $userRow . ':C' . $userRow);
                $userSheet->setCellValue('D' . $userRow, "=SUBTOTAL(9,D" . $filaInicioDatos . ":D" . $filaFinDatos . ")");
                $userSheet->setCellValue('E' . $userRow, "=SUBTOTAL(9,E" . $filaInicioDatos . ":E" . $filaFinDatos . ")");
                $userSheet->setCellValue('F' . $userRow, "=SUBTOTAL(9,F" . $filaInicioDatos . ":F" . $filaFinDatos . ")");
                $userSheet->setCellValue('G' . $userRow, "=SUBTOTAL(9,G" . $filaInicioDatos . ":G" . $filaFinDatos . ")");
                
                $userSheet->getStyle('A' . $userRow . ':J' . $userRow)->getFont()->setBold(true);
                $userSheet->getStyle('A' . $userRow . ':J' . $userRow)->getFont()->getColor()->setRGB('FFFFFF');
                $userSheet->getStyle('A' . $userRow . ':J' . $userRow)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('E74C3C');
                $userSheet->getStyle('D' . $userRow . ':G' . $userRow)->getNumberFormat()->setFormatCode('"$"#,##0.00');
                applySafeStyle($userSheet, 'D' . $userRow . ':G' . $userRow, 'formula');
            }

            // Autoajustar columnas
            foreach (range('A', 'J') as $col) {
                $userSheet->getColumnDimension($col)->setAutoSize(true);
            }
            $userSheet->freezePane('A' . $filaInicioDatos);
            
        } catch (Exception $e) {
            error_log("Error al crear hoja para usuario {$usuario['nombre']}: " . $e->getMessage());
            continue;
        }
    }
}

// ============================================================================
// HOJA GANANCIA VOLUMEN - CON FÓRMULAS QUE REFERENCIAN HOJAS INDIVIDUALES
// ============================================================================

if (!empty($usuariosConGanancias)) {
    try {
        $volumenSheet = new \PhpOffice\PhpSpreadsheet\Worksheet\Worksheet($spreadsheet, 'Ganancia Volumen');
        $spreadsheet->addSheet($volumenSheet);
        $spreadsheet->setActiveSheetIndex($spreadsheet->getIndex($volumenSheet));
        $volumenSheet = $spreadsheet->getActiveSheet();

        $volumenSheet->getPageSetup()
            ->setOrientation(PageSetup::ORIENTATION_LANDSCAPE)
            ->setPaperSize(PageSetup::PAPERSIZE_A4)
            ->setFitToWidth(1)
            ->setFitToHeight(0);

        // Título
        $volumenSheet->mergeCells('A1:G2');
        $tituloVolumen = 'RESUMEN DE GANANCIA VOLUMEN POR USUARIO Y ROL - ACTUALIZACIÓN AUTOMÁTICA';

        if (!empty($salaNombre)) {
            $tituloVolumen .= ' - SALA: ' . strtoupper($salaNombre);
        }

        $volumenSheet->setCellValue('A1', $tituloVolumen);
        applySafeStyle($volumenSheet, 'A1:G2', 'title');

        $volumenSheet->mergeCells('A3:G3');
        $volumenSheet->setCellValue('A3', 'Filtro aplicado: ' . $filtroTexto . ' - Fecha: ' . date('d/m/Y H:i:s'));
        $volumenSheet->getStyle('A3:G3')->getFont()->setItalic(true)->setSize(10);
        $volumenSheet->getStyle('A3:G3')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

        $volumenSheet->mergeCells('A4:G4');
        $volumenSheet->setCellValue('A4', 'ACTUALIZACIÓN AUTOMÁTICA: Los cambios en hojas individuales se reflejan aquí. Presione F9 para recalcular.');
        $volumenSheet->getStyle('A4:G4')->getFont()->setBold(true)->setSize(10);
        $volumenSheet->getStyle('A4:G4')->getFont()->getColor()->setRGB('E74C3C');
        $volumenSheet->getStyle('A4:G4')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('FDEBD0');

        // Headers
        $volumenHeaders = ['Usuario', 'GANANCIA VOLUMEN Liner', 'GANANCIA VOLUMEN Closer 1', 'GANANCIA VOLUMEN Closer 2', 'GANANCIA VOLUMEN Front', 'TOTAL USUARIO', 'VENTAS'];
        $volumenSheet->fromArray($volumenHeaders, NULL, 'A5');
        applySafeStyle($volumenSheet, 'A5:G5', 'header');

        $volumenRow = 6;

        foreach ($usuariosConGanancias as $clave => $usuario) {
            $nombreHojaUsuario = $usuario['titulo_limpio'];
            $nombreHojaUsuario = str_replace(['*', ':', '/', '\\', '?', '[', ']'], '', $nombreHojaUsuario);
            if (empty(trim($nombreHojaUsuario))) $nombreHojaUsuario = 'Usuario_' . $usuario['ventas_count'];
            $nombreHojaUsuario = substr($nombreHojaUsuario, 0, 31);
            $nombreHojaEscapado = "'" . str_replace("'", "''", $nombreHojaUsuario) . "'";
            
            // Obtener el rango de filas para este usuario
            $rango = isset($rangosUsuarios[$usuario['titulo_limpio']]) ? $rangosUsuarios[$usuario['titulo_limpio']] : null;
            
            if ($rango) {
                $inicio = $rango['inicio'];
                $fin = $rango['fin'];
                
                $volumenSheet->setCellValue('A' . $volumenRow, $usuario['nombre']);
                
                // FÓRMULAS que suman las ganancias volumen desde la hoja individual (columna E)
                $volumenSheet->setCellValue('B' . $volumenRow, 
                    "=SUMIF(" . $nombreHojaEscapado . "!C" . $inicio . ":C" . $fin . ",\"Liner\"," . $nombreHojaEscapado . "!E" . $inicio . ":E" . $fin . ")");
                
                $volumenSheet->setCellValue('C' . $volumenRow, 
                    "=SUMIF(" . $nombreHojaEscapado . "!C" . $inicio . ":C" . $fin . ",\"Closer 1\"," . $nombreHojaEscapado . "!E" . $inicio . ":E" . $fin . ")");
                
                $volumenSheet->setCellValue('D' . $volumenRow, 
                    "=SUMIF(" . $nombreHojaEscapado . "!C" . $inicio . ":C" . $fin . ",\"Closer 2\"," . $nombreHojaEscapado . "!E" . $inicio . ":E" . $fin . ")");
                
                $volumenSheet->setCellValue('E' . $volumenRow, 
                    "=SUMIF(" . $nombreHojaEscapado . "!C" . $inicio . ":C" . $fin . ",\"Front\"," . $nombreHojaEscapado . "!E" . $inicio . ":E" . $fin . ")");
                
                $volumenSheet->setCellValue('F' . $volumenRow, "=SUM(B" . $volumenRow . ":E" . $volumenRow . ")");
                
                $volumenSheet->setCellValue('G' . $volumenRow, 
                    "=COUNTA(" . $nombreHojaEscapado . "!A" . $inicio . ":A" . $fin . ")");
                
                // Aplicar estilo de fórmulas
                applySafeStyle($volumenSheet, 'B' . $volumenRow . ':G' . $volumenRow, 'formula');
            } else {
                // Fallback: usar valores calculados
                $gananciaVolumenLiner = 0;
                $gananciaVolumenCloser1 = 0;
                $gananciaVolumenCloser2 = 0;
                $gananciaVolumenFront = 0;

                foreach ($usuario['ventas'] as $venta) {
                    $ventaBruta = (float)$venta['venta_bruto'];
                    switch ($venta['rol_en_venta']) {
                        case 'Liner':
                            $gananciaVolumenLiner += $ventaBruta / 2;
                            break;
                        case 'Closer 1':
                            $gananciaVolumenCloser1 += $ventaBruta / 2;
                            break;
                        case 'Closer 2':
                            $gananciaVolumenCloser2 += $ventaBruta / 3;
                            break;
                        case 'Front':
                            $gananciaVolumenFront += $ventaBruta;
                            break;
                    }
                }

                $volumenSheet->setCellValue('A' . $volumenRow, $usuario['nombre']);
                $volumenSheet->setCellValue('B' . $volumenRow, $gananciaVolumenLiner);
                $volumenSheet->setCellValue('C' . $volumenRow, $gananciaVolumenCloser1);
                $volumenSheet->setCellValue('D' . $volumenRow, $gananciaVolumenCloser2);
                $volumenSheet->setCellValue('E' . $volumenRow, $gananciaVolumenFront);
                $volumenSheet->setCellValue('F' . $volumenRow, "=SUM(B" . $volumenRow . ":E" . $volumenRow . ")");
                $volumenSheet->setCellValue('G' . $volumenRow, $usuario['ventas_count']);
            }

            $volumenRow++;
        }

        // TOTALES GENERALES
        if ($volumenRow > 6) {
            $filaTotales = $volumenRow;
            
            $volumenSheet->setCellValue('A' . $filaTotales, 'TOTALES GENERALES:');
            $volumenSheet->setCellValue('B' . $filaTotales, "=SUBTOTAL(9,B6:B" . ($volumenRow - 1) . ")");
            $volumenSheet->setCellValue('C' . $filaTotales, "=SUBTOTAL(9,C6:C" . ($volumenRow - 1) . ")");
            $volumenSheet->setCellValue('D' . $filaTotales, "=SUBTOTAL(9,D6:D" . ($volumenRow - 1) . ")");
            $volumenSheet->setCellValue('E' . $filaTotales, "=SUBTOTAL(9,E6:E" . ($volumenRow - 1) . ")");
            $volumenSheet->setCellValue('F' . $filaTotales, "=SUBTOTAL(9,F6:F" . ($volumenRow - 1) . ")");
            $volumenSheet->setCellValue('G' . $filaTotales, "=SUBTOTAL(9,G6:G" . ($volumenRow - 1) . ")");
            
            applySafeStyle($volumenSheet, 'A' . $filaTotales . ':G' . $filaTotales, 'granTotal');
            applySafeStyle($volumenSheet, 'B' . $filaTotales . ':G' . $filaTotales, 'formula');
        }

        // Formato numérico
        if ($volumenRow > 6) {
            $volumenSheet->getStyle('B6:F' . $volumenRow)->getNumberFormat()->setFormatCode('"$"#,##0.00');
        }

        // Autoajustar columnas
        foreach (range('A', 'G') as $col) {
            $volumenSheet->getColumnDimension($col)->setAutoSize(true);
        }
        $volumenSheet->freezePane('A6');
        
        if ($volumenRow > 6) {
            $volumenSheet->setAutoFilter('A5:G' . ($volumenRow - 1));
        }
        
    } catch (Exception $e) {
        error_log("Error al crear hoja de Ganancia Volumen: " . $e->getMessage());
        $volumenSheet->setCellValue('A1', 'ERROR AL GENERAR GANANCIA VOLUMEN');
        $volumenSheet->setCellValue('A3', 'Error: ' . $e->getMessage());
    }
}

// ============================================================================
// HOJA RESUMEN COMISIONES - CON FÓRMULAS QUE REFERENCIAN HOJAS INDIVIDUALES
// ============================================================================

if ($tieneComisiones && !empty($usuariosComisiones)) {
    try {
        $resumenSheet = new \PhpOffice\PhpSpreadsheet\Worksheet\Worksheet($spreadsheet, 'Resumen Comisiones');
        $spreadsheet->addSheet($resumenSheet);
        $spreadsheet->setActiveSheetIndex($spreadsheet->getIndex($resumenSheet));
        $resumenSheet = $spreadsheet->getActiveSheet();

        $resumenSheet->getPageSetup()
            ->setOrientation(PageSetup::ORIENTATION_LANDSCAPE)
            ->setPaperSize(PageSetup::PAPERSIZE_A4)
            ->setFitToWidth(1)
            ->setFitToHeight(0);

        // Título
        $resumenSheet->mergeCells('A1:G2');
        $tituloResumen = 'RESUMEN DE COMISIONES Y SISTEMA OVER - ACTUALIZACIÓN AUTOMÁTICA';

        if (!empty($salaNombre)) {
            $tituloResumen .= ' - SALA: ' . strtoupper($salaNombre);
        }

        $resumenSheet->setCellValue('A1', $tituloResumen);
        applySafeStyle($resumenSheet, 'A1:G2', 'title');

        $resumenSheet->mergeCells('A3:G3');
        $resumenSheet->setCellValue('A3', 'Filtro aplicado: ' . $filtroTexto . ' - Fecha: ' . date('d/m/Y H:i:s'));
        $resumenSheet->getStyle('A3:G3')->getFont()->setItalic(true)->setSize(10);
        $resumenSheet->getStyle('A3:G3')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

        $resumenSheet->mergeCells('A4:G4');
        $resumenSheet->setCellValue('A4', 'ACTUALIZACIÓN AUTOMÁTICA: Los cambios en hojas individuales se reflejan aquí. Presione F9 para recalcular.');
        $resumenSheet->getStyle('A4:G4')->getFont()->setBold(true)->setSize(10);
        $resumenSheet->getStyle('A4:G4')->getFont()->getColor()->setRGB('E74C3C');
        $resumenSheet->getStyle('A4:G4')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('FDEBD0');

        // Encabezados
        $resumenHeaders = ['Usuario', 'Comisión Total', 'Total Over', 'Descuento', 'Sueldo', 'SPIFF', 'Total General'];
        $resumenSheet->fromArray($resumenHeaders, NULL, 'A5');
        applySafeStyle($resumenSheet, 'A5:G5', 'header');

        $resumenRow = 6;
        $filaInicioDatos = 6;
        $filaFinDatos = 0;

        foreach ($usuariosComisiones as $usuario) {
            $resumenSheet->setCellValue('A' . $resumenRow, $usuario['nombre']);
            
            // Buscar el nombre de la hoja del usuario
            $nombreHojaUsuario = '';
            $rangoUsuario = null;
            foreach ($usuariosConGanancias as $clave => $userData) {
                if (normalizarNombre($userData['nombre']) === normalizarNombre($usuario['nombre'])) {
                    $nombreHojaUsuario = $userData['titulo_limpio'];
                    $nombreHojaUsuario = str_replace(['*', ':', '/', '\\', '?', '[', ']'], '', $nombreHojaUsuario);
                    if (empty(trim($nombreHojaUsuario))) $nombreHojaUsuario = 'Usuario_' . $userData['ventas_count'];
                    $nombreHojaUsuario = substr($nombreHojaUsuario, 0, 31);
                    $rangoUsuario = isset($rangosUsuarios[$userData['titulo_limpio']]) ? $rangosUsuarios[$userData['titulo_limpio']] : null;
                    break;
                }
            }
            
            if (!empty($nombreHojaUsuario) && $rangoUsuario) {
                $nombreHojaEscapado = "'" . str_replace("'", "''", $nombreHojaUsuario) . "'";
                // FÓRMULA que suma la columna D (Ganancia por Rol) de la hoja individual
                $resumenSheet->setCellValue('B' . $resumenRow, 
                    "=SUM(" . $nombreHojaEscapado . "!D" . $rangoUsuario['inicio'] . ":D" . $rangoUsuario['fin'] . ")");
            } else {
                // Fallback: usar el valor del array
                $resumenSheet->setCellValue('B' . $resumenRow, $usuario['comision_total']);
            }
            
            // OVER - se actualizará después
            $resumenSheet->setCellValue('C' . $resumenRow, 0);
            $resumenSheet->setCellValue('D' . $resumenRow, $usuario['descuento']);
            $resumenSheet->setCellValue('E' . $resumenRow, $usuario['sueldo']);
            $resumenSheet->setCellValue('F' . $resumenRow, 0);
            $resumenSheet->setCellValue('G' . $resumenRow, "=B" . $resumenRow . "+C" . $resumenRow . "-D" . $resumenRow . "+E" . $resumenRow . "+F" . $resumenRow);
            
            // Aplicar estilos
            applySafeStyle($resumenSheet, 'B' . $resumenRow, 'formula');
            applySafeStyle($resumenSheet, 'C' . $resumenRow, 'formula');
            applySafeStyle($resumenSheet, 'F' . $resumenRow, 'formula');
            applySafeStyle($resumenSheet, 'G' . $resumenRow, 'formula');
            applySafeStyle($resumenSheet, 'D' . $resumenRow . ':E' . $resumenRow, 'editable');
            
            $resumenRow++;
        }
        
        $filaFinDatos = $resumenRow - 1;
        
        // TOTALES GENERALES
        if ($resumenRow > 6) {
            $totalRow = $resumenRow;
            $resumenSheet->setCellValue('A' . $totalRow, 'TOTALES GENERALES:');
            $resumenSheet->setCellValue('B' . $totalRow, "=SUM(B6:B" . ($resumenRow - 1) . ")");
            $resumenSheet->setCellValue('C' . $totalRow, "=SUM(C6:C" . ($resumenRow - 1) . ")");
            $resumenSheet->setCellValue('D' . $totalRow, "=SUM(D6:D" . ($resumenRow - 1) . ")");
            $resumenSheet->setCellValue('E' . $totalRow, "=SUM(E6:E" . ($resumenRow - 1) . ")");
            $resumenSheet->setCellValue('F' . $totalRow, "=SUM(F6:F" . ($resumenRow - 1) . ")");
            $resumenSheet->setCellValue('G' . $totalRow, "=SUM(G6:G" . ($resumenRow - 1) . ")");
            
            applySafeStyle($resumenSheet, 'A' . $totalRow . ':G' . $totalRow, 'granTotal');
            applySafeStyle($resumenSheet, 'B' . $totalRow . ':G' . $totalRow, 'formula');
        }
        
        // ============================================================================
        // SISTEMA DE OVER
        // ============================================================================
        
        $overStartRow = ($resumenRow > 6 ? $resumenRow : $totalRow) + 3;
        
        $resumenSheet->setCellValue('A' . $overStartRow, 'SISTEMA DE OVER - BONOS POR META');
        $resumenSheet->getStyle('A' . $overStartRow)->getFont()->setBold(true)->setSize(12);
        
        $overHeaders = ['VOL TOTAL (NETO)', 'PORCENTAJE OVER', 'PERSONAL C/OVER', 'SALA', 'TOTAL OVER'];
        $resumenSheet->fromArray($overHeaders, NULL, 'A' . ($overStartRow + 1));
        applySafeStyle($resumenSheet, 'A' . ($overStartRow + 1) . ':E' . ($overStartRow + 1), 'header');
        
        // Calcular ventas netas por sala
        $ventaNetoTotal = 0;
        $ventaNetoTotalPorSala = [1 => 0, 2 => 0];
        
        foreach ($ventas as $venta) {
            $salaIdVenta = $venta['sala_id'] ?? 0;
            if ($salaIdVenta == 1) {
                $ventaNetoTotalPorSala[1] += (float)$venta['precio_neto'];
            } elseif ($salaIdVenta == 2) {
                $ventaNetoTotalPorSala[2] += (float)$venta['precio_neto'];
            }
            $ventaNetoTotal += (float)$venta['precio_neto'];
        }
        
        $overRow = $overStartRow + 2;
        $overPorUsuario = [];
        
        foreach ($configOver as $persona => $config) {
            $volTotalParaEsteOver = 0;
            
            if (count($config['salas']) == 1) {
                $salaIdOver = $config['salas'][0];
                $volTotalParaEsteOver = $ventaNetoTotalPorSala[$salaIdOver] ?? 0;
            } else {
                foreach ($config['salas'] as $salaIdOver) {
                    $volTotalParaEsteOver += $ventaNetoTotalPorSala[$salaIdOver] ?? 0;
                }
            }
            
            $resumenSheet->setCellValue('A' . $overRow, $volTotalParaEsteOver);
            
            $overFormula = "=IF(A" . $overRow . "<=300000, " . ($config['metas'][0]['porcentaje'] / 100) . ", ";
            if (count($config['metas']) >= 3) {
                $overFormula .= "IF(A" . $overRow . "<=350000, " . ($config['metas'][1]['porcentaje'] / 100) . ", ";
                if (count($config['metas']) == 4) {
                    $overFormula .= "IF(A" . $overRow . "<=425000, " . ($config['metas'][2]['porcentaje'] / 100) . ", ";
                    $overFormula .= ($config['metas'][3]['porcentaje'] / 100) . ")))";
                } else {
                    $overFormula .= ($config['metas'][2]['porcentaje'] / 100) . "))";
                }
            } elseif (count($config['metas']) == 2) {
                $overFormula .= ($config['metas'][1]['porcentaje'] / 100) . ")";
            } else {
                $overFormula .= ($config['metas'][0]['porcentaje'] / 100) . ")";
            }
            
            $resumenSheet->setCellValue('B' . $overRow, $overFormula);
            $resumenSheet->setCellValue('C' . $overRow, $config['nombre']);
            
            $salaTexto = implode(', ', array_map(function ($id) {
                return 'Sala ' . $id;
            }, $config['salas']));
            $resumenSheet->setCellValue('D' . $overRow, $salaTexto);
            $resumenSheet->setCellValue('E' . $overRow, "=A" . $overRow . "*B" . $overRow);
            
            $overPorUsuario[$config['nombre']] = [
                'celda' => 'E' . $overRow,
                'salas' => $config['salas']
            ];
            
            $overRow++;
        }
        
        // Aplicar estilos a la sección OVER
        $resumenSheet->getStyle('A' . ($overStartRow + 2) . ':E' . ($overRow - 1))->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
        applySafeStyle($resumenSheet, 'A' . ($overStartRow + 2) . ':A' . ($overRow - 1), 'editable');
        applySafeStyle($resumenSheet, 'B' . ($overStartRow + 2) . ':E' . ($overRow - 1), 'formula');
        $resumenSheet->getStyle('A' . ($overStartRow + 2) . ':A' . ($overRow - 1))->getNumberFormat()->setFormatCode('"$"#,##0.00');
        $resumenSheet->getStyle('E' . ($overStartRow + 2) . ':E' . ($overRow - 1))->getNumberFormat()->setFormatCode('"$"#,##0.00');
        $resumenSheet->getStyle('B' . ($overStartRow + 2) . ':B' . ($overRow - 1))->getNumberFormat()->setFormatCode('0.00%');
        
        // TOTAL OVER GENERAL
        $overRow++;
        $resumenSheet->setCellValue('D' . $overRow, 'TOTAL OVER GENERAL:');
        $resumenSheet->setCellValue('E' . $overRow, "=SUM(E" . ($overStartRow + 2) . ":E" . ($overRow - 1) . ")");
        applySafeStyle($resumenSheet, 'D' . $overRow . ':E' . $overRow, 'total');
        $resumenSheet->getStyle('E' . $overRow)->getNumberFormat()->setFormatCode('"$"#,##0.00');
        applySafeStyle($resumenSheet, 'E' . $overRow, 'formula');
        
        // ACTUALIZAR OVER EN LA TABLA DE COMISIONES
        $resumenRow = 6;
        foreach ($usuariosComisiones as $usuario) {
            $nombreUsuario = trim($usuario['nombre']);
            $salaUsuario = $usuario['sala_id'];
            
            $overEncontrado = false;
            foreach ($overPorUsuario as $nombreOver => $infoOver) {
                if (stripos($nombreUsuario, $nombreOver) !== false) {
                    if (in_array($salaUsuario, $infoOver['salas'])) {
                        $resumenSheet->setCellValue('C' . $resumenRow, "=" . $infoOver['celda']);
                        $overEncontrado = true;
                        break;
                    }
                }
            }
            
            if (!$overEncontrado) {
                $resumenSheet->setCellValue('C' . $resumenRow, 0);
            }
            
            $resumenRow++;
        }
        
        // Formato numérico final
        if ($filaFinDatos >= $filaInicioDatos) {
            $resumenSheet->getStyle('B' . $filaInicioDatos . ':G' . $filaFinDatos)->getNumberFormat()->setFormatCode('"$"#,##0.00');
            applySafeStyle($resumenSheet, 'C' . $filaInicioDatos . ':C' . $filaFinDatos, 'formula');
        }
        
        // Autoajustar columnas
        foreach (range('A', 'G') as $col) {
            $resumenSheet->getColumnDimension($col)->setAutoSize(true);
        }
        $resumenSheet->freezePane('A6');
        
    } catch (Exception $e) {
        error_log("Error al crear hoja de resumen: " . $e->getMessage());
    }
}

// Regresar a la primera hoja
$spreadsheet->setActiveSheetIndex(0);

// ============================================================================
// GUARDAR Y DESCARGAR EL ARCHIVO
// ============================================================================

try {
    // Crear el escritor
    $writer = new Xlsx($spreadsheet);
    $fileName = 'Reporte_Ventas_' . date('Y-m-d_His') . '.xlsx';

    // Limpiar buffer completamente
    while (ob_get_level()) {
        ob_end_clean();
    }

    // Headers para descarga
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment;filename="' . $fileName . '"');
    header('Cache-Control: max-age=0');
    header('Content-Transfer-Encoding: binary');
    header('Pragma: public');
    header('Expires: 0');

    // Guardar y enviar
    $tempFile = tempnam(sys_get_temp_dir(), 'phpxltmp');
    $writer->save($tempFile);

    // Verificar que el archivo se creó correctamente
    if (file_exists($tempFile) && filesize($tempFile) > 0) {
        registrarAccion($pdo, 'EXPORTAR EXCEL', 'ventas_facturacion', null, [
            'archivo' => $fileName,
            'filtros' => $_GET,
            'ventas_count' => count($ventas ?? []),
        ]);
        readfile($tempFile);
        unlink($tempFile);
        exit;
    } else {
        throw new Exception('El archivo Excel no se generó correctamente');
    }
} catch (Exception $e) {
    // Headers para error en HTML
    header('Content-Type: text/html; charset=utf-8');
    die('Error al generar el archivo Excel: ' . $e->getMessage());
}
?>