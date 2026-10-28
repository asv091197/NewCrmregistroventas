<?php
require_once __DIR__ . '/../../includes/db.php';
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
try {
    $conn = conexionPDO();
} catch (Exception $e) {
    error_log("Error de conexión a BD: " . $e->getMessage());
    http_response_code(500);
    die(json_encode(['success' => false, 'message' => 'Error de conexión a la base de datos']));
}

try {
    // Verificar que el cliente existe
    $sql_verificar = "SELECT id FROM cliente WHERE id = ?";
    $stmt_verificar = $conn->prepare($sql_verificar);
    $stmt_verificar->execute([$cliente_id]);
    $result_verificar = $stmt_verificar->fetchAll(PDO::FETCH_ASSOC);

    if (count($result_verificar) === 0) {
        http_response_code(404);
        die(json_encode(['success' => false, 'message' => 'Cliente no encontrado']));
    }
    
    // Si se proporciona un closer, verificar que existe
    if ($usuario_closer !== null && $usuario_closer > 0) {
        $sql_verificar_closer = "SELECT id FROM usuarios WHERE id = ?";
        $stmt_verificar_closer = $conn->prepare($sql_verificar_closer);
        $stmt_verificar_closer->execute([$usuario_closer]);
        $result_verificar_closer = $stmt_verificar_closer->fetchAll(PDO::FETCH_ASSOC);

        if (count($result_verificar_closer) === 0) {
            http_response_code(404);
            die(json_encode(['success' => false, 'message' => 'Usuario closer no encontrado']));
        }
    }
    
    // Actualizar el closer del cliente
    if ($usuario_closer === null || $usuario_closer === 0) {
        // Si no se proporciona closer o es 0, establecer como NULL
        $sql_actualizar = "UPDATE cliente SET usuario_closer = NULL WHERE id = ?";
        $stmt_actualizar = $conn->prepare($sql_actualizar);
        $params_actualizar = [$cliente_id];
    } else {
        // Actualizar con el closer proporcionado
        $sql_actualizar = "UPDATE cliente SET usuario_closer = ? WHERE id = ?";
        $stmt_actualizar = $conn->prepare($sql_actualizar);
        $params_actualizar = [$usuario_closer, $cliente_id];
    }

    // Ejecutar la actualización
    if ($stmt_actualizar->execute($params_actualizar)) {
        $mensaje = $usuario_closer ? "Closer asignado correctamente" : "Closer removido correctamente";
        
        error_log("Cliente {$cliente_id} actualizado - Closer: " . ($usuario_closer ?: 'NULL') . " por usuario: {$_SESSION['usuario_id']}");
        registrarAccion($conn, 'UPDATE', 'cliente', $cliente_id, ['usuario_closer' => $usuario_closer ?: null]);
        
        echo json_encode([
            'success' => true,
            'message' => $mensaje
        ]);
    } else {
        throw new Exception("Error al ejecutar la actualización");
    }

} catch (Exception $e) {
    error_log("Error al actualizar closer: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error al actualizar el closer del cliente'
    ]);
}
?>