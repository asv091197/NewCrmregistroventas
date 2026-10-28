<?php
require_once __DIR__ . '/../../includes/db.php';
header('Access-Control-Allow-Origin: *');
require_once __DIR__ . '/../../session_config.php'; session_start();
date_default_timezone_set('America/Mexico_City');
require_once __DIR__ . '/../../includes/registro_acciones.php';

$rolSesion = $_SESSION['rol'] ?? '';
if (!isset($_SESSION['usuario_id']) || ($rolSesion !== 'admin' && $rolSesion !== 'superadmin')) {
    header("Location: ../login.php");
    exit;
}

// Conexión a la base de datos
try {
    $pdo = conexionPDO();
} catch (PDOException $e) {
    error_log("Error de conexión: " . $e->getMessage());
    die("Error de conexión a la base de datos.");
}

// OBTENER INFORMACIÓN DEL ADMIN LOGEADO
$admin_id = $_SESSION['usuario_id'];
try {
    $stmtAdmin = $pdo->prepare("SELECT acceso_todas_salas, salas_permitidas FROM usuarios WHERE id = ?");
    $stmtAdmin->execute([$admin_id]);
    $adminData = $stmtAdmin->fetch(PDO::FETCH_ASSOC);

    // Superadmin siempre tiene acceso a todas las salas
    $admin_acceso_todas = $rolSesion === 'superadmin' ? 1 : (int)($adminData['acceso_todas_salas'] ?? 0);

    // Convertir salas permitidas a array
    $admin_salas_array = json_decode($adminData['salas_permitidas'] ?? '[]', true);
    if (!is_array($admin_salas_array)) {
        $admin_salas_array = [];
    }
} catch (PDOException $e) {
    error_log("Error al cargar permisos del administrador: " . $e->getMessage());
    die("Error al cargar permisos del administrador.");
}

// Procesamiento del formulario
$mensaje = '';
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $nombre_agente = trim($_POST["apemat"] ?? '');  // Campo: Nombre Agente
    $nombre_asesor = trim($_POST["nombre"] ?? '');  // Campo: Nombre Asesor
    $apellido_asesor = trim($_POST["apepat"] ?? ''); // Campo: Apellido Asesor
    $email = trim($_POST["email"] ?? '');
    $pass = $_POST["password"] ?? '';
    $rol = $_POST["rol"] ?? '';
    $acceso_salas = $_POST["acceso_salas"] ?? '';
    $salas_seleccionadas = $_POST["salas_seleccionadas"] ?? '';

    // VALIDAR ROL Y PERMISOS DEL ADMIN
    $error_permiso = false;

    if (!empty($rol) && !in_array($rol, ['admin', 'usuario'], true)) {
        $mensaje = "<div class='alert alert-danger'>Rol de usuario no válido.</div>";
        $error_permiso = true;
    } elseif ($acceso_salas === 'especificas' && empty($salas_seleccionadas)) {
        $mensaje = "<div class='alert alert-danger'>Debes seleccionar una sala para el usuario.</div>";
        $error_permiso = true;
    } elseif ($admin_acceso_todas == 0) {
        // Admin limitado: solo puede crear usuarios con acceso a sus salas
        if ($acceso_salas === 'todas') {
            $mensaje = "<div class='alert alert-danger'>No tienes permiso para crear usuarios con acceso a todas las salas.</div>";
            $error_permiso = true;
        } elseif ($acceso_salas === 'especificas' && !in_array($salas_seleccionadas, $admin_salas_array)) {
            // La sala seleccionada debe estar entre las permitidas del admin
            $mensaje = "<div class='alert alert-danger'>No tienes permiso para asignar esa sala al usuario.</div>";
            $error_permiso = true;
        }
    }

    if (!empty($nombre_agente) && !empty($nombre_asesor) && filter_var($email, FILTER_VALIDATE_EMAIL) && !empty($pass) && !empty($rol) && !$error_permiso) {
        // Verificar si el correo ya existe
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM usuarios WHERE correo = ?");
        $stmt->execute([$email]);
        $existe = $stmt->fetchColumn();

        if ($existe > 0) {
            $mensaje = "<div class='alert alert-danger'>El correo ya está registrado. Intenta con otro.</div>";
        } else {
            $hash = password_hash($pass, PASSWORD_DEFAULT);

            // Procesar salas según tipo de acceso
            $acceso_todas_salas = ($acceso_salas === 'todas') ? 1 : 0;
            $salas_permitidas = null;

            if ($acceso_salas === 'especificas' && !empty($salas_seleccionadas)) {
                $salas_permitidas = json_encode([$salas_seleccionadas]); // Convertir a array
            }

            try {
                // Insertar con los nombres de campo correctos
                $stmt = $pdo->prepare("INSERT INTO usuarios (nombre, apepat, apemat, correo, contrasena, rol, acceso_todas_salas, salas_permitidas) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([$nombre_asesor, $apellido_asesor, $nombre_agente, $email, $hash, $rol, $acceso_todas_salas, $salas_permitidas]);

                registrarAccion($pdo, 'INSERT', 'usuarios', $pdo->lastInsertId(), [
                    'nombre' => trim("$nombre_asesor $apellido_asesor $nombre_agente"),
                    'correo' => $email,
                    'rol' => $rol,
                    'acceso_todas_salas' => $acceso_todas_salas,
                    'salas_permitidas' => $salas_permitidas
                ]);

                $mensaje = "<div class='alert alert-success'>Usuario registrado como <strong>" . htmlspecialchars($rol) . "</strong>.</div>";

                header("refresh:2;url=usuarios.php");
            } catch (PDOException $e) {
                $mensaje = "<div class='alert alert-danger'>Error: " . htmlspecialchars($e->getMessage()) . "</div>";
            }
        }
    } elseif (!$error_permiso) {
        $mensaje = "<div class='alert alert-warning'>Completa todos los campos correctamente.</div>";
    }
}
?>

