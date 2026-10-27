<?php
require_once __DIR__ . '/../../session_config.php'; session_start();
date_default_timezone_set('America/Mexico_City');

if (!isset($_SESSION['usuario_id']) || ($_SESSION['rol'] !== 'admin' && $_SESSION['rol'] !== 'usuario' && $_SESSION['rol'] !== 'superadmin')) {
    http_response_code(401);
    die(json_encode(['success' => false, 'message' => 'No autorizado']));
}

// Conexión a la base de datos
try {
    $pdo = new PDO("mysql:host=65.99.205.112;dbname=noreplyc_crmregistroventas;charset=utf8", "noreplyc_alexsanchez09", "SWn_VrAc3K7lq;LS");
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    error_log("Error de conexión: " . $e->getMessage());
    http_response_code(500);
    die(json_encode(['success' => false, 'message' => 'Error de conexión a la base de datos']));
}

if (!isset($_GET['id']) || empty($_GET['id'])) {
    http_response_code(400);
    die(json_encode(['success' => false, 'message' => 'ID no proporcionado']));
}

$id = intval($_GET['id']);

try {
    $sql = "SELECT 
                c.id, 
                c.nombre, 
                c.apepat, 
                c.apemat, 
                c.numero1, 
                c.numero2, 
                c.asunto, 
                c.descripcion,
                c.usuario_id,
                c.usuario_closer,
                c.sala_id
            FROM cliente c 
            WHERE c.id = ?";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$id]);
    $cliente = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($cliente) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'success' => true,
            'data' => $cliente
        ]);
    } else {
        http_response_code(404);
        echo json_encode([
            'success' => false,
            'message' => 'Cliente no encontrado'
        ]);
    }
    
} catch (PDOException $e) {
    error_log("Error en obtener_cliente.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error al obtener los datos del cliente: ' . $e->getMessage()
    ]);
}
?>