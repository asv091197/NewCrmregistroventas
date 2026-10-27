<?php
date_default_timezone_set('America/Mexico_City');
require_once __DIR__ . '/../includes/registro_acciones.php';

// Configuración de manejo de errores
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/php_errors.log');

// Headers HTTP apropiados
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

// Función para enviar respuesta JSON
function sendResponse($success, $message, $redirect = '') {
    $response = ['success' => $success, 'message' => $message];
    if ($redirect) {
        $response['redirect'] = $redirect;
    }
    echo json_encode($response);
    exit;
}

// Función para obtener datos actuales de la venta
function obtenerVentaActual($conn, $id) {
    $sql = "SELECT * FROM ventas_facturacion WHERE id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $result = $stmt->get_result();
    return $result->fetch_assoc();
}

// Función para registrar en el historial
function registrarHistorial($conn, $venta_id, $usuario_id, $accion, $campo_modificado, $valor_anterior, $valor_nuevo) {
    $sql = "INSERT INTO ventas_historial (venta_id, usuario_id, accion, campo_modificado, valor_anterior, valor_nuevo, ip_address, user_agent) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
    $stmt = $conn->prepare($sql);
    
    $ip_address = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
    $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown';
    
    $stmt->bind_param("iissssss", $venta_id, $usuario_id, $accion, $campo_modificado, $valor_anterior, $valor_nuevo, $ip_address, $user_agent);
    return $stmt->execute();
}

// Función para comparar y registrar cambios
function compararYRegistrarCambios($conn, $venta_actual, $nuevos_datos, $venta_id, $usuario_id) {
    $campos_modificados = [];
    
    // Definir campos a comparar - INCLUYENDO CAMPOS MANUALES, TIPO_VENTA Y SALA_ID
    $campos = [
        'fecha', 'precio', 'cliente', 'apepat', 'apemat', 'celular', 'correo', 
        'producto', 'liner_id', 'closer1_id', 'closer2_id', 'full_stack_id', 
        'plataforma_id', 'precio_neto', 'ganancia_liner', 'ganancia_closer1', 
        'ganancia_closer2', 'ganancia_fullstack', 'tipo_venta', 'sala_id',
        'liner_nombre_manual', 'closer1_nombre_manual', 'closer2_nombre_manual', 'full_stack_nombre_manual'
    ];
    
    foreach ($campos as $campo) {
        $valor_actual = $venta_actual[$campo] ?? null;
        $valor_nuevo = $nuevos_datos[$campo] ?? null;
        
        // Convertir a string para comparación
        $valor_actual_str = (string)$valor_actual;
        $valor_nuevo_str = (string)$valor_nuevo;
        
        if ($valor_actual_str !== $valor_nuevo_str) {
            // Registrar cambio en el historial
            registrarHistorial(
                $conn, 
                $venta_id, 
                $usuario_id, 
                'ACTUALIZACION', 
                $campo, 
                $valor_actual_str, 
                $valor_nuevo_str
            );
            $campos_modificados[] = $campo;
        }
    }
    
    return $campos_modificados;
}

