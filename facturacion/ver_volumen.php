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

// Función para limpiar símbolos de los campos de texto
function limpiarTexto($texto) {
    if ($texto === null || $texto === '') {
        return $texto;
    }
    return preg_replace('/[^\p{L}\p{N}\s]/u', '', $texto);
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

// Manejar múltiples plataformas
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

// Consulta SQL
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
    p.metodo_pago AS metodo_pago_plataforma
    FROM ventas_facturacion v
    LEFT JOIN usuarios liner ON v.liner_id = liner.id
    LEFT JOIN usuarios c1 ON v.closer1_id = c1.id
    LEFT JOIN usuarios c2 ON v.closer2_id = c2.id
    LEFT JOIN usuarios fs ON v.full_stack_id = fs.id
    LEFT JOIN plataforma p ON v.plataforma_id = p.id" . $whereClause . " ORDER BY v.fecha DESC";

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
    }
}

// ============================================================================
// PROCESAR USUARIOS CON GANANCIAS PARA GANANCIA VOLUMEN
// ============================================================================

$usuariosConGanancias = [];
if ($tieneComisiones && !empty($ventas)) {
    foreach ($ventas as $venta) {
        // Procesar Liner (solo apellido materno)
        if (!empty($venta['liner_nombre']) && $venta['liner_nombre'] !== 'No asignado') {
            $userId = $venta['liner_id'];
            if (!isset($usuariosConGanancias[$userId])) {
                $usuariosConGanancias[$userId] = [
                    'nombre' => $venta['liner_nombre'],
                    'titulo_limpio' => substr(preg_replace('/[^\w\sáéíóúÁÉÍÓÚñÑ]/u', '', $venta['liner_nombre']), 0, 25),
                    'roles' => [], 
                    'ventas_count' => 0, 
                    'ganancia_total' => 0, 
                    'ganancia_por_rol' => ['Liner' => 0, 'Closer 1' => 0, 'Closer 2' => 0, 'Front' => 0],
                    'ventas' => [],
                    'tiene_over' => false,
                    'over_config' => null
                ];
            }
            $gananciaLiner = (float)($venta['ganancia_liner'] ?? 0);
            if ($gananciaLiner > 0) {
                $usuariosConGanancias[$userId]['ventas_count']++;
                $usuariosConGanancias[$userId]['ganancia_total'] += $gananciaLiner;
                $usuariosConGanancias[$userId]['ganancia_por_rol']['Liner'] += $gananciaLiner;
                if (!in_array('Liner', $usuariosConGanancias[$userId]['roles'])) {
                    $usuariosConGanancias[$userId]['roles'][] = 'Liner';
                }
                $usuariosConGanancias[$userId]['ventas'][] = [
                    'id' => $venta['id'],
                    'fecha' => $venta['fecha'], 
                    'cliente_nombre' => $venta['cliente_nombre'],
                    'rol_en_venta' => 'Liner', 
                    'ganancia_en_venta' => $gananciaLiner,
                    'plataforma_nombre_completo' => $venta['plataforma_nombre_completo'] ?? 'N/A',
                    'precio_neto' => $venta['precio_neto'],
                    'venta_bruto' => $venta['precio']
                ];
            }
        }
        
        // Procesar Closer 1 (solo apellido materno)
        if (!empty($venta['closer1_nombre']) && $venta['closer1_nombre'] !== 'No asignado') {
            $userId = $venta['closer1_id'];
            if (!isset($usuariosConGanancias[$userId])) {
                $usuariosConGanancias[$userId] = [
                    'nombre' => $venta['closer1_nombre'],
                    'titulo_limpio' => substr(preg_replace('/[^\w\sáéíóúÁÉÍÓÚñÑ]/u', '', $venta['closer1_nombre']), 0, 25),
                    'roles' => [], 
                    'ventas_count' => 0, 
                    'ganancia_total' => 0, 
                    'ganancia_por_rol' => ['Liner' => 0, 'Closer 1' => 0, 'Closer 2' => 0, 'Front' => 0],
                    'ventas' => [],
                    'tiene_over' => false,
                    'over_config' => null
                ];
            }
            $gananciaCloser1 = (float)($venta['ganancia_closer1'] ?? 0);
            if ($gananciaCloser1 > 0) {
                $usuariosConGanancias[$userId]['ventas_count']++;
                $usuariosConGanancias[$userId]['ganancia_total'] += $gananciaCloser1;
                $usuariosConGanancias[$userId]['ganancia_por_rol']['Closer 1'] += $gananciaCloser1;
                if (!in_array('Closer 1', $usuariosConGanancias[$userId]['roles'])) {
                    $usuariosConGanancias[$userId]['roles'][] = 'Closer 1';
                }
                $usuariosConGanancias[$userId]['ventas'][] = [
                    'id' => $venta['id'],
                    'fecha' => $venta['fecha'], 
                    'cliente_nombre' => $venta['cliente_nombre'],
                    'rol_en_venta' => 'Closer 1', 
                    'ganancia_en_venta' => $gananciaCloser1,
                    'plataforma_nombre_completo' => $venta['plataforma_nombre_completo'] ?? 'N/A',
                    'precio_neto' => $venta['precio_neto'],
                    'venta_bruto' => $venta['precio']
                ];
            }
        }
        
        // Procesar Closer 2 (solo apellido materno)
        if (!empty($venta['closer2_nombre']) && $venta['closer2_nombre'] !== 'No asignado') {
            $userId = $venta['closer2_id'];
            if (!isset($usuariosConGanancias[$userId])) {
                $usuariosConGanancias[$userId] = [
                    'nombre' => $venta['closer2_nombre'],
                    'titulo_limpio' => substr(preg_replace('/[^\w\sáéíóúÁÉÍÓÚñÑ]/u', '', $venta['closer2_nombre']), 0, 25),
                    'roles' => [], 
                    'ventas_count' => 0, 
                    'ganancia_total' => 0, 
                    'ganancia_por_rol' => ['Liner' => 0, 'Closer 1' => 0, 'Closer 2' => 0, 'Front' => 0],
                    'ventas' => [],
                    'tiene_over' => false,
                    'over_config' => null
                ];
            }
            $gananciaCloser2 = (float)($venta['ganancia_closer2'] ?? 0);
            if ($gananciaCloser2 > 0) {
                $usuariosConGanancias[$userId]['ventas_count']++;
                $usuariosConGanancias[$userId]['ganancia_total'] += $gananciaCloser2;
                $usuariosConGanancias[$userId]['ganancia_por_rol']['Closer 2'] += $gananciaCloser2;
                if (!in_array('Closer 2', $usuariosConGanancias[$userId]['roles'])) {
                    $usuariosConGanancias[$userId]['roles'][] = 'Closer 2';
                }
                $usuariosConGanancias[$userId]['ventas'][] = [
                    'id' => $venta['id'],
                    'fecha' => $venta['fecha'], 
                    'cliente_nombre' => $venta['cliente_nombre'],
                    'rol_en_venta' => 'Closer 2', 
                    'ganancia_en_venta' => $gananciaCloser2,
                    'plataforma_nombre_completo' => $venta['plataforma_nombre_completo'] ?? 'N/A',
                    'precio_neto' => $venta['precio_neto'],
                    'venta_bruto' => $venta['precio']
                ];
            }
        }
        
        // Procesar Front (solo apellido materno)
        if (!empty($venta['front_nombre']) && $venta['front_nombre'] !== 'No asignado') {
            $userId = $venta['full_stack_id'];
            if (!isset($usuariosConGanancias[$userId])) {
                $usuariosConGanancias[$userId] = [
                    'nombre' => $venta['front_nombre'],
                    'titulo_limpio' => substr(preg_replace('/[^\w\sáéíóúÁÉÍÓÚñÑ]/u', '', $venta['front_nombre']), 0, 25),
                    'roles' => [], 
                    'ventas_count' => 0, 
                    'ganancia_total' => 0, 
                    'ganancia_por_rol' => ['Liner' => 0, 'Closer 1' => 0, 'Closer 2' => 0, 'Front' => 0],
                    'ventas' => [],
                    'tiene_over' => false,
                    'over_config' => null
                ];
            }
            $gananciaFront = (float)($venta['ganancia_fullstack'] ?? 0);
            if ($gananciaFront > 0) {
                $usuariosConGanancias[$userId]['ventas_count']++;
                $usuariosConGanancias[$userId]['ganancia_total'] += $gananciaFront;
                $usuariosConGanancias[$userId]['ganancia_por_rol']['Front'] += $gananciaFront;
                if (!in_array('Front', $usuariosConGanancias[$userId]['roles'])) {
                    $usuariosConGanancias[$userId]['roles'][] = 'Front';
                }
                $usuariosConGanancias[$userId]['ventas'][] = [
                    'id' => $venta['id'],
                    'fecha' => $venta['fecha'], 
                    'cliente_nombre' => $venta['cliente_nombre'],
                    'rol_en_venta' => 'Front', 
                    'ganancia_en_venta' => $gananciaFront,
                    'plataforma_nombre_completo' => $venta['plataforma_nombre_completo'] ?? 'N/A',
                    'precio_neto' => $venta['precio_neto'],
                    'venta_bruto' => $venta['precio']
                ];
            }
        }
    }
}

