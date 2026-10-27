<?php
// Configuración de manejo de errores
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/php_errors.log');

// Manejo de excepciones global
set_exception_handler(function($exception) {
    error_log("Excepción no capturada: " . $exception->getMessage());
    http_response_code(500);
    die("Error interno del servidor. Por favor, intente más tarde.");
});

require_once __DIR__ . '/../../session_config.php'; session_start();
require_once __DIR__ . '/../../includes/registro_acciones.php';

// Verificación de sesión
if (!isset($_SESSION['usuario_id']) || empty($_SESSION['rol']) || 
    !in_array($_SESSION['rol'], ['admin', 'usuario'])) {
    http_response_code(401);
    die(json_encode(['success' => false, 'message' => 'No autorizado']));
}

// Verificar que sea una solicitud POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    die(json_encode(['success' => false, 'message' => 'Método no permitido']));
}

// Validar datos de entrada
if (!isset($_POST['id']) || empty($_POST['id'])) {
    http_response_code(400);
    die(json_encode(['success' => false, 'message' => 'ID del cliente no proporcionado']));
}

$cliente_id = intval($_POST['id']);
$usuario_closer = isset($_POST['usuario_closer']) ? intval($_POST['usuario_closer']) : null;

// Conexión a base de datos
$host = '65.99.205.112';
$dbname = 'noreplyc_crmregistroventas';
$username = 'noreplyc_alexsanchez09';
$password = 'SWn_VrAc3K7lq;LS';

try {
    $conn = new mysqli($host, $username, $password, $dbname);
    
    // Verificar conexión
    if ($conn->connect_error) {
        throw new Exception("Error de conexión: " . $conn->connect_error);
    }
    
    // Configurar charset
    $conn->set_charset("utf8");
    
} catch (Exception $e) {
    error_log("Error de conexión a BD: " . $e->getMessage());
    http_response_code(500);
    die(json_encode(['success' => false, 'message' => 'Error de conexión a la base de datos']));
}

try {
    // Verificar que el cliente existe
    $sql_verificar = "SELECT id FROM cliente WHERE id = ?";
    $stmt_verificar = $conn->prepare($sql_verificar);
    $stmt_verificar->bind_param("i", $cliente_id);
    $stmt_verificar->execute();
    $result_verificar = $stmt_verificar->get_result();
    
    if ($result_verificar->num_rows === 0) {
        http_response_code(404);
        die(json_encode(['success' => false, 'message' => 'Cliente no encontrado']));
    }
    $stmt_verificar->close();
    
    // Si se proporciona un closer, verificar que existe
    if ($usuario_closer !== null && $usuario_closer > 0) {
        $sql_verificar_closer = "SELECT id FROM usuarios WHERE id = ?";
        $stmt_verificar_closer = $conn->prepare($sql_verificar_closer);
        $stmt_verificar_closer->bind_param("i", $usuario_closer);
        $stmt_verificar_closer->execute();
        $result_verificar_closer = $stmt_verificar_closer->get_result();
        
        if ($result_verificar_closer->num_rows === 0) {
            http_response_code(404);
            die(json_encode(['success' => false, 'message' => 'Usuario closer no encontrado']));
        }
        $stmt_verificar_closer->close();
    }
    
    // Actualizar el closer del cliente
    if ($usuario_closer === null || $usuario_closer === 0) {
        // Si no se proporciona closer o es 0, establecer como NULL
        $sql_actualizar = "UPDATE cliente SET usuario_closer = NULL WHERE id = ?";
        $stmt_actualizar = $conn->prepare($sql_actualizar);
        $stmt_actualizar->bind_param("i", $cliente_id);
    } else {
        // Actualizar con el closer proporcionado
        $sql_actualizar = "UPDATE cliente SET usuario_closer = ? WHERE id = ?";
        $stmt_actualizar = $conn->prepare($sql_actualizar);
        $stmt_actualizar->bind_param("ii", $usuario_closer, $cliente_id);
    }
    
    // Ejecutar la actualización
    if ($stmt_actualizar->execute()) {
        $mensaje = $usuario_closer ? "Closer asignado correctamente" : "Closer removido correctamente";
        
        error_log("Cliente {$cliente_id} actualizado - Closer: " . ($usuario_closer ?: 'NULL') . " por usuario: {$_SESSION['usuario_id']}");
        registrarAccion($conn, 'UPDATE', 'cliente', $cliente_id, ['usuario_closer' => $usuario_closer ?: null]);
        
        echo json_encode([
            'success' => true,
            'message' => $mensaje
        ]);
    } else {
        throw new Exception("Error al ejecutar la actualización: " . $stmt_actualizar->error);
    }
    
    $stmt_actualizar->close();
    
} catch (Exception $e) {
    error_log("Error al actualizar closer: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false, 
        'message' => 'Error al actualizar el closer del cliente'
    ]);
} finally {
    // Cerrar conexión
    if (isset($conn)) {
        $conn->close();
    }
}
?>