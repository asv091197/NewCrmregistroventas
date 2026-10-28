<?php
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../session_config.php'; session_start();
date_default_timezone_set('America/Mexico_City');
require_once __DIR__ . '/../../includes/registro_acciones.php';
if (!isset($_SESSION['usuario_id']) || ($_SESSION['rol'] !== 'admin' && $_SESSION['rol'] !== 'usuario')) {
    header("HTTP/1.1 401 Unauthorized");
    echo json_encode(['success' => false, 'message' => 'No autorizado']);
    exit;
}

$pdo = conexionPDO();

$id = $_POST['id'] ?? 0;
$nombre = $_POST['nombre'] ?? '';
$apepat = $_POST['apepat'] ?? '';
$apemat = $_POST['apemat'] ?? '';
$numero1 = $_POST['numero1'] ?? '';
$numero2 = $_POST['numero2'] ?? '';
$asunto = $_POST['asunto'] ?? '';
$descripcion = $_POST['descripcion'] ?? '';
$usuario_id = $_POST['usuarios_id'] ?? '';

// Validar datos
if (empty($id) || empty($nombre) || empty($apepat) || empty($numero1) || empty($asunto) || empty($descripcion)) {
    echo json_encode(['success' => false, 'message' => 'Faltan campos obligatorios']);
    exit;
}

try {
    $stmt = $pdo->prepare("UPDATE cliente SET nombre = ?, apepat = ?, apemat = ?, numero1 = ?, numero2 = ?, asunto = ?, descripcion = ?, usuario_id = ? WHERE id = ?");
    $success = $stmt->execute([$nombre, $apepat, $apemat, $numero1, $numero2, $asunto, $descripcion, $usuario_id, $id]);
    
    if ($success && $stmt->rowCount() > 0) {
        registrarAccion($pdo, 'UPDATE', 'cliente', $id, [
            'nombre' => trim("$nombre $apepat $apemat"),
            'numero1' => $numero1,
            'numero2' => $numero2,
            'asunto' => $asunto,
            'usuario_id' => $usuario_id
        ]);
        echo json_encode(['success' => true, 'message' => 'Cliente actualizado correctamente']);
    } else {
        echo json_encode(['success' => false, 'message' => 'No se realizaron cambios']);
    }
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Error al actualizar: ' . $e->getMessage()]);
}
?>