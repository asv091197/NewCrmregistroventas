<?php
date_default_timezone_set('America/Mexico_City');


// Parámetros de conexión
$servername = "65.99.205.112";
$username = "noreplyc_alexsanchez09";
$password = "SWn_VrAc3K7lq;LS";
$dbname = "noreplyc_crmregistroventas";

// Crear conexión MySQLi
$conn = new mysqli($servername, $username, $password, $dbname);
$conn->set_charset("utf8");

// Verificar conexión
if ($conn->connect_error) {
    http_response_code(500);
    echo json_encode(["error" => "Conexión fallida: " . $conn->connect_error]);
    exit;
}
// Consulta para obtener los bancos
$sql = "SELECT id, nombre_ancronimo FROM speiplat ORDER BY nombre_ancronimo";
$result = $conn->query($sql);

$bancos = [];
if ($result->num_rows > 0) {
    while($row = $result->fetch_assoc()) {
        $bancos[] = $row;
    }
}

// Cerrar conexión
$conn->close();

// Devolver resultados como JSON
echo json_encode($bancos);
?>