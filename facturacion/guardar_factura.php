<?php
require_once __DIR__ . '/../includes/db.php';
// Configuración de errores: se registran en log, no se muestran (romperían el JSON)
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/php_errors.log');

// Configuración para sesión persistente
ini_set('session.gc_probability', 1);
ini_set('session.gc_divisor', 1000);
ini_set('session.cookie_secure', 0);
ini_set('session.cookie_httponly', 1);
ini_set('session.use_strict_mode', 1);

date_default_timezone_set('America/Mexico_City');

// HEADER DEBE SER LO PRIMERO
header('Content-Type: application/json; charset=utf-8');

// Iniciar sesión - SEGUNDO
require_once __DIR__ . '/../session_config.php'; session_start();
require_once __DIR__ . '/../includes/registro_acciones.php';

// Verificar sesión - TERCERO
if (!isset($_SESSION['usuario_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Sesión expirada. Por favor, inicie sesión nuevamente.']);
    exit;
}

$usuario_creacion_id = $_SESSION['usuario_id'];
$es_admin = (($_SESSION['rol'] ?? '') === 'admin') || (($_SESSION['rol'] ?? '') === 'superadmin');

// FUNCIONES AUXILIARES
function obtenerSalaDesdeUsuario($usuario_id) {
    try {
        $conn = conexionPDO();
        
        $sql = "SELECT salas_permitidas FROM usuarios WHERE id = ? AND activo = 1";
        $stmt = $conn->prepare($sql);
        $stmt->execute([$usuario_id]);
        
        if ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $salas_permitidas = json_decode($row['salas_permitidas'] ?? '[]', true);
            if (!empty($salas_permitidas) && is_array($salas_permitidas)) {
                return intval($salas_permitidas[0]);
            }
        }
        
        return null;
        
    } catch (Exception $e) {
        error_log("Error en obtenerSalaDesdeUsuario: " . $e->getMessage());
        return null;
    }
}

function obtenerSalaPorDefecto() {
    try {
        $conn = conexionPDO();
        
        $result = $conn->query("SELECT id FROM salas WHERE activo = 1 ORDER BY id LIMIT 1");
        if ($result && $row = $result->fetch(PDO::FETCH_ASSOC)) {
            return intval($row['id']);
        }

        error_log("No se encontraron salas activas");
        return null;
        
    } catch (Exception $e) {
        error_log("Error en obtenerSalaPorDefecto: " . $e->getMessage());
        return null;
    }
}

function sendResponse($success, $message, $data = []) {
    $response = [
        'success' => $success,
        'message' => $message
    ];
    
    if (!empty($data)) {
        $response['data'] = $data;
    }
    
    echo json_encode($response);
    exit;
}

// OBTENER SALA_ID
$sala_id = null;

if ($es_admin && isset($_POST['sala_id']) && $_POST['sala_id'] !== '') {
    // ADMIN: USAR EL VALOR DEL FORMULARIO (DEL SELECTOR) y actualizar la sesión para mantener consistencia
    $sala_id = intval($_POST['sala_id']);
    $_SESSION['sala_id'] = $sala_id;
} elseif (isset($_SESSION['sala_id']) && $_SESSION['sala_id'] !== '') {
    // USUARIO NORMAL (o admin sin selector): USAR LA SESIÓN, se ignora el formulario
    $sala_id = intval($_SESSION['sala_id']);
}

// Si aún no tenemos sala_id, intentar obtenerla de la base de datos
if (!$sala_id) {
    $sala_id = obtenerSalaDesdeUsuario($usuario_creacion_id);

    if ($sala_id) {
        $_SESSION['sala_id'] = $sala_id;
    }
}

// Último recurso: sala por defecto
if (!$sala_id) {
    $sala_id = obtenerSalaPorDefecto();

    if ($sala_id) {
        $_SESSION['sala_id'] = $sala_id;
    }
}

if (!$sala_id) {
    error_log("ERROR guardar_factura: No se pudo determinar la sala (usuario $usuario_creacion_id)");
    sendResponse(false, "No se pudo determinar la sala. Por favor, contacte al administrador.");
}

