<?php
require_once __DIR__ . '/../../includes/db.php';
header('Access-Control-Allow-Origin: *');
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../session_config.php'; session_start();
require_once __DIR__ . '/../../includes/registro_acciones.php';

// Verificar sesión y permisos
$rol = $_SESSION['rol'] ?? '';
if (!isset($_SESSION['usuario_id']) || ($rol !== 'admin' && $rol !== 'superadmin')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'No autorizado']);
    exit;
}

// Conexión a base de datos

try {
    $pdo = conexionPDO();
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Error de conexión a la base de datos']);
    exit;
}

// Obtener parámetros por POST
$id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
$accion = $_POST['accion'] ?? '';

// Validar parámetros
if ($id <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'ID de usuario inválido']);
    exit;
}

if (!in_array($accion, ['activar', 'desactivar'], true)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Acción inválida. Debe ser "activar" o "desactivar"']);
    exit;
}

// Determinar nuevo estado
$nuevoEstado = $accion === 'activar' ? 1 : 0;
$accionTexto = $accion === 'activar' ? 'activado' : 'desactivado';

try {
    // Verificar que el usuario existe
    $stmt = $pdo->prepare("SELECT id, nombre, apepat, rol FROM usuarios WHERE id = ?");
    $stmt->execute([$id]);
    $usuario = $stmt->fetch(PDO::FETCH_ASSOC);

    // Un admin no ve ni modifica superadmins
    if ($usuario && $usuario['rol'] === 'superadmin' && $rol !== 'superadmin') {
        $usuario = false;
    }

    if (!$usuario) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Usuario no encontrado']);
        exit;
    }
    
    // No permitir desactivarse a sí mismo
    if ($id === (int)$_SESSION['usuario_id'] && $accion === 'desactivar') {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'No puedes desactivar tu propio usuario']);
        exit;
    }
    
    // Actualizar estado
    $stmt = $pdo->prepare("UPDATE usuarios SET activo = ? WHERE id = ?");
    $stmt->execute([$nuevoEstado, $id]);
    
    if ($stmt->rowCount() > 0) {
        // Registrar en bitácora
        $nombreCompleto = $usuario['nombre'] . ' ' . $usuario['apepat'];
        registrarAccion($pdo, 'UPDATE', 'usuarios', $id, ['nombre' => $nombreCompleto, 'activo' => $nuevoEstado]);
        
        echo json_encode([
            'success' => true, 
            'message' => "Usuario {$accionTexto} correctamente",
            'nuevo_estado' => $nuevoEstado
        ]);
    } else {
        echo json_encode([
            'success' => false, 
            'message' => 'No se realizaron cambios en el usuario'
        ]);
    }
    
} catch (PDOException $e) {
    error_log("Error al cambiar estado de usuario: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Error interno del servidor']);
}
?>