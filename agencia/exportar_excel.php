<?php
require_once __DIR__ . '/../includes/db.php';
// exportar_excel.php
require_once __DIR__ . '/../session_config.php'; session_start();
date_default_timezone_set('America/Mexico_City');

// Verificar autenticación y permisos
if (!isset($_SESSION['usuario_id']) || ($_SESSION['rol'] !== 'admin' && $_SESSION['rol'] !== 'usuario' && $_SESSION['rol'] !== 'superadmin')) {
    die("Acceso denegado. Debe iniciar sesión primero.");
}

// Solo administradores pueden exportar
if ($_SESSION['rol'] !== 'admin' && $_SESSION['rol'] !== 'superadmin') {
    die("Acceso denegado. Solo los administradores pueden exportar a Excel.");
}

// Cargar PhpSpreadsheet
require __DIR__ . '/../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Color;

// Conexión a la base de datos
try {
    $pdo = conexionPDO();
} catch (PDOException $e) {
    die("Error de conexión: " . $e->getMessage());
}

// Obtener permisos del usuario admin
$acceso_todas_salas = 1;
$salas_permitidas = [];

$sqlPermisos = "SELECT acceso_todas_salas, salas_permitidas FROM usuarios WHERE id = :usuario_id";
$stmtPermisos = $pdo->prepare($sqlPermisos);
$stmtPermisos->execute([':usuario_id' => $_SESSION['usuario_id']]);
$permisos = $stmtPermisos->fetch(PDO::FETCH_ASSOC);

if ($permisos) {
    $acceso_todas_salas = (int)$permisos['acceso_todas_salas'];
    if (!empty($permisos['salas_permitidas'])) {
        $salas_permitidas = json_decode($permisos['salas_permitidas'], true);
        if (!is_array($salas_permitidas)) {
            $salas_permitidas = [];
        }
    }
}

// Obtener parámetros de filtrado desde GET
$fechaInicio = isset($_GET['fecha_inicio']) ? $_GET['fecha_inicio'] : '';
$fechaFin = isset($_GET['fecha_fin']) ? $_GET['fecha_fin'] : '';
$salaFiltro = isset($_GET['sala_id']) ? (int)$_GET['sala_id'] : '';

// Para admin con acceso limitado y sala asignada
if ($acceso_todas_salas === 0 && isset($_SESSION['sala_id']) && !empty($_SESSION['sala_id'])) {
    $salaFiltro = $_SESSION['sala_id'];
}

// Construir consulta con filtros
$whereConditions = [];
$params = [];

// Filtro por permisos de sala
if ($acceso_todas_salas === 0) {
    if (isset($_SESSION['sala_id']) && !empty($_SESSION['sala_id'])) {
        $whereConditions[] = "c.sala_id = :sala_asignada";
        $params[':sala_asignada'] = $_SESSION['sala_id'];
    } elseif (!empty($salas_permitidas)) {
        $placeholders = [];
        foreach ($salas_permitidas as $index => $sala_id) {
            $param_name = ":sala_permitida_" . $index;
            $placeholders[] = $param_name;
            $params[$param_name] = $sala_id;
        }
        $whereConditions[] = "c.sala_id IN (" . implode(',', $placeholders) . ")";
    } else {
        die("No tienes permisos para ver ninguna sala.");
    }
}

// Filtro por sala seleccionada (solo para admin con acceso a todas las salas)
if ($acceso_todas_salas === 1 && !empty($salaFiltro)) {
    $whereConditions[] = "c.sala_id = :sala_filtro";
    $params[':sala_filtro'] = $salaFiltro;
}

// Para admin con acceso limitado que selecciona una sala en el filtro
if ($acceso_todas_salas === 0 && !empty($salaFiltro) && (!isset($_SESSION['sala_id']) || empty($_SESSION['sala_id']))) {
    if (in_array($salaFiltro, $salas_permitidas)) {
        $whereConditions[] = "c.sala_id = :sala_filtro";
        $params[':sala_filtro'] = $salaFiltro;
    } else {
        die("No tienes permisos para exportar datos de esta sala.");
    }
}

// Filtros de fecha
if (!empty($fechaInicio)) {
    $whereConditions[] = "c.fecha_registro >= :fecha_inicio";
    $params[':fecha_inicio'] = $fechaInicio . ' 00:00:00';
}

if (!empty($fechaFin)) {
    $whereConditions[] = "c.fecha_registro <= :fecha_fin";
    $params[':fecha_fin'] = $fechaFin . ' 23:59:59';
}