try {
    // VERIFICAR MÉTODO
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        sendResponse(false, "Método no permitido");
    }

    // VALIDAR CAMPOS OBLIGATORIOS
    $requeridos = ['fecha', 'precio', 'cliente', 'apepat', 'celular', 'correo', 'producto', 'metodoPago', 'tipoVenta', 'tipoCliente'];
    $campos_faltantes = [];
    
    foreach ($requeridos as $campo) {
        if (!isset($_POST[$campo]) || trim($_POST[$campo]) === '') {
            $campos_faltantes[] = $campo;
        }
    }

    if (!empty($campos_faltantes)) {
        sendResponse(false, "Faltan campos obligatorios: " . implode(', ', $campos_faltantes));
    }

    // VALIDAR TIPO DE CLIENTE
    $tipo_cliente = $_POST['tipoCliente'];
    if (!in_array($tipo_cliente, ['nuevo', 'repack'])) {
        sendResponse(false, "Tipo de cliente no válido");
    }

    // VALIDAR TIPO DE VENTA - CORREGIDO PARA INCLUIR CAMPOS MANUALES
    $tipo_venta = $_POST['tipoVenta'];
    
    if ($tipo_venta === 'normal') {
        $liner_select = isset($_POST['liner']) ? trim($_POST['liner']) : '';
        $liner_manual = isset($_POST['liner_manual']) ? trim($_POST['liner_manual']) : '';
        
        // CORRECCIÓN: Verificar ambos campos (select y manual)
        $has_liner = (!empty($liner_select) || !empty($liner_manual));
        
        if (!$has_liner) {
            sendResponse(false, "Para venta normal debe seleccionar o ingresar un liner");
        }
        
    } elseif ($tipo_venta === 'fullstack') {
        $fullstack_select = isset($_POST['fullstack']) ? trim($_POST['fullstack']) : '';
        $fullstack_manual = isset($_POST['fullstack_manual']) ? trim($_POST['fullstack_manual']) : '';
        
        // CORRECCIÓN: Verificar ambos campos (select y manual)
        $has_fullstack = (!empty($fullstack_select) || !empty($fullstack_manual));
        
        if (!$has_fullstack) {
            sendResponse(false, "Para venta full stack debe seleccionar o ingresar un full stack");
        }
    } else {
        sendResponse(false, "Tipo de venta no válido");
    }

    // VALIDAR PRECIO
    $precio = str_replace(',', '', $_POST['precio']);
    if (!is_numeric($precio) || floatval($precio) <= 0) {
        sendResponse(false, "El precio debe ser un valor numérico mayor a 0");
    }
    $precio = floatval($precio);

    // CAPTURAR DATOS
    $fecha = $_POST['fecha'];
    $cliente = trim($_POST['cliente']);
    $apepat = trim($_POST['apepat']);
    $apemat = isset($_POST['apemat']) ? trim($_POST['apemat']) : null;
    $celular = trim($_POST['celular']);
    $correo = trim($_POST['correo']);
    $producto = trim($_POST['producto']);
    $metodo_pago = $_POST['metodoPago'];
    $plataforma_id = isset($_POST['plataforma']) && $_POST['plataforma'] !== '' ? intval($_POST['plataforma']) : null;

    // VALIDAR PLATAFORMA SI ES REQUERIDA
    if (($metodo_pago === 'SPEI' || $metodo_pago === 'TDC') && empty($plataforma_id)) {
        sendResponse(false, "Debe seleccionar una plataforma para el método de pago " . $metodo_pago);
    }

    // MANEJAR USUARIOS (LINER/CLOSERS/FULLSTACK)
    $liner_id = null;
    $liner_nombre_manual = null;
    $closer1_id = null;
    $closer1_nombre_manual = null;
    $closer2_id = null;
    $closer2_nombre_manual = null;
    $full_stack_id = null;
    $full_stack_nombre_manual = null;

    if ($tipo_venta === 'normal') {
        // Liner
        if (isset($_POST['liner_manual']) && !empty(trim($_POST['liner_manual']))) {
            $liner_nombre_manual = trim($_POST['liner_manual']);
        } else if (isset($_POST['liner']) && !empty(trim($_POST['liner']))) {
            $liner_id = intval($_POST['liner']);
        }
        
        // Closer 1
        if (isset($_POST['closer1_manual']) && !empty(trim($_POST['closer1_manual']))) {
            $closer1_nombre_manual = trim($_POST['closer1_manual']);
        } else if (isset($_POST['closer1']) && !empty(trim($_POST['closer1']))) {
            $closer1_id = intval($_POST['closer1']);
        }
        
        // Closer 2
        if (isset($_POST['closer2_manual']) && !empty(trim($_POST['closer2_manual']))) {
            $closer2_nombre_manual = trim($_POST['closer2_manual']);
        } else if (isset($_POST['closer2']) && !empty(trim($_POST['closer2']))) {
            $closer2_id = intval($_POST['closer2']);
        }
        
    } elseif ($tipo_venta === 'fullstack') {
        // Full Stack
        if (isset($_POST['fullstack_manual']) && !empty(trim($_POST['fullstack_manual']))) {
            $full_stack_nombre_manual = trim($_POST['fullstack_manual']);
        } else if (isset($_POST['fullstack']) && !empty(trim($_POST['fullstack']))) {
            $full_stack_id = intval($_POST['fullstack']);
        }
    }

    // CALCULAR PRECIO NETO
    $precio_neto = round($precio * 0.82, 10);

    // CONEXIÓN A LA BASE DE DATOS
    $conn = conexionPDO();

    // OBTENER PORCENTAJES DE LA PLATAFORMA
    $porcent_front = null;
    $porcent_linea = null;
    $porcent_cierre = null;

    if ($plataforma_id) {
        $sql_plataforma = "SELECT porcent_front, porcent_linea, porcent_cierre FROM plataforma WHERE id = ? AND activo = 1";
        $stmt_plat = $conn->prepare($sql_plataforma);
        $stmt_plat->execute([$plataforma_id]);
        $plataforma_data = $stmt_plat->fetch(PDO::FETCH_ASSOC);

        if ($plataforma_data) {
            $porcent_front = $plataforma_data['porcent_front'];
            $porcent_linea = $plataforma_data['porcent_linea'];
            $porcent_cierre = $plataforma_data['porcent_cierre'];
        }
        $stmt_plat->closeCursor();
    }

    // CALCULAR GANANCIAS - CORREGIDO PARA INCLUIR NOMBRES MANUALES
    $ganancia_liner = null;
    $ganancia_closer1 = null;
    $ganancia_closer2 = null;
    $ganancia_fullstack = null;

    if ($tipo_venta === 'normal' && $porcent_linea !== null && $porcent_cierre !== null) {
        $ganancia_linea_total = $precio_neto * ($porcent_linea / 100);
        $ganancia_cierre_total = $precio_neto * ($porcent_cierre / 100);

        // Verificar tanto IDs como nombres manuales
        $has_liner = ($liner_id || $liner_nombre_manual);
        $has_closer1 = ($closer1_id || $closer1_nombre_manual);
        $has_closer2 = ($closer2_id || $closer2_nombre_manual);

        if ($has_liner) {
            if (!$has_closer1 && !$has_closer2) {
                // Solo liner (hace línea y cierre)
                $ganancia_liner = $ganancia_linea_total + $ganancia_cierre_total;
            } elseif ($has_closer1 && !$has_closer2) {
                // Liner + 1 closer
                $ganancia_liner = $ganancia_linea_total;
                $ganancia_closer1 = $ganancia_cierre_total;
            } elseif ($has_closer1 && $has_closer2) {
                // Liner + 2 closers
                $ganancia_liner = $ganancia_linea_total;
                $ganancia_closer1 = $ganancia_cierre_total * 0.5;
                $ganancia_closer2 = $ganancia_cierre_total * 0.5;
            }
        }

    } elseif ($tipo_venta === 'fullstack' && $porcent_front !== null) {
        // Verificar tanto ID como nombre manual
        if ($full_stack_id || $full_stack_nombre_manual) {
            $ganancia_fullstack = $precio_neto * ($porcent_front / 100);
        }
    }

    // PREPARAR INSERT
    $check_columns = $conn->query("SHOW COLUMNS FROM ventas_facturacion LIKE 'usuario_creacion_id'");
    $tiene_columnas_usuario = (count($check_columns->fetchAll(PDO::FETCH_ASSOC)) > 0);
    
    if ($tiene_columnas_usuario) {
        $sql = "INSERT INTO ventas_facturacion (
            fecha, precio, cliente, apepat, apemat, celular, correo, producto,
            liner_id, liner_nombre_manual, 
            closer1_id, closer1_nombre_manual, 
            closer2_id, closer2_nombre_manual, 
            plataforma_id, precio_neto,
            ganancia_liner, ganancia_closer1, ganancia_closer2,
            full_stack_id, full_stack_nombre_manual, ganancia_fullstack,
            usuario_creacion_id, tipo_venta, sala_id
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";

        $stmt = $conn->prepare($sql);
        
        $parametros = [
            $fecha, $precio, $cliente, $apepat, $apemat, $celular, $correo, $producto,
            $liner_id, $liner_nombre_manual, 
            $closer1_id, $closer1_nombre_manual, 
            $closer2_id, $closer2_nombre_manual, 
            $plataforma_id, $precio_neto,
            $ganancia_liner, $ganancia_closer1, $ganancia_closer2,
            $full_stack_id, $full_stack_nombre_manual, $ganancia_fullstack,
            $usuario_creacion_id, $tipo_cliente, $sala_id
        ];
    } else {
        $sql = "INSERT INTO ventas_facturacion (
            fecha, precio, cliente, apepat, apemat, celular, correo, producto,
            liner_id, liner_nombre_manual, 
            closer1_id, closer1_nombre_manual, 
            closer2_id, closer2_nombre_manual, 
            plataforma_id, precio_neto,
            ganancia_liner, ganancia_closer1, ganancia_closer2,
            full_stack_id, full_stack_nombre_manual, ganancia_fullstack,
            tipo_venta, sala_id
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";

        $stmt = $conn->prepare($sql);
        
        $parametros = [
            $fecha, $precio, $cliente, $apepat, $apemat, $celular, $correo, $producto,
            $liner_id, $liner_nombre_manual, 
            $closer1_id, $closer1_nombre_manual, 
            $closer2_id, $closer2_nombre_manual, 
            $plataforma_id, $precio_neto,
            $ganancia_liner, $ganancia_closer1, $ganancia_closer2,
            $full_stack_id, $full_stack_nombre_manual, $ganancia_fullstack,
            $tipo_cliente, $sala_id
        ];
    }

    // EJECUTAR INSERT
    if (!$stmt->execute($parametros)) {
        throw new Exception("Error al ejecutar la consulta");
    }

    $idInsertado = (int) $conn->lastInsertId();

    registrarAccion($conn, 'INSERT', 'ventas_facturacion', $idInsertado, [
        'cliente' => trim("$cliente $apepat $apemat"),
        'producto' => $producto,
        'precio' => $precio,
        'tipo_venta' => $tipo_cliente,
        'plataforma_id' => $plataforma_id,
        'sala_id' => $sala_id
    ]);

    sendResponse(true, "✅ Venta registrada correctamente. ID: " . $idInsertado, ['id' => $idInsertado]);

} catch (Exception $e) {
    error_log("❌ ERROR CRÍTICO en guardar_factura: " . $e->getMessage());
    error_log("Stack trace: " . $e->getTraceAsString());
    
    http_response_code(500);
    
    echo json_encode([
        'success' => false, 
        'message' => 'Error interno del servidor: ' . $e->getMessage()
    ]);
    exit;
}
?>