// Función para calcular ganancias
function calcularGanancias($tipo_vendedor, $precio_neto, $porcent_front, $porcent_linea, $porcent_cierre, $liner_id, $closer1_id, $closer2_id, $liner_nombre_manual, $closer1_nombre_manual, $closer2_nombre_manual) {
    $ganancia_liner = null;
    $ganancia_closer1 = null;
    $ganancia_closer2 = null;
    $ganancia_fullstack = null;

    error_log("Calculando ganancias - Tipo: $tipo_vendedor, PrecioNeto: $precio_neto");
    error_log("Porcentajes - Front: $porcent_front, Linea: $porcent_linea, Cierre: $porcent_cierre");
    error_log("IDs - Liner: $liner_id, Closer1: $closer1_id, Closer2: $closer2_id");
    error_log("Manuales - Liner: $liner_nombre_manual, Closer1: $closer1_nombre_manual, Closer2: $closer2_nombre_manual");

    if ($tipo_vendedor === 'normal') {
        // Solo calcular si tenemos porcentajes de plataforma
        if ($porcent_front !== null && $porcent_linea !== null && $porcent_cierre !== null) {
            // Calcular ganancias basadas en porcent_linea y porcent_cierre
            $ganancia_linea_total = $precio_neto * ($porcent_linea / 100);
            $ganancia_cierre_total = $precio_neto * ($porcent_cierre / 100);
            
            error_log("Ganancias totales - Linea: $ganancia_linea_total, Cierre: $ganancia_cierre_total");
            
            // Verificar si hay liner (por ID o manual)
            $tieneLiner = !empty($liner_id) || !empty($liner_nombre_manual);
            $tieneCloser1 = !empty($closer1_id) || !empty($closer1_nombre_manual);
            $tieneCloser2 = !empty($closer2_id) || !empty($closer2_nombre_manual);
            
            if ($tieneLiner && !$tieneCloser1 && !$tieneCloser2) {
                // Solo liner - se lleva toda la ganancia de línea + cierre
                $ganancia_liner = $ganancia_linea_total + $ganancia_cierre_total;
                error_log("Caso 1 - Solo Liner: $ganancia_liner");
            } elseif ($tieneLiner && $tieneCloser1 && !$tieneCloser2) {
                // Liner + 1 closer
                $ganancia_liner = $ganancia_linea_total;
                $ganancia_closer1 = $ganancia_cierre_total;
                error_log("Caso 2 - Liner + 1 Closer: Liner=$ganancia_liner, Closer1=$ganancia_closer1");
            } elseif ($tieneLiner && $tieneCloser1 && $tieneCloser2) {
                // Liner + 2 closers
                $ganancia_liner = $ganancia_linea_total;
                $ganancia_closer1 = $ganancia_cierre_total * 0.5;
                $ganancia_closer2 = $ganancia_cierre_total * 0.5;
                error_log("Caso 3 - Liner + 2 Closers: Liner=$ganancia_liner, Closer1=$ganancia_closer1, Closer2=$ganancia_closer2");
            } else {
                error_log("Caso no manejado - Configuración de usuarios no válida");
            }
        } else {
            error_log("No se pueden calcular ganancias - Faltan porcentajes de plataforma");
        }
    } elseif ($tipo_vendedor === 'fullstack') {
        // Fullstack solo si tenemos porcentaje front
        if ($porcent_front !== null) {
            $ganancia_fullstack = $precio_neto * ($porcent_front / 100);
            error_log("Full Stack Ganancia: $ganancia_fullstack");
        } else {
            error_log("No se puede calcular ganancia Full Stack - Falta porcentaje front");
        }
    }

    // Formatear a 10 decimales para coincidir con la BD
    if ($ganancia_liner !== null) $ganancia_liner = number_format($ganancia_liner, 10, '.', '');
    if ($ganancia_closer1 !== null) $ganancia_closer1 = number_format($ganancia_closer1, 10, '.', '');
    if ($ganancia_closer2 !== null) $ganancia_closer2 = number_format($ganancia_closer2, 10, '.', '');
    if ($ganancia_fullstack !== null) $ganancia_fullstack = number_format($ganancia_fullstack, 10, '.', '');

    error_log("Ganancias finales - Liner: $ganancia_liner, Closer1: $ganancia_closer1, Closer2: $ganancia_closer2, Fullstack: $ganancia_fullstack");

    return [
        'ganancia_liner' => $ganancia_liner,
        'ganancia_closer1' => $ganancia_closer1,
        'ganancia_closer2' => $ganancia_closer2,
        'ganancia_fullstack' => $ganancia_fullstack
    ];
}

// Función para comparar precios considerando formato decimal
function compararPrecios($precio_actual_bd, $precio_nuevo_input) {
    // Convertir el precio de la BD a float con 2 decimales
    $precio_actual_float = round(floatval($precio_actual_bd), 2);
    
    // Convertir el nuevo precio a float con 2 decimales
    $precio_nuevo_float = round(floatval($precio_nuevo_input), 2);
    
    error_log("Comparando precios - BD: $precio_actual_bd ($precio_actual_float) vs Nuevo: $precio_nuevo_input ($precio_nuevo_float)");
    
    return $precio_actual_float == $precio_nuevo_float;
}

// Función para formatear precio como decimal(20,10)
function formatearPrecioDecimal($precio) {
    // Convertir a float primero
    $precio_float = floatval($precio);
    
    // Formatear con 10 decimales para coincidir con decimal(20,10)
    return number_format($precio_float, 10, '.', '');
}