<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <title>Registro de Usuario</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        .salas-container {
            border: 1px solid #dee2e6;
            border-radius: 0.375rem;
            padding: 1rem;
            background-color: #f8f9fa;
        }

        .salas-list {
            max-height: 200px;
            overflow-y: auto;
        }

        .admin-badge {
            font-size: 0.7rem;
            vertical-align: middle;
        }

        .permission-info {
            font-size: 0.9rem;
            color: #6c757d;
        }
    </style>
</head>

<body class="bg-light">
    <nav class="navbar navbar-expand-lg navbar-light bg-white shadow-sm fixed-top border-bottom">
        <div class="container-fluid">
            <div class="d-flex align-items-center ms-auto">
                <ul class="navbar-nav me-3">
                    <?php if ($_SESSION['rol'] === 'admin' || $_SESSION['rol'] === 'superadmin'): ?>
                        <li class="nav-item">
                            <a class="nav-link text-dark" href="usuarios.php" style="text-decoration: none;">
                                👤 Usuarios
                            </a>
                        </li>
                    <?php endif; ?>
                    <li class="nav-item">
                        <a class="nav-link text-dark" href="../Agencia/agencia.php" style="text-decoration: none;">
                            🕵️ Agentes
                        </a>
                    </li>
                </ul>

                <span class="navbar-text text-muted me-3">
                    <?php
                    if (isset($_SESSION['apemat']) && !empty($_SESSION['apemat'])) {
                        echo htmlspecialchars($_SESSION['apemat']);
                    } elseif (isset($_SESSION['nombre']) && !empty($_SESSION['nombre'])) {
                        echo htmlspecialchars($_SESSION['nombre']);
                    } elseif (isset($_SESSION['usuario']) && !empty($_SESSION['usuario'])) {
                        echo htmlspecialchars($_SESSION['usuario']);
                    } else {
                        echo 'Usuario';
                    }
                    ?>
                </span>

                <a href="../logout.php" class="btn btn-sm btn-outline-danger">
                    <i class="bi bi-box-arrow-right"></i> Salir
                </a>
            </div>
        </div>
    </nav>

    <div style="height: 70px;"></div>
    <div class="container mt-5">
        <div class="row justify-content-center">
            <div class="col-md-8 col-lg-6">
                <div class="card shadow">
                    <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center">
                        <h4 class="mb-0">Registro de usuario</h4>
                        <?php if ($admin_acceso_todas == 1): ?>
                            <span class="badge bg-light text-primary">Vista Total</span>
                        <?php else: ?>
                            <span class="badge bg-warning text-dark">Vista Limitada</span>
                        <?php endif; ?>
                    </div>
                    <div class="card-body">
                        <?php if ($admin_acceso_todas == 0): ?>
                            <div class="alert alert-info mb-3">
                                <i class="bi bi-info-circle me-2"></i>
                                <strong>Vista Limitada:</strong> Solo puedes crear usuarios con acceso a tus salas permitidas.
                            </div>
                        <?php endif; ?>

                        <?= $mensaje ?>

                        <form method="POST" action="" id="formRegistro">
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label for="apemat" class="form-label">Nombre Agente<span class="text-danger">*</span></label>
                                        <input type="text" class="form-control" id="apemat" name="apemat" required>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label for="nombre" class="form-label">Nombre Asesor <span class="text-danger">*</span></label>
                                        <input type="text" class="form-control" id="nombre" name="nombre" required>
                                    </div>
                                </div>
                            </div>

                            <div class="row">
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label for="apepat" class="form-label">Apellido Asesor</label>
                                        <input type="text" class="form-control" id="apepat" name="apepat">
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label for="email" class="form-label">Correo electrónico <span class="text-danger">*</span></label>
                                        <input type="email" class="form-control" id="email" name="email" required>
                                    </div>
                                </div>
                            </div>

                            <div class="row">
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label for="password" class="form-label">Contraseña <span class="text-danger">*</span></label>
                                        <input type="password" class="form-control" id="password" name="password" required minlength="6">
                                        <div class="form-text">Mínimo 6 caracteres</div>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label for="rol" class="form-label">Rol de usuario <span class="text-danger">*</span></label>
                                        <select class="form-select" id="rol" name="rol" required>
                                            <option value="">Selecciona un rol</option>
                                            <option value="admin">Administrador</option>
                                            <option value="usuario">Agente</option>
                                        </select>
                                    </div>
                                </div>
                            </div>

                            <!-- Sección de salas - MODIFICADA según permisos -->
                            <div class="mb-3">
                                <label class="form-label">Acceso a salas <span class="text-danger">*</span></label>
                                <div class="salas-container">
                                    <?php if ($admin_acceso_todas == 1): ?>
                                        <!-- Admin total: puede asignar todas las salas o específicas -->
                                        <div class="form-check mb-3">
                                            <input class="form-check-input" type="radio" name="acceso_salas" id="todas_salas" value="todas" checked>
                                            <label class="form-check-label fw-bold" for="todas_salas">
                                                <i class="bi bi-building-check"></i> Todas las salas
                                            </label>
                                            <div class="form-text">El usuario tendrá acceso a todas las salas existentes y futuras</div>
                                        </div>

                                        <div class="form-check mb-3">
                                            <input class="form-check-input" type="radio" name="acceso_salas" id="salas_especificas" value="especificas">
                                            <label class="form-check-label fw-bold" for="salas_especificas">
                                                <i class="bi bi-building"></i> Salas específicas
                                            </label>
                                        </div>
                                    <?php else: ?>
                                        <!-- Admin limitado: solo puede asignar salas específicas -->
                                        <div class="alert alert-warning">
                                            <i class="bi bi-exclamation-triangle"></i>
                                            <strong>Nota:</strong> Como administrador limitado, solo puedes asignar acceso a tus salas permitidas.
                                        </div>
                                        <input type="hidden" name="acceso_salas" value="especificas">
                                    <?php endif; ?>

                                    <div id="selector_salas" style="<?= ($admin_acceso_todas == 1) ? 'display: none;' : '' ?> margin-top: 15px;">
                                        <div class="salas-list">
                                            <?php
                                            // Obtener salas según permisos del admin
                                            if ($admin_acceso_todas == 1) {
                                                // Admin total: todas las salas activas
                                                $stmtSalas = $pdo->query("SELECT id, nombre FROM salas WHERE activo = 1 ORDER BY nombre");
                                            } else {
                                                // Admin limitado: solo sus salas permitidas
                                                if (!empty($admin_salas_array)) {
                                                    $placeholders = implode(',', array_fill(0, count($admin_salas_array), '?'));
                                                    $stmtSalas = $pdo->prepare("SELECT id, nombre FROM salas WHERE id IN ($placeholders) AND activo = 1 ORDER BY nombre");
                                                    $stmtSalas->execute($admin_salas_array);
                                                } else {
                                                    $stmtSalas = $pdo->query("SELECT id, nombre FROM salas WHERE 1=0");
                                                }
                                            }

                                            $salas = $stmtSalas->fetchAll(PDO::FETCH_ASSOC);
                                            if (count($salas) > 0) {
                                                foreach ($salas as $sala) {
                                                    $salaId = (int)$sala['id'];
                                                    $salaNombre = htmlspecialchars($sala['nombre']);
                                                    echo "<div class='form-check'>";
                                                    echo "<input class='form-check-input' type='radio' name='salas_seleccionadas' value='{$salaId}' id='sala_{$salaId}'>";
                                                    echo "<label class='form-check-label' for='sala_{$salaId}'>{$salaNombre}</label>";
                                                    echo "</div>";
                                                }
                                            } else {
                                                echo "<div class='alert alert-warning'>No hay salas disponibles para asignar</div>";
                                            }
                                            ?>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="d-grid gap-2">
                                <button type="submit" class="btn btn-success btn-lg">
                                    <i class="bi bi-person-plus"></i> Registrar Usuario
                                </button>
                                <a href="usuarios.php" class="btn btn-outline-secondary">
                                    <i class="bi bi-arrow-left"></i> Volver a Usuarios
                                </a>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const todasSalas = document.getElementById('todas_salas');
            const salasEspecificas = document.getElementById('salas_especificas');
            const selectorSalas = document.getElementById('selector_salas');
            const radioSalas = document.querySelectorAll('input[name="salas_seleccionadas"]');

            // Solo configurar eventos si el admin es total
            <?php if ($admin_acceso_todas == 1): ?>

                function toggleSalasSelector() {
                    if (salasEspecificas.checked) {
                        selectorSalas.style.display = 'block';
                    } else {
                        selectorSalas.style.display = 'none';
                        // Desmarcar todos los radio buttons
                        radioSalas.forEach(radio => {
                            radio.checked = false;
                        });
                    }
                }

                // Event listeners solo para admin total
                if (todasSalas && salasEspecificas) {
                    todasSalas.addEventListener('change', toggleSalasSelector);
                    salasEspecificas.addEventListener('change', toggleSalasSelector);
                }
            <?php endif; ?>

            // Validación del formulario
            document.getElementById('formRegistro').addEventListener('submit', function(e) {
                // Validar que se haya seleccionado una sala si es necesario
                <?php if ($admin_acceso_todas == 1): ?>
                    if (salasEspecificas && salasEspecificas.checked) {
                        const algunaSeleccionada = Array.from(radioSalas).some(radio => radio.checked);
                        if (!algunaSeleccionada) {
                            e.preventDefault();
                            alert('Por favor selecciona una sala o elige "Todas las salas"');
                            return false;
                        }
                    }
                <?php else: ?>
                    // Para admin limitado, siempre debe seleccionar una sala
                    const algunaSeleccionada = Array.from(radioSalas).some(radio => radio.checked);
                    if (!algunaSeleccionada) {
                        e.preventDefault();
                        alert('Por favor selecciona una sala para el usuario');
                        return false;
                    }
                <?php endif; ?>

                // Validar contraseña
                const password = document.getElementById('password');
                if (password.value.length < 6) {
                    e.preventDefault();
                    alert('La contraseña debe tener al menos 6 caracteres');
                    password.focus();
                    return false;
                }

                return true;
            });
        });
    </script>
</body>

</html>