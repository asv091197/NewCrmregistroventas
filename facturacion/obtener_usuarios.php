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
    
    // LOG para debugging
    error_log("=== OBTENER USUARIOS ===");
    error_log("Sala ID solicitada: " . ($sala_id ?: 'NULL'));
    error_log("Parámetro todos: " . ($todos ? 'SI' : 'NO'));
    
    // Crear conexión PDO
    try {
        $conn = conexionPDO();
    } catch (PDOException $e) {
        error_log("Error de conexión a BD: " . $e->getMessage());
        sendError("Error de conexión a la base de datos");
    }

    // Si se solicita TODOS los usuarios
    if ($todos) {
        // Obtener todos los usuarios activos
        $sql = "SELECT 
                    id, 
                    nombre, 
                    apepat, 
                    apemat,
                    CONCAT_WS(' ',  apemat) AS nombre_completo
                FROM usuarios 
                WHERE activo = 1 
                AND rol = 'usuario'
                AND salas_permitidas IS NOT NULL
                ORDER BY nombre, apepat";
        
        error_log("SQL SIN FILTRO (todos los usuarios) - Parámetro todos=1 recibido");
        error_log("SQL: " . $sql);
        
        $stmt = $conn->prepare($sql);
        $params = [];
    } elseif ($sala_id) {
        // Filtrar usuarios por sala específica
        $sql = "SELECT 
                    id, 
                    nombre, 
                    apepat, 
                    apemat,
                    CONCAT_WS(' ',  apemat) AS nombre_completo
                FROM usuarios 
                WHERE activo = 1 
                AND rol = 'usuario'
                AND JSON_CONTAINS(salas_permitidas, ?, '$')
                ORDER BY nombre, apepat";
        
        error_log("SQL CON FILTRO de sala: " . $sala_id);
        error_log("SQL: " . $sql);
        
        $stmt = $conn->prepare($sql);

        $json_sala = '["' . $sala_id . '"]';
        error_log("JSON buscado: " . $json_sala);
        
        $params = [$json_sala];
    } else {
        // Si no hay parámetros, también devolver todos (por defecto)
        $sql = "SELECT 
                    id, 
                    nombre, 
                    apepat, 
                    apemat,
                    CONCAT_WS(' ',  apemat) AS nombre_completo
                FROM usuarios 
                WHERE activo = 1 
                AND rol = 'usuario'
                AND salas_permitidas IS NOT NULL
                ORDER BY nombre, apepat";
        
        error_log("SQL SIN FILTRO (sin parámetros)");
        error_log("SQL: " . $sql);
        
        $stmt = $conn->prepare($sql);
        $params = [];
    }
    
    // Ejecutar consulta
    if (!$stmt->execute($params)) {
        error_log("Error al ejecutar consulta");
        sendError("Error al ejecutar la consulta");
    }

    $usuarios = [];
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (count($rows) > 0) {
        foreach ($rows as $row) {
            // Limpiar símbolos
            if (!empty($row['apemat'])) {
                $row['apemat'] = preg_replace('/[^\p{L}\p{N}\s]/u', '', $row['apemat']);
            }
            if (!empty($row['nombre_completo'])) {
                $row['nombre_completo'] = preg_replace('/[^\p{L}\p{N}\s]/u', '', $row['nombre_completo']);
            }
            $usuarios[] = $row;
        }
    }
    
    error_log("Usuarios encontrados: " . count($usuarios));
    
    sendSuccess($usuarios);

} catch (Exception $e) {
    error_log("Error en obtener_usuarios.php: " . $e->getMessage());
    error_log("Stack trace: " . $e->getTraceAsString());
    
    sendError("Error interno del servidor: " . $e->getMessage());
}
?>