<?php
require_once __DIR__ . '/../includes/db.php';
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

require_once __DIR__ . '/../session_config.php'; session_start();

// Solo admin o superadmin pueden editar ventas (igual que ventas.php)
if (!isset($_SESSION['usuario_id']) || !in_array($_SESSION['rol'] ?? '', ['admin', 'superadmin'], true)) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'No autorizado. Inicie sesión de nuevo.']);
    exit;
}

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
    $stmt->execute([$id]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

// Función para registrar en el historial
function registrarHistorial($conn, $venta_id, $usuario_id, $accion, $campo_modificado, $valor_anterior, $valor_nuevo) {
    $sql = "INSERT INTO ventas_historial (venta_id, usuario_id, accion, campo_modificado, valor_anterior, valor_nuevo, ip_address, user_agent) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
    $stmt = $conn->prepare($sql);
    
    $ip_address = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
    $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown';
    
    return $stmt->execute([$venta_id, $usuario_id, $accion, $campo_modificado, $valor_anterior, $valor_nuevo, $ip_address, $user_agent]);
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

    if ($tipo_vendedor === 'normal') {
        // Solo calcular si tenemos porcentajes de plataforma
        if ($porcent_front !== null && $porcent_linea !== null && $porcent_cierre !== null) {
            // Calcular ganancias basadas en porcent_linea y porcent_cierre
            $ganancia_linea_total = $precio_neto * ($porcent_linea / 100);
            $ganancia_cierre_total = $precio_neto * ($porcent_cierre / 100);

            // Verificar si hay liner (por ID o manual)
            $tieneLiner = !empty($liner_id) || !empty($liner_nombre_manual);
            $tieneCloser1 = !empty($closer1_id) || !empty($closer1_nombre_manual);
            $tieneCloser2 = !empty($closer2_id) || !empty($closer2_nombre_manual);
            
            if ($tieneLiner && !$tieneCloser1 && !$tieneCloser2) {
                // Solo liner - se lleva toda la ganancia de línea + cierre
                $ganancia_liner = $ganancia_linea_total + $ganancia_cierre_total;
            } elseif ($tieneLiner && $tieneCloser1 && !$tieneCloser2) {
                // Liner + 1 closer
                $ganancia_liner = $ganancia_linea_total;
                $ganancia_closer1 = $ganancia_cierre_total;
            } elseif ($tieneLiner && $tieneCloser1 && $tieneCloser2) {
                // Liner + 2 closers
                $ganancia_liner = $ganancia_linea_total;
                $ganancia_closer1 = $ganancia_cierre_total * 0.5;
                $ganancia_closer2 = $ganancia_cierre_total * 0.5;
            }
            // Otras combinaciones (p. ej. closer2 sin closer1): sin ganancias
        }
    } elseif ($tipo_vendedor === 'fullstack') {
        // Fullstack solo si tenemos porcentaje front
        if ($porcent_front !== null) {
            $ganancia_fullstack = $precio_neto * ($porcent_front / 100);
        }
    }

    // Formatear a 10 decimales para coincidir con la BD
    if ($ganancia_liner !== null) $ganancia_liner = number_format($ganancia_liner, 10, '.', '');
    if ($ganancia_closer1 !== null) $ganancia_closer1 = number_format($ganancia_closer1, 10, '.', '');
    if ($ganancia_closer2 !== null) $ganancia_closer2 = number_format($ganancia_closer2, 10, '.', '');
    if ($ganancia_fullstack !== null) $ganancia_fullstack = number_format($ganancia_fullstack, 10, '.', '');

    return [
        'ganancia_liner' => $ganancia_liner,
        'ganancia_closer1' => $ganancia_closer1,
        'ganancia_closer2' => $ganancia_closer2,
        'ganancia_fullstack' => $ganancia_fullstack
    ];
}

// Función para comparar precios considerando formato decimal
function compararPrecios($precio_actual_bd, $precio_nuevo_input) {
    // Ambos precios redondeados a 2 decimales
    return round(floatval($precio_actual_bd), 2) == round(floatval($precio_nuevo_input), 2);
}

// Función para formatear precio como decimal(20,10)
function formatearPrecioDecimal($precio) {
    // Formatear con 10 decimales para coincidir con decimal(20,10)
    return number_format(floatval($precio), 10, '.', '');
}

try {
    // VERIFICAR MÉTODO DE PETICIÓN
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        sendResponse(false, "Método no permitido");
    }

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
    $sala_id = $_POST['sala_id'] ?? ($_SESSION['sala_id'] ?? null);
    $usuario_id = $_SESSION['usuario_id'];
    
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
        }

        // Para closers, si se ingresó manualmente, limpiar el ID
        if (!empty($closer1_nombre_manual)) {
            $closer1_id = null;
        }

        if (!empty($closer2_nombre_manual)) {
            $closer2_id = null;
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
    $conn = conexionPDO();

    // OBTENER DATOS ACTUALES DE LA VENTA PARA COMPARACIÓN
    $venta_actual = obtenerVentaActual($conn, $id);
    if (!$venta_actual) {
        throw new Exception("No se encontró la venta con ID: " . $id);
    }

    // DETERMINAR SI HAY CAMBIOS QUE REQUIERAN RECALCULAR GANANCIAS

    // Verificar si el precio cambió
    $precio_cambio = !compararPrecios($venta_actual['precio'], $precio_input);

    // Verificar si la plataforma cambió (lo que afectaría los porcentajes)
    $plataforma_cambio = ($venta_actual['plataforma_id'] != $plataforma_id);

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

    // Verificar si el tipo de vendedor cambió
    $tipo_vendedor_actual = ($venta_actual['full_stack_id'] || $venta_actual['full_stack_nombre_manual']) ? 'fullstack' : 'normal';
    $tipo_vendedor_cambio = ($tipo_vendedor_actual != $tipo_vendedor);

    // Recalcular ganancias si hay algún cambio relevante
    $recalcular_ganancias = $precio_cambio || $plataforma_cambio || $usuarios_cambiaron || $tipo_vendedor_cambio;

    // USAR EL PRECIO ACTUAL DE LA BD SI NO HUBO CAMBIO, SINO FORMATEAR EL NUEVO
    $precio = $precio_cambio ? formatearPrecioDecimal($precio_input) : $venta_actual['precio'];

    // OBTENER PORCENTAJES DE LA PLATAFORMA SI EXISTE
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
        // Si la plataforma no existe o está inactiva, no se calculan ganancias
        $stmt_plat->closeCursor();
    }

    // CALCULAR PRECIO NETO Y GANANCIAS SI ES NECESARIO
    if ($recalcular_ganancias) {
        // Siempre recalcular precio neto basado en el precio actual
        $precio_para_calculo = $precio_cambio ? floatval($precio_input) : floatval($venta_actual['precio']);
        $precio_neto = round($precio_para_calculo * 0.82, 10);

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
    } else {
        // Mantener los valores actuales de la base de datos
        $precio_neto = $venta_actual['precio_neto'];
        $ganancia_liner = $venta_actual['ganancia_liner'];
        $ganancia_closer1 = $venta_actual['ganancia_closer1'];
        $ganancia_closer2 = $venta_actual['ganancia_closer2'];
        $ganancia_fullstack = $venta_actual['ganancia_fullstack'];
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

    // Parámetros para la consulta - INCLUYENDO CAMPOS MANUALES, TIPO_VENTA Y SALA_ID
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

    if ($stmt->execute($parametros)) {
        // Si no hubo filas afectadas (mismos datos) no se registra historial
        if ($stmt->rowCount() > 0) {
            // REGISTRAR CAMBIOS EN EL HISTORIAL
            $campos_modificados = compararYRegistrarCambios($conn, $venta_actual, $nuevos_datos, $id, $usuario_id);

            $cambios = [];
            foreach ($campos_modificados as $campo) {
                $cambios[$campo] = ['antes' => $venta_actual[$campo] ?? null, 'despues' => $nuevos_datos[$campo] ?? null];
            }
            registrarAccion($conn, 'UPDATE', 'ventas_facturacion', $id, $cambios);
        }

        // Enviar éxito incluso si no hubo cambios (mismos datos)
        sendResponse(true, "✅ Venta actualizada correctamente", "ventas.php?success=" . urlencode("Venta actualizada correctamente"));
        
    } else {
        throw new Exception("Error al ejecutar la actualización");
    }

} catch (Exception $e) {
    error_log("ERROR en actualizar_venta.php: " . $e->getMessage());
    sendResponse(false, "Error al actualizar la venta: " . $e->getMessage());
}