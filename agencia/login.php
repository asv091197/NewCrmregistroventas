<?php
// agencia/login.php

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
ob_start();

date_default_timezone_set('America/Mexico_City');

error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/php_errors.log');

function debugLog($message, $data = null) {
    $logFile = __DIR__ . '/debug_login.log';
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
                'header'  => "User-Agent: CRM-Login/1.0\r\n"
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
 * Registra un intento de login en tabla logs_login con origen='agencia'
 */
function registrarLogLogin($conexion, array $datos) {
    if (!$conexion) {
        debugLog('No se puede registrar log: sin conexión a BD');
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
            VALUES (?, ?, 'agencia', ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ");

        if (!$stmt) {
            debugLog('Error prepare log_login: ' . $conexion->error);
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
            debugLog('Error execute log_login: ' . $stmt->error);
        } else {
            $logId = $stmt->insert_id;
            debugLog('Log de login registrado (agencia)', [
                'correo' => $correo, 'exito' => $exito, 'ip' => $ip,
                'dispositivo' => $dispositivo, 'so' => $so, 'navegador' => $navegador,
                'pais' => $pais, 'ciudad' => $ciudad
            ]);
        }
        $stmt->close();
        return $logId;
    } catch (Exception $e) {
        debugLog('Excepción registrando log_login: ' . $e->getMessage());
    }
    return null;
}

// ============================================================
// FIN FUNCIONES DE LOGS
// ============================================================

debugLog('=== INICIO DE PROCESO LOGIN ===');
debugLog('Navegador: ' . ($_SERVER['HTTP_USER_AGENT'] ?? 'No disponible'));

require_once __DIR__ . '/../session_config.php'; session_start();
debugLog('Sesión ID: ' . session_id());

if (isset($_SESSION['usuario_id']) && isset($_SESSION['rol'])) {
    debugLog('Usuario ya tiene sesión activa', [
        'usuario_id' => $_SESSION['usuario_id'],
        'rol' => $_SESSION['rol']
    ]);
    
    if ($_SESSION['rol'] === 'admin' || $_SESSION['rol'] === 'superadmin') {
        header("Location: opcionesAdmin.php");
    } else {
        header("Location: Agencia/agencia.php");
    }
    exit;
}

$conexion = null;
$mensaje = "";
$mensaje_tipo = "";
$redirect_url = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    debugLog('Método POST recibido', $_POST);
    
    $correo = trim($_POST['correo'] ?? '');
    $contraseña = $_POST['contraseña'] ?? '';
    
    if (empty($correo) || empty($contraseña)) {
        $mensaje = "Por favor complete todos los campos";
        $mensaje_tipo = "danger";
        debugLog('Campos vacíos', ['correo' => $correo, 'password_length' => strlen($contraseña)]);
        
        try {
            $connLog = new mysqli("65.99.205.112", "noreplyc_alexsanchez09", "SWn_VrAc3K7lq;LS", "noreplyc_crmregistroventas");
            if (!$connLog->connect_error) {
                $connLog->set_charset("utf8mb4");
                registrarLogLogin($connLog, [
                    'correo'       => $correo,
                    'exito'        => false,
                    'motivo_fallo' => 'Campos vacíos'
                ]);
                $connLog->close();
            }
        } catch (Exception $e) {
            debugLog('Error log campos vacíos: ' . $e->getMessage());
        }
        
    } else {
        try {
            $conexion = new mysqli("65.99.205.112", "noreplyc_alexsanchez09", "SWn_VrAc3K7lq;LS", "noreplyc_crmregistroventas");
            
            if ($conexion->connect_error) {
                throw new Exception("Error de conexión: " . $conexion->connect_error);
            }
            
            $conexion->set_charset("utf8mb4");
            
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
            
            if (!$stmt) {
                throw new Exception("Error en prepare: " . $conexion->error);
            }
            
            $stmt->bind_param("s", $correo);
            
            if (!$stmt->execute()) {
                throw new Exception("Error en execute: " . $stmt->error);
            }
            
            $stmt->store_result();
            
            if ($stmt->num_rows === 1) {
                $stmt->bind_result($id, $nombre, $apemat, $hash, $rol, $acceso_todas_salas, $salas_permitidas_json);
                $stmt->fetch();
                
                debugLog('Usuario encontrado', [
                    'id' => $id,
                    'nombre' => $nombre,
                    'apemat' => $apemat,
                    'rol' => $rol
                ]);
                
                if (password_verify($contraseña, $hash)) {
                    $_SESSION['usuario_id'] = $id;
                    $_SESSION['nombre'] = $nombre;
                    $_SESSION['apemat'] = $apemat;
                    $_SESSION['rol'] = $rol;
                    $_SESSION['correo'] = $correo;
                    
                    if ($acceso_todas_salas == 1) {
                        unset($_SESSION['sala_id']);
                        $_SESSION['acceso_todas_salas'] = true;
                    } else {
                        if (!empty($salas_permitidas_json) && $salas_permitidas_json !== 'null') {
                            $salas_permitidas = json_decode($salas_permitidas_json, true);
                            if (is_array($salas_permitidas) && count($salas_permitidas) > 0) {
                                $_SESSION['sala_id'] = intval($salas_permitidas[0]);
                                $_SESSION['todas_salas_permitidas'] = $salas_permitidas;
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
                    $_SESSION['log_login_id'] = registrarLogLogin($conexion, [
                        'correo'     => $correo,
                        'exito'      => true,
                        'usuario_id' => $id
                    ]);
                    $_SESSION['log_origen'] = 'agencia';
                    
                    debugLog('Login exitoso', [
                        'usuario' => $correo,
                        'rol' => $rol,
                        'apemat' => $apemat,
                        'session_id' => session_id()
                    ]);
                    
                    $stmt->close();
                    
                    if ($rol === 'admin' || $rol === 'superadmin') {
                        $redirect_url = "opcionesAdmin.php";
                    } else {
                        $redirect_url = "Agencia/agencia.php";
                    }
                    
                    debugLog('Redirigiendo a', $redirect_url);
                    
                    ob_end_clean();
                    header("Location: " . $redirect_url);
                    exit();
                    
                } else {
                    $mensaje = "Contraseña incorrecta.";
                    $mensaje_tipo = "danger";
                    debugLog('Contraseña incorrecta para usuario', $correo);
                    
                    // LOG FALLIDO - contraseña incorrecta
                    registrarLogLogin($conexion, [
                        'correo'       => $correo,
                        'exito'        => false,
                        'motivo_fallo' => 'Contraseña incorrecta',
                        'usuario_id'   => $id
                    ]);
                }
            } else {
                $mensaje = "Correo no registrado o usuario inactivo.";
                $mensaje_tipo = "danger";
                debugLog('Usuario no encontrado o inactivo', $correo);
                
                // LOG FALLIDO - usuario inexistente/inactivo
                registrarLogLogin($conexion, [
                    'correo'       => $correo,
                    'exito'        => false,
                    'motivo_fallo' => 'Usuario no registrado o inactivo'
                ]);
            }
            
            if (isset($stmt) && $stmt instanceof mysqli_stmt) {
                $stmt->close();
            }
            
        } catch (Exception $e) {
            $mensaje = "Error interno del sistema";
            $mensaje_tipo = "danger";
            debugLog('EXCEPCIÓN: ' . $e->getMessage());
        } finally {
            if ($conexion) {
                $conexion->close();
            }
        }
    }
} else {
    debugLog('Método no POST', $_SERVER['REQUEST_METHOD']);
}

if ($conexion) {
    $conexion->close();
}

debugLog('=== FIN DE PROCESO LOGIN ===');
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Iniciar Sesión - Gestión de Agentes</title>
    
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
            max-width: 420px;
            width: 100%;
        }
        
        .login-card {
            background: rgba(255, 255, 255, 0.95);
            border-radius: 15px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.3);
            border: none;
            overflow: hidden;
        }
        
        .login-header {
            background: linear-gradient(135deg, var(--sky-blue-primary) 0%, var(--sky-blue-dark) 100%);
            color: white;
            padding: 30px 20px;
            text-align: center;
            border-bottom: 5px solid var(--sky-blue-accent);
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
            width: 100%;
        }
        
        .btn-login:hover:not(:disabled) {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(135, 206, 235, 0.4);
        }
        
        .btn-login:disabled {
            opacity: 0.7;
            cursor: not-allowed;
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
            border-radius: 0 8px 8px 0;
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
        
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(-20px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        @keyframes shake {
            0%, 100% { transform: translateX(0); }
            10%, 30%, 50%, 70%, 90% { transform: translateX(-5px); }
            20%, 40%, 60%, 80% { transform: translateX(5px); }
        }
        
        .shake {
            animation: shake 0.5s ease-in-out;
        }
        
        @media (max-width: 576px) {
            .login-container {
                padding: 10px;
            }
            
            .login-body {
                padding: 20px;
            }
            
            body {
                padding: 10px;
            }
        }
        
        .no-bg-image {
            background: linear-gradient(135deg, var(--sky-blue-primary) 0%, var(--sky-blue-dark) 100%) !important;
        }
    </style>
</head>
<body>
    <div class="container login-container">
        <div class="login-card">
            <div class="login-header">
                <i class="fas fa-users-cog"></i>
                <h1 class="system-title">Gestión de Agentes</h1>
                <p class="system-subtitle">CRM Registro Ventas</p>
            </div>
            
            <div class="login-body">
                <form method="POST" id="loginForm" novalidate>
                    <div class="mb-4">
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
                    </div>
                    
                    <div class="d-grid gap-2 mb-3">
                        <button type="submit" class="btn btn-login" id="btnLogin">
                            <i class="fas fa-sign-in-alt me-2"></i>Acceder a Agencia
                        </button>
                    </div>
                    
                    <?php if (!empty($mensaje)): ?>
                        <div class="alert alert-<?php echo $mensaje_tipo; ?> alert-dismissible fade show text-center <?php echo $mensaje_tipo == 'danger' ? 'shake' : ''; ?>" role="alert">
                            <i class="fas <?php echo $mensaje_tipo == 'success' ? 'fa-check-circle' : 'fa-exclamation-circle'; ?> me-2"></i>
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
        
        let formSubmitting = false;
        
        document.getElementById('loginForm').addEventListener('submit', function(e) {
            const btnLogin = document.getElementById('btnLogin');
            
            if (formSubmitting) {
                e.preventDefault();
                return false;
            }
            
            const emailInput = document.getElementById('correo');
            const passwordInput = document.getElementById('contraseña');
            
            if (!emailInput.value || !passwordInput.value) {
                e.preventDefault();
                return false;
            }
            
            formSubmitting = true;
            btnLogin.disabled = true;
            btnLogin.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Verificando...';
            
            return true;
        });
        
        document.addEventListener('DOMContentLoaded', function() {
            document.getElementById('correo').focus();
            
            const bgImage = new Image();
            bgImage.src = '../images/agencia.avif';
            
            bgImage.onerror = function() {
                document.body.classList.add('no-bg-image');
            };
        });
    </script>
</body>
</html>
<?php
ob_end_flush();
?>