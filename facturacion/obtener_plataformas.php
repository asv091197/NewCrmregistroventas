<?php
require_once __DIR__ . '/../includes/db.php';
header('Content-Type: application/json');

require_once __DIR__ . '/../session_config.php'; session_start();

if (!isset($_SESSION['usuario_id'])) {
    http_response_code(401);
    die(json_encode(["error" => "No autorizado"]));
}

// Conexión a la base de datos
try {
    $conn = conexionPDO();
} catch (PDOException $e) {
    error_log("Error de conexión en obtener_plataformas.php: " . $e->getMessage());
    http_response_code(500);
    die(json_encode(["error" => "Error de conexión a la base de datos"]));
}

try {
    // Consulta para obtener solo plataformas activas, incluyendo permiso_plataforma
    $sql = "SELECT id, metodo_pago, plataformaspei, nombre_ancronimo, porcent_front, porcent_linea, porcent_cierre, permiso_plataforma 
            FROM plataforma 
            WHERE activo = TRUE AND permiso_plataforma = TRUE
            ORDER BY metodo_pago, nombre_ancronimo";
    
    echo json_encode($conn->query($sql)->fetchAll(PDO::FETCH_ASSOC));
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(["error" => "Error al obtener plataformas: " . $e->getMessage()]);
}
?>