<?php
header('Access-Control-Allow-Origin: *');
header('Content-Type: application/json');

// Conexión a la base de datos
$servername = "65.99.205.112";
$username = "noreplyc_alexsanchez09";
$password = "SWn_VrAc3K7lq;LS";
$dbname = "noreplyc_crmregistroventas";

$conn = new mysqli($servername, $username, $password, $dbname);
$conn->set_charset("utf8");

if ($conn->connect_error) {
    http_response_code(500);
    die(json_encode(["error" => "Conexión fallida: " . $conn->connect_error]));
}

try {
    // Consulta para obtener solo plataformas activas, incluyendo permiso_plataforma
    $sql = "SELECT id, metodo_pago, plataformaspei, nombre_ancronimo, porcent_front, porcent_linea, porcent_cierre, permiso_plataforma 
            FROM plataforma 
            WHERE activo = TRUE AND permiso_plataforma = TRUE
            ORDER BY metodo_pago, nombre_ancronimo";
    
    $result = $conn->query($sql);

    $plataformas = [];
    if ($result->num_rows > 0) {
        while($row = $result->fetch_assoc()) {
            $plataformas[] = $row;
        }
    }

    echo json_encode($plataformas);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(["error" => "Error al obtener plataformas: " . $e->getMessage()]);
} finally {
    $conn->close();
}
?>