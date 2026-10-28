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
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Error interno del servidor']);
    exit;
});

require_once __DIR__ . '/../../session_config.php'; session_start();
require_once __DIR__ . '/../../includes/registro_acciones.php';
if (!isset($_SESSION['usuario_id']) || $_SESSION['rol'] !== 'admin') {
    http_response_code(403);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Acceso no autorizado']);
    exit;
}

// Verificar que la solicitud sea DELETE
if ($_SERVER['REQUEST_METHOD'] !== 'DELETE') {
    http_response_code(405);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Método no permitido']);
    exit;
}

// Obtener el ID del usuario a eliminar
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) {
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'ID de usuario no válido']);
    exit;
}

// Conexión a base de datos

try {
    $pdo = conexionPDO();
} catch (PDOException $e) {
    error_log("Error de conexión a BD: " . $e->getMessage());
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Error de conexión a la base de datos']);
    exit;
}

try {
    // Verificar si el usuario existe
    $stmt = $pdo->prepare("SELECT nombre, apepat, apemat FROM usuarios WHERE id = :id");
    $stmt->bindParam(':id', $id, PDO::PARAM_INT);
    $stmt->execute();
    
    if ($stmt->rowCount() === 0) {
        http_response_code(404);
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Usuario no encontrado']);
        exit;
    }
    
    $usuario = $stmt->fetch(PDO::FETCH_ASSOC);
    $nombreCompleto = "{$usuario['nombre']} {$usuario['apepat']} {$usuario['apemat']}";
    
    // Prevenir que un usuario se elimine a sí mismo
    if ($id == $_SESSION['usuario_id']) {
        http_response_code(400);
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'No puede eliminarse a sí mismo']);
        exit;
    }
    
    // Eliminar el usuario
    $stmt = $pdo->prepare("DELETE FROM usuarios WHERE id = :id");
    $stmt->bindParam(':id', $id, PDO::PARAM_INT);
    $stmt->execute();
    
    error_log("Usuario eliminado: ID $id - $nombreCompleto por el usuario {$_SESSION['usuario_id']}");
    registrarAccion($pdo, 'DELETE', 'usuarios', $id, ['nombre' => trim($nombreCompleto)]);
    
    header('Content-Type: application/json');
    echo json_encode(['success' => true, 'message' => "Usuario $nombreCompleto eliminado correctamente"]);
    
} catch (PDOException $e) {
    error_log("Error al eliminar usuario: " . $e->getMessage());
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Error al eliminar el usuario']);
}
?>