<?php
require_once __DIR__ . '/../includes/db.php';
// Configuración de errores
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/php_errors_usuarios.log');

date_default_timezone_set('America/Mexico_City');

header('Content-Type: application/json; charset=utf-8');

function sendError($message, $code = 500) {
    http_response_code($code);
    echo json_encode([
        "error" => true,
        "message" => $message
    ]);
    exit;
}

function sendSuccess($data) {
    echo json_encode($data);
    exit;
}

try {
    // Obtener parámetros
    $sala_id = isset($_GET['sala_id']) ? intval($_GET['sala_id']) : null;
    $todos = isset($_GET['todos']) ? $_GET['todos'] === '1' : false;

    // Crear conexión PDO
    try {
        $conn = conexionPDO();
    } catch (PDOException $e) {
        error_log("Error de conexión a BD: " . $e->getMessage());
        sendError("Error de conexión a la base de datos");
    }

    // Filtrar por sala solo si se indicó sala y no se pidieron TODOS;
    // sin parámetros también se devuelven todos (por defecto)
    if (!$todos && $sala_id) {
        $filtro = "AND JSON_CONTAINS(salas_permitidas, ?, '$')";
        $params = ['["' . $sala_id . '"]'];
    } else {
        $filtro = "AND salas_permitidas IS NOT NULL";
        $params = [];
    }

    $sql = "SELECT
                id,
                nombre,
                apepat,
                apemat,
                CONCAT_WS(' ',  apemat) AS nombre_completo
            FROM usuarios
            WHERE activo = 1
            AND rol = 'usuario'
            $filtro
            ORDER BY nombre, apepat";

    $stmt = $conn->prepare($sql);

    // Ejecutar consulta
    if (!$stmt->execute($params)) {
        error_log("Error al ejecutar consulta");
        sendError("Error al ejecutar la consulta");
    }

    $usuarios = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        // Limpiar símbolos
        if (!empty($row['apemat'])) {
            $row['apemat'] = preg_replace('/[^\p{L}\p{N}\s]/u', '', $row['apemat']);
        }
        if (!empty($row['nombre_completo'])) {
            $row['nombre_completo'] = preg_replace('/[^\p{L}\p{N}\s]/u', '', $row['nombre_completo']);
        }
        $usuarios[] = $row;
    }

    sendSuccess($usuarios);

} catch (Exception $e) {
    error_log("Error en obtener_usuarios.php: " . $e->getMessage());
    error_log("Stack trace: " . $e->getTraceAsString());
    
    sendError("Error interno del servidor: " . $e->getMessage());
}
?>