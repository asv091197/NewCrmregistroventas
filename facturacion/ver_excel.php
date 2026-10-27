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

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;

date_default_timezone_set('America/Mexico_City');

// Iniciar sesión para verificar el rol del usuario
session_start();

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
$esAdmin = isset($_SESSION['rol']) && $_SESSION['rol'] === 'admin';

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
    // Si no hay fechas seleccionadas, usar el mes actual
    if (empty($fechaInicio)) {
        $fechaInicio = $fechaInicioMes;
    }
    if (empty($fechaFin)) {
        $fechaFin = $fechaFinMes;
    }
    
    // Validar y ajustar fechas si están fuera del mes actual
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
    // Validar que fecha inicio no sea mayor que fecha fin
    if (!empty($fechaInicio) && !empty($fechaFin) && $fechaInicio > $fechaFin) {
        $fechaInicio = $fechaInicioMes;
        $fechaFin = $fechaFinMes;
    }
}

// Construir consulta base - MODIFICADO PARA INCLUIR FRONT
$sql = "SELECT v.*, 
    CONCAT(v.cliente, ' ', v.apepat, ' ', COALESCE(v.apemat, '')) AS cliente_nombre,
    liner.apemat AS liner_nombre,
    liner.id AS liner_id,
    c1.apemat AS closer1_nombre,
    c1.id AS closer1_id,
    c2.apemat AS closer2_nombre,
    c2.id AS closer2_id,
    front.apemat AS front_nombre,
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

// Los filtros normales se aplican además del filtro del mes
if (!empty($fechaInicio)) {
    $conditions[] = "v.fecha >= ?";
    $params[] = $fechaInicio . ' 00:00:00';
}

if (!empty($fechaFin)) {
    $conditions[] = "v.fecha <= ?";
    $params[] = $fechaFin . ' 23:59:59';
}

// MODIFICADO: Manejar múltiples plataformas
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
                     CONCAT(liner.nombre, ' ', liner.apepat, ' ', COALESCE(liner.apemat, '')) LIKE ? OR
                     CONCAT(c1.nombre, ' ', c1.apepat, ' ', COALESCE(c1.apemat, '')) LIKE ? OR
                     CONCAT(c2.nombre, ' ', c2.apepat, ' ', COALESCE(c2.apemat, '')) LIKE ? OR
                     CONCAT(front.nombre, ' ', front.apepat, ' ', COALESCE(front.apemat, '')) LIKE ? OR
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

// MODIFICADO: Obtener los nombres de las plataformas filtradas
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

// Verificar si existen campos de comisión - MODIFICADO PARA INCLUIR FRONT
$tieneComisiones = false;
if (count($ventas) > 0) {
    $tieneComisiones = array_key_exists('ganancia_liner', $ventas[0]) || 
                        array_key_exists('ganancia_closer1', $ventas[0]) || 
                        array_key_exists('ganancia_closer2', $ventas[0]) ||
                        array_key_exists('ganancia_fullstack', $ventas[0]);
}

// ============================================================================
// DEFINIR ESTILOS SEGUROS (EVITAR PROBLEMAS XML)
// ============================================================================

// Función para aplicar estilos de forma segura
function applySafeStyle($sheet, $range, $styleType) {
    switch($styleType) {
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
            
        case 'info':
            $sheet->getStyle($range)->getFont()->setItalic(true);
            $sheet->getStyle($range)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('E8F4FD');
            $sheet->getStyle($range)->getFont()->getColor()->setRGB('0C63E4');
            break;
    }
}

// ============================================================================
// DEFINIR HEADERS SEGÚN FILTRO POR PLATAFORMA Y NÚMERO DE PLATAFORMAS
// ============================================================================

$esFiltroPlataforma = !empty($plataformaIds);
$multiplesPlataformas = count($plataformaIds) > 1;

// Definir headers según el tipo de filtro - MODIFICADO CON FRONT
if ($esFiltroPlataforma) {
    // HEADERS SIMPLIFICADOS PARA FILTRO POR PLATAFORMA
    $headers = [
        'Fecha', 
        'Venta Bruto', 
        'Plataforma'
    ];
    $ultimaColumna = 'C';
} else {
    // HEADERS COMPLETOS PARA OTROS FILTROS - MODIFICADO CON FRONT
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
        'Suma Comisión'
    ];
    $ultimaColumna = 'N';
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

// HOJA PRINCIPAL - RESUMEN GENERAL CON FÓRMULAS
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