// Crear un nuevo libro de Excel
$spreadsheet = new Spreadsheet();

// Configurar propiedades del documento
$spreadsheet->getProperties()
    ->setCreator('Sistema CRM - Grupo Ideas')
    ->setLastModifiedBy('Sistema CRM')
    ->setTitle('Reporte de Ganancia Volumen')
    ->setSubject('Reporte de Ganancia Volumen')
    ->setDescription('Reporte generado automáticamente por el sistema CRM')
    ->setKeywords('ganancia volumen reporte excel crm')
    ->setCategory('Reporte');

// Configurar estilo por defecto para evitar problemas
$spreadsheet->getDefaultStyle()->getFont()->setName('Arial');
$spreadsheet->getDefaultStyle()->getFont()->setSize(10);

// HOJA PRINCIPAL - GANANCIA VOLUMEN
$sheet = $spreadsheet->getActiveSheet();
$sheet->setTitle('Ganancia Volumen');

// Configurar página
$sheet->getPageSetup()
    ->setOrientation(PageSetup::ORIENTATION_LANDSCAPE)
    ->setPaperSize(PageSetup::PAPERSIZE_A4)
    ->setFitToWidth(1)
    ->setFitToHeight(0);

// ============================================================================
// HOJA: GANANCIA VOLUMEN - COMPLETAMENTE ACTUALIZADO
// ============================================================================

