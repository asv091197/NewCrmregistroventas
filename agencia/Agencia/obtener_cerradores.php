<?php
$host = '65.99.205.112';         // o la IP del servidor
$usuario = 'noreplyc_alexsanchez09';     // reemplaza con tu usuario de MySQL
$contrasena = 'SWn_VrAc3K7lq;LS'; // reemplaza con tu contraseña
$base_datos = 'marketinpro'; // reemplaza con el nombre de tu base

$conn = new mysqli($host, $usuario, $contrasena, $base_datos);

// Verificar conexión
if ($conn->connect_error) {
    die("Conexión fallida: " . $conn->connect_error);
}

$sql = "SELECT id, nombre, apepat, apemat FROM usuariosA ";
$resultado = $conn->query($sql);

$cerradores = [];
while ($row = $resultado->fetch_assoc()) {
    $cerradores[] = [
        'id' => $row['id'],
        'nombre_completo' => $row['nombre'] . ' ' . $row['apepat'] . ' ' . $row['apemat']
    ];
}

echo json_encode($cerradores);
?>
 