<?php
// Configuración de manejo de errores
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/php_errors.log');

session_start();
require_once __DIR__ . '/../../includes/registro_acciones.php';
if (!isset($_SESSION['usuario_id']) || $_SESSION['rol'] !== 'admin'  && $_SESSION['rol'] !== 'superadmin' ) {
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

// SOLUCIÓN: Aquí está el cambio principal
// Obtener las salas seleccionadas como array
$salas_seleccionadas = [];
if (isset($_POST['salas_seleccionadas'])) {
    // Si viene como string (de un radio button), convertirlo a array
    if (is_string($_POST['salas_seleccionadas'])) {
        $salas_seleccionadas = [$_POST['salas_seleccionadas']];
    } 
    // Si ya es un array, mantenerlo
    elseif (is_array($_POST['salas_seleccionadas'])) {
        $salas_seleccionadas = $_POST['salas_seleccionadas'];
    }
}

// Validaciones básicas
if ($id <= 0 || empty($nombre) || empty($apepat) || empty($correo) || empty($rol) || empty($acceso_salas)) {
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

// Validar salas
if ($acceso_salas === 'especificas' && empty($salas_seleccionadas)) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Debe seleccionar al menos una sala']);
    exit;
}

try {
    // Verificar si el usuario existe
    $stmt = $pdo->prepare("SELECT id, correo FROM usuarios_facturacion WHERE id = :id");
    $stmt->bindParam(':id', $id, PDO::PARAM_INT);
    $stmt->execute();
    
    if ($stmt->rowCount() === 0) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Usuario no encontrado']);
        exit;
    }
    
    $usuario = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Verificar si el correo ya existe en otro usuario
    $stmt = $pdo->prepare("SELECT id FROM usuarios_facturacion WHERE correo = :correo AND id != :id");
    $stmt->bindParam(':correo', $correo);
    $stmt->bindParam(':id', $id, PDO::PARAM_INT);
    $stmt->execute();
    
    if ($stmt->rowCount() > 0) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'El correo electrónico ya está en uso por otro usuario']);
        exit;
    }
    
    // Procesar salas
    $acceso_todas_salas = ($acceso_salas === 'todas') ? 1 : 0;
    $salas_permitidas = null;
    
    // SOLUCIÓN: Aquí garantizamos que siempre sea un array JSON
    if ($acceso_salas === 'especificas' && !empty($salas_seleccionadas)) {
        // Asegurar que sea un array válido de números
        $salas_filtradas = array_filter($salas_seleccionadas, 'is_numeric');
        if (!empty($salas_filtradas)) {
            $salas_permitidas = json_encode(array_values($salas_filtradas));
        }
    }
    
    // Depuración (opcional, solo para desarrollo)
    // error_log("Salas permitidas a guardar: " . $salas_permitidas);
    
    // Construir consulta de actualización
    if (!empty($password)) {
        // Si se proporcionó una nueva contraseña, actualizarla
        $passwordHash = password_hash($password, PASSWORD_DEFAULT);
        $stmt = $pdo->prepare("UPDATE usuarios_facturacion SET nombre = :nombre, apepat = :apepat, apemat = :apemat, correo = :correo, rol = :rol, contrasena = :contrasena, acceso_todas_salas = :acceso_todas, salas_permitidas = :salas_permitidas WHERE id = :id");
        $stmt->bindParam(':contrasena', $passwordHash);
    } else {
        // Si no se proporcionó contraseña, mantener la actual
        $stmt = $pdo->prepare("UPDATE usuarios_facturacion SET nombre = :nombre, apepat = :apepat, apemat = :apemat, correo = :correo, rol = :rol, acceso_todas_salas = :acceso_todas, salas_permitidas = :salas_permitidas WHERE id = :id");
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