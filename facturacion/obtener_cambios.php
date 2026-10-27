<?php
date_default_timezone_set('America/Mexico_City');

// Configuración de manejo de errores
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/php_errors.log');

header('Content-Type: application/json; charset=utf-8');

// Iniciar sesión
require_once __DIR__ . '/../session_config.php'; session_start();

// Verificar que el usuario esté logueado
if (!isset($_SESSION['usuario_id'])) {
    echo json_encode(['success' => false, 'message' => 'No autorizado']);
    exit;
}

if (!isset($_GET['historial_id']) || empty($_GET['historial_id'])) {
    echo json_encode(['success' => false, 'message' => 'ID de historial no especificado']);
    exit;
}

$historial_id = intval($_GET['historial_id']);

try {
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

    // Obtener datos del historial
    $sql = "SELECT datos_anteriores, datos_nuevos FROM ventas_historial WHERE id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $historial_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $registro = $result->fetch_assoc();
    $stmt->close();

    if (!$registro) {
        throw new Exception("No se encontró el registro de historial");
    }

    $anteriores = json_decode($registro['datos_anteriores'], true);
    $nuevos = json_decode($registro['datos_nuevos'], true);

    if (!$anteriores || !$nuevos) {
        throw new Exception("Error al decodificar los datos del historial");
    }

    // Obtener información de usuarios y plataformas para mostrar nombres
    $usuarios_info = obtenerInfoUsuarios($conn);
    $plataformas_info = obtenerInfoPlataformas($conn);

    $conn->close();

    // Generar HTML para mostrar los cambios
    $html = '<div class="row">';
    
    // Columna de valores anteriores
    $html .= '<div class="col-md-6">';
    $html .= '<h6 class="text-danger mb-3"><i class="bi bi-arrow-left"></i> Valores Anteriores</h6>';
    foreach ($anteriores as $campo => $valor) {
        $valor_formateado = formatearValor($campo, $valor, $usuarios_info, $plataformas_info);
        $html .= '<div class="cambio-item campo-anterior">';
        $html .= '<strong>' . formatearNombreCampo($campo) . ':</strong><br>';
        $html .= '<span class="text-dark">' . $valor_formateado . '</span>';
        $html .= '</div>';
    }
    $html .= '</div>';
    
    // Columna de valores nuevos
    $html .= '<div class="col-md-6">';
    $html .= '<h6 class="text-success mb-3"><i class="bi bi-arrow-right"></i> Valores Nuevos</h6>';
    foreach ($nuevos as $campo => $valor) {
        $valor_formateado = formatearValor($campo, $valor, $usuarios_info, $plataformas_info);
        $html .= '<div class="cambio-item campo-nuevo">';
        $html .= '<strong>' . formatearNombreCampo($campo) . ':</strong><br>';
        $html .= '<span class="text-dark">' . $valor_formateado . '</span>';
        $html .= '</div>';
    }
    $html .= '</div>';
    
    $html .= '</div>';

    echo json_encode(['success' => true, 'html' => $html]);

} catch (Exception $e) {
    error_log("ERROR en obtener_cambios.php: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}

// Función para obtener información de usuarios
function obtenerInfoUsuarios($conn) {
    $sql = "SELECT id, nombre, apepat, apemat FROM usuarios";
    $result = $conn->query($sql);
    $usuarios = [];
    
    while ($row = $result->fetch_assoc()) {
        $usuarios[$row['id']] = $row['nombre'] . ' ' . $row['apepat'] . ' ' . ($row['apemat'] ?? '');
    }
    
    return $usuarios;
}

// Función para obtener información de plataformas
function obtenerInfoPlataformas($conn) {
    $sql = "SELECT id, nombre_ancronimo, plataformaspei FROM plataforma";
    $result = $conn->query($sql);
    $plataformas = [];
    
    while ($row = $result->fetch_assoc()) {
        $plataformas[$row['id']] = $row['nombre_ancronimo'] . ' (' . $row['plataformaspei'] . ')';
    }
    
    return $plataformas;
}

// Función para formatear nombres de campos
function formatearNombreCampo($campo) {
    $nombres = [
        'fecha' => '📅 Fecha',
        'precio' => '💰 Precio',
        'cliente' => '👤 Cliente',
        'apepat' => '📝 Apellido Paterno',
        'apemat' => '📝 Apellido Materno',
        'celular' => '📱 Celular',
        'correo' => '📧 Correo',
        'producto' => '📦 Producto',
        'tipo_venta' => '🏷️ Tipo de Venta',
        'metodo_pago' => '💳 Método de Pago',
        'liner_id' => '👥 Liner',
        'closer1_id' => '👥 Closer 1',
        'closer2_id' => '👥 Closer 2',
        'full_stack_id' => '👨‍💻 Full Stack',
        'plataforma_id' => '🖥️ Plataforma',
        'precio_neto' => '💵 Precio Neto',
        'ganancia_liner' => '💸 Ganancia Liner',
        'ganancia_closer1' => '💸 Ganancia Closer 1',
        'ganancia_closer2' => '💸 Ganancia Closer 2',
        'ganancia_fullstack' => '💸 Ganancia Full Stack'
    ];
    
    return $nombres[$campo] ?? ucfirst(str_replace('_', ' ', $campo));
}

// Función para formatear valores
function formatearValor($campo, $valor, $usuarios_info = [], $plataformas_info = []) {
    if ($valor === null || $valor === '') {
        return '<em class="text-muted">No asignado</em>';
    }
    
    // Formatear campos monetarios
    if (is_numeric($valor) && in_array($campo, ['precio', 'precio_neto', 'ganancia_liner', 'ganancia_closer1', 'ganancia_closer2', 'ganancia_fullstack'])) {
        return '$' . number_format(floatval($valor), 2);
    }
    
    // Formatear IDs de usuarios - mostrar nombres completos
    if (strpos($campo, '_id') !== false && is_numeric($valor)) {
        $user_id = intval($valor);
        
        // Campos específicos de usuarios
        if (in_array($campo, ['liner_id', 'closer1_id', 'closer2_id', 'full_stack_id'])) {
            if (isset($usuarios_info[$user_id])) {
                return htmlspecialchars($usuarios_info[$user_id]) . ' <small class="text-muted">(ID: ' . $user_id . ')</small>';
            } else {
                return '<em class="text-muted">Usuario no encontrado</em> <small class="text-muted">(ID: ' . $user_id . ')</small>';
            }
        }
        
        // Para otros IDs, mostrar solo el ID
        return 'ID: ' . $user_id;
    }
    
    // Formatear plataforma_id - mostrar nombre de plataforma
    if ($campo === 'plataforma_id' && !empty($valor)) {
        if (isset($plataformas_info[$valor])) {
            return htmlspecialchars($plataformas_info[$valor]);
        } else {
            return '<em class="text-muted">Plataforma no encontrada</em> <small class="text-muted">(ID: ' . htmlspecialchars($valor) . ')</small>';
        }
    }
    
    // Formatear fechas
    if ($campo === 'fecha' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $valor)) {
        return date('d/m/Y', strtotime($valor));
    }
    
    // Formatear tipo_venta
    if ($campo === 'tipo_venta') {
        if ($valor === 'normal') return '🔄 Venta Normal';
        if ($valor === 'fullstack') return '👨‍💻 Full Stack';
    }
    
    // Formatear metodo_pago
    if ($campo === 'metodo_pago') {
        if ($valor === 'SPEI') return '💰 SPEI/EFECTIVO';
        if ($valor === 'TDC') return '💳 Tarjeta de Crédito (TDC)';
    }
    
    return htmlspecialchars($valor);
}
?>