// Función para determinar el tipo de parámetro considerando NULLs
function getParamType($value) {
    if (is_null($value)) return 's'; // Tratar NULLs como string para bind_param
    if (is_int($value)) return 'i';
    if (is_float($value)) return 'd';
    if (is_double($value)) return 'd';
    return 's';
}

// Función para debug de parámetros
function debugParametros($parametros, $tipos) {
    error_log("=== DEBUG PARÁMETROS ===");
    for ($i = 0; $i < count($parametros); $i++) {
        $tipo = $tipos[$i];
        $valor = $parametros[$i];
        $tipo_desc = '';
        
        switch($tipo) {
            case 's': $tipo_desc = 'string'; break;
            case 'i': $tipo_desc = 'integer'; break;
            case 'd': $tipo_desc = 'double'; break;
            default: $tipo_desc = 'unknown';
        }
        
        error_log("Param $i: Tipo=$tipo_desc, Valor=" . ($valor === null ? 'NULL' : $valor));
    }
    error_log("=== FIN DEBUG ===");
}

try {
    // VERIFICAR MÉTODO DE PETICIÓN
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        sendResponse(false, "Método no permitido");
    }

    // DEBUG: Mostrar datos recibidos
    error_log("=== DATOS POST RECIBIDOS ===");
    error_log(print_r($_POST, true));

    // Validación de campos obligatorios - AGREGADO tipoCliente
    $requeridos = ['id', 'fecha', 'precio', 'cliente', 'apepat', 'celular', 'correo', 'producto', 'metodo_pago', 'tipoCliente'];
    $campos_faltantes = [];
    
    foreach ($requeridos as $campo) {
        if (!isset($_POST[$campo]) || trim($_POST[$campo]) === '') {
            $campos_faltantes[] = $campo;
        }
    }

    if (!empty($campos_faltantes)) {
        sendResponse(false, "Faltan campos obligatorios: " . implode(', ', $campos_faltantes));
    }

    // Captura de datos básicos - AGREGADO tipoCliente
    $id = intval($_POST['id']);
    $fecha = $_POST['fecha'];
    $cliente = $_POST['cliente'];
    $apepat = $_POST['apepat'];
    $apemat = $_POST['apemat'] ?? '';
    $celular = $_POST['celular'];
    $correo = $_POST['correo'];
    $producto = $_POST['producto'];
    $metodo_pago = $_POST['metodo_pago'];
    $tipo_venta = $_POST['tipoCliente']; // NUEVO CAMPO
    $tipo_vendedor = $_POST['tipoVendedor'] ?? 'normal';
    
    // Capturar sala_id desde el formulario (si existe) o desde la sesión
    require_once __DIR__ . '/../session_config.php'; session_start();
    $sala_id = $_POST['sala_id'] ?? ($_SESSION['sala_id'] ?? null);
    $usuario_id = $_SESSION['usuario_id'] ?? 1;
    
    // Captura de campos manuales
    $liner_nombre_manual = isset($_POST['liner_nombre_manual']) && trim($_POST['liner_nombre_manual']) !== '' ? trim($_POST['liner_nombre_manual']) : null;
    $closer1_nombre_manual = isset($_POST['closer1_nombre_manual']) && trim($_POST['closer1_nombre_manual']) !== '' ? trim($_POST['closer1_nombre_manual']) : null;
    $closer2_nombre_manual = isset($_POST['closer2_nombre_manual']) && trim($_POST['closer2_nombre_manual']) !== '' ? trim($_POST['closer2_nombre_manual']) : null;
    $full_stack_nombre_manual = isset($_POST['full_stack_nombre_manual']) && trim($_POST['full_stack_nombre_manual']) !== '' ? trim($_POST['full_stack_nombre_manual']) : null;
    
    // Captura de IDs
    $plataforma_id = isset($_POST['plataforma_id']) && $_POST['plataforma_id'] !== '' ? $_POST['plataforma_id'] : null;
    $liner_id = isset($_POST['liner_id']) && $_POST['liner_id'] !== '' ? intval($_POST['liner_id']) : null;
    $closer1_id = isset($_POST['closer1_id']) && $_POST['closer1_id'] !== '' ? intval($_POST['closer1_id']) : null;
    $closer2_id = isset($_POST['closer2_id']) && $_POST['closer2_id'] !== '' ? intval($_POST['closer2_id']) : null;
    $full_stack_id = isset($_POST['fullstack_id']) && $_POST['fullstack_id'] !== '' ? intval($_POST['fullstack_id']) : null;

    // DEBUG: Verificar datos recibidos
    error_log("=== DATOS PROCESADOS ===");
    error_log("ID: $id");
    error_log("Tipo Venta: $tipo_venta");
    error_log("Tipo Vendedor: $tipo_vendedor");
    error_log("Sala ID: " . ($sala_id ?: 'NULL'));
    error_log("Plataforma ID: " . ($plataforma_id ?: 'NULL'));
    error_log("Liner ID: " . ($liner_id ?: 'NULL'));
    error_log("Liner Manual: " . ($liner_nombre_manual ?: 'NULL'));
    error_log("Closer1 ID: " . ($closer1_id ?: 'NULL'));
    error_log("Closer1 Manual: " . ($closer1_nombre_manual ?: 'NULL'));
    error_log("Closer2 ID: " . ($closer2_id ?: 'NULL'));
    error_log("Closer2 Manual: " . ($closer2_nombre_manual ?: 'NULL'));
    error_log("FullStack ID: " . ($full_stack_id ?: 'NULL'));
    error_log("FullStack Manual: " . ($full_stack_nombre_manual ?: 'NULL'));

    // Validar que el precio sea numérico
    $precio_input = str_replace(['$', ','], '', $_POST['precio']);
    if (!is_numeric($precio_input) || floatval($precio_input) <= 0) {
        sendResponse(false, "El precio debe ser un valor numérico mayor a 0");
    }

    // CORRECCIÓN IMPORTANTE: Validaciones según tipo de vendedor
    if ($tipo_vendedor === 'normal') {
        // Validar que tenga liner (select o manual)
        if (empty($liner_id) && empty($liner_nombre_manual)) {
            sendResponse(false, "Para venta normal debe seleccionar un liner o ingresar el nombre manualmente");
        }
        
        // Si se ingresó manualmente, limpiar el ID
        if (!empty($liner_nombre_manual)) {
            $liner_id = null;
            error_log("Usando Liner Manual: $liner_nombre_manual");
        }
        
        // Para closers, si se ingresó manualmente, limpiar el ID
        if (!empty($closer1_nombre_manual)) {
            $closer1_id = null;
            error_log("Usando Closer1 Manual: $closer1_nombre_manual");
        }
        
        if (!empty($closer2_nombre_manual)) {
            $closer2_id = null;
            error_log("Usando Closer2 Manual: $closer2_nombre_manual");
        }
        
        // Para venta normal, full_stack debe ser null
        $full_stack_id = null;
        $full_stack_nombre_manual = null;
        
    } elseif ($tipo_vendedor === 'fullstack') {
        // Validar que tenga fullstack (select o manual)
        if (empty($full_stack_id) && empty($full_stack_nombre_manual)) {
            sendResponse(false, "Para venta full stack debe seleccionar un front o ingresar el nombre manualmente");
        }
        
        // Si se ingresó manualmente, limpiar el ID
        if (!empty($full_stack_nombre_manual)) {
            $full_stack_id = null;
            error_log("Usando FullStack Manual: $full_stack_nombre_manual");
        }
        
        // Para fullstack, los otros IDs y manuales deben ser null
        $liner_id = null;
        $closer1_id = null;
        $closer2_id = null;
        $liner_nombre_manual = null;
        $closer1_nombre_manual = null;
        $closer2_nombre_manual = null;
    }

    // VALIDACIÓN: Verificar plataforma cuando es requerida
    if (($metodo_pago === 'SPEI' || $metodo_pago === 'TDC') && empty($plataforma_id)) {
        sendResponse(false, "Debe seleccionar una plataforma para el método de pago " . $metodo_pago);
    }

    // Conexión a la base de datos
    $servername = "65.99.205.112";
    $username = "noreplyc_alexsanchez09";
    $password = "SWn_VrAc3K7lq;LS";
    $dbname = "noreplyc_crmregistroventas";

    $conn = new mysqli($servername, $username, $password, $dbname);
    
    if ($conn->connect_error) {
        throw new Exception("Conexión fallida: " . $conn->connect_error);
    }
    
    $conn->set_charset("utf8");

    // OBTENER DATOS ACTUALES DE LA VENTA PARA COMPARACIÓN
    $venta_actual = obtenerVentaActual($conn, $id);
    if (!$venta_actual) {
        throw new Exception("No se encontró la venta con ID: " . $id);
    }

    error_log("Precio actual en BD: " . $venta_actual['precio']);
    error_log("Precio nuevo input: " . $precio_input);

    // DETERMINAR SI HAY CAMBIOS QUE REQUIERAN RECALCULAR GANANCIAS
    $recalcular_ganancias = false;

    // Verificar si el precio cambió
    $precio_cambio = !compararPrecios($venta_actual['precio'], $precio_input);
    error_log("¿Precio cambió? " . ($precio_cambio ? 'SÍ' : 'NO'));

    // Verificar si la plataforma cambió (lo que afectaría los porcentajes)
    $plataforma_cambio = ($venta_actual['plataforma_id'] != $plataforma_id);
    error_log("¿Plataforma cambió? " . ($plataforma_cambio ? 'SÍ' : 'NO'));

    // Verificar si los usuarios (liner/closers/fullstack) cambiaron
    $usuarios_cambiaron = (
        $venta_actual['liner_id'] != $liner_id ||
        $venta_actual['closer1_id'] != $closer1_id ||
        $venta_actual['closer2_id'] != $closer2_id ||
        $venta_actual['full_stack_id'] != $full_stack_id ||
        $venta_actual['liner_nombre_manual'] != $liner_nombre_manual ||
        $venta_actual['closer1_nombre_manual'] != $closer1_nombre_manual ||
        $venta_actual['closer2_nombre_manual'] != $closer2_nombre_manual ||
        $venta_actual['full_stack_nombre_manual'] != $full_stack_nombre_manual
    );
    error_log("¿Usuarios cambiaron? " . ($usuarios_cambiaron ? 'SÍ' : 'NO'));

    // Verificar si el tipo de vendedor cambió
    $tipo_vendedor_actual = ($venta_actual['full_stack_id'] || $venta_actual['full_stack_nombre_manual']) ? 'fullstack' : 'normal';
    $tipo_vendedor_cambio = ($tipo_vendedor_actual != $tipo_vendedor);
    error_log("¿Tipo vendedor cambió? " . ($tipo_vendedor_cambio ? 'SÍ' : 'NO'));

    // Verificar si la sala cambió
    $sala_cambio = ($venta_actual['sala_id'] != $sala_id);
    error_log("¿Sala cambió? " . ($sala_cambio ? 'SÍ' : 'NO'));

    // Recalcular ganancias si hay algún cambio relevante
    $recalcular_ganancias = $precio_cambio || $plataforma_cambio || $usuarios_cambiaron || $tipo_vendedor_cambio;
    error_log("¿Recalcular ganancias? " . ($recalcular_ganancias ? 'SÍ' : 'NO'));

    // USAR EL PRECIO ACTUAL DE LA BD SI NO HUBO CAMBIO, SINO FORMATEAR EL NUEVO
    if ($precio_cambio) {
        $precio = formatearPrecioDecimal($precio_input);
        error_log("Precio cambió - Usando nuevo precio formateado: $precio");
    } else {
        $precio = $venta_actual['precio'];
        error_log("Precio NO cambió - Manteniendo precio actual de BD: $precio");
    }

    // OBTENER PORCENTAJES DE LA PLATAFORMA SI EXISTE
    $porcent_front = null;
    $porcent_linea = null;
    $porcent_cierre = null;

    if ($plataforma_id) {
        $sql_plataforma = "SELECT porcent_front, porcent_linea, porcent_cierre FROM plataforma WHERE id = ? AND activo = 1";
        $stmt_plat = $conn->prepare($sql_plataforma);
        $stmt_plat->bind_param("i", $plataforma_id);
        $stmt_plat->execute();
        $result_plat = $stmt_plat->get_result();
        
        if ($result_plat->num_rows > 0) {
            $plataforma_data = $result_plat->fetch_assoc();
            $porcent_front = $plataforma_data['porcent_front'];
            $porcent_linea = $plataforma_data['porcent_linea'];
            $porcent_cierre = $plataforma_data['porcent_cierre'];
            
            error_log("Porcentajes de plataforma ID $plataforma_id: Front=$porcent_front%, Linea=$porcent_linea%, Cierre=$porcent_cierre%");
        } else {
            error_log("Plataforma ID $plataforma_id no encontrada o inactiva, no se calcularán ganancias");
        }
        $stmt_plat->close();
    }

    // CALCULAR PRECIO NETO Y GANANCIAS SI ES NECESARIO
    if ($recalcular_ganancias) {
        // Siempre recalcular precio neto basado en el precio actual
        $precio_para_calculo = $precio_cambio ? floatval($precio_input) : floatval($venta_actual['precio']);
        $precio_neto = round($precio_para_calculo * 0.82, 10);
        error_log("Recalculando precio_neto: $precio_neto (precio base: $precio_para_calculo)");
        
        // Recalcular ganancias - PASANDO LOS CAMPOS MANUALES
        $ganancias = calcularGanancias(
            $tipo_vendedor, 
            $precio_neto, 
            $porcent_front, 
            $porcent_linea, 
            $porcent_cierre, 
            $liner_id, 
            $closer1_id, 
            $closer2_id,
            $liner_nombre_manual,
            $closer1_nombre_manual,
            $closer2_nombre_manual
        );
        $ganancia_liner = $ganancias['ganancia_liner'];
        $ganancia_closer1 = $ganancias['ganancia_closer1'];
        $ganancia_closer2 = $ganancias['ganancia_closer2'];
        $ganancia_fullstack = $ganancias['ganancia_fullstack'];
        
        error_log("Ganancias recalculadas:");
        error_log("- Ganancia Liner: $ganancia_liner");
        error_log("- Ganancia Closer1: $ganancia_closer1");
        error_log("- Ganancia Closer2: $ganancia_closer2");
        error_log("- Ganancia Fullstack: $ganancia_fullstack");
    } else {
        // Mantener los valores actuales de la base de datos
        $precio_neto = $venta_actual['precio_neto'];
        $ganancia_liner = $venta_actual['ganancia_liner'];
        $ganancia_closer1 = $venta_actual['ganancia_closer1'];
        $ganancia_closer2 = $venta_actual['ganancia_closer2'];
        $ganancia_fullstack = $venta_actual['ganancia_fullstack'];
        
        error_log("No se requirió recalcular - Manteniendo valores actuales de la BD");
        error_log("- Precio Neto: $precio_neto");
        error_log("- Ganancia Liner: $ganancia_liner");
        error_log("- Ganancia Closer1: $ganancia_closer1");
        error_log("- Ganancia Closer2: $ganancia_closer2");
        error_log("- Ganancia Fullstack: $ganancia_fullstack");
    }

    // PREPARAR DATOS PARA EL UPDATE - INCLUYENDO CAMPOS MANUALES, TIPO_VENTA Y SALA_ID
    $nuevos_datos = [
        'fecha' => $fecha,
        'precio' => $precio,
        'cliente' => $cliente,
        'apepat' => $apepat,
        'apemat' => $apemat,
        'celular' => $celular,
        'correo' => $correo,
        'producto' => $producto,
        'liner_id' => $liner_id,
        'closer1_id' => $closer1_id,
        'closer2_id' => $closer2_id,
        'full_stack_id' => $full_stack_id,
        'plataforma_id' => $plataforma_id,
        'precio_neto' => $precio_neto,
        'ganancia_liner' => $ganancia_liner,
        'ganancia_closer1' => $ganancia_closer1,
        'ganancia_closer2' => $ganancia_closer2,
        'ganancia_fullstack' => $ganancia_fullstack,
        'tipo_venta' => $tipo_venta,
        'sala_id' => $sala_id,
        'liner_nombre_manual' => $liner_nombre_manual,
        'closer1_nombre_manual' => $closer1_nombre_manual,
        'closer2_nombre_manual' => $closer2_nombre_manual,
        'full_stack_nombre_manual' => $full_stack_nombre_manual
    ];

    // UPDATE DIRECTO sin stored procedure - INCLUYENDO CAMPOS MANUALES, TIPO_VENTA Y SALA_ID
    $sql = "UPDATE ventas_facturacion SET 
            fecha = ?,
            precio = ?,
            cliente = ?,
            apepat = ?,
            apemat = ?,
            celular = ?,
            correo = ?,
            producto = ?,
            liner_id = ?,
            closer1_id = ?,
            closer2_id = ?,
            full_stack_id = ?,
            plataforma_id = ?,
            precio_neto = ?,
            ganancia_liner = ?,
            ganancia_closer1 = ?,
            ganancia_closer2 = ?,
            ganancia_fullstack = ?,
            tipo_venta = ?,
            sala_id = ?,
            liner_nombre_manual = ?,
            closer1_nombre_manual = ?,
            closer2_nombre_manual = ?,
            full_stack_nombre_manual = ?,
            fecha_ultima_actualizacion = NOW(),
            usuario_ultima_actualizacion_id = ?
        WHERE id = ?";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new Exception("Error al preparar la consulta: " . $conn->error);
    }

    // Parámetros para bind_param - INCLUYENDO CAMPOS MANUALES, TIPO_VENTA Y SALA_ID
    $parametros = [
        $fecha,
        $precio,
        $cliente,
        $apepat,
        $apemat,
        $celular,
        $correo,
        $producto,
        $liner_id,
        $closer1_id,
        $closer2_id,
        $full_stack_id,
        $plataforma_id,
        $precio_neto,
        $ganancia_liner,
        $ganancia_closer1,
        $ganancia_closer2,
        $ganancia_fullstack,
        $tipo_venta,
        $sala_id,
        $liner_nombre_manual,
        $closer1_nombre_manual,
        $closer2_nombre_manual,
        $full_stack_nombre_manual,
        $usuario_id,
        $id
    ];

    // Generar cadena de tipos dinámicamente
    $tipos = '';
    foreach ($parametros as $param) {
        $tipos .= getParamType($param);
    }

    error_log("Tipos generados: $tipos");
    error_log("Número de parámetros: " . count($parametros));

    // Debug de parámetros
    debugParametros($parametros, str_split($tipos));

    // Verificar que coincidan
    if (count($parametros) !== strlen($tipos)) {
        throw new Exception("Error: Número de parámetros (" . count($parametros) . ") no coincide con tipos (" . strlen($tipos) . ")");
    }

    $stmt->bind_param($tipos, ...$parametros);

    if ($stmt->execute()) {
        $filas_afectadas = $stmt->affected_rows;
        
        if ($filas_afectadas > 0) {
            // REGISTRAR CAMBIOS EN EL HISTORIAL
            $campos_modificados = compararYRegistrarCambios($conn, $venta_actual, $nuevos_datos, $id, $usuario_id);

            $cambios = [];
            foreach ($campos_modificados as $campo) {
                $cambios[$campo] = ['antes' => $venta_actual[$campo] ?? null, 'despues' => $nuevos_datos[$campo] ?? null];
            }
            registrarAccion($conn, 'UPDATE', 'ventas_facturacion', $id, $cambios);
            
            error_log("Venta actualizada correctamente. Filas afectadas: " . $filas_afectadas);
            error_log("Campos modificados: " . implode(', ', $campos_modificados));
            
            // DEBUG: Mostrar datos finales guardados
            error_log("=== DATOS GUARDADOS ===");
            error_log("Tipo Venta: $tipo_venta");
            error_log("Sala ID: " . ($sala_id ?: 'NULL'));
            error_log("Liner ID: " . ($liner_id ?: 'NULL'));
            error_log("Liner Manual: " . ($liner_nombre_manual ?: 'NULL'));
            error_log("Closer1 ID: " . ($closer1_id ?: 'NULL'));
            error_log("Closer1 Manual: " . ($closer1_nombre_manual ?: 'NULL'));
            error_log("Closer2 ID: " . ($closer2_id ?: 'NULL'));
            error_log("Closer2 Manual: " . ($closer2_nombre_manual ?: 'NULL'));
            error_log("FullStack ID: " . ($full_stack_id ?: 'NULL'));
            error_log("FullStack Manual: " . ($full_stack_nombre_manual ?: 'NULL'));
            
        } else {
            error_log("Venta no se actualizó (posiblemente mismos datos). Filas afectadas: " . $filas_afectadas);
        }
        
        // Enviar éxito incluso si no hubo cambios (mismos datos)
        sendResponse(true, "✅ Venta actualizada correctamente", "ventas.php?success=" . urlencode("Venta actualizada correctamente"));
        
    } else {
        throw new Exception("Error al ejecutar la actualización: " . $stmt->error);
    }

    $stmt->close();
    $conn->close();

} catch (Exception $e) {
    error_log("ERROR en actualizar_venta.php: " . $e->getMessage());
    sendResponse(false, "Error al actualizar la venta: " . $e->getMessage());
}