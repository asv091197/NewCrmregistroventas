<?php
require_once __DIR__ . '/../../includes/db.php';
$base_datos = 'marketinpro'; // reemplaza con el nombre de tu base

try {
    $conn = conexionPDO($base_datos);
} catch (PDOException $e) {
    die("Conexión fallida: " . $e->getMessage());
}

$sql = "SELECT id, nombre, apepat, apemat FROM usuariosA ";
$resultado = $conn->query($sql);

$cerradores = [];
while ($row = $resultado->fetch(PDO::FETCH_ASSOC)) {
    $cerradores[] = [
        'id' => $row['id'],
        'nombre_completo' => $row['nombre'] . ' ' . $row['apepat'] . ' ' . $row['apemat']
    ];
}

echo json_encode($cerradores);
?>
 