<?php
require_once __DIR__ . '/../../includes/db.php';
// obtener_usuarios.php
require_once __DIR__ . '/../../session_config.php'; session_start();
date_default_timezone_set('America/Mexico_City');

if (!isset($_SESSION['usuario_id'])) {
    header('HTTP/1.1 401 Unauthorized');
    echo json_encode(['success' => false, 'message' => 'No autorizado']);
    exit;
}

// Conexión a la base de datos

try {
    $pdo = conexionPDO();
} catch (PDOException $e) {
    die(json_encode(['success' => false, 'message' => 'Error de conexión']));
}

// Obtener parámetros
$sala_id = isset($_GET['sala_id']) && !empty($_GET['sala_id']) ? intval($_GET['sala_id']) : null;
$tipo = isset($_GET['tipo']) ? $_GET['tipo'] : 'ambos';

// Si no se especifica sala, usar la sala de sesión
if (!$sala_id && isset($_SESSION['sala_id']) && intval($_SESSION['sala_id']) > 0) {
    $sala_id = intval($_SESSION['sala_id']);
}

$usuarios = [];

try {
    if ($sala_id) {
        // Obtener todos los usuarios activos
        $sql = "SELECT u.id, u.nombre, u.apepat, u.apemat, u.salas_permitidas, u.acceso_todas_salas, u.rol 
                FROM usuarios u 
                WHERE u.activo = 1 
                AND u.rol = 'usuario'
                ORDER BY u.nombre, u.apepat";
        
        $stmt = $pdo->query($sql);
        $todos_usuarios = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Filtrar por sala
        $usuarios = array_filter($todos_usuarios, function($usuario) use ($sala_id) {
            // Solo usuarios con rol 'usuario' (excepto para administradores que ven todos)
            if ($usuario['rol'] !== 'usuario' && $_SESSION['rol'] !== 'admin') {
                return false;
            }
            
            // Si tiene acceso a todas las salas
            if ($usuario['acceso_todas_salas'] == 1) {
                return true;
            }
            
            // Si salas_permitidas está vacío o nulo
            if (empty($usuario['salas_permitidas']) || $usuario['salas_permitidas'] === 'null') {
                return false;
            }
            
            // Intentar interpretar salas_permitidas como JSON
            $salas_permitidas = json_decode($usuario['salas_permitidas'], true);
            
            if (json_last_error() === JSON_ERROR_NONE) {
                // Es un JSON válido, buscar el ID en el array
                if (is_array($salas_permitidas)) {
                    return in_array($sala_id, $salas_permitidas);
                } elseif (is_string($salas_permitidas) && strpos($salas_permitidas, (string)$sala_id) !== false) {
                    return true;
                }
            } else {
                // No es JSON válido, verificar si es una cadena que contiene el ID
                if (strpos($usuario['salas_permitidas'], (string)$sala_id) !== false) {
                    return true;
                }
            }
            
            return false;
        });
        
        $usuarios = array_values($usuarios);
    } else {
        // Si no hay sala, mostrar todos los usuarios activos
        if ($_SESSION['rol'] === 'admin') {
            $sql = "SELECT id, nombre, apepat, apemat, rol 
                    FROM usuarios 
                    WHERE activo = 1
                    ORDER BY nombre, apepat";
        } else {
            $sql = "SELECT id, nombre, apepat, apemat, rol 
                    FROM usuarios 
                    WHERE activo = 1 AND rol = 'usuario'
                    ORDER BY nombre, apepat";
        }
        $stmt = $pdo->query($sql);
        $usuarios = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    // Filtrar por tipo si es necesario
    if ($tipo === 'usuarios') {
        // Solo para asignar como usuario principal
        $usuarios = array_filter($usuarios, function($u) {
            return $u['rol'] === 'usuario' || $_SESSION['rol'] === 'admin';
        });
    } elseif ($tipo === 'closers') {
        // Solo para asignar como closer
        $usuarios = array_filter($usuarios, function($u) {
            return $u['rol'] === 'usuario';
        });
    }
    
    // Formatear para JSON - SOLO apellido materno limpio
    $resultado = [];
    foreach ($usuarios as $usuario) {
        // Aplicar preg_replace para limpiar símbolos del apellido materno
        $apematLimpio = $usuario['apemat'] ? preg_replace('/[^\p{L}\p{N}\s]/u', '', $usuario['apemat']) : '';
        
        // Si no hay apellido materno, usar nombre o ID
        if (empty($apematLimpio)) {
            $apematLimpio = $usuario['nombre'] ? $usuario['nombre'] . ' ' . $usuario['apepat'] : 'Usuario ' . $usuario['id'];
        }
        
        $resultado[] = [
            'id' => $usuario['id'],
            'nombre' => $usuario['nombre'],
            'apepat' => $usuario['apepat'],
            'apemat' => $apematLimpio, // Solo el apellido materno limpio
            'nombre_completo' => trim($usuario['nombre'] . ' ' . $usuario['apepat'] . ' ' . $usuario['apemat']),
            'rol' => $usuario['rol']
        ];
    }
    
    header('Content-Type: application/json');
    echo json_encode([
        'success' => true, 
        'usuarios' => $resultado, 
        'total' => count($resultado),
        'sala_id' => $sala_id,
        'tipo' => $tipo
    ]);
    
} catch (PDOException $e) {
    error_log("Error en obtener_usuarios.php: " . $e->getMessage());
    header('HTTP/1.1 500 Internal Server Error');
    echo json_encode(['success' => false, 'message' => 'Error al obtener usuarios: ' . $e->getMessage()]);
}