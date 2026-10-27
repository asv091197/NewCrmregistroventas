<?php
header('Access-Control-Allow-Origin: *');
header('Content-Type: application/json; charset=utf-8');

session_start();
require_once __DIR__ . '/../../includes/registro_acciones.php';

// Verificar sesión y permisos
if (!isset($_SESSION['usuario_id']) || $_SESSION['rol'] !== 'admin'  && $_SESSION['rol'] !== 'superadmin' ) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'No autorizado']);
    exit;
}

// Conexión a base de datos
$host = '65.99.205.112';
$dbname = 'noreplyc_crmregistroventas';
$username = 'noreplyc_alexsanchez09';
$password = 'SWn_VrAc3K7lq;LS';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8", $username, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
    ]);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Error de conexión a la base de datos: ' . $e->getMessage()]);
    exit;
}

// Obtener parámetros por POST
$id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
$accion = isset($_POST['accion']) ? $_POST['accion'] : '';

// Validar parámetros
if ($id <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'ID de usuario inválido']);
    exit;
}

if (!in_array($accion, ['activar', 'desactivar'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Acción inválida. Debe ser "activar" o "desactivar"']);
    exit;
}

// Determinar nuevo estado
$nuevoEstado = $accion === 'activar' ? 1 : 0;
$accionTexto = $accion === 'activar' ? 'activado' : 'desactivado';

try {
    // Verificar que el usuario existe
    $stmt = $pdo->prepare("SELECT id, nombre, apepat FROM usuarios WHERE id = ?");
    $stmt->execute([$id]);
    $usuario = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$usuario) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Usuario no encontrado']);
        exit;
    }
    
    // No permitir desactivarse a sí mismo
    if ($id == $_SESSION['usuario_id'] && $accion === 'desactivar') {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'No puedes desactivar tu propio usuario']);
        exit;
    }
    
    // Actualizar estado
    $stmt = $pdo->prepare("UPDATE usuarios SET activo = ? WHERE id = ?");
    $stmt->execute([$nuevoEstado, $id]);
    
    if ($stmt->rowCount() > 0) {
        // Registrar en log
        $nombreCompleto = $usuario['nombre'] . ' ' . $usuario['apepat'];
        error_log("Usuario {$accionTexto}: ID {$id} - {$nombreCompleto} por admin ID {$_SESSION['usuario_id']}");
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
    echo json_encode(['success' => false, 'message' => 'Error interno del servidor: ' . $e->getMessage()]);
}
?>