// Construir WHERE clause
$whereClause = '';
if (!empty($whereConditions)) {
    $whereClause = 'WHERE ' . implode(' AND ', $whereConditions);
}

// Consulta para obtener los datos de clientes
$sqlClientes = "
    SELECT 
        c.id,
        CONCAT(c.nombre, ' ', c.apepat, ' ', c.apemat) as nombre_completo,
        c.asunto,
        c.descripcion,
        c.numero1,
        c.numero2,
        c.fecha_registro,
        CONCAT(u.nombre, ' ', u.apemat) as usuario_asignado,
        CONCAT(uc.nombre, ' ', uc.apemat) as closer_asignado,
        s.nombre as sala_nombre,
        CASE 
            WHEN c.usuario_closer IS NOT NULL AND c.usuario_closer != '' THEN 'Sí'
            ELSE 'No'
        END as tiene_closer,
        DATE_FORMAT(c.fecha_registro, '%d/%m/%Y %H:%i') as fecha_formateada
    FROM cliente c 
    LEFT JOIN usuarios u ON c.usuario_id = u.id 
    LEFT JOIN usuarios uc ON c.usuario_closer = uc.id
    LEFT JOIN salas s ON c.sala_id = s.id
    $whereClause
    ORDER BY c.fecha_registro DESC
";

try {
    $stmtClientes = $pdo->prepare($sqlClientes);
    foreach ($params as $key => $value) {
        $stmtClientes->bindValue($key, $value);
    }
    $stmtClientes->execute();
    $clientes = $stmtClientes->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    die("Error al obtener los datos: " . $e->getMessage());
}

// Crear un nuevo objeto Spreadsheet
$spreadsheet = new Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();

// Configurar propiedades del documento
$spreadsheet->getProperties()
    ->setCreator("CRM Sistema")
    ->setLastModifiedBy($_SESSION['nombre'])
    ->setTitle("Reporte de Clientes")
    ->setSubject("Exportación de clientes del CRM")
    ->setDescription("Reporte generado desde el sistema CRM")
    ->setKeywords("clientes crm exportación excel")
    ->setCategory("Reporte");

// Establecer el nombre de la hoja
$sheet->setTitle('Clientes');

// Definir los encabezados
$encabezados = [
    'ID',
    'Nombre Completo',
    'Asunto',
    'Descripción',
    'Teléfono 1',
    'Teléfono 2',
    'Fecha Registro',
    'Liner Asignado',
    'Closer Asignado',
    'Sala/Plataforma',
    '¿Tiene Closer?'
];

// Aplicar estilos a los encabezados
$headerStyle = [
    'font' => [
        'bold' => true,
        'color' => ['rgb' => 'FFFFFF'],
        'size' => 11
    ],
    'alignment' => [
        'horizontal' => Alignment::HORIZONTAL_CENTER,
        'vertical' => Alignment::VERTICAL_CENTER,
        'wrapText' => true
    ],
    'fill' => [
        'fillType' => Fill::FILL_SOLID,
        'startColor' => ['rgb' => '2c3e50']
    ],
    'borders' => [
        'allBorders' => [
            'borderStyle' => Border::BORDER_THIN,
            'color' => ['rgb' => '1a252f']
        ]
    ]
];

// Estilo para datos
$dataStyle = [
    'alignment' => [
        'vertical' => Alignment::VERTICAL_CENTER,
        'wrapText' => true
    ],
    'borders' => [
        'allBorders' => [
            'borderStyle' => Border::BORDER_THIN,
            'color' => ['rgb' => 'd5d8dc']
        ]
    ]
];

// Estilo para fechas
$dateStyle = [
    'alignment' => [
        'horizontal' => Alignment::HORIZONTAL_CENTER,
        'vertical' => Alignment::VERTICAL_CENTER
    ],
    'numberFormat' => [
        'formatCode' => 'dd/mm/yyyy hh:mm'
    ]
];

// Escribir encabezados
$columna = 'A';
foreach ($encabezados as $encabezado) {
    $sheet->setCellValue($columna . '1', $encabezado);
    $sheet->getColumnDimension($columna)->setAutoSize(true);
    $sheet->getStyle($columna . '1')->applyFromArray($headerStyle);
    $columna++;
}

