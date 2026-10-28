<?php
require_once __DIR__ . '/../includes/db.php';
date_default_timezone_set('America/Mexico_City');


// Crear conexión PDO
try {
    $conn = conexionPDO();
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(["error" => "Conexión fallida: " . $e->getMessage()]);
    exit;
}
// Consulta para obtener los bancos
$sql = "SELECT id, nombre_ancronimo FROM speiplat ORDER BY nombre_ancronimo";
$result = $conn->query($sql);

$bancos = [];
while($row = $result->fetch(PDO::FETCH_ASSOC)) {
    $bancos[] = $row;
}

// Devolver resultados como JSON
echo json_encode($bancos);
?>