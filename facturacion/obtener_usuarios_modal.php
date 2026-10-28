<?php
// Configuración para sesión persistente
ini_set('session.gc_probability', 1);
ini_set('session.gc_divisor', 1000);
ini_set('session.cookie_secure', 0);
ini_set('session.cookie_httponly', 1);
ini_set('session.use_strict_mode', 1);

require_once __DIR__ . '/../session_config.php'; session_start();

// Verificar sesión
if (!isset($_SESSION['usuario_id'])) {
    header("Location: ./login.php");
    exit;
}

// Configuración de manejo de errores
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/php_errors.log');

// Conexión a base de datos
$host = '65.99.205.112';
$dbname = 'noreplyc_crmregistroventas';
$username = 'noreplyc_alexsanchez09';
$password = 'SWn_VrAc3K7lq;LS';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8", $username, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_TIMEOUT => 30,
        PDO::ATTR_PERSISTENT => false,
        PDO::ATTR_EMULATE_PREPARES => false
    ]);
} catch (PDOException $e) {
    error_log("Error de conexión a BD: " . $e->getMessage());
    echo json_encode(['error' => 'Error de conexión a la base de datos']);
    exit;
}

try {
    // Obtener todos los usuarios activos - SOLO apemat
    $sql = "SELECT 
                u.id,
                u.nombre,
                u.apepat,
                u.apemat,
                -- Usar apemat como nombre para mostrar (esto es lo que necesitas)
                u.apemat AS nombre_mostrar
            FROM usuarios u
            WHERE u.activo = 1
            ORDER BY u.apemat";
    
    $stmt = $pdo->query($sql);
    $usuarios = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Filtrar usuarios que tengan apemat (no vacío y no nulo)
    $usuariosFiltrados = array_filter($usuarios, function($usuario) {
        return !empty($usuario['apemat']) && trim($usuario['apemat']) !== '';
    });
    
    // Reindexar el array
    $usuariosFiltrados = array_values($usuariosFiltrados);
    
    echo json_encode($usuariosFiltrados);
    
} catch (PDOException $e) {
    error_log("Error en consulta de usuarios: " . $e->getMessage());
    // Enviar array vacío en caso de error
    echo json_encode([]);
}
?>