// Escribir datos
$fila = 2;
foreach ($clientes as $cliente) {
    $sheet->setCellValue('A' . $fila, $cliente['id']);
    $sheet->setCellValue('B' . $fila, $cliente['nombre_completo']);
    $sheet->setCellValue('C' . $fila, $cliente['asunto']);
    $sheet->setCellValue('D' . $fila, $cliente['descripcion']);
    $sheet->setCellValue('E' . $fila, $cliente['numero1']);
    $sheet->setCellValue('F' . $fila, $cliente['numero2']);
    $sheet->setCellValue('G' . $fila, \PhpOffice\PhpSpreadsheet\Shared\Date::PHPToExcel(strtotime($cliente['fecha_registro'])));
    $sheet->setCellValue('H' . $fila, $cliente['usuario_asignado'] ?: 'No asignado');
    $sheet->setCellValue('I' . $fila, $cliente['closer_asignado'] ?: 'No asignado');
    $sheet->setCellValue('J' . $fila, $cliente['sala_nombre'] ?: 'Sin sala');
    $sheet->setCellValue('K' . $fila, $cliente['tiene_closer']);
    
    // Aplicar estilos a toda la fila
    $sheet->getStyle('A' . $fila . ':K' . $fila)->applyFromArray($dataStyle);
    
    // Formato especial para fecha
    $sheet->getStyle('G' . $fila)->applyFromArray($dateStyle);
    
    // Alternar colores de fila para mejor legibilidad
    if ($fila % 2 == 0) {
        $sheet->getStyle('A' . $fila . ':K' . $fila)->getFill()
            ->setFillType(Fill::FILL_SOLID)
            ->getStartColor()->setARGB('FFF8F9FA');
    }
    
    $fila++;
}

// Ajustar altura de filas automáticamente para descripción
$sheet->getStyle('D2:D' . $sheet->getHighestRow())
    ->getAlignment()->setWrapText(true);

// Congelar la primera fila (encabezados)
$sheet->freezePane('A2');

// Agregar filtros a los encabezados
$sheet->setAutoFilter('A1:K1');

// Configurar la columna de descripción para mejor visualización
$sheet->getColumnDimension('D')->setWidth(40);
$sheet->getColumnDimension('B')->setWidth(25);
$sheet->getColumnDimension('C')->setWidth(20);
$sheet->getColumnDimension('G')->setWidth(18);

// Crear información de filtros aplicados
$infoFiltros = "Reporte generado el: " . date('d/m/Y H:i:s') . "\n";
$infoFiltros .= "Generado por: " . $_SESSION['nombre'] . "\n";
$infoFiltros .= "Filtros aplicados: ";

if (!empty($fechaInicio)) $infoFiltros .= "Desde: $fechaInicio ";
if (!empty($fechaFin)) $infoFiltros .= "Hasta: $fechaFin ";
if (!empty($salaFiltro)) {
    // Obtener nombre de la sala
    $sqlSala = "SELECT nombre FROM salas WHERE id = :sala_id";
    $stmtSala = $pdo->prepare($sqlSala);
    $stmtSala->execute([':sala_id' => $salaFiltro]);
    $sala = $stmtSala->fetch(PDO::FETCH_ASSOC);
    $infoFiltros .= " | Sala: " . ($sala['nombre'] ?? 'Desconocida');
}

if ($acceso_todas_salas === 0) {
    $infoFiltros .= " | (Acceso limitado a " . count($salas_permitidas) . " sala(s))";
}

// Agregar información como comentario en la primera celda
$sheet->setCellValue('A' . ($fila + 2), 'Información del Reporte:');
$sheet->setCellValue('A' . ($fila + 3), $infoFiltros);
$sheet->mergeCells('A' . ($fila + 3) . ':K' . ($fila + 3));
$sheet->getStyle('A' . ($fila + 2))->getFont()->setBold(true);
$sheet->getStyle('A' . ($fila + 3))->getFont()->setItalic(true)->setSize(9);
$sheet->getStyle('A' . ($fila + 3))->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);

// Generar nombre del archivo con timestamp
$nombreArchivo = 'clientes_' . date('Ymd_His') . '.xlsx';

// Configurar headers para descarga
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment;filename="' . $nombreArchivo . '"');
header('Cache-Control: max-age=0');
header('Expires: 0');
header('Pragma: public');

// Crear el writer y enviar al navegador
$writer = new Xlsx($spreadsheet);
$writer->save('php://output');

// Cerrar conexión a la base de datos
$pdo = null;
exit;