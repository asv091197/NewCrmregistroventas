<?php
// facturacion/login.php

date_default_timezone_set('America/Mexico_City');

error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/php_errors.log');

require_once __DIR__ . '/../session_config.php'; session_start();

function debugLog($message, $data = null) {
    $logFile = __DIR__ . '/debug_facturacion.log';
    $timestamp = date('Y-m-d H:i:s');
    $logMessage = "[$timestamp] $message";
    if ($data !== null) {
        $logMessage .= " - " . print_r($data, true);
    }
    $logMessage .= "\n";
    file_put_contents($logFile, $logMessage, FILE_APPEND);
}

// ============================================================
// FUNCIONES DE LOGS (IP, DISPOSITIVO, SO, NAVEGADOR, GEO)
// ============================================================

function obtenerIP() {
    $headers = [
        'HTTP_CF_CONNECTING_IP',
        'HTTP_X_FORWARDED_FOR',
        'HTTP_X_REAL_IP',
        'HTTP_CLIENT_IP',
        'REMOTE_ADDR'
    ];
    foreach ($headers as $header) {
        if (!empty($_SERVER[$header])) {
            $ips = explode(',', $_SERVER[$header]);
            $ip = trim($ips[0]);
            if (filter_var($ip, FILTER_VALIDATE_IP)) {
                return $ip;
            }
        }
    }
    return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}

function detectarDispositivo($userAgent) {
    $ua = strtolower($userAgent);
    if (preg_match('/ipad|tablet|playbook|silk|kindle|nexus 7|nexus 9|nexus 10/i', $ua)) {
        return 'Tablet';
    }
    if (preg_match('/mobile|iphone|ipod|android.*mobile|windows phone|blackberry|opera mini|iemobile/i', $ua)) {
        return 'Móvil';
    }
    if (preg_match('/windows nt|macintosh|mac os x|linux|x11|cros/i', $ua)) {
        return 'PC';
    }
    return 'Desconocido';
}

function detectarSO($userAgent) {
    $ua = $userAgent;
    $osArray = [
        '/Windows NT 10.0/i'  => 'Windows 10/11',
        '/Windows NT 6.3/i'   => 'Windows 8.1',
        '/Windows NT 6.2/i'   => 'Windows 8',
        '/Windows NT 6.1/i'   => 'Windows 7',
        '/Windows NT 6.0/i'   => 'Windows Vista',
        '/Windows NT 5.1/i'   => 'Windows XP',
        '/Windows Phone/i'    => 'Windows Phone',
        '/Windows/i'          => 'Windows',
        '/iPhone OS ([0-9_]+)/i' => 'iOS',
        '/iPad.*OS ([0-9_]+)/i'  => 'iPadOS',
        '/Mac OS X/i'         => 'macOS',
        '/Android ([0-9.]+)/i' => 'Android',
        '/CrOS/i'             => 'Chrome OS',
        '/Ubuntu/i'           => 'Ubuntu',
        '/Fedora/i'           => 'Fedora',
        '/Linux/i'            => 'Linux',
        '/BlackBerry/i'       => 'BlackBerry OS',
    ];
    foreach ($osArray as $pattern => $name) {
        if (preg_match($pattern, $ua, $matches)) {
            if (!empty($matches[1])) {
                return $name . ' ' . str_replace('_', '.', $matches[1]);
            }
            return $name;
        }
    }
    return 'Desconocido';
}

function detectarNavegador($userAgent) {
    $ua = $userAgent;
    $navegadores = [
        '/Edg\/([0-9.]+)/i'             => 'Edge',
        '/OPR\/([0-9.]+)/i'             => 'Opera',
        '/OPiOS\/([0-9.]+)/i'           => 'Opera Mini',
        '/SamsungBrowser\/([0-9.]+)/i'  => 'Samsung Internet',
        '/UCBrowser\/([0-9.]+)/i'       => 'UC Browser',
        '/Firefox\/([0-9.]+)/i'         => 'Firefox',
        '/FxiOS\/([0-9.]+)/i'           => 'Firefox iOS',
        '/CriOS\/([0-9.]+)/i'           => 'Chrome iOS',
        '/Chrome\/([0-9.]+)/i'          => 'Chrome',
        '/Safari\/([0-9.]+)/i'          => 'Safari',
        '/MSIE ([0-9.]+)/i'             => 'Internet Explorer',
        '/Trident\/.*rv:([0-9.]+)/i'    => 'Internet Explorer',
        '/Brave\/([0-9.]+)/i'           => 'Brave',
        '/Vivaldi\/([0-9.]+)/i'         => 'Vivaldi',
    ];
    foreach ($navegadores as $pattern => $name) {
        if (preg_match($pattern, $ua, $matches)) {
            if ($name === 'Safari') return 'Safari';
            $version = $matches[1] ?? '';
            if ($version) {
                $version = explode('.', $version)[0];
                return $name . ' ' . $version;
            }
            return $name;
        }
    }
    return 'Desconocido';
}

function obtenerGeoIP($ip) {
    if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
        return ['pais' => null, 'ciudad' => null];
    }
    try {
        $context = stream_context_create([
            'http' => [
                'timeout' => 2,
                'method'  => 'GET',
                'header'  => "User-Agent: CRM-Facturacion/1.0\r\n"
            ]
        ]);
        $response = @file_get_contents("http://ip-api.com/json/{$ip}?fields=status,country,city&lang=es", false, $context);
        if ($response === false) {
            return ['pais' => null, 'ciudad' => null];
        }
        $data = json_decode($response, true);
        if ($data && ($data['status'] ?? '') === 'success') {
            return [
                'pais'   => $data['country'] ?? null,
                'ciudad' => $data['city'] ?? null
            ];
        }
    } catch (Exception $e) {
        debugLog('Error GeoIP: ' . $e->getMessage());
    }
    return ['pais' => null, 'ciudad' => null];
}

/**
 * Registra un intento de login en tabla logs_login con origen='facturacion'
 */
function registrarLogLoginFacturacion($conexion, array $datos) {
    if (!$conexion) {
        debugLog('No se puede registrar log facturacion: sin conexión a BD');
        return;
    }
    try {
        $userAgent   = $_SERVER['HTTP_USER_AGENT'] ?? '';
        $ip          = obtenerIP();
        $dispositivo = detectarDispositivo($userAgent);
        $so          = detectarSO($userAgent);
        $navegador   = detectarNavegador($userAgent);
        $geo         = obtenerGeoIP($ip);

        $correo      = $datos['correo'] ?? '';
        $exito       = $datos['exito'] ? 1 : 0;
        $motivoFallo = $datos['motivo_fallo'] ?? null;
        $usuarioId   = $datos['usuario_id'] ?? null;
        $pais        = $geo['pais'];
        $ciudad      = $geo['ciudad'];

        $stmt = $conexion->prepare("
            INSERT INTO logs_login 
                (usuario_id, correo, origen, exito, motivo_fallo, ip, pais, ciudad, 
                 dispositivo, sistema_operativo, navegador, user_agent, fecha_intento)
            VALUES (?, ?, 'facturacion', ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ");

        if (!$stmt) {
            debugLog('Error prepare log facturacion: ' . $conexion->error);
            return;
        }

        $stmt->bind_param(
            "isissssssss",
            $usuarioId,
            $correo,
            $exito,
            $motivoFallo,
            $ip,
            $pais,
            $ciudad,
            $dispositivo,
            $so,
            $navegador,
            $userAgent
        );

        $logId = null;
        if (!$stmt->execute()) {
            debugLog('Error execute log facturacion: ' . $stmt->error);
        } else {
            $logId = $stmt->insert_id;
            debugLog('Log login facturacion registrado', [
                'correo' => $correo, 'exito' => $exito, 'ip' => $ip,
                'dispositivo' => $dispositivo, 'so' => $so, 'navegador' => $navegador,
                'pais' => $pais, 'ciudad' => $ciudad
            ]);
        }
        $stmt->close();
        return $logId;
    } catch (Exception $e) {
        debugLog('Excepción registrando log facturacion: ' . $e->getMessage());
    }
    return null;
}

// ============================================================
// FIN FUNCIONES DE LOGS
// ============================================================

debugLog('=== INICIO DE PROCESO LOGIN FACTURACION ===');

if (isset($_SESSION['usuario_id']) && isset($_SESSION['rol'])) {
    if (in_array($_SESSION['rol'], ['admin', 'superadmin'], true)) {
        debugLog('Usuario admin ya tiene sesión activa para facturación', [
            'usuario_id' => $_SESSION['usuario_id'],
            'rol' => $_SESSION['rol']
        ]);
        header("Location: ventas.php");
        exit;
    } else {
        debugLog('Sesión no admin detectada, limpiando sesión', [
            'usuario_id' => $_SESSION['usuario_id'],
            'rol' => $_SESSION['rol']
        ]);
        session_unset();
        session_destroy();
        require_once __DIR__ . '/../session_config.php'; session_start();
    }
}

$conexion = new mysqli("65.99.205.112", "noreplyc_alexsanchez09", "SWn_VrAc3K7lq;LS", "noreplyc_crmregistroventas");
if ($conexion->connect_error) {
    debugLog('Error de conexión a BD', $conexion->connect_error);
    die("Error de conexión: " . $conexion->connect_error);
}
$conexion->set_charset("utf8mb4");

$mensaje = "";
$mensaje_tipo = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    debugLog('Método POST recibido para facturación', $_POST);

    $correo     = trim($_POST['correo'] ?? '');
    $contraseña = $_POST['contraseña'] ?? '';

    if (empty($correo) || empty($contraseña)) {
        $mensaje = "Por favor complete todos los campos";
        $mensaje_tipo = "danger";
        debugLog('Campos vacíos en facturación', ['correo' => $correo, 'password_length' => strlen($contraseña)]);

        registrarLogLoginFacturacion($conexion, [
            'correo'       => $correo,
            'exito'        => false,
            'motivo_fallo' => 'Campos vacíos'
        ]);

    } else {
        $stmt = $conexion->prepare("
            SELECT
                u.id,
                u.nombre,
                u.contrasena,
                u.rol,
                u.acceso_todas_salas,
                u.salas_permitidas
            FROM usuarios_facturacion u
            WHERE u.correo = ? AND u.activo = 1
        ");

        if (!$stmt) {
            debugLog('Error en prepare para facturación', $conexion->error);
            $mensaje = "Error interno del sistema";
            $mensaje_tipo = "danger";
        } else {
            $stmt->bind_param("s", $correo);
            $stmt->execute();
            $stmt->store_result();

            if ($stmt->num_rows === 1) {
                $stmt->bind_result($id, $nombre, $hash, $rol, $acceso_todas_salas, $salas_permitidas_json);
                $stmt->fetch();

                debugLog('Usuario encontrado en facturación', [
                    'id' => $id, 'nombre' => $nombre, 'rol' => $rol
                ]);

                if (password_verify($contraseña, $hash)) {
                    if (!in_array($rol, ['admin', 'superadmin'], true)) {
                        $mensaje = "Acceso denegado: solo los roles admin y superadmin pueden iniciar sesión.";
                        $mensaje_tipo = "danger";
                        debugLog('Intento de acceso con rol no admin', [
                            'correo' => $correo, 'rol' => $rol
                        ]);

                        registrarLogLoginFacturacion($conexion, [
                            'correo'       => $correo,
                            'exito'        => false,
                            'motivo_fallo' => 'Rol no autorizado: ' . $rol,
                            'usuario_id'   => $id
                        ]);

                    } else {
                        session_regenerate_id(true);
                        $_SESSION['usuario_id'] = $id;
                        $_SESSION['nombre']     = $nombre;
                        $_SESSION['rol']        = $rol;
                        $_SESSION['correo']     = $correo;

                        if ($acceso_todas_salas == 1) {
                            unset($_SESSION['sala_id']);
                            $_SESSION['acceso_todas_salas'] = true;
                            debugLog('Usuario con acceso a todas las salas (facturación)');
                        } else {
                            if (!empty($salas_permitidas_json) && $salas_permitidas_json !== 'null') {
                                $salas_permitidas = json_decode($salas_permitidas_json, true);
                                if (is_array($salas_permitidas) && count($salas_permitidas) > 0) {
                                    $_SESSION['sala_id'] = intval($salas_permitidas[0]);
                                    $_SESSION['todas_salas_permitidas'] = $salas_permitidas;
                                    debugLog('Salas permitidas asignadas en facturación', $salas_permitidas);
                                } else {
                                    unset($_SESSION['sala_id']);
                                }
                            } else {
                                unset($_SESSION['sala_id']);
                            }
                            $_SESSION['acceso_todas_salas'] = false;
                        }

                        $_SESSION['ultimo_acceso'] = time();

                        // LOG EXITOSO
                        $_SESSION['log_login_id'] = registrarLogLoginFacturacion($conexion, [
                            'correo'     => $correo,
                            'exito'      => true,
                            'usuario_id' => $id
                        ]);
                        $_SESSION['log_origen'] = 'facturacion';

                        debugLog('Login exitoso en facturación', [
                            'usuario'    => $correo,
                            'rol'        => $rol,
                            'session_id' => session_id()
                        ]);

                        $stmt->close();
                        $conexion->close();

                        debugLog('Redirigiendo a ventas.php (facturación)');
                        header("Location: ventas.php");
                        exit();
                    }
                } else {
                    $mensaje = "Contraseña incorrecta.";
                    $mensaje_tipo = "danger";
                    debugLog('Contraseña incorrecta para usuario en facturación', $correo);

                    registrarLogLoginFacturacion($conexion, [
                        'correo'       => $correo,
                        'exito'        => false,
                        'motivo_fallo' => 'Contraseña incorrecta',
                        'usuario_id'   => $id
                    ]);
                }
            } else {
                $mensaje = "Correo no registrado o usuario inactivo.";
                $mensaje_tipo = "danger";
                debugLog('Usuario no encontrado o inactivo en facturación', $correo);

                registrarLogLoginFacturacion($conexion, [
                    'correo'       => $correo,
                    'exito'        => false,
                    'motivo_fallo' => 'Usuario no registrado o inactivo'
                ]);
            }

            if (isset($stmt) && $stmt instanceof mysqli_stmt) {
                $stmt->close();
            }
        }
    }
} else {
    debugLog('Método no POST para facturación', $_SERVER['REQUEST_METHOD']);
}

$conexion->close();
debugLog('=== FIN DE PROCESO LOGIN FACTURACION ===');
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Iniciar Sesión - Sistema de Gestión de Ventas</title>
    
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <style>
        :root {
            --sky-blue-primary: #87CEEB;
            --sky-blue-light: #E0F7FF;
            --sky-blue-medium: #4FC3F7;
            --sky-blue-dark: #0288D1;
            --sky-blue-accent: #81D4FA;
        }
        
        body {
            background: linear-gradient(rgba(0, 0, 0, 0.7), rgba(0, 0, 0, 0.7)),
                url('../images/facturacion.avif') no-repeat center center fixed;
            background-size: cover;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }

        .login-container {
            animation: fadeIn 0.8s ease-in-out;
            max-width: 420px;
            width: 100%;
        }

        .login-card {
            background: rgba(255, 255, 255, 0.95);
            border-radius: 15px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.3);
            border: none;
            overflow: hidden;
            backdrop-filter: blur(5px);
        }

        .login-header {
            background: linear-gradient(135deg, var(--sky-blue-primary) 0%, var(--sky-blue-dark) 100%);
            color: white;
            padding: 30px 20px;
            text-align: center;
            border-bottom: 5px solid var(--sky-blue-accent);
            position: relative;
        }

        .login-header i {
            font-size: 3rem;
            margin-bottom: 15px;
            background: rgba(255, 255, 255, 0.2);
            width: 80px;
            height: 80px;
            line-height: 80px;
            border-radius: 50%;
            display: inline-block;
        }

        .login-body {
            padding: 30px;
        }

        .form-control {
            border-radius: 8px;
            padding: 12px 15px;
            border: 1px solid #ddd;
            transition: all 0.3s;
        }

        .form-control:focus {
            border-color: var(--sky-blue-medium);
            box-shadow: 0 0 0 0.25rem rgba(79, 195, 247, 0.25);
        }

        .input-group-text {
            background-color: #f8f9fa;
            border-radius: 8px 0 0 8px;
            border: 1px solid #ddd;
            color: var(--sky-blue-dark);
        }

        .btn-login {
            background: linear-gradient(135deg, var(--sky-blue-primary) 0%, var(--sky-blue-dark) 100%);
            border: none;
            border-radius: 8px;
            padding: 12px;
            font-weight: 600;
            transition: all 0.3s;
            color: white;
            position: relative;
            overflow: hidden;
        }
        
        .btn-login:hover:not(:disabled) {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(135, 206, 235, 0.4);
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
            width: 20px;
            height: 20px;
            top: 50%;
            left: 50%;
            margin-left: -10px;
            margin-top: -10px;
            border: 2px solid rgba(255, 255, 255, 0.3);
            border-radius: 50%;
            border-top-color: white;
            animation: spin 0.8s linear infinite;
        }

        .form-label {
            font-weight: 600;
            color: #333;
            margin-bottom: 8px;
        }

        .system-title {
            font-size: 1.8rem;
            font-weight: 700;
            margin: 0;
            letter-spacing: 0.5px;
        }

        .system-subtitle {
            font-size: 0.9rem;
            opacity: 0.9;
            margin-top: 5px;
        }

        .password-toggle {
            cursor: pointer;
            background: none;
            border: none;
            color: var(--sky-blue-dark);
        }

        .footer-links {
            text-align: center;
            margin-top: 20px;
            font-size: 0.9rem;
            color: #666;
        }

        .footer-links a {
            color: var(--sky-blue-dark);
            text-decoration: none;
            font-weight: 500;
        }

        .footer-links a:hover {
            text-decoration: underline;
        }

        @keyframes fadeIn {
            from {
                opacity: 0;
                transform: translateY(-20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
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
            animation: shake 0.5s ease-in-out;
        }

        .bg-source {
            position: absolute;
            bottom: 10px;
            right: 10px;
            background: rgba(0, 0, 0, 0.5);
            color: white;
            padding: 2px 8px;
            border-radius: 3px;
            font-size: 0.7rem;
            opacity: 0.7;
            transition: opacity 0.3s;
        }

        .bg-source:hover {
            opacity: 1;
        }

        @media (max-width: 576px) {
            .login-container {
                padding: 15px;
            }

            .login-body {
                padding: 20px;
            }

            .system-title {
                font-size: 1.5rem;
            }

            body {
                background: linear-gradient(rgba(0, 0, 0, 0.8), rgba(0, 0, 0, 0.8)),
                    url('../images/facturacion.avif') no-repeat center center fixed;
                background-size: cover;
            }
        }

        .no-bg-image {
            background: linear-gradient(135deg, var(--sky-blue-primary) 0%, var(--sky-blue-dark) 100%) !important;
        }
        
        .invalid-feedback {
            display: none;
            font-size: 0.875em;
            color: #dc3545;
        }
        
        .is-invalid ~ .invalid-feedback {
            display: block;
        }
        
        .is-invalid {
            border-color: #dc3545;
            padding-right: calc(1.5em + 0.75rem);
            background-image: url("data:image/svg+xml,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 12 12' width='12' height='12' fill='none' stroke='%23dc3545'%3e%3ccircle cx='6' cy='6' r='4.5'/%3e%3cpath stroke-linejoin='round' d='M5.8 3.6h.4L6 6.5z'/%3e%3ccircle cx='6' cy='8.2' r='.6' fill='%23dc3545' stroke='none'/%3e%3c/svg%3e");
            background-repeat: no-repeat;
            background-position: right calc(0.375em + 0.1875rem) center;
            background-size: calc(0.75em + 0.375rem) calc(0.75em + 0.375rem);
        }
    </style>
</head>

<body>
    <div class="container login-container">
        <div class="login-card">
            <div class="login-header">
                <i class="fas fa-chart-line"></i>
                <h1 class="system-title">Gestión de Ventas</h1>
                <p class="system-subtitle">CRM Registro Ventas</p>
            </div>

            <div class="login-body">
                <form method="POST" id="loginForm" novalidate>
                    <div class="mb-4">
                        <label for="correo" class="form-label">
                            <i class="fas fa-user-tie me-2"></i>Correo Electrónico
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

                    <div class="mb-4">
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
                            <i class="fas fa-sign-in-alt me-2"></i>Acceder a Ventas
                        </button>
                    </div>

                    <?php if (!empty($mensaje)): ?>
                        <div class="alert alert-<?php echo $mensaje_tipo; ?> alert-dismissible fade show text-center <?php echo $mensaje_tipo == 'danger' ? 'shake' : ''; ?>" role="alert">
                            <i class="fas <?php echo $mensaje_tipo == 'success' ? 'fa-check-circle' : ($mensaje_tipo == 'danger' ? 'fa-exclamation-circle' : 'fa-info-circle'); ?> me-2"></i>
                            <?php echo htmlspecialchars($mensaje); ?>
                            <?php if ($mensaje_tipo == 'danger'): ?>
                                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </form>

                <div class="footer-links">
                    <p>
                        <i class="fas fa-info-circle me-1"></i>
                        Si tiene problemas para acceder, contacte al administrador
                    </p>
                    <p class="mb-0">
                        <i class="fas fa-copyright me-1"></i>
                        <?php echo date('Y'); ?> - Grupo Ideas
                    </p>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

    <script>
        let formSubmitting = false;
        
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

        document.getElementById('loginForm').addEventListener('submit', function(e) {
            if (formSubmitting) {
                e.preventDefault();
                return false;
            }
            
            const emailInput = document.getElementById('correo');
            const passwordInput = document.getElementById('contraseña');
            const btnLogin = document.getElementById('btnLogin');
            
            emailInput.classList.remove('is-invalid');
            passwordInput.classList.remove('is-invalid');
            
            let isValid = true;
            
            const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
            if (!emailRegex.test(emailInput.value.trim())) {
                emailInput.classList.add('is-invalid');
                isValid = false;
            }
            
            if (passwordInput.value.length < 1) {
                passwordInput.classList.add('is-invalid');
                isValid = false;
            }
            
            if (!isValid) {
                e.preventDefault();
                return false;
            }
            
            formSubmitting = true;
            btnLogin.innerHTML = '';
            btnLogin.classList.add('loading');
            btnLogin.disabled = true;
            
            return true;
        });
        
        document.getElementById('loginForm').addEventListener('input', function() {
            const emailInput = document.getElementById('correo');
            const passwordInput = document.getElementById('contraseña');
            const btnLogin = document.getElementById('btnLogin');
            
            if (emailInput.classList.contains('is-invalid')) {
                emailInput.classList.remove('is-invalid');
            }
            
            if (passwordInput.classList.contains('is-invalid')) {
                passwordInput.classList.remove('is-invalid');
            }
            
            if (btnLogin.disabled && !btnLogin.classList.contains('loading')) {
                btnLogin.disabled = false;
            }
        });

        document.addEventListener('DOMContentLoaded', function() {
            document.getElementById('correo').focus();

            const bgImage = new Image();
            bgImage.src = '../images/facturacion.avif';

            bgImage.onerror = function() {
                document.body.classList.add('no-bg-image');
                console.log('La imagen de fondo no pudo cargar, usando gradiente alternativo');
            };
            
            setTimeout(() => {
                const btnLogin = document.getElementById('btnLogin');
                if (btnLogin.classList.contains('loading')) {
                    btnLogin.classList.remove('loading');
                    btnLogin.disabled = false;
                    btnLogin.innerHTML = '<i class="fas fa-sign-in-alt me-2"></i>Acceder a Ventas';
                    formSubmitting = false;
                    
                    showAlert('Error de conexión. Por favor intente nuevamente.', 'danger');
                }
            }, 10000);
        });

        function showAlert(message, type) {
            const oldAlerts = document.querySelectorAll('.alert.temporary');
            oldAlerts.forEach(alert => alert.remove());
            
            const alertDiv = document.createElement('div');
            alertDiv.className = `alert alert-${type} alert-dismissible fade show text-center shake temporary`;
            alertDiv.innerHTML = `
                <i class="fas ${type === 'danger' ? 'fa-exclamation-circle' : 'fa-info-circle'} me-2"></i>
                ${message}
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            `;
            
            const form = document.getElementById('loginForm');
            if (form && form.parentNode) {
                form.parentNode.insertBefore(alertDiv, form);
                
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
        
        document.getElementById('btnLogin').addEventListener('click', function(e) {
            if (formSubmitting) {
                e.preventDefault();
                e.stopPropagation();
                return false;
            }
        });
        
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Enter' && !e.target.matches('button') && !formSubmitting) {
                const btnLogin = document.getElementById('btnLogin');
                if (!btnLogin.disabled) {
                    document.getElementById('loginForm').dispatchEvent(new Event('submit'));
                }
            }
        });
    </script>
</body>
</html>