if (!empty($usuariosConGanancias)) {
    try {
        $volumenSheet = $spreadsheet->getActiveSheet();
        $volumenSheet->setTitle('Ganancia Volumen');
        
        $volumenSheet->getPageSetup()
            ->setOrientation(PageSetup::ORIENTATION_LANDSCAPE)
            ->setPaperSize(PageSetup::PAPERSIZE_A4)
            ->setFitToWidth(1)
            ->setFitToHeight(0);
        
        // Título
        $volumenSheet->mergeCells('A1:G2');
        $volumenSheet->setCellValue('A1', 'RESUMEN DE GANANCIA VOLUMEN POR USUARIO Y ROL');
        applySafeStyle($volumenSheet, 'A1:G2', 'title');
        
        // Información de filtros
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

        $volumenSheet->mergeCells('A3:G3');
        $volumenSheet->setCellValue('A3', 'Filtro aplicado: ' . $filtroTexto . ' - Fecha de generación: ' . date('d/m/Y H:i:s'));
        $volumenSheet->getStyle('A3:G3')->getFont()->setItalic(true)->setSize(10);
        $volumenSheet->getStyle('A3:G3')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        
        // Nota explicativa
        $volumenSheet->mergeCells('A4:G4');
        $volumenSheet->setCellValue('A4', 'GANANCIA VOLUMEN: Liner=Venta Bruta/2 | Closer 1=Venta Bruta/2 | Closer 2=Venta Bruta/3 | Front=Venta Bruta');
        $volumenSheet->getStyle('A4:G4')->getFont()->setBold(true)->setSize(10);
        $volumenSheet->getStyle('A4:G4')->getFont()->getColor()->setRGB('E74C3C');
        $volumenSheet->getStyle('A4:G4')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('FDEBD0');
        $volumenSheet->getStyle('A4:G4')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        
        // Headers de la tabla
        $volumenHeaders = ['Usuario', 'GANANCIA VOLUMEN Liner', 'GANANCIA VOLUMEN Closer 1', 'GANANCIA VOLUMEN Closer 2', 'GANANCIA VOLUMEN Front', 'TOTAL USUARIO', 'VENTAS'];
        $volumenSheet->fromArray($volumenHeaders, NULL, 'A5');
        applySafeStyle($volumenSheet, 'A5:G5', 'header');
        
        // Llenar datos
        $volumenRow = 6;
        
        foreach ($usuariosConGanancias as $userId => $usuario) {
            $volumenSheet->setCellValue('A' . $volumenRow, $usuario['nombre']);
            
            // Calcular ganancia volumen desde los datos ya procesados
            $gananciaVolumenLiner = 0;
            $gananciaVolumenCloser1 = 0;
            $gananciaVolumenCloser2 = 0;
            $gananciaVolumenFront = 0;
            
            foreach ($usuario['ventas'] as $venta) {
                $ventaBruta = (float)$venta['venta_bruto'];
                
                switch($venta['rol_en_venta']) {
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
            
            // Insertar valores calculados
            $volumenSheet->setCellValue('B' . $volumenRow, $gananciaVolumenLiner);
            $volumenSheet->setCellValue('C' . $volumenRow, $gananciaVolumenCloser1);
            $volumenSheet->setCellValue('D' . $volumenRow, $gananciaVolumenCloser2);
            $volumenSheet->setCellValue('E' . $volumenRow, $gananciaVolumenFront);
            
            // FÓRMULA PARA TOTAL USUARIO
            $volumenSheet->setCellValue('F' . $volumenRow, "=SUM(B" . $volumenRow . ":E" . $volumenRow . ")");
            
            // Número de ventas
            $volumenSheet->setCellValue('G' . $volumenRow, $usuario['ventas_count']);
            
            $volumenRow++;
        }
        
        // ============================================================================
        // FILA DE TOTALES GENERALES
        // ============================================================================
        
        if ($volumenRow > 6) {
            $filaTotales = $volumenRow;
            
            $volumenSheet->setCellValue('A' . $filaTotales, 'TOTALES GENERALES:');
            
            // FÓRMULAS PARA TOTALES GENERALES
            $volumenSheet->setCellValue('B' . $filaTotales, "=SUM(B6:B" . ($volumenRow-1) . ")"); // Total Liner
            $volumenSheet->setCellValue('C' . $filaTotales, "=SUM(C6:C" . ($volumenRow-1) . ")"); // Total Closer 1
            $volumenSheet->setCellValue('D' . $filaTotales, "=SUM(D6:D" . ($volumenRow-1) . ")"); // Total Closer 2
            $volumenSheet->setCellValue('E' . $filaTotales, "=SUM(E6:E" . ($volumenRow-1) . ")"); // Total Front
            $volumenSheet->setCellValue('F' . $filaTotales, "=SUM(F6:F" . ($volumenRow-1) . ")"); // Total General
            $volumenSheet->setCellValue('G' . $filaTotales, "=SUM(G6:G" . ($volumenRow-1) . ")"); // Total Ventas
            
            applySafeStyle($volumenSheet, 'A' . $filaTotales . ':G' . $filaTotales, 'granTotal');
            
            // Aplicar estilo de fórmulas a los totales
            applySafeStyle($volumenSheet, 'B' . $filaTotales . ':G' . $filaTotales, 'formula');
        }
        
        // ============================================================================
        // ESTADÍSTICAS ADICIONALES
        // ============================================================================
        
        $statsRow = $volumenRow + 2;
        
        $volumenSheet->setCellValue('A' . $statsRow, 'ESTADÍSTICAS DE GANANCIA VOLUMEN:');
        $volumenSheet->getStyle('A' . $statsRow)->getFont()->setBold(true)->setSize(12);
        
        $statsRow++;
        $volumenSheet->setCellValue('A' . $statsRow, 'Total Ganancia Volumen General:');
        $volumenSheet->setCellValue('B' . $statsRow, "=F" . $filaTotales);
        $volumenSheet->getStyle('B' . $statsRow)->getNumberFormat()->setFormatCode('"$"#,##0.00');
        applySafeStyle($volumenSheet, 'B' . $statsRow, 'formula');
        
        $statsRow++;
        $volumenSheet->setCellValue('A' . $statsRow, 'Promedio Ganancia Volumen por Usuario:');
        $volumenSheet->setCellValue('B' . $statsRow, "=F" . $filaTotales . "/" . ($volumenRow - 6));
        $volumenSheet->getStyle('B' . $statsRow)->getNumberFormat()->setFormatCode('"$"#,##0.00');
        applySafeStyle($volumenSheet, 'B' . $statsRow, 'formula');
        
        $statsRow++;
        $volumenSheet->setCellValue('A' . $statsRow, 'Total Ventas:');
        $volumenSheet->setCellValue('B' . $statsRow, "=G" . $filaTotales);
        applySafeStyle($volumenSheet, 'B' . $statsRow, 'formula');
        
        $statsRow++;
        $volumenSheet->setCellValue('A' . $statsRow, 'Promedio Ventas por Usuario:');
        $volumenSheet->setCellValue('B' . $statsRow, "=G" . $filaTotales . "/" . ($volumenRow - 6));
        $volumenSheet->getStyle('B' . $statsRow)->getNumberFormat()->setFormatCode('0.00');
        applySafeStyle($volumenSheet, 'B' . $statsRow, 'formula');

        // ============================================================================
        // DISTRIBUCIÓN POR ROL - TABLA RESUMEN
        // ============================================================================
        
        $distribucionRow = $statsRow + 2;
        
        $volumenSheet->setCellValue('A' . $distribucionRow, 'DISTRIBUCIÓN DE GANANCIA VOLUMEN POR ROL:');
        $volumenSheet->getStyle('A' . $distribucionRow)->getFont()->setBold(true)->setSize(12);
        
        $distribucionRow++;
        $volumenSheet->setCellValue('A' . $distribucionRow, 'ROL');
        $volumenSheet->setCellValue('B' . $distribucionRow, 'MONTO');
        $volumenSheet->setCellValue('C' . $distribucionRow, 'PORCENTAJE');
        applySafeStyle($volumenSheet, 'A' . $distribucionRow . ':C' . $distribucionRow, 'header');
        
        $distribucionRow++;
        $volumenSheet->setCellValue('A' . $distribucionRow, 'Liner');
        $volumenSheet->setCellValue('B' . $distribucionRow, "=B" . $filaTotales);
        $volumenSheet->setCellValue('C' . $distribucionRow, "=B" . $distribucionRow . "/$F$" . $filaTotales);
        
        $distribucionRow++;
        $volumenSheet->setCellValue('A' . $distribucionRow, 'Closer 1');
        $volumenSheet->setCellValue('B' . $distribucionRow, "=C" . $filaTotales);
        $volumenSheet->setCellValue('C' . $distribucionRow, "=B" . $distribucionRow . "/$F$" . $filaTotales);
        
        $distribucionRow++;
        $volumenSheet->setCellValue('A' . $distribucionRow, 'Closer 2');
        $volumenSheet->setCellValue('B' . $distribucionRow, "=D" . $filaTotales);
        $volumenSheet->setCellValue('C' . $distribucionRow, "=B" . $distribucionRow . "/$F$" . $filaTotales);
        
        $distribucionRow++;
        $volumenSheet->setCellValue('A' . $distribucionRow, 'Front');
        $volumenSheet->setCellValue('B' . $distribucionRow, "=E" . $filaTotales);
        $volumenSheet->setCellValue('C' . $distribucionRow, "=B" . $distribucionRow . "/$F$" . $filaTotales);
        
        // Formato para la distribución
        $volumenSheet->getStyle('B' . ($distribucionRow-4) . ':B' . $distribucionRow)->getNumberFormat()->setFormatCode('"$"#,##0.00');
        $volumenSheet->getStyle('C' . ($distribucionRow-4) . ':C' . $distribucionRow)->getNumberFormat()->setFormatCode('0.00%"');
        applySafeStyle($volumenSheet, 'B' . ($distribucionRow-4) . ':C' . $distribucionRow, 'formula');
        
        // Formato numérico para todas las celdas de montos
        if ($volumenRow > 6) {
            $volumenSheet->getStyle('B6:F' . $volumenRow)->getNumberFormat()->setFormatCode('"$"#,##0.00');
        }
        
        // Aplicar estilo de fórmulas a todas las celdas con fórmulas
        if ($volumenRow > 6) {
            applySafeStyle($volumenSheet, 'B6:F' . $volumenRow, 'formula');
            applySafeStyle($volumenSheet, 'G6:G' . $volumenRow, 'formula');
        }
        
        // Autoajustar columnas
        foreach (range('A', 'G') as $col) {
            $volumenSheet->getColumnDimension($col)->setAutoSize(true);
        }
        
        // Congelar paneles para mejor navegación
        $volumenSheet->freezePane('A6');
        
        // Agregar Autofilter para facilitar el filtrado
        if ($volumenRow > 6) {
            $volumenSheet->setAutoFilter('A5:G' . ($volumenRow-1));
        }
        
    } catch (Exception $e) {
        error_log("Error al crear hoja de Ganancia Volumen: " . $e->getMessage());
        
        // Crear hoja básica en caso de error
        $volumenSheet->setCellValue('A1', 'ERROR AL GENERAR GANANCIA VOLUMEN');
        $volumenSheet->setCellValue('A3', 'Error: ' . $e->getMessage());
    }
} else {
    $sheet->setCellValue('A1', 'GANANCIA VOLUMEN');
    $sheet->setCellValue('A3', 'No hay datos de ganancias para generar el reporte.');
    $sheet->setCellValue('A4', 'Verifique que existan comisiones registradas en las ventas.');
}

// ============================================================================
// GUARDAR Y DESCARGAR EL ARCHIVO
// ============================================================================

try {
    // Crear el escritor
    $writer = new Xlsx($spreadsheet);
    $fileName = 'Reporte_Ganancia_Volumen_' . date('Y-m-d_His') . '.xlsx';

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