<?php
require_once __DIR__ . '/../includes/db.php';
header('Access-Control-Allow-Origin: *');
header('Content-Type: application/json');

// Conexión a la base de datos
try {
    $conn = conexionPDO();
} catch (PDOException $e) {
    http_response_code(500);
    die(json_encode(["error" => "Conexión fallida: " . $e->getMessage()]));
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