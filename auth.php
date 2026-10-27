<?php
// auth.php - Sesiones separadas por módulo + control de acceso por rol.
//
// CAUSA DEL BUG: Agencia y Ventas usaban la MISMA sesión (mismo cookie PHPSESSID en "/").
// Si entrabas como agente y sin cerrar sesión ibas a Ventas, Ventas veía la sesión del
// agente ya iniciada y te dejaba pasar. Ahora cada módulo tiene su PROPIA sesión.
//
// USO (primera línea de TODA página y del login de cada módulo, antes de cualquier salida):
//
//   require_once __DIR__ . '/../auth.php';        // ajustar ruta
//   iniciarSesionModulo('ventas');                // 'agencia' o 'ventas'
//   requerirRol(['ventas', 'admin']);             // solo en páginas protegidas (no en login)
//
// Ajusta los nombres de rol a los valores reales de la columna usuarios.rol.

const AUTH_MODULOS = ['agencia', 'ventas'];
const AUTH_INACTIVIDAD_MAX = 28800; // 8 horas en segundos (igual que .htaccess)

$GLOBALS['AUTH_MODULO_ACTUAL'] = null;

function iniciarSesionModulo($modulo) {
    if (!in_array($modulo, AUTH_MODULOS, true)) {
        http_response_code(500);
        exit('Módulo de sesión inválido');
    }
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close(); // por si algo inició la sesión por defecto
    }
    require_once __DIR__ . '/session_config.php';
    session_name('CRM_' . strtoupper($modulo));
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => !empty($_SERVER['HTTPS']),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
    $GLOBALS['AUTH_MODULO_ACTUAL'] = $modulo;
}

// Llamar justo después de validar credenciales en el login del módulo.
function marcarSesionModulo() {
    $_SESSION['modulo'] = $GLOBALS['AUTH_MODULO_ACTUAL'];
}

function authDenegar($codigo, $loginUrl = null) {
    if ($loginUrl) {
        header('Location: ' . $loginUrl);
    } else {
        http_response_code($codigo);
        echo 'Acceso denegado';
    }
    exit;
}

function requerirLogin($loginUrl = 'login.php') {
    // Evita que el botón "atrás" del navegador muestre páginas protegidas desde caché
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');

    $modulo = $GLOBALS['AUTH_MODULO_ACTUAL'];
    if ($modulo === null) {
        http_response_code(500);
        exit('Llama a iniciarSesionModulo() antes de requerirLogin()');
    }
    if (empty($_SESSION['usuario_id']) || empty($_SESSION['rol']) || ($_SESSION['modulo'] ?? null) !== $modulo) {
        authDenegar(401, $loginUrl);
    }
    if (isset($_SESSION['ultimo_acceso']) && (time() - $_SESSION['ultimo_acceso']) > AUTH_INACTIVIDAD_MAX) {
        $_SESSION = [];
        session_destroy();
        authDenegar(401, $loginUrl);
    }
    $_SESSION['ultimo_acceso'] = time();
}

function requerirRol(array $rolesPermitidos, $loginUrl = 'login.php') {
    requerirLogin($loginUrl);
    if (!in_array($_SESSION['rol'], $rolesPermitidos, true)) {
        authDenegar(403);
    }
}

// Para páginas que filtran por sala: valida que la sala pedida sea de las permitidas.
function salaPermitida($sala_id) {
    if (!empty($_SESSION['acceso_todas_salas'])) {
        return true;
    }
    $permitidas = $_SESSION['todas_salas_permitidas'] ?? [];
    if (empty($permitidas) && isset($_SESSION['sala_id'])) {
        $permitidas = [$_SESSION['sala_id']];
    }
    return in_array((int)$sala_id, array_map('intval', $permitidas), true);
}