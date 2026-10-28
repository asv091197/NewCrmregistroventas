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

// Verificación de sesión (superadmin tiene los mismos permisos que admin)
if (!isset($_SESSION['usuario_id']) || empty($_SESSION['rol']) ||
    !in_array($_SESSION['rol'], ['admin', 'superadmin', 'usuario'], true)) {
    http_response_code(401);
    die(json_encode(['success' => false, 'message' => 'No autorizado']));
}

// Verificar que sea una solicitud POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    die(json_encode(['success' => false, 'message' => 'Método no permitido']));
}

// Validar datos de entrada
if (empty($_POST['id'])) {
    http_response_code(400);
    die(json_encode(['success' => false, 'message' => 'ID del cliente no proporcionado']));
}

$cliente_id = intval($_POST['id']);
// Sin closer o con valor no válido (0, vacío) se guarda como NULL
$usuario_closer = intval($_POST['usuario_closer'] ?? 0);
$usuario_closer = $usuario_closer > 0 ? $usuario_closer : null;

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
    $stmt_verificar = $conn->prepare("SELECT 1 FROM cliente WHERE id = ?");
    $stmt_verificar->execute([$cliente_id]);
    if (!$stmt_verificar->fetchColumn()) {
        http_response_code(404);
        die(json_encode(['success' => false, 'message' => 'Cliente no encontrado']));
    }

    // Si se proporciona un closer, verificar que existe
    if ($usuario_closer !== null) {
        $stmt_verificar_closer = $conn->prepare("SELECT 1 FROM usuarios WHERE id = ?");
        $stmt_verificar_closer->execute([$usuario_closer]);
        if (!$stmt_verificar_closer->fetchColumn()) {
            http_response_code(404);
            die(json_encode(['success' => false, 'message' => 'Usuario closer no encontrado']));
        }
    }

    // Actualizar el closer del cliente (NULL lo remueve)
    $stmt_actualizar = $conn->prepare("UPDATE cliente SET usuario_closer = ? WHERE id = ?");
    $stmt_actualizar->execute([$usuario_closer, $cliente_id]);

    registrarAccion($conn, 'UPDATE', 'cliente', $cliente_id, ['usuario_closer' => $usuario_closer]);

    echo json_encode([
        'success' => true,
        'message' => $usuario_closer ? "Closer asignado correctamente" : "Closer removido correctamente"
    ]);

} catch (Exception $e) {
    error_log("Error al actualizar closer: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error al actualizar el closer del cliente'
    ]);
}
?>