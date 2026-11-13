<?php
// Iniciar buffer de salida para evitar problemas con headers
ob_start();


// Configurar zona horaria
date_default_timezone_set('America/Mexico_City');


// Configurar manejo de errores
error_reporting(E_ALL);
ini_set('display_errors', 0); // Cambiado a 0 para producción
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/php_errors.log');

require_once __DIR__ . '/includes/db.php';

// Iniciar sesión
if (session_status() === PHP_SESSION_NONE) {
    require_once __DIR__ . '/session_config.php'; session_start();
}

// Verificar si el usuario ya está logueado
if (isset($_SESSION['usuario_id']) && isset($_SESSION['rol'])) {
    if ($_SESSION['rol'] === 'admin') {
        header("Location: opcionesAdmin.php");
    } else {
        header("Location: Agencia/agencia.php");
    }
    exit;
}

// Inicializar variables
$mensaje = "";
$mensaje_tipo = ""; // success, danger, warning

// Verificar si hay datos POST
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    // Validar que existan los campos
    if (!isset($_POST['correo']) || !isset($_POST['contraseña'])) {
        $mensaje = "Por favor complete todos los campos";
        $mensaje_tipo = "danger";
    } else {
        $correo = trim($_POST['correo']);
        $contraseña = $_POST['contraseña'];

        // Validaciones básicas
        if (empty($correo) || empty($contraseña)) {
            $mensaje = "Por favor complete todos los campos";
            $mensaje_tipo = "danger";
        } else {
            // Conectar a la base de datos
            try {
                $conexion = conexionPDO();

                // Consulta para obtener datos del usuario
                $stmt = $conexion->prepare("
                    SELECT
                        u.id,
                        u.nombre,
                        u.apemat,
                        u.contrasena,
                        u.rol,
                        u.acceso_todas_salas,
                        u.salas_permitidas
                    FROM usuarios u
                    WHERE u.correo = ? AND u.activo = 1
                ");

                $stmt->execute([$correo]);
                $filas = $stmt->fetchAll(PDO::FETCH_NUM);

                if (count($filas) === 1) {
                    [$id, $nombre, $apemat, $hash, $rol, $acceso_todas_salas, $salas_permitidas_json] = $filas[0];

                    if (password_verify($contraseña, $hash)) {
                        session_regenerate_id(true);
                        $_SESSION['usuario_id'] = $id;
                        $_SESSION['nombre'] = $nombre;
                        $_SESSION['apemat'] = $apemat;
                        $_SESSION['rol'] = $rol;
                        $_SESSION['correo'] = $correo;

                        // Lógica para determinar sala_id
                        if ($acceso_todas_salas == 1) {
                            // Si tiene acceso a todas las salas, no establecer sala específica
                            unset($_SESSION['sala_id']);
                            $_SESSION['acceso_todas_salas'] = true;
                        } else {
                            // Si tiene salas permitidas específicas, tomar la primera
                            $salas_permitidas = (!empty($salas_permitidas_json) && $salas_permitidas_json !== 'null')
                                ? json_decode($salas_permitidas_json, true)
                                : null;
                            if (is_array($salas_permitidas) && count($salas_permitidas) > 0) {
                                $_SESSION['sala_id'] = intval($salas_permitidas[0]);
                                // Guardar todas las salas permitidas (las usa salaPermitida() de auth.php)
                                $_SESSION['todas_salas_permitidas'] = $salas_permitidas;
                            } else {
                                unset($_SESSION['sala_id']);
                            }
                            $_SESSION['acceso_todas_salas'] = false;
                        }

                        $_SESSION['ultimo_acceso'] = time();

                        // Redirigir según el rol
                        header("Location: " . ($rol === 'admin' ? "opcionesAdmin.php" : "Agencia/agencia.php"));
                        exit();
                    } else {
                        $mensaje = "Contraseña incorrecta.";
                        $mensaje_tipo = "danger";
                    }
                } else {
                    $mensaje = "Correo no registrado o usuario inactivo.";
                    $mensaje_tipo = "danger";
                }
            } catch (Exception $e) {
                $mensaje = "Error en el sistema. Por favor intente más tarde.";
                $mensaje_tipo = "danger";
                error_log('Excepción en login: ' . $e->getMessage());
            }
        }
    }
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Iniciar Sesión - Gestión de Agentes</title>
    
    <!-- Bootstrap 5 -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <link href="CSS/login.css?v=<?= filemtime(__DIR__ . '/CSS/login.css') ?>" rel="stylesheet">
</head>
<body>
    <div class="container-fluid">
        <div class="row justify-content-center align-items-center min-vh-100">
            <div class="col-xxl-3 col-xl-3 col-lg-4 col-md-5">
                <div class="login-container">
                    <div class="login-card">
                        <div class="login-header">
                            <i class="fas fa-users-cog"></i>
                            <h1 class="system-title">Gestión de Agentes</h1>
                            <p class="system-subtitle">CRM Registro Ventas</p>
                        </div>
                        
                        <div class="login-body">
                            <form method="POST" id="loginForm" novalidate>
                                <div class="mb-3">
                                    <label for="correo" class="form-label">
                                        <i class="fas fa-envelope me-2"></i>Correo Electrónico
                                    </label>
                                    <div class="input-group">
                                        <span class="input-group-text">
                                            <i class="fas fa-user"></i>
                                        </span>
                                        <input type="email" class="form-control" id="correo" name="correo" 
                                               placeholder="usuario@ejemplo.com" required 
                                               value="<?php echo isset($_POST['correo']) ? htmlspecialchars($_POST['correo']) : ''; ?>"
                                               autocomplete="email">
                                    </div>
                                    <div class="invalid-feedback" id="emailError">
                                        Por favor ingrese un correo electrónico válido
                                    </div>
                                </div>
                                
                                <div class="mb-3">
                                    <label for="contraseña" class="form-label">
                                        <i class="fas fa-lock me-2"></i>Contraseña
                                    </label>
                                    <div class="input-group">
                                        <span class="input-group-text">
                                            <i class="fas fa-key"></i>
                                        </span>
                                        <input type="password" class="form-control" id="contraseña" name="contraseña" 
                                               placeholder="Ingrese su contraseña" required
                                               autocomplete="current-password">
                                        <button type="button" class="input-group-text password-toggle" id="togglePassword">
                                            <i class="fas fa-eye"></i>
                                        </button>
                                    </div>
                                    <div class="invalid-feedback" id="passwordError">
                                        La contraseña es requerida
                                    </div>
                                </div>
                                
                                <div class="d-grid gap-2 mb-3">
                                    <button type="submit" class="btn btn-login" id="btnLogin">
                                        <i class="fas fa-sign-in-alt me-2"></i>Acceder a Agencia
                                    </button>
                                </div>
                                
                                <?php if (!empty($mensaje)): ?>
                                    <div class="alert alert-<?php echo $mensaje_tipo; ?> alert-dismissible fade show text-center <?php echo $mensaje_tipo == 'danger' ? 'shake' : ''; ?>" role="alert">
                                        <i class="fas <?php echo $mensaje_tipo == 'success' ? 'fa-check-circle' : ($mensaje_tipo == 'danger' ? 'fa-exclamation-circle' : 'fa-info-circle'); ?> me-2"></i>
                                        <?php echo htmlspecialchars($mensaje); ?>
                                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                                    </div>
                                <?php endif; ?>
                            </form>
                            
                            <div class="footer-links">
                                <p>
                                    <i class="fas fa-info-circle me-1"></i>
                                    Si tiene problemas para acceder, contacte al administrador
                                </p>
                                <p class="copyright mb-0">
                                    <i class="fas fa-copyright me-1"></i>
                                    <?php echo date('Y'); ?> - Grupo Ideas. Todos los derechos reservados.
                                </p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Bootstrap JS Bundle -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    
    <script src="JS/login.js?v=<?= filemtime(__DIR__ . '/JS/login.js') ?>"></script>
</body>
</html>
<?php
ob_end_flush();
?>