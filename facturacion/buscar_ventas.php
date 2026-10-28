<?php
require_once __DIR__ . '/../includes/db.php';
// buscar_ventas.php
error_reporting(E_ALL);
ini_set('display_errors', 0);

// Headers para JSON
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

// Conexión a base de datos (usar la misma que en ventas.php)

try {
    $pdo = conexionPDO();
} catch (PDOException $e) {
    echo json_encode([]);
    exit;
}

// Obtener parámetro de búsqueda
$busqueda = isset($_GET['busqueda']) ? trim($_GET['busqueda']) : '';

if (empty($busqueda)) {
    echo json_encode([]);
    exit;
}

// Consulta para búsqueda global SIN límites
$sql = "SELECT v.*, 
    CONCAT(v.cliente, ' ', v.apepat, ' ', COALESCE(v.apemat, '')) AS cliente_nombre,
    CONCAT(liner.nombre, ' ', liner.apepat, ' ', COALESCE(liner.apemat, '')) AS liner_nombre,
    CONCAT(c1.nombre, ' ', c1.apepat, ' ', COALESCE(c1.apemat, '')) AS closer1_nombre,
    CONCAT(c2.nombre, ' ', c2.apepat, ' ', COALESCE(c2.apemat, '')) AS closer2_nombre,
    CONCAT(p.nombre_ancronimo, ' (', p.plataformaspei, ')') AS plataforma_nombre_completo,
    p.metodo_pago AS metodo_pago_plataforma
    FROM ventas_facturacion v
    LEFT JOIN usuarios liner ON v.liner_id = liner.id
    LEFT JOIN usuarios c1 ON v.closer1_id = c1.id
    LEFT JOIN usuarios c2 ON v.closer2_id = c2.id
    LEFT JOIN plataforma p ON v.plataforma_id = p.id
    WHERE (v.folio LIKE :busqueda1 OR 
           v.cliente LIKE :busqueda2 OR 
           v.apepat LIKE :busqueda3 OR 
           v.apemat LIKE :busqueda4 OR 
           v.producto LIKE :busqueda5 OR 
           CONCAT(v.cliente, ' ', v.apepat, ' ', COALESCE(v.apemat, '')) LIKE :busqueda6)
    ORDER BY v.fecha DESC
    LIMIT 1000"; // Límite alto para evitar sobrecarga

try {
    $stmt = $pdo->prepare($sql);
    for ($i = 1; $i <= 6; $i++) {
        $stmt->bindValue(':busqueda' . $i, "%$busqueda%");
    }
    $stmt->execute();
    $ventas = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode($ventas);
} catch (PDOException $e) {
    echo json_encode([]);
}
?>