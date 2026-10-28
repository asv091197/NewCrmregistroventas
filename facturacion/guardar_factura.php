<?php
require_once __DIR__ . '/../includes/db.php';
// Configuración para mostrar TODOS los errores - PRIMERO
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
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
                $sala = intval($salas_permitidas[0]);
                error_log("Sala obtenida de salas_permitidas: " . $sala);
                return $sala;
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
            $sala = intval($row['id']);
            error_log("Sala por defecto obtenida: " . $sala);
            return $sala;
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

// DEBUG: Verificar qué datos están llegando
error_log("=== DEBUG GUARDAR FACTURA ===");
error_log("POST recibido: " . print_r($_POST, true));
error_log("SESSION usuario_id: " . ($_SESSION['usuario_id'] ?? 'NO'));
error_log("SESSION sala_id: " . ($_SESSION['sala_id'] ?? 'NO'));
error_log("Usuario es admin: " . ($es_admin ? 'SI' : 'NO'));

// OBTENER SALA_ID - LÓGICA CORREGIDA
$sala_id = null;

if ($es_admin) {
    // ADMIN: USAR SIEMPRE EL VALOR DEL FORMULARIO (DEL SELECTOR)
    if (isset($_POST['sala_id']) && $_POST['sala_id'] !== '') {
        $sala_id = intval($_POST['sala_id']);
        error_log("✅ ADMIN: Sala ID del formulario (selector): " . $sala_id);
        
        // IMPORTANTE: Actualizar también la sesión para mantener consistencia
        $_SESSION['sala_id'] = $sala_id;
        error_log("✅ ADMIN: Sesión actualizada con sala_id: " . $sala_id);
    } else {
        error_log("❌ ADMIN: No se recibió sala_id en el formulario");
        
        // Si no viene del formulario, intentar de la sesión
        if (isset($_SESSION['sala_id']) && $_SESSION['sala_id'] !== '') {
            $sala_id = intval($_SESSION['sala_id']);
            error_log("⚠️ ADMIN: Usando sala_id de sesión como fallback: " . $sala_id);
        }
    }
} else {
    // USUARIO NORMAL: USAR SIEMPRE LA SESIÓN
    if (isset($_SESSION['sala_id']) && $_SESSION['sala_id'] !== '') {
        $sala_id = intval($_SESSION['sala_id']);
        error_log("✅ USUARIO: Sala ID de la sesión: " . $sala_id);
        
        // Verificar que coincida con lo que viene del formulario (solo para debug)
        if (isset($_POST['sala_id']) && $_POST['sala_id'] !== '' && $_POST['sala_id'] != $sala_id) {
            error_log("⚠️ ADVERTENCIA: Usuario normal tiene sala_id diferente en formulario. Sesión: " . 
                     $sala_id . ", Formulario: " . $_POST['sala_id']);
            error_log("⚠️ IGNORANDO valor del formulario, usando sesión: " . $sala_id);
        }
    } else {
        error_log("⚠️ USUARIO: No hay sala_id en la sesión");
    }
}

// Si aún no tenemos sala_id, intentar obtenerla de la base de datos
if (!$sala_id) {
    error_log("⚠️ No se pudo obtener sala_id de sesión/formulario, intentando desde BD...");
    $sala_id = obtenerSalaDesdeUsuario($usuario_creacion_id);
    
    if ($sala_id) {
        $_SESSION['sala_id'] = $sala_id;
        error_log("✅ Sala obtenida de BD y guardada en sesión: " . $sala_id);
    }
}

// Último recurso: sala por defecto
if (!$sala_id) {
    error_log("⚠️ No se encontró sala, usando por defecto...");
    $sala_id = obtenerSalaPorDefecto();
    
    if ($sala_id) {
        $_SESSION['sala_id'] = $sala_id;
        error_log("✅ Sala por defecto asignada: " . $sala_id);
    }
}

// Verificar final
if ($sala_id) {
    error_log("✅ FINAL - Sala ID a usar: " . $sala_id);
} else {
    error_log("❌ ERROR: No se pudo determinar la sala");
    sendResponse(false, "No se pudo determinar la sala. Por favor, contacte al administrador.");
}

try {
    // VERIFICAR MÉTODO
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        sendResponse(false, "Método no permitido");
    }

    // LOG para debugging
    error_log("=== INICIO PROCESO GUARDAR FACTURA ===");
    error_log("Usuario ID: " . $usuario_creacion_id);
    error_log("Sala ID final: " . ($sala_id ?: 'NULL'));

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

    // DEBUG: Ver qué datos están llegando para usuarios
    error_log("=== DEBUG USUARIOS ===");
    error_log("liner_id: " . ($liner_id ?: 'NULL'));
    error_log("liner_nombre_manual: " . ($liner_nombre_manual ?: 'NULL'));
    error_log("closer1_id: " . ($closer1_id ?: 'NULL'));
    error_log("closer1_nombre_manual: " . ($closer1_nombre_manual ?: 'NULL'));
    error_log("closer2_id: " . ($closer2_id ?: 'NULL'));
    error_log("closer2_nombre_manual: " . ($closer2_nombre_manual ?: 'NULL'));
    error_log("full_stack_id: " . ($full_stack_id ?: 'NULL'));
    error_log("full_stack_nombre_manual: " . ($full_stack_nombre_manual ?: 'NULL'));

    // CALCULAR PRECIO NETO
    $precio_neto = round($precio * 0.82, 10);

    // CONEXIÓN A LA BASE DE DATOS
    error_log("Conectando a la base de datos...");
    $conn = conexionPDO();
    error_log("Conexión a BD exitosa");

    // OBTENER PORCENTAJES DE LA PLATAFORMA
    $porcent_front = null;
    $porcent_linea = null;
    $porcent_cierre = null;

    if ($plataforma_id) {
        $sql_plataforma = "SELECT porcent_front, porcent_linea, porcent_cierre FROM plataforma WHERE id = ? AND activo = 1";
        $stmt_plat = $conn->prepare($sql_plataforma);
        if ($stmt_plat) {
            $stmt_plat->execute([$plataforma_id]);
            $plataforma_data = $stmt_plat->fetch(PDO::FETCH_ASSOC);
            
            if ($plataforma_data) {
                $porcent_front = $plataforma_data['porcent_front'];
                $porcent_linea = $plataforma_data['porcent_linea'];
                $porcent_cierre = $plataforma_data['porcent_cierre'];
                error_log("Porcentajes plataforma - Front: $porcent_front%, Línea: $porcent_linea%, Cierre: $porcent_cierre%");
            } else {
                error_log("⚠️ No se encontró la plataforma con ID: $plataforma_id");
            }
            $stmt_plat->closeCursor();
        }
    } else {
        error_log("⚠️ No hay plataforma_id, porcentajes serán NULL");
    }

    // CALCULAR GANANCIAS - CORREGIDO PARA INCLUIR NOMBRES MANUALES
    $ganancia_liner = null;
    $ganancia_closer1 = null;
    $ganancia_closer2 = null;
    $ganancia_fullstack = null;

    if ($tipo_venta === 'normal' && $porcent_linea !== null && $porcent_cierre !== null) {
        $ganancia_linea_total = $precio_neto * ($porcent_linea / 100);
        $ganancia_cierre_total = $precio_neto * ($porcent_cierre / 100);
        
        error_log("Cálculo ganancias - Línea total: $ganancia_linea_total, Cierre total: $ganancia_cierre_total");
        
        // CORRECCIÓN: Verificar tanto IDs como nombres manuales
        $has_liner = ($liner_id || $liner_nombre_manual);
        $has_closer1 = ($closer1_id || $closer1_nombre_manual);
        $has_closer2 = ($closer2_id || $closer2_nombre_manual);
        
        if ($has_liner) {
            if ($has_liner && !$has_closer1 && !$has_closer2) {
                // Solo liner (hace línea y cierre)
                $ganancia_liner = $ganancia_linea_total + $ganancia_cierre_total;
                error_log("✅ Solo liner - Ganancia liner: $ganancia_liner");
            } elseif ($has_liner && $has_closer1 && !$has_closer2) {
                // Liner + 1 closer
                $ganancia_liner = $ganancia_linea_total;
                $ganancia_closer1 = $ganancia_cierre_total;
                error_log("✅ Liner + 1 closer - Liner: $ganancia_liner, Closer1: $ganancia_closer1");
            } elseif ($has_liner && $has_closer1 && $has_closer2) {
                // Liner + 2 closers
                $ganancia_liner = $ganancia_linea_total;
                $ganancia_closer1 = $ganancia_cierre_total * 0.5;
                $ganancia_closer2 = $ganancia_cierre_total * 0.5;
                error_log("✅ Liner + 2 closers - Liner: $ganancia_liner, Closer1: $ganancia_closer1, Closer2: $ganancia_closer2");
            }
        } else {
            error_log("⚠️ No hay liner especificado para venta normal");
        }
        
    } elseif ($tipo_venta === 'fullstack' && $porcent_front !== null) {
        // CORRECCIÓN: Verificar tanto ID como nombre manual
        $has_fullstack = ($full_stack_id || $full_stack_nombre_manual);
        
        if ($has_fullstack) {
            $ganancia_fullstack = $precio_neto * ($porcent_front / 100);
            error_log("✅ Fullstack - Ganancia: $ganancia_fullstack");
        } else {
            error_log("⚠️ No hay fullstack especificado para venta fullstack");
        }
    } else {
        error_log("⚠️ No se pueden calcular ganancias - Tipo: $tipo_venta, Porcentajes: F=$porcent_front, L=$porcent_linea, C=$porcent_cierre");
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
        
        error_log("SQL CON USUARIO Y SALA: " . $sql);
        
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
        
        error_log("SQL SIN USUARIO PERO CON SALA: " . $sql);
        
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

    $num_placeholders = substr_count($sql, '?');
    $num_params = count($parametros);
    
    if ($num_placeholders !== $num_params) {
        error_log("❌ ERROR: Número de placeholders ($num_placeholders) no coincide con número de parámetros ($num_params)");
        error_log("SQL: " . $sql);
        error_log("Parámetros: " . print_r($parametros, true));
        throw new Exception("Error: Número de placeholders no coincide con número de parámetros");
    }

    // DEBUG: Mostrar parámetros antes de ejecutar
    error_log("=== PARÁMETROS PARA INSERT ===");
    foreach ($parametros as $i => $param) {
        error_log("  [$i] = " . ($param !== null ? "$param (" . gettype($param) . ")" : "NULL"));
    }

    // EJECUTAR INSERT
    error_log("Ejecutando execute...");
    $execute_result = $stmt->execute($parametros);
    if (!$execute_result) {
        throw new Exception("Error al ejecutar la consulta");
    }

    $idInsertado = (int) $conn->lastInsertId();
    
    error_log("✅ Venta registrada correctamente. ID: $idInsertado - Sala: $sala_id - Es admin: " . ($es_admin ? 'SI' : 'NO'));
    error_log("✅ Ganancia liner: " . ($ganancia_liner ?: 'NULL'));
    error_log("✅ Ganancia closer1: " . ($ganancia_closer1 ?: 'NULL'));
    error_log("✅ Ganancia closer2: " . ($ganancia_closer2 ?: 'NULL'));
    error_log("✅ Ganancia fullstack: " . ($ganancia_fullstack ?: 'NULL'));
    
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