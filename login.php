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

// Función de depuración
function debugLog($message, $data = null) {
    $logFile = __DIR__ . '/debug_login.log';
    $timestamp = date('Y-m-d H:i:s');
    $logMessage = "[$timestamp] $message";
    
    if ($data !== null) {
        $logMessage .= " - " . (is_array($data) || is_object($data) ? print_r($data, true) : $data);
    }
    
    $logMessage .= "\n";
    file_put_contents($logFile, $logMessage, FILE_APPEND);
}

debugLog('=== INICIO DE PROCESO LOGIN ===');

// Verificar si el usuario ya está logueado
if (isset($_SESSION['usuario_id']) && isset($_SESSION['rol'])) {
    debugLog('Usuario ya tiene sesión activa', [
        'usuario_id' => $_SESSION['usuario_id'],
        'rol' => $_SESSION['rol']
    ]);
    
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
$conexion = null;

// Verificar si hay datos POST
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    debugLog('Método POST recibido', $_POST);
    
    // Validar que existan los campos
    if (!isset($_POST['correo']) || !isset($_POST['contraseña'])) {
        $mensaje = "Por favor complete todos los campos";
        $mensaje_tipo = "danger";
        debugLog('Campos POST incompletos', $_POST);
    } else {
        $correo = trim($_POST['correo']);
        $contraseña = $_POST['contraseña'];
        
        // Validaciones básicas
        if (empty($correo) || empty($contraseña)) {
            $mensaje = "Por favor complete todos los campos";
            $mensaje_tipo = "danger";
            debugLog('Campos vacíos', ['correo' => $correo, 'password_length' => strlen($contraseña)]);
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

                    debugLog('Usuario encontrado', [
                        'id' => $id,
                        'nombre' => $nombre,
                        'apemat' => $apemat,
                        'rol' => $rol
                    ]);
                    
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
                            debugLog('Usuario con acceso a todas las salas');
                        } else {
                            // Si tiene salas permitidas específicas
                            if (!empty($salas_permitidas_json) && $salas_permitidas_json !== 'null') {
                                $salas_permitidas = json_decode($salas_permitidas_json, true);
                                if (is_array($salas_permitidas) && count($salas_permitidas) > 0) {
                                    // Tomar la primera sala del array
                                    $_SESSION['sala_id'] = intval($salas_permitidas[0]);
                                    // Opcional: guardar todas las salas permitidas si se necesitan
                                    $_SESSION['todas_salas_permitidas'] = $salas_permitidas;
                                    debugLog('Salas permitidas asignadas', $salas_permitidas);
                                } else {
                                    unset($_SESSION['sala_id']);
                                    debugLog('Array de salas vacío o inválido');
                                }
                            } else {
                                unset($_SESSION['sala_id']);
                                debugLog('No hay salas permitidas definidas');
                            }
                            $_SESSION['acceso_todas_salas'] = false;
                        }
                        
                        $_SESSION['ultimo_acceso'] = time();
                        
                        debugLog('Login exitoso', [
                            'usuario' => $correo,
                            'rol' => $rol,
                            'apemat' => $apemat,
                            'session_id' => session_id()
                        ]);
                        
                        // Cerrar conexión y redirigir
                        $stmt = null;
                        $conexion = null;

                        // Determinar URL de redirección
                        if ($rol === 'admin') {
                            $redirect_url = "opcionesAdmin.php";
                        } else {
                            $redirect_url = "Agencia/agencia.php";
                        }
                        
                        debugLog('Redirigiendo a', $redirect_url);
                        
                        header("Location: " . $redirect_url);
                        exit();
                        
                    } else {
                        $mensaje = "Contraseña incorrecta.";
                        $mensaje_tipo = "danger";
                        debugLog('Contraseña incorrecta para usuario', $correo);
                    }
                } else {
                    $mensaje = "Correo no registrado o usuario inactivo.";
                    $mensaje_tipo = "danger";
                    debugLog('Usuario no encontrado o inactivo', $correo);
                }
                
                $stmt = null;
                $conexion = null;

            } catch (Exception $e) {
                $mensaje = "Error en el sistema. Por favor intente más tarde.";
                $mensaje_tipo = "danger";
                debugLog('Excepción en login', $e->getMessage());

                $conexion = null;
            }
        }
    }
} else {
    debugLog('Método no POST', $_SERVER['REQUEST_METHOD']);
}

debugLog('=== FIN DE PROCESO LOGIN ===');
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
    
    <style>
        :root {
            --sky-blue-primary: #1E90FF; /* Dodger Blue - más vibrante */
            --sky-blue-light: #B0E0E6; /* Powder Blue */
            --sky-blue-medium: #00BFFF; /* Deep Sky Blue */
            --sky-blue-dark: #0066CC; /* Azul más oscuro */
            --sky-blue-accent: #4DABF7; /* Azul brillante */
        }
        
        body {
            background: linear-gradient(rgba(135, 206, 235, 0.1), rgba(135, 206, 235, 0.2)), 
                     url('../images/agencia.avif') 
                    no-repeat center center fixed;
            background-size: cover;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            padding: 20px;
        }
        
        .login-container {
            animation: fadeIn 0.8s ease-in-out;
            width: 100%;
            max-width: 400px; /* Más pequeño para PC */
        }
        
        .login-card {
            background: white;
            border-radius: 15px;
            box-shadow: 0 15px 35px rgba(0, 0, 0, 0.4);
            border: 2px solid var(--sky-blue-primary);
            overflow: hidden;
            position: relative;
            transition: all 0.3s ease;
        }
        
        /* Efecto de iluminación en el borde */
        .login-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(90deg, 
                var(--sky-blue-primary), 
                var(--sky-blue-accent), 
                var(--sky-blue-primary));
            z-index: 1;
        }
        
        /* Efecto hover para PC */
        @media (min-width: 992px) {
            .login-card:hover {
                transform: translateY(-3px);
                box-shadow: 0 20px 40px rgba(0, 0, 0, 0.5);
            }
        }
        
        .login-header {
            background: linear-gradient(135deg, var(--sky-blue-primary) 0%, var(--sky-blue-dark) 100%);
            color: white;
            padding: 25px 20px; /* Padding más pequeño */
            text-align: center;
            border-bottom: 4px solid var(--sky-blue-accent);
            position: relative;
        }
        
        .login-header i {
            font-size: 2.8rem; /* Icono más pequeño */
            margin-bottom: 15px;
            background: rgba(255, 255, 255, 0.3);
            width: 70px; /* Más pequeño */
            height: 70px; /* Más pequeño */
            line-height: 70px; /* Más pequeño */
            border-radius: 50%;
            display: inline-block;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.2);
            border: 2px solid rgba(255, 255, 255, 0.5);
        }
        
        .login-body {
            padding: 30px 25px; /* Padding más compacto */
            background: white;
        }
        
        .form-control {
            border-radius: 10px;
            padding: 12px 16px; /* Más compacto */
            border: 2px solid #d1e3ff;
            transition: all 0.3s;
            font-size: 0.95rem; /* Fuente más pequeña */
            background-color: #f8fbff;
            height: auto;
        }
        
        .form-control:focus {
            border-color: var(--sky-blue-medium);
            box-shadow: 0 0 0 0.25rem rgba(0, 191, 255, 0.2);
            background-color: white;
        }
        
        .input-group-text {
            background-color: var(--sky-blue-light);
            border-radius: 10px 0 0 10px;
            border: 2px solid #d1e3ff;
            border-right: none;
            color: var(--sky-blue-dark);
            padding: 0 18px;
            font-size: 1rem; /* Icono más pequeño */
            font-weight: bold;
        }
        
        .btn-login {
            background: linear-gradient(135deg, var(--sky-blue-primary) 0%, var(--sky-blue-dark) 100%);
            border: none;
            border-radius: 10px;
            padding: 14px; /* Más compacto */
            font-weight: 600;
            transition: all 0.3s;
            color: white;
            position: relative;
            overflow: hidden;
            font-size: 1rem; /* Fuente más pequeña */
            letter-spacing: 0.3px;
            box-shadow: 0 5px 15px rgba(30, 144, 255, 0.3);
            height: auto;
            margin-top: 5px;
        }
        
        .btn-login:hover:not(:disabled) {
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(30, 144, 255, 0.4);
            background: linear-gradient(135deg, var(--sky-blue-medium) 0%, #0052cc 100%);
        }
        
        .btn-login:active:not(:disabled) {
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(30, 144, 255, 0.3);
        }
        
        .btn-login:disabled {
            opacity: 0.7;
            cursor: not-allowed;
        }
        
        .btn-login.loading {
            color: transparent;
        }
        
        .btn-login.loading::after {
            content: '';
            position: absolute;
            width: 20px; /* Spinner más pequeño */
            height: 20px; /* Spinner más pequeño */
            top: 50%;
            left: 50%;
            margin-left: -10px;
            margin-top: -10px;
            border: 3px solid rgba(255, 255, 255, 0.3);
            border-radius: 50%;
            border-top-color: white;
            animation: spin 0.8s linear infinite;
        }
        
        .form-label {
            font-weight: 600;
            color: #1a365d;
            margin-bottom: 8px; /* Margen más pequeño */
            font-size: 0.95rem; /* Fuente más pequeña */
        }
        
        .form-label i {
            color: var(--sky-blue-dark);
            width: 24px; /* Ancho más pequeño */
            text-align: center;
            font-size: 0.9rem;
        }
        
        .system-title {
            font-size: 1.8rem; /* Título más pequeño */
            font-weight: 700;
            margin: 0;
            letter-spacing: 0.3px;
            text-shadow: 1px 1px 3px rgba(0, 0, 0, 0.3);
            margin-bottom: 8px;
        }
        
        .system-subtitle {
            font-size: 0.9rem; /* Subtítulo más pequeño */
            opacity: 0.95;
            margin-top: 5px;
            font-weight: 400;
            letter-spacing: 0.2px;
            background: rgba(255, 255, 255, 0.2);
            display: inline-block;
            padding: 4px 12px;
            border-radius: 15px;
        }
        
        .password-toggle {
            cursor: pointer;
            background: var(--sky-blue-light);
            border: 2px solid #d1e3ff;
            border-left: none;
            border-radius: 0 10px 10px 0;
            color: var(--sky-blue-dark);
            padding: 0 18px;
            transition: all 0.3s;
            font-size: 1rem;
            font-weight: bold;
        }
        
        .password-toggle:hover {
            background: var(--sky-blue-primary);
            color: white;
            border-color: var(--sky-blue-primary);
        }
        
        .footer-links {
            text-align: center;
            margin-top: 20px; /* Margen más pequeño */
            font-size: 0.85rem; /* Fuente más pequeña */
            color: #4a5568;
            padding-top: 18px; /* Padding más pequeño */
            border-top: 1px solid #e2e8f0;
        }
        
        .footer-links a {
            color: var(--sky-blue-dark);
            text-decoration: none;
            font-weight: 600;
            transition: color 0.3s;
        }
        
        .footer-links a:hover {
            color: var(--sky-blue-primary);
            text-decoration: underline;
        }
        
        .footer-links i {
            margin-right: 6px;
            font-size: 0.8rem;
        }
        
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(-20px) scale(0.95); }
            to { opacity: 1; transform: translateY(0) scale(1); }
        }
        
        @keyframes spin {
            to { transform: rotate(360deg); }
        }
        
        @keyframes shake {
            0%, 100% { transform: translateX(0); }
            10%, 30%, 50%, 70%, 90% { transform: translateX(-5px); }
            20%, 40%, 60%, 80% { transform: translateX(5px); }
        }
        
        .shake {
            animation: shake 0.4s ease-in-out;
        }
        
        /* Alertas más compactas */
        .alert {
            border-radius: 10px;
            border: 2px solid transparent;
            padding: 12px 18px; /* Más compacto */
            margin-top: 15px;
            font-weight: 500;
            font-size: 0.9rem; /* Fuente más pequeña */
        }
        
        .alert-danger {
            background-color: rgba(220, 53, 69, 0.1);
            border-color: rgba(220, 53, 69, 0.2);
            color: #c53030;
        }
        
        .alert-success {
            background-color: rgba(25, 135, 84, 0.1);
            border-color: rgba(25, 135, 84, 0.2);
            color: #0f9d58;
        }
        
        /* Input group con sombra más sutil */
        .input-group {
            box-shadow: 0 3px 10px rgba(0, 0, 0, 0.05);
            border-radius: 10px;
            transition: box-shadow 0.3s;
            margin-bottom: 3px;
        }
        
        .input-group:focus-within {
            box-shadow: 0 3px 15px rgba(0, 191, 255, 0.2);
        }
        
        /* Responsive para PC grande - mantener pequeño */
        @media (min-width: 1400px) {
            .login-container {
                max-width: 380px; /* Incluso más pequeño en pantallas grandes */
            }
        }
        
        /* Responsive para tablets */
        @media (max-width: 768px) {
            .login-container {
                max-width: 350px;
            }
            
            .login-body {
                padding: 25px 20px;
            }
            
            .login-header {
                padding: 20px 15px;
            }
            
            .system-title {
                font-size: 1.6rem;
            }
            
            .login-header i {
                font-size: 2.5rem;
                width: 60px;
                height: 60px;
                line-height: 60px;
            }
        }
        
        /* Responsive para móviles */
        @media (max-width: 576px) {
            .login-container {
                max-width: 320px;
            }
            
            .login-body {
                padding: 20px 18px;
            }
            
            .login-header {
                padding: 18px 15px;
            }
            
            .system-title {
                font-size: 1.5rem;
            }
            
            .login-header i {
                font-size: 2.2rem;
                width: 55px;
                height: 55px;
                line-height: 55px;
            }
            
            .form-control {
                padding: 11px 14px;
                font-size: 0.9rem;
            }
            
            .btn-login {
                padding: 12px;
                font-size: 0.95rem;
            }
        }
        
        /* Móviles muy pequeños */
        @media (max-width: 400px) {
            .login-container {
                max-width: 95%;
            }
            
            .login-body {
                padding: 18px 15px;
            }
            
            .login-header {
                padding: 15px 12px;
            }
            
            .system-title {
                font-size: 1.4rem;
            }
            
            .system-subtitle {
                font-size: 0.8rem;
            }
        }
        
        /* Fallback para cuando la imagen no carga */
        .no-bg-image {
            background: linear-gradient(135deg, #a1c4fd 0%, #c2e9fb 100%) !important;
        }
        
        /* Estilo para el enlace de copyright */
        .copyright {
            font-size: 0.8rem;
            color: #718096;
            margin-top: 15px;
        }
        
        .copyright i {
            color: var(--sky-blue-primary);
            font-size: 0.75rem;
        }
        
        /* Efecto de brillo en el botón - más sutil */
        .btn-login::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(
                90deg,
                transparent,
                rgba(255, 255, 255, 0.15),
                transparent
            );
            transition: left 0.7s;
        }
        
        .btn-login:hover::before {
            left: 100%;
        }
        
        /* Animación para el icono del header - más sutil */
        @keyframes pulse {
            0% { transform: scale(1); }
            50% { transform: scale(1.03); }
            100% { transform: scale(1); }
        }
        
        .login-header i {
            animation: pulse 3s infinite;
        }
        
        /* Mejor separación entre elementos del formulario - más compacta */
        .mb-4 {
            margin-bottom: 1.5rem !important;
        }
        
        /* Espaciado mejorado */
        .d-grid {
            gap: 0.8rem !important;
        }
        
        /* Clases de Bootstrap override para más compacidad */
        .mb-3 {
            margin-bottom: 1rem !important;
        }
    </style>
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
    
    <script>
        // Evitar múltiples envíos del formulario
        let formSubmitting = false;
        
        // Toggle para mostrar/ocultar contraseña
        document.getElementById('togglePassword').addEventListener('click', function() {
            const passwordInput = document.getElementById('contraseña');
            const icon = this.querySelector('i');
            
            if (passwordInput.type === 'password') {
                passwordInput.type = 'text';
                icon.classList.remove('fa-eye');
                icon.classList.add('fa-eye-slash');
            } else {
                passwordInput.type = 'password';
                icon.classList.remove('fa-eye-slash');
                icon.classList.add('fa-eye');
            }
        });
        
        // Validación del formulario antes de enviar
        document.getElementById('loginForm').addEventListener('submit', function(e) {
            if (formSubmitting) {
                e.preventDefault();
                return false;
            }
            
            const emailInput = document.getElementById('correo');
            const passwordInput = document.getElementById('contraseña');
            const btnLogin = document.getElementById('btnLogin');
            
            // Limpiar estados previos
            emailInput.classList.remove('is-invalid');
            passwordInput.classList.remove('is-invalid');
            
            let isValid = true;
            
            // Validación de email
            const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
            if (!emailRegex.test(emailInput.value.trim())) {
                emailInput.classList.add('is-invalid');
                isValid = false;
            }
            
            // Validación de contraseña
            if (passwordInput.value.length < 1) {
                passwordInput.classList.add('is-invalid');
                isValid = false;
            }
            
            if (!isValid) {
                e.preventDefault();
                return false;
            }
            
            // Cambiar estado del botón durante el envío
            formSubmitting = true;
            btnLogin.innerHTML = '';
            btnLogin.classList.add('loading');
            btnLogin.disabled = true;
            
            // Permitir el envío del formulario
            return true;
        });
        
        // Resetear estado del formulario si hay errores de validación
        document.getElementById('loginForm').addEventListener('input', function() {
            const emailInput = document.getElementById('correo');
            const passwordInput = document.getElementById('contraseña');
            const btnLogin = document.getElementById('btnLogin');
            
            // Remover estado de error al empezar a escribir
            if (emailInput.classList.contains('is-invalid')) {
                emailInput.classList.remove('is-invalid');
            }
            
            if (passwordInput.classList.contains('is-invalid')) {
                passwordInput.classList.remove('is-invalid');
            }
            
            // Si el botón está deshabilitado (por error previo) y hay contenido, habilitarlo
            if (btnLogin.disabled && !btnLogin.classList.contains('loading')) {
                btnLogin.disabled = false;
            }
        });
        
        // Auto-focus en el campo de correo al cargar la página
        document.addEventListener('DOMContentLoaded', function() {
            document.getElementById('correo').focus();
            
            // Detectar si la imagen de fondo no carga
            const bgImage = new Image();
            bgImage.src = '../images/agencia.avif';
            
            bgImage.onerror = function() {
                document.body.classList.add('no-bg-image');
                console.log('La imagen de fondo no pudo cargar, usando gradiente alternativo');
            };
            
            bgImage.onload = function() {
                console.log('Imagen de fondo cargada correctamente');
            };
            
            // Si hay un error de redirección (por ejemplo, si JavaScript bloqueó la redirección automática)
            // podemos agregar un botón de redirección manual
            setTimeout(() => {
                const btnLogin = document.getElementById('btnLogin');
                if (btnLogin.classList.contains('loading')) {
                    // Si después de 10 segundos sigue en estado loading, restaurar botón
                    btnLogin.classList.remove('loading');
                    btnLogin.disabled = false;
                    btnLogin.innerHTML = '<i class="fas fa-sign-in-alt me-2"></i>Acceder a Agencia';
                    formSubmitting = false;
                    
                    // Mostrar mensaje de error
                    showAlert('Error de conexión. Por favor intente nuevamente.', 'danger');
                }
            }, 10000);
        });
        
        function showAlert(message, type) {
            // Remover alertas anteriores del mismo tipo
            const oldAlerts = document.querySelectorAll('.alert.temporary');
            oldAlerts.forEach(alert => alert.remove());
            
            // Crear alerta temporal
            const alertDiv = document.createElement('div');
            alertDiv.className = `alert alert-${type} alert-dismissible fade show text-center shake temporary`;
            alertDiv.innerHTML = `
                <i class="fas ${type === 'danger' ? 'fa-exclamation-circle' : 'fa-info-circle'} me-2"></i>
                ${message}
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            `;
            
            // Insertar antes del formulario
            const form = document.getElementById('loginForm');
            if (form && form.parentNode) {
                form.parentNode.insertBefore(alertDiv, form);
                
                // Auto-eliminar después de 5 segundos
                setTimeout(() => {
                    if (alertDiv.parentNode) {
                        try {
                            alertDiv.remove();
                        } catch (e) {
                            console.log('Error al remover alerta:', e);
                        }
                    }
                }, 5000);
            }
        }
        
        // Prevenir doble clic en el botón
        document.getElementById('btnLogin').addEventListener('click', function(e) {
            if (formSubmitting) {
                e.preventDefault();
                e.stopPropagation();
                return false;
            }
        });
        
        // Permitir enviar el formulario con Enter (solo si no está en estado de envío)
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Enter' && !e.target.matches('button') && !formSubmitting) {
                const btnLogin = document.getElementById('btnLogin');
                if (!btnLogin.disabled) {
                    document.getElementById('loginForm').dispatchEvent(new Event('submit'));
                }
            }
        });
        
        // Agregar efecto visual al hacer hover en el botón
        const btnLogin = document.getElementById('btnLogin');
        btnLogin.addEventListener('mouseenter', function() {
            if (!this.disabled) {
                this.style.transform = 'translateY(-2px)';
            }
        });
        
        btnLogin.addEventListener('mouseleave', function() {
            if (!this.disabled && !this.classList.contains('loading')) {
                this.style.transform = 'translateY(0)';
            }
        });
    </script>
</body>
</html>
<?php
ob_end_flush();
?>