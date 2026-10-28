<?php
require_once __DIR__ . '/../../includes/db.php';
// Configuración de manejo de errores
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/php_errors.log');

require_once __DIR__ . '/../../session_config.php'; session_start();
require_once __DIR__ . '/../../includes/registro_acciones.php';

header('Content-Type: application/json');

// Envía la respuesta JSON y termina
function responder(bool $success, string $message): never {
    echo json_encode(['success' => $success, 'message' => $message]);
    exit;
}

$rolSesion = $_SESSION['rol'] ?? '';
if (!isset($_SESSION['usuario_id']) || ($rolSesion !== 'admin' && $rolSesion !== 'superadmin')) {
    responder(false, 'Acceso no autorizado');
}

// Conexión a base de datos
try {
    $pdo = conexionPDO();
} catch (PDOException $e) {
    responder(false, 'Error de conexión a la base de datos');
}

$id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
$nombre = trim($_POST['nombre'] ?? '');
$apepat = trim($_POST['apepat'] ?? '');
$apemat = trim($_POST['apemat'] ?? '');
$correo = trim($_POST['correo'] ?? '');
$rol = trim($_POST['rol'] ?? '');
$password = trim($_POST['password'] ?? '');
$acceso_salas = $_POST['acceso_salas'] ?? '';

// Salas seleccionadas como array (el formulario envía un solo valor de radio button)
$salas_seleccionadas = $_POST['salas_seleccionadas'] ?? [];
if (!is_array($salas_seleccionadas)) {
    $salas_seleccionadas = $salas_seleccionadas === '' ? [] : [$salas_seleccionadas];
}

// Validaciones básicas
if ($id <= 0 || empty($nombre) || empty($apepat) || empty($correo) || empty($rol) || empty($acceso_salas)) {
    responder(false, 'Datos incompletos o inválidos');
}

if (!filter_var($correo, FILTER_VALIDATE_EMAIL)) {
    responder(false, 'Correo electrónico no válido');
}

if (!in_array($rol, ['superadmin', 'admin', 'usuario'], true)) {
    responder(false, 'Rol de usuario no válido');
}

// Solo un superadmin puede asignar el rol superadmin
if ($rol === 'superadmin' && $rolSesion !== 'superadmin') {
    responder(false, 'Solo un superadmin puede asignar el rol superadmin');
}

// Validar longitud de contraseña si se proporciona
if (!empty($password) && strlen($password) < 6) {
    responder(false, 'La contraseña debe tener al menos 6 caracteres');
}

// Validar salas
if ($acceso_salas === 'especificas' && empty($salas_seleccionadas)) {
    responder(false, 'Debe seleccionar al menos una sala');
}

try {
    // Verificar si el usuario existe
    $stmt = $pdo->prepare("SELECT id, rol FROM usuarios_facturacion WHERE id = :id");
    $stmt->bindParam(':id', $id, PDO::PARAM_INT);
    $stmt->execute();
    $usuario = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$usuario) {
        responder(false, 'Usuario no encontrado');
    }

    // Un admin no puede modificar a un superadmin
    if ($usuario['rol'] === 'superadmin' && $rolSesion !== 'superadmin') {
        responder(false, 'Solo un superadmin puede modificar a otro superadmin');
    }

    // Verificar si el correo ya existe en otro usuario
    $stmt = $pdo->prepare("SELECT id FROM usuarios_facturacion WHERE correo = :correo AND id != :id");
    $stmt->bindParam(':correo', $correo);
    $stmt->bindParam(':id', $id, PDO::PARAM_INT);
    $stmt->execute();
    if ($stmt->fetch(PDO::FETCH_ASSOC)) {
        responder(false, 'El correo electrónico ya está en uso por otro usuario');
    }

    // Procesar salas: se guardan como array JSON de IDs numéricos
    $acceso_todas_salas = ($acceso_salas === 'todas') ? 1 : 0;
    $salas_permitidas = null;
    if ($acceso_salas === 'especificas') {
        $salas_filtradas = array_filter($salas_seleccionadas, 'is_numeric');
        if (!empty($salas_filtradas)) {
            $salas_permitidas = json_encode(array_values($salas_filtradas));
        }
    }

    // Construir consulta de actualización (la contraseña solo se cambia si se proporcionó)
    $setContrasena = !empty($password) ? ', contrasena = :contrasena' : '';
    $stmt = $pdo->prepare("UPDATE usuarios_facturacion SET nombre = :nombre, apepat = :apepat, apemat = :apemat, correo = :correo, rol = :rol, acceso_todas_salas = :acceso_todas, salas_permitidas = :salas_permitidas$setContrasena WHERE id = :id");
    if (!empty($password)) {
        $stmt->bindValue(':contrasena', password_hash($password, PASSWORD_DEFAULT));
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
        registrarAccion($pdo, 'UPDATE', 'usuarios_facturacion', $id, [
            'nombre' => trim("$nombre $apepat $apemat"),
            'correo' => $correo,
            'rol' => $rol,
            'acceso_todas_salas' => $acceso_todas_salas,
            'salas_permitidas' => $salas_permitidas,
            'cambio_contrasena' => !empty($password)
        ]);
        responder(true, 'Usuario actualizado correctamente');
    }
    responder(false, 'Error al actualizar el usuario');

} catch (PDOException $e) {
    error_log("Error al actualizar usuario: " . $e->getMessage());
    responder(false, 'Error en la base de datos');
}
