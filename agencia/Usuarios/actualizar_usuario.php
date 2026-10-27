<?php
// Configuración de manejo de errores
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/php_errors.log');

require_once __DIR__ . '/../../session_config.php'; session_start();
require_once __DIR__ . '/../../includes/registro_acciones.php';
if (!isset($_SESSION['usuario_id']) || $_SESSION['rol'] !== 'admin'  && $_SESSION['rol'] !== 'superadmin') {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Acceso no autorizado']);
    exit;
}

// Conexión a base de datos
$host = '65.99.205.112';
$dbname = 'noreplyc_crmregistroventas';
$username = 'noreplyc_alexsanchez09';
$password = 'SWn_VrAc3K7lq;LS';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8", $username, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
    ]);
} catch (PDOException $e) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Error de conexión a la base de datos']);
    exit;
}

$id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
$nombre = isset($_POST['nombre']) ? trim($_POST['nombre']) : '';
$apepat = isset($_POST['apepat']) ? trim($_POST['apepat']) : '';
$apemat = isset($_POST['apemat']) ? trim($_POST['apemat']) : '';
$correo = isset($_POST['correo']) ? trim($_POST['correo']) : '';
$rol = isset($_POST['rol']) ? trim($_POST['rol']) : '';
$password = isset($_POST['password']) ? trim($_POST['password']) : '';
$acceso_salas = isset($_POST['acceso_salas']) ? $_POST['acceso_salas'] : '';
$salas_seleccionadas = isset($_POST['salas_seleccionadas']) ? $_POST['salas_seleccionadas'] : '';

// Validaciones básicas
if ($id <= 0 || empty($nombre) || empty($correo) || empty($rol) || empty($acceso_salas)) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Datos incompletos o inválidos']);
    exit;
}

if (!filter_var($correo, FILTER_VALIDATE_EMAIL)) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Correo electrónico no válido']);
    exit;
}

if (!in_array($rol, ['admin', 'usuario'])) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Rol de usuario no válido']);
    exit;
}

// Validar longitud de contraseña si se proporciona
if (!empty($password) && strlen($password) < 6) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'La contraseña debe tener al menos 6 caracteres']);
    exit;
}

// Validar salas - IMPORTANTE: Ahora salas_seleccionadas es un solo valor (string), no un array
if ($acceso_salas === 'especificas' && empty($salas_seleccionadas)) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Debe seleccionar una sala']);
    exit;
}

try {
    // Verificar si el usuario existe
    $stmt = $pdo->prepare("SELECT id, correo FROM usuarios WHERE id = :id");
    $stmt->bindParam(':id', $id, PDO::PARAM_INT);
    $stmt->execute();
    
    if ($stmt->rowCount() === 0) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Usuario no encontrado']);
        exit;
    }
    
    $usuario = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Verificar si el correo ya existe en otro usuario
    $stmt = $pdo->prepare("SELECT id FROM usuarios WHERE correo = :correo AND id != :id");
    $stmt->bindParam(':correo', $correo);
    $stmt->bindParam(':id', $id, PDO::PARAM_INT);
    $stmt->execute();
    
    if ($stmt->rowCount() > 0) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'El correo electrónico ya está en uso por otro usuario']);
        exit;
    }
    
    // Procesar salas - IMPORTANTE: Convertir el valor único a array para JSON
    $acceso_todas_salas = ($acceso_salas === 'todas') ? 1 : 0;
    $salas_permitidas = null;
    
    if ($acceso_salas === 'especificas' && !empty($salas_seleccionadas)) {
        // Convertir el valor único a array con un solo elemento
        $salas_array = [$salas_seleccionadas];
        $salas_permitidas = json_encode($salas_array);
    }
    
    // Construir consulta de actualización
    if (!empty($password)) {
        // Si se proporcionó una nueva contraseña, actualizarla
        $passwordHash = password_hash($password, PASSWORD_DEFAULT);
        $stmt = $pdo->prepare("UPDATE usuarios SET nombre = :nombre, apepat = :apepat, apemat = :apemat, correo = :correo, rol = :rol, contrasena = :contrasena, acceso_todas_salas = :acceso_todas, salas_permitidas = :salas_permitidas WHERE id = :id");
        $stmt->bindParam(':contrasena', $passwordHash);
    } else {
        // Si no se proporcionó contraseña, mantener la actual
        $stmt = $pdo->prepare("UPDATE usuarios SET nombre = :nombre, apepat = :apepat, apemat = :apemat, correo = :correo, rol = :rol, acceso_todas_salas = :acceso_todas, salas_permitidas = :salas_permitidas WHERE id = :id");
    }
    
    $stmt->bindParam(':id', $id, PDO::PARAM_INT);
    $stmt->bindParam(':nombre', $nombre);
    $stmt->bindParam(':apepat', $apepat);
    $stmt->bindParam(':apemat', $apemat);
    $stmt->bindParam(':correo', $correo);
    $stmt->bindParam(':rol', $rol);
    $stmt->bindParam(':acceso_todas', $acceso_todas_salas, PDO::PARAM_INT);
    $stmt->bindParam(':salas_permitidas', $salas_permitidas);
    
    if ($stmt->execute()) {
        registrarAccion($pdo, 'UPDATE', 'usuarios', $id, [
            'nombre' => trim("$nombre $apepat $apemat"),
            'correo' => $correo,
            'rol' => $rol,
            'acceso_todas_salas' => $acceso_todas_salas,
            'salas_permitidas' => $salas_permitidas,
            'cambio_contrasena' => !empty($password)
        ]);
        header('Content-Type: application/json');
        echo json_encode(['success' => true, 'message' => 'Usuario actualizado correctamente']);
    } else {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Error al actualizar el usuario']);
    }
    
} catch (PDOException $e) {
    error_log("Error al actualizar usuario: " . $e->getMessage());
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Error en la base de datos: ' . $e->getMessage()]);
}
?>