// Agregar información sobre restricciones para usuarios no admin
if (!$esAdmin) {
    $tituloReporte .= ' - MES ACTUAL: ' . date('F Y');
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

$sheet->mergeCells('A3:' . $ultimaColumna . '3');
$sheet->setCellValue('A3', 'Filtro aplicado: ' . $filtroTexto . ' - Fecha de generación: ' . date('d/m/Y H:i:s'));
$sheet->getStyle('A3:' . $ultimaColumna . '3')->getFont()->setItalic(true)->setSize(10);
$sheet->getStyle('A3:' . $ultimaColumna . '3')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

// Nota sobre permisos y restricciones
$sheet->mergeCells('A4:' . $ultimaColumna . '4');
$instrucciones = 'INSTRUCCIONES: ';

// Agregar información de permisos
if (!$esAdmin) {
    $instrucciones .= '[MODO RESTRINGIDO: Solo datos del mes actual] | ';
} else {
    $instrucciones .= '[MODO ADMIN: Acceso completo] | ';
}

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

// Cambiar color según el tipo de usuario
if (!$esAdmin) {
    $sheet->getStyle('A4:' . $ultimaColumna . '4')->getFont()->getColor()->setRGB('E74C3C');
    $sheet->getStyle('A4:' . $ultimaColumna . '4')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('FDEBD0');
} else {
    $sheet->getStyle('A4:' . $ultimaColumna . '4')->getFont()->getColor()->setRGB('0C63E4');
    $sheet->getStyle('A4:' . $ultimaColumna . '4')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('E8F4FD');
}

$sheet->getStyle('A4:' . $ultimaColumna . '4')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

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
        uasort($ventasPorPlataforma, function($a, $b) {
            return strcmp($a['nombre'], $b['nombre']);
        });
        
        $totalesPlataforma = [];
        
        foreach ($ventasPorPlataforma as $plataformaId => $plataformaData) {
            $plataformaNombre = $plataformaData['nombre'];
            
            // TÍTULO DE LA PLATAFORMA
            $sheet->mergeCells('A' . $row . ':C' . $row);
            $sheet->setCellValue('A' . $row, 'PLATAFORMA: ' . strtoupper($plataformaNombre));
            applySafeStyle($sheet, 'A' . $row . ':C' . $row, 'plataformaHeader');
            $row++;
            
            // HEADERS DE LA TABLA
            $sheet->fromArray($headers, NULL, 'A' . $row);
            applySafeStyle($sheet, 'A' . $row . ':C' . $row, 'header');
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
                
                $totalPlataforma += $totalDia;
                $row++;
            }
            
            $filaFinPlataforma = $row - 1;
            
            // TOTAL DE LA PLATAFORMA
            if ($filaFinPlataforma >= $filaInicioPlataforma) {
                $sheet->setCellValue('A' . $row, 'TOTAL ' . $plataformaNombre . ':');
                $sheet->setCellValue('B' . $row, $totalPlataforma);
                $sheet->mergeCells('A' . $row . ':A' . $row);
                applySafeStyle($sheet, 'A' . $row . ':C' . $row, 'total');
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
            
            $sheet->mergeCells('A' . $row . ':C' . $row);
            $sheet->setCellValue('A' . $row, 'TOTAL GENERAL DE TODAS LAS PLATAFORMAS');
            $sheet->getStyle('A' . $row . ':C' . $row)->getFont()->setBold(true)->setSize(12);
            $row++;
            
            $sheet->setCellValue('A' . $row, 'TOTAL GENERAL:');
            $sheet->setCellValue('B' . $row, $granTotal);
            $sheet->mergeCells('A' . $row . ':A' . $row);
            applySafeStyle($sheet, 'A' . $row . ':C' . $row, 'granTotal');
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
                    'plataforma' => $venta['plataforma_nombre_completo'] ?? 'N/A'
                ];
            }
            $ventasAgrupadas[$fecha]['total_bruto'] += (float)$venta['precio'];
        }
        
        // HEADERS
        $sheet->fromArray($headers, NULL, 'A' . $row);
        applySafeStyle($sheet, 'A' . $row . ':C' . $row, 'header');
        $row++;
        
        // Ordenar por fecha descendente
        krsort($ventasAgrupadas);
        
        foreach ($ventasAgrupadas as $grupo) {
            $fechaFormateada = date('d/m/Y', strtotime($grupo['fecha']));
            
            $sheet->setCellValue('A' . $row, $fechaFormateada);                    // Fecha
            $sheet->setCellValue('B' . $row, $grupo['total_bruto']);               // Venta Bruto (total por fecha)
            $sheet->setCellValue('C' . $row, $grupo['plataforma']);                // Plataforma
            
            $row++;
        }
        
        // Fila de totales para una sola plataforma
        if ($row > 6) {
            $sheet->mergeCells('A' . $row . ':A' . $row);
            $sheet->setCellValue('A' . $row, 'TOTALES (automáticos):');
            
            // Solo Venta Bruto para plataforma
            $sheet->setCellValue('B' . $row, "=SUBTOTAL(9,B6:B" . ($row-1) . ")");
            
            applySafeStyle($sheet, 'A' . $row . ':C' . $row, 'total');
            $sheet->getStyle('B' . $row)->getNumberFormat()->setFormatCode('"$"#,##0.00');
        }
    }
} else {
    // PARA OTROS FILTROS: MOSTRAR TODAS LAS VENTAS INDIVIDUALMENTE - MODIFICADO CON FRONT
    $sheet->fromArray($headers, NULL, 'A' . $row);
    applySafeStyle($sheet, 'A' . $row . ':' . $ultimaColumna . $row, 'header');
    $row++;
    
    foreach ($ventas as $venta) {
        $fechaFormateada = date('d/m/Y', strtotime($venta['fecha']));
        
        $sheet->setCellValue('A' . $row, $fechaFormateada);                    // Fecha
        $sheet->setCellValue('B' . $row, (float)$venta['precio']);             // Venta Bruto
        $sheet->setCellValue('C' . $row, (float)$venta['precio_neto']);        // Venta Neto
        $sheet->setCellValue('D' . $row, $venta['cliente_nombre']);            // Cliente
        $sheet->setCellValue('E' . $row, $venta['liner_nombre'] ?? 'No asignado'); // Liner
        
        // Comisión Liner
        $comisionLiner = $tieneComisiones ? (float)($venta['ganancia_liner'] ?? 0) : 0;
        $sheet->setCellValue('F' . $row, $comisionLiner);                      // Comisión Liner
        
        $sheet->setCellValue('G' . $row, $venta['closer1_nombre'] ?? 'No asignado'); // Closer 1
        
        // Comisión Closer 1
        $comisionCloser1 = $tieneComisiones ? (float)($venta['ganancia_closer1'] ?? 0) : 0;
        $sheet->setCellValue('H' . $row, $comisionCloser1);                    // Comisión Closer 1
        
        $sheet->setCellValue('I' . $row, $venta['closer2_nombre'] ?? 'No asignado'); // Closer 2
        
        // Comisión Closer 2
        $comisionCloser2 = $tieneComisiones ? (float)($venta['ganancia_closer2'] ?? 0) : 0;
        $sheet->setCellValue('J' . $row, $comisionCloser2);                    // Comisión Closer 2
        
        // Front - NUEVA COLUMNA
        $sheet->setCellValue('K' . $row, $venta['front_nombre'] ?? 'No asignado'); // Front
        
        // Comisión Front - NUEVA COLUMNA
        $comisionFront = $tieneComisiones ? (float)($venta['ganancia_fullstack'] ?? 0) : 0;
        $sheet->setCellValue('L' . $row, $comisionFront);                      // Comisión Front
        
        $sheet->setCellValue('M' . $row, $venta['plataforma_nombre_completo'] ?? 'N/A'); // Plataforma
        
        // Suma Comisión (FÓRMULA) - MODIFICADA PARA INCLUIR FRONT
        $sheet->setCellValue('N' . $row, "=F{$row}+H{$row}+J{$row}+L{$row}");  // Suma Comisión (ahora incluye Front)
        
        $row++;
    }
}

// AGREGAR AUTOFILTER Y APLICAR ESTILOS
if ($row > 6) {
    if ($esFiltroPlataforma && $multiplesPlataformas) {
        // Para múltiples plataformas, no aplicar autofilter global (cada tabla tiene su propia estructura)
        // Pero aplicar formato numérico a todas las columnas de venta bruto
        $sheet->getStyle('B6:B' . ($row-1))->getNumberFormat()->setFormatCode('"$"#,##0.00');
    } else {
        // Aplicar AutoFilter a toda la tabla (encabezados y datos)
        $sheet->setAutoFilter('A5:' . $ultimaColumna . ($row-1));
        
        if (!$esFiltroPlataforma) {
            // Campos editables (amarillo) - solo para reporte completo - MODIFICADO CON FRONT
            applySafeStyle($sheet, 'B6:B' . ($row-1), 'editable');   // Venta Bruto
            applySafeStyle($sheet, 'C6:C' . ($row-1), 'editable');   // Venta Neto
            applySafeStyle($sheet, 'F6:F' . ($row-1), 'editable');   // Comisión Liner
            applySafeStyle($sheet, 'H6:H' . ($row-1), 'editable');   // Comisión Closer 1
            applySafeStyle($sheet, 'J6:J' . ($row-1), 'editable');   // Comisión Closer 2
            applySafeStyle($sheet, 'L6:L' . ($row-1), 'editable');   // Comisión Front - NUEVO
            
            // Campos con fórmulas (verde) - según el nuevo orden
            applySafeStyle($sheet, 'N6:N' . ($row-1), 'formula');    // Suma Comisión
        }
        
        // Formato de números
        if ($esFiltroPlataforma) {
            $sheet->getStyle('B6:B' . ($row-1))->getNumberFormat()->setFormatCode('"$"#,##0.00');
        } else {
            // MODIFICADO PARA INCLUIR NUEVAS COLUMNAS
            $numericColumns = ['B', 'C', 'F', 'H', 'J', 'L', 'N'];
            foreach ($numericColumns as $column) {
                $sheet->getStyle($column . '6:' . $column . ($row-1))
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
// GUARDAR Y DESCARGAR EL ARCHIVO - SOLO CON HOJA RESUMEN GENERAL
// ============================================================================

try {
    // Crear el escritor
    $writer = new Xlsx($spreadsheet);
    $fileName = 'Reporte_Ventas_Resumen_General_' . date('Y-m-d_His') . '.xlsx';

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