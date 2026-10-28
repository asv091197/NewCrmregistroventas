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

// Superadmin tiene los mismos permisos que admin
$rol = $_SESSION['rol'] ?? '';
$esAdmin = $rol === 'admin' || $rol === 'superadmin';

// Conexión a la base de datos
try {
    $pdo = conexionPDO();
} catch (PDOException $e) {
    error_log("Error de conexión en obtener_usuarios.php: " . $e->getMessage());
    die(json_encode(['success' => false, 'message' => 'Error de conexión']));
}

// Obtener parámetros
$sala_id = !empty($_GET['sala_id']) ? intval($_GET['sala_id']) : null;
$tipo = $_GET['tipo'] ?? 'ambos';

// Si no se especifica sala, usar la sala de sesión
if (!$sala_id && intval($_SESSION['sala_id'] ?? 0) > 0) {
    $sala_id = intval($_SESSION['sala_id']);
}

// ¿El usuario tiene acceso a la sala? salas_permitidas puede ser JSON ([1,3]) o texto ("1,3")
function usuarioTieneSala(array $usuario, int $salaId): bool {
    if ($usuario['acceso_todas_salas'] == 1) {
        return true;
    }
    $salas = json_decode($usuario['salas_permitidas'] ?? '', true);
    if (!is_array($salas)) {
        preg_match_all('/\d+/', (string)($usuario['salas_permitidas'] ?? ''), $m);
        $salas = $m[0];
    }
    return in_array($salaId, array_map('intval', $salas), true);
}

try {
    if ($sala_id) {
        // Usuarios activos con rol 'usuario', filtrados por sala
        $sql = "SELECT u.id, u.nombre, u.apepat, u.apemat, u.salas_permitidas, u.acceso_todas_salas, u.rol
                FROM usuarios u
                WHERE u.activo = 1
                AND u.rol = 'usuario'
                ORDER BY u.nombre, u.apepat";
        $usuarios = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
        $usuarios = array_filter($usuarios, fn($u) => usuarioTieneSala($u, $sala_id));
    } else {
        // Si no hay sala, mostrar todos los usuarios activos (admin ve todos los roles)
        $sql = "SELECT id, nombre, apepat, apemat, rol
                FROM usuarios
                WHERE activo = 1" . ($esAdmin ? "" : " AND rol = 'usuario'") . "
                ORDER BY nombre, apepat";
        $usuarios = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    }

    // Filtrar por tipo si es necesario
    if ($tipo === 'usuarios') {
        // Solo para asignar como usuario principal
        $usuarios = array_filter($usuarios, fn($u) => $u['rol'] === 'usuario' || $esAdmin);
    } elseif ($tipo === 'closers') {
        // Solo para asignar como closer
        $usuarios = array_filter($usuarios, fn($u) => $u['rol'] === 'usuario');
    }

    // Formatear para JSON - SOLO apellido materno limpio
    $resultado = [];
    foreach ($usuarios as $usuario) {
        // Limpiar símbolos del apellido materno
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
    echo json_encode(['success' => false, 'message' => 'Error al obtener usuarios']);
}
