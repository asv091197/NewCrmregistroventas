<?php
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../session_config.php'; session_start();
require_once __DIR__ . '/../includes/registro_acciones.php';

header('Access-Control-Allow-Origin: *');
header('Content-Type: application/json');
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/php_errors.log');

// Verificar sesión
if (!isset($_SESSION['usuario_id'])) {
    http_response_code(401);
    echo json_encode(["success" => false, "message" => "No autenticado"]);
    exit;
}

// Verificar si el usuario tiene permisos
$es_admin = (($_SESSION['rol'] ?? '') === 'admin') || (($_SESSION['rol'] ?? '') === 'superadmin');
$tiene_permiso_plataforma = false;

if ($es_admin && isset($_SESSION['usuario_id'])) {
    try {
        $conn_check = conexionPDO();
        $stmt = $conn_check->prepare("SELECT permiso_plataforma FROM usuarios_facturacion WHERE id = ?");
        $stmt->execute([$_SESSION['usuario_id']]);
        $usuario = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($usuario && $usuario['permiso_plataforma'] == 1) {
            $tiene_permiso_plataforma = true;
        }
    } catch (Exception $e) {
        error_log("Error al verificar permisos: " . $e->getMessage());
    }
}

// Conexión a la base de datos
try {
    $conn = conexionPDO();
} catch (PDOException $e) {
    http_response_code(500);
    die(json_encode(["success" => false, "message" => "Conexión fallida: " . $e->getMessage()]));
}

try {
    // Leer datos JSON
    $input = file_get_contents('php://input');
    $data = json_decode($input, true);
    
    if (!$data) {
        throw new Exception("Datos JSON inválidos");
    }
    
    $action = $data['action'] ?? '';
    $id = $data['id'] ?? 0;
    
    switch($action) {
        case 'create':
        case 'update':
            // Validar campos requeridos
            $required_fields = ['metodo_pago', 'plataformaspei', 'nombre_ancronimo', 'porcent_front', 'porcent_linea', 'porcent_cierre'];
            foreach ($required_fields as $field) {
                if (!isset($data[$field]) || $data[$field] === '') {
                    throw new Exception("El campo $field es requerido");
                }
            }
            
            $metodo_pago = $data['metodo_pago'] ?? '';
            $plataformaspei = $data['plataformaspei'] ?? '';
            $nombre_ancronimo = $data['nombre_ancronimo'] ?? '';
            $porcent_front = floatval($data['porcent_front'] ?? 0);
            $porcent_linea = floatval($data['porcent_linea'] ?? 0);
            $porcent_cierre = floatval($data['porcent_cierre'] ?? 0);
            
            // Solo permitir modificar permiso_plataforma si el usuario tiene permisos
            $permiso_plataforma = 1; // Valor por defecto
            if ($tiene_permiso_plataforma && isset($data['permiso_plataforma'])) {
                $permiso_plataforma = intval($data['permiso_plataforma']);
            }
            
            if ($action === 'create') {
                $sql = "INSERT INTO plataforma (metodo_pago, plataformaspei, nombre_ancronimo, porcent_front, porcent_linea, porcent_cierre, permiso_plataforma, activo) 
                        VALUES (?, ?, ?, ?, ?, ?, ?, 1)";
                $stmt = $conn->prepare($sql);
                $params = [$metodo_pago, $plataformaspei, $nombre_ancronimo, $porcent_front, $porcent_linea, $porcent_cierre, $permiso_plataforma];
                $message = "Plataforma creada exitosamente";
            } else {
                $sql = "UPDATE plataforma SET 
                        metodo_pago = ?, 
                        plataformaspei = ?, 
                        nombre_ancronimo = ?, 
                        porcent_front = ?, 
                        porcent_linea = ?, 
                        porcent_cierre = ?,
                        permiso_plataforma = ?
                        WHERE id = ?";
                $stmt = $conn->prepare($sql);
                $params = [$metodo_pago, $plataformaspei, $nombre_ancronimo, $porcent_front, $porcent_linea, $porcent_cierre, $permiso_plataforma, $id];
                $message = "Plataforma actualizada exitosamente";
            }
            
            if ($stmt->execute($params)) {
                registrarAccion($conn, $action === 'create' ? 'INSERT' : 'UPDATE', 'plataforma',
                    $action === 'create' ? (int) $conn->lastInsertId() : $id, [
                        'metodo_pago' => $metodo_pago,
                        'plataformaspei' => $plataformaspei,
                        'nombre_ancronimo' => $nombre_ancronimo,
                        'porcent_front' => $porcent_front,
                        'porcent_linea' => $porcent_linea,
                        'porcent_cierre' => $porcent_cierre,
                        'permiso_plataforma' => $permiso_plataforma
                    ]);
                echo json_encode(["success" => true, "message" => $message]);
            } else {
                throw new Exception("Error al guardar");
            }
            break;
            
        case 'deactivate':
            $sql = "UPDATE plataforma SET activo = 0 WHERE id = ?";
            $stmt = $conn->prepare($sql);
            
            if ($stmt->execute([$id])) {
                registrarAccion($conn, 'UPDATE', 'plataforma', $id, ['activo' => 0]);
                echo json_encode(["success" => true, "message" => "Plataforma desactivada exitosamente"]);
            } else {
                throw new Exception("Error al desactivar");
            }
            break;
            
        case 'activate':
            $sql = "UPDATE plataforma SET activo = 1 WHERE id = ?";
            $stmt = $conn->prepare($sql);
            
            if ($stmt->execute([$id])) {
                registrarAccion($conn, 'UPDATE', 'plataforma', $id, ['activo' => 1]);
                echo json_encode(["success" => true, "message" => "Plataforma activada exitosamente"]);
            } else {
                throw new Exception("Error al activar");
            }
            break;
            
        case 'toggle_permiso':
            // Verificar permisos antes de cambiar el permiso
            if (!$tiene_permiso_plataforma) {
                throw new Exception("No tiene permisos para modificar los permisos de plataforma");
            }
            
            $permiso_plataforma = isset($data['permiso_plataforma']) ? intval($data['permiso_plataforma']) : 0;
            
            $sql = "UPDATE plataforma SET permiso_plataforma = ? WHERE id = ?";
            $stmt = $conn->prepare($sql);
            
            if ($stmt->execute([$permiso_plataforma, $id])) {
                registrarAccion($conn, 'UPDATE', 'plataforma', $id, ['permiso_plataforma' => $permiso_plataforma]);
                $estado = $permiso_plataforma ? "permitida" : "restringida";
                echo json_encode(["success" => true, "message" => "Permiso de plataforma actualizado a {$estado}"]);
            } else {
                throw new Exception("Error al cambiar permiso");
            }
            break;
            
        default:
            throw new Exception("Acción no válida: " . $action);
    }
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(["success" => false, "message" => $e->getMessage()]);
}
?>