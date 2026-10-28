<?php
require_once __DIR__ . '/../includes/db.php';
// Configuración para sesión persistente
ini_set('session.gc_probability', 1);
ini_set('session.gc_divisor', 1000);
ini_set('session.cookie_secure', 0);
ini_set('session.cookie_httponly', 1);
ini_set('session.use_strict_mode', 1);

require_once __DIR__ . '/../session_config.php'; session_start();

if (!isset($_SESSION['usuario_id'])) {
    header("Location: ./login.php");
    exit;
}

// Configuración de manejo de errores
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/php_errors.log');

// Manejo de excepciones global
set_exception_handler(function ($exception) {
    error_log("Excepción no capturada: " . $exception->getMessage());
    http_response_code(500);
    die("Error interno del servidor. Por favor, intente más tarde.");
});

// Obtener información del usuario
$nombreUsuario = 'Usuario';
if (isset($_SESSION['nombre']) && !empty($_SESSION['nombre'])) {
    $nombreUsuario = htmlspecialchars($_SESSION['nombre']);
}

// Variables para control de acceso
$es_admin = (($_SESSION['rol'] ?? '') === 'admin') || (($_SESSION['rol'] ?? '') === 'superadmin');
$accesoTodasSalas = 1; // Por defecto sí tiene acceso
$tiene_permiso_plataforma = false; // Nueva variable
$salasPermitidas = [];

try {
    $pdo = conexionPDO();

    // Obtener información del usuario de la tabla usuarios_facturacion
    $stmt = $pdo->prepare("SELECT acceso_todas_salas, salas_permitidas, permiso_plataforma FROM usuarios_facturacion WHERE id = ? AND activo = 1");
    $stmt->execute([$_SESSION['usuario_id']]);
    $usuarioData = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($usuarioData) {
        $accesoTodasSalas = $usuarioData['acceso_todas_salas'];
        $salasPermitidas = json_decode($usuarioData['salas_permitidas'] ?? '[]', true) ?? [];
        $tiene_permiso_plataforma = $usuarioData['permiso_plataforma'] == 1;
    }

    // Determinar qué salas mostrar según el tipo de usuario
    $salas_disponibles = [];
    $sala_id = null;
    $nombreSala = 'No asignada';

    if ($es_admin) {
        if ($accesoTodasSalas == 1) {
            // Admin CON acceso a todas salas: mostrar todas las salas activas
            $stmt = $pdo->prepare("SELECT id, nombre FROM salas WHERE activo = 1 ORDER BY nombre");
            $stmt->execute();
            $salas_disponibles = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Usar primera sala como valor inicial
            if (!empty($salas_disponibles)) {
                $sala_id = $salas_disponibles[0]['id'];
                $nombreSala = $salas_disponibles[0]['nombre'];
            } else {
                $nombreSala = 'No hay salas disponibles';
            }
        } else {
            // Admin SIN acceso a todas salas: mostrar solo sus salas_permitidas
            if (!empty($salasPermitidas)) {
                $placeholders = str_repeat('?,', count($salasPermitidas) - 1) . '?';
                $sql = "SELECT id, nombre FROM salas WHERE id IN ($placeholders) AND activo = 1 ORDER BY nombre";
                $stmt = $pdo->prepare($sql);
                $stmt->execute($salasPermitidas);
                $salas_disponibles = $stmt->fetchAll(PDO::FETCH_ASSOC);

                if (!empty($salas_disponibles)) {
                    $sala_id = $salas_disponibles[0]['id'];
                    $nombreSala = $salas_disponibles[0]['nombre'];
                } else {
                    $nombreSala = 'No hay salas disponibles en tus permisos';
                }
            } else {
                $nombreSala = 'No tienes salas permitidas';
            }
        }
    } else {
        // Para usuario normal: usar sesión o obtener del usuario
        if (isset($_SESSION['sala_id']) && $_SESSION['sala_id']) {
            $sala_id = $_SESSION['sala_id'];

            // Obtener nombre de la sala
            $stmt = $pdo->prepare("SELECT nombre FROM salas WHERE id = ? AND activo = 1");
            $stmt->execute([$sala_id]);
            $sala = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($sala) {
                $nombreSala = htmlspecialchars($sala['nombre']);
            }
        } else {
            // Si no hay sala en sesión, obtener del usuario
            $stmt = $pdo->prepare("SELECT salas_permitidas FROM usuarios WHERE id = ?");
            $stmt->execute([$_SESSION['usuario_id']]);
            $usuario = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($usuario && $usuario['salas_permitidas']) {
                $salas_permitidas = json_decode($usuario['salas_permitidas'], true);
                if (!empty($salas_permitidas) && is_array($salas_permitidas)) {
                    $sala_id = $salas_permitidas[0];
                    $_SESSION['sala_id'] = $sala_id;

                    // Obtener nombre de la sala
                    $stmt = $pdo->prepare("SELECT nombre FROM salas WHERE id = ? AND activo = 1");
                    $stmt->execute([$sala_id]);
                    $sala = $stmt->fetch(PDO::FETCH_ASSOC);
                    if ($sala) {
                        $nombreSala = htmlspecialchars($sala['nombre']);
                    }
                }
            }
        }
    }
} catch (PDOException $e) {
    error_log("Error al obtener información de salas: " . $e->getMessage());
    $nombreSala = 'Error al obtener';
}
?>
<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <title>Formulario de Venta</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/select2-bootstrap-5-theme@1.3.0/dist/select2-bootstrap-5-theme.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .required-field::after {
            content: " *";
            color: red;
        }

        .alert {
            display: none;
        }

        .loading {
            opacity: 0.7;
            pointer-events: none;
        }

        .select2-container--bootstrap-5 .select2-selection {
            min-height: 38px;
        }

        .select2-container--bootstrap-5 .select2-dropdown {
            z-index: 1060;
        }

        .select-with-btn {
            display: flex;
            align-items: stretch;
        }

        .select-with-btn .select2-container {
            flex: 1;
        }

        .select-with-btn .btn-add-platform {
            border-top-left-radius: 0;
            border-bottom-left-radius: 0;
            border-left: 1px solid #ced4da;
            width: 45px;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 0;
        }

        .select-with-btn .form-select {
            border-top-right-radius: 0;
            border-bottom-right-radius: 0;
            border-right: none;
        }

        .select-with-btn .select2-selection {
            border-top-right-radius: 0 !important;
            border-bottom-right-radius: 0 !important;
            border-right: none !important;
        }

        .select-with-btn .form-select:focus+.btn-add-platform {
            border-color: #86b7fe;
            box-shadow: 0 0 0 0.25rem rgba(13, 110, 253, 0.25);
        }

        .select-with-btn:hover .btn-add-platform {
            border-color: #86b7fe;
        }

        .manual-input-container {
            display: none;
            margin-top: 5px;
        }

        .toggle-input-type {
            cursor: pointer;
            color: #0d6efd;
            font-size: 0.875em;
            margin-top: 5px;
            display: inline-block;
        }

        .toggle-input-type:hover {
            text-decoration: underline;
        }

        .sala-info {
            background-color: #e7f1ff;
            border-left: 4px solid #0d6efd;
            padding: 10px 15px;
            margin-bottom: 20px;
            border-radius: 4px;
        }

        .sala-info i {
            color: #0d6efd;
            margin-right: 8px;
        }

        .sala-selector {
            background-color: #fff3cd;
            border-left: 4px solid #ffc107;
            padding: 15px;
            margin-bottom: 20px;
            border-radius: 4px;
        }

        .sala-selector i {
            color: #ffc107;
            margin-right: 8px;
        }

        .admin-badge {
            background-color: #dc3545;
            color: white;
            padding: 2px 8px;
            border-radius: 12px;
            font-size: 0.75em;
            margin-left: 8px;
        }

        .admin-badge-restricted {
            background-color: #fd7e14;
            color: white;
            padding: 2px 8px;
            border-radius: 12px;
            font-size: 0.75em;
            margin-left: 8px;
        }

        .acceso-total-info {
            background-color: #d1e7dd;
            border-left: 4px solid #198754;
            padding: 10px 15px;
            margin-top: 10px;
            border-radius: 4px;
            font-size: 0.9em;
        }

        .acceso-total-info i {
            color: #198754;
            margin-right: 8px;
        }

        .acceso-restringido-info {
            background-color: #fff3cd;
            border-left: 4px solid #ffc107;
            padding: 10px 15px;
            margin-top: 10px;
            border-radius: 4px;
            font-size: 0.9em;
        }

        .acceso-restringido-info i {
            color: #ffc107;
            margin-right: 8px;
        }

        .debug-info {
            background-color: #f8f9fa;
            border: 1px solid #dee2e6;
            padding: 8px;
            margin-top: 10px;
            border-radius: 4px;
            font-size: 0.8em;
            font-family: monospace;
        }

        /* Estilos para el interruptor de permiso */
        .permiso-plataforma-container {
            background-color: #f8f9fa;
            border: 1px solid #dee2e6;
            border-radius: 6px;
            padding: 12px;
            margin-top: 10px;
        }

        .permiso-plataforma-container .form-switch {
            margin-bottom: 5px;
        }

        .permiso-plataforma-container .text-muted {
            font-size: 0.85em;
        }

        .select2-results__option .permiso-badge {
            font-size: 0.75em;
            padding: 2px 6px;
            border-radius: 10px;
            margin-left: 8px;
        }

        .permiso-activo {
            background-color: #d1e7dd;
            color: #0f5132;
        }

        .permiso-inactivo {
            background-color: #f8d7da;
            color: #842029;
        }
    </style>
</head>

<body class="bg-light">
    <nav class="navbar navbar-expand-lg navbar-light bg-white shadow-sm fixed-top border-bottom">
        <div class="container-fluid">
            <div class="d-flex align-items-center ms-auto">
                <ul class="navbar-nav me-3">
                    <?php if ($_SESSION['rol'] === 'superadmin'): ?>
                        <li class="nav-item">
                            <a class="nav-link text-dark" href="../agencia/opcionesAdmin.php" style="text-decoration: none;">
                                Dashboard Agentes
                            </a>
                        </li>
                    <?php endif; ?>
                    <li class="nav-item">
                        <a class="nav-link text-dark" href="./Usuarios/usuarios.php" style="text-decoration: none;">
                            👤 Usuarios
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link text-dark" href="./ventas.php" style="text-decoration: none;">
                            🕵️ Ventas
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link text-dark" href="./gestionar_plataformas.php" style="text-decoration: none;">
                            💳 Plataformas
                        </a>
                    </li>
                </ul>
                <span class="navbar-text text-muted me-3">
                    <?= $nombreUsuario ?>
                </span>
                <a href="logout.php" class="btn btn-sm btn-outline-danger">
                    <i class="fas fa-sign-out-alt"></i> Salir
                </a>
            </div>
        </div>
    </nav>
    <div style="height: 70px;"></div>
    <div class="container mt-4">
        <div style="height: 20px;"></div>
        <div class="card shadow mx-auto" style="max-width: 850px;">
            <div class="card-header bg-primary text-white py-3">
                <h4 class="mb-0">Formulario de Venta</h4>
            </div>
            <div class="card-body py-4">
                <?php if ($es_admin && !empty($salas_disponibles)): ?>
                    <!-- Selector de sala para admin -->
                    <div class="row mb-4">
                        <div class="col-md-6">
                            <label for="salaSelector" class="form-label required-field">Sala</label>
                            <select class="form-select" id="salaSelector" name="salaSelector" required
                                data-acceso-todas-salas="<?= $accesoTodasSalas ?>"
                                data-es-admin="<?= $es_admin ? '1' : '0' ?>">
                                <option value="" disabled>Selecciona una sala</option>
                                <?php foreach ($salas_disponibles as $sala): ?>
                                    <option value="<?= htmlspecialchars($sala['id']) ?>"
                                        <?= ($sala_id == $sala['id']) ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($sala['nombre']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <!-- Espacio para otro campo -->
                        </div>
                    </div>
                <?php else: ?>
                    <!-- Mostrar información de la sala asignada para no-admin -->
                    <div class="sala-info mb-4">
                        <i class="fas fa-building"></i>
                        <strong>Sala asignada:</strong> <?= $nombreSala ?>
                    </div>
                <?php endif; ?>

                <div id="alertMessage" class="alert alert-dismissible fade show" role="alert">
                    <span id="alertText"></span>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>

                <form id="ventaForm" method="POST">
                    <!-- CAMPO HIDDEN PARA LA SALA - DEBE ESTAR DENTRO DEL FORM -->
                    <input type="hidden" id="sala_id" name="sala_id" value="<?= $sala_id ?>">

                    <!-- Fecha y Precio -->
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label for="fecha" class="form-label required-field">Fecha</label>
                            <input type="date" class="form-control" id="fecha" name="fecha" required>
                        </div>
                        <div class="col-md-6">
                            <label for="precio" class="form-label required-field">Precio</label>
                            <div class="input-group">
                                <span class="input-group-text">$</span>
                                <input type="text" class="form-control" id="precio" name="precio" required
                                    pattern="^\d+(\.\d{1,2})?$" title="Ingrese un valor numérico válido (ej. 1000 o 1000.50)">
                            </div>
                        </div>
                    </div>

                    <!-- Cliente y Apellidos -->
                    <div class="row mb-3">
                        <div class="col-md-4">
                            <label for="cliente" class="form-label required-field">Nombre del Cliente</label>
                            <input type="text" class="form-control" id="cliente" name="cliente" required>
                        </div>
                        <div class="col-md-4">
                            <label for="apepat" class="form-label required-field">Apellido Paterno</label>
                            <input type="text" class="form-control" id="apepat" name="apepat" required>
                        </div>
                        <div class="col-md-4">
                            <label for="apemat" class="form-label">Apellido Materno</label>
                            <input type="text" class="form-control" id="apemat" name="apemat">
                        </div>
                    </div>

                    <!-- Celular y Correo -->
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label for="celular" class="form-label required-field">Número celular</label>
                            <input type="text" class="form-control" id="celular" name="celular"
                                placeholder="55xxxxxxxx" required>
                        </div>
                        <div class="col-md-6">
                            <label for="correo" class="form-label required-field">Correo del Cliente</label>
                            <input type="email" class="form-control" id="correo" name="correo" required>
                        </div>
                    </div>

                    <!-- Producto -->
                    <div class="mb-3">
                        <label for="producto" class="form-label required-field">Producto</label>
                        <input type="text" class="form-control" id="producto" name="producto" required>
                    </div>

                    <div class="mb-3">
                        <div class="mt-3">
                            <label for="tipoCliente" class="form-label required-field">Tipo de Venta</label>
                            <select class="form-select" id="tipoCliente" name="tipoCliente" required style="max-width: 200px;">
                                <option value="" selected disabled>Selecciona el tipo</option>
                                <option value="nuevo">Nuevo</option>
                                <option value="repack">Repack</option>
                            </select>
                        </div>
                        <div class="mt-3">
                            <label class="form-label required-field">Vendedor</label>
                            <br>
                            <div class="form-check form-check-inline">
                                <input class="form-check-input" type="radio" name="tipoVenta" id="ventaNormal" value="normal" checked required>
                                <label class="form-check-label" for="ventaNormal">Closer/Liner</label>
                            </div>
                            <div class="form-check form-check-inline">
                                <input class="form-check-input" type="radio" name="tipoVenta" id="ventaFullStack" value="fullstack" required>
                                <label class="form-check-label" for="ventaFullStack">Front</label>
                            </div>
                        </div>
                    </div>

                    <!-- Liner y Closers -->
                    <div class="row mb-3" id="linerClosersDiv">
                        <div class="col-md-4">
                            <label for="liner" class="form-label required-field">Liner</label>
                            <select class="form-select search-select" id="liner" name="liner" required>
                                <option value="" selected disabled>Selecciona un liner</option>
                                <!-- Los usuarios se cargarán dinámicamente -->
                            </select>
                            <div class="toggle-input-type" onclick="toggleManualInput('liner')">
                                <i class="fas fa-edit"></i> Ingresar manualmente
                            </div>
                            <div class="manual-input-container" id="liner_manual_container">
                                <input type="text" class="form-control" id="liner_manual" name="liner_manual" placeholder="Ingresar nombre del liner">
                            </div>
                        </div>
                        <div class="col-md-4">
                            <label for="closer1" class="form-label">Closer</label>
                            <select class="form-select search-select" id="closer1" name="closer1">
                                <option value="" selected>Selecciona un closer</option>
                            </select>
                            <div class="toggle-input-type" onclick="toggleManualInput('closer1')">
                                <i class="fas fa-edit"></i> Ingresar manualmente
                            </div>
                            <div class="manual-input-container" id="closer1_manual_container">
                                <input type="text" class="form-control" id="closer1_manual" name="closer1_manual" placeholder="Ingresar nombre del closer">
                            </div>
                        </div>
                        <div class="col-md-4">
                            <label for="closer2" class="form-label">Closer 2</label>
                            <select class="form-select search-select" id="closer2" name="closer2">
                                <option value="" selected>Selecciona un closer</option>
                            </select>
                            <div class="toggle-input-type" onclick="toggleManualInput('closer2')">
                                <i class="fas fa-edit"></i> Ingresar manualmente
                            </div>
                            <div class="manual-input-container" id="closer2_manual_container">
                                <input type="text" class="form-control" id="closer2_manual" name="closer2_manual" placeholder="Ingresar nombre del closer">
                            </div>
                        </div>
                    </div>

                    <!-- Full Stack -->
                    <div class="mb-3" id="fullStackDiv" style="display:none;">
                        <label for="fullstack" class="form-label required-field">Front</label>
                        <select class="form-select search-select" id="fullstack" name="fullstack">
                            <option value="" selected disabled>Selecciona un front</option>
                        </select>
                        <div class="toggle-input-type" onclick="toggleManualInput('fullstack')">
                            <i class="fas fa-edit"></i> Ingresar manualmente
                        </div>
                        <div class="manual-input-container" id="fullstack_manual_container">
                            <input type="text" class="form-control" id="fullstack_manual" name="fullstack_manual" placeholder="Ingresar nombre del front">
                        </div>
                    </div>

                    <!-- Método de pago -->
                    <div class="mb-3">
                        <label class="form-label required-field">Método de pago</label>
                        <div class="form-check form-check-inline">
                            <input class="form-check-input" type="radio" name="metodoPago" id="pagoSpei" value="SPEI" required>
                            <label class="form-check-label" for="pagoSpei">SPEI/EFECTIVO</label>
                        </div>
                        <div class="form-check form-check-inline">
                            <input class="form-check-input" type="radio" name="metodoPago" id="pagoTarjeta" value="TDC">
                            <label class="form-check-label" for="pagoTarjeta">Tarjeta de crédito (TDC)</label>
                        </div>
                    </div>

                    <!-- Plataforma -->
                    <div class="mb-3" id="plataformaDiv" style="display:none;">
                        <label for="plataforma" class="form-label required-field">Plataforma</label>
                        <div class="select-with-btn">
                            <select class="form-select search-select" id="plataforma" name="plataforma">
                                <option value="" selected disabled>Selecciona una plataforma</option>
                            </select>
                            <button type="button" class="btn btn-outline-secondary btn-add-platform" id="btnNuevaPlataforma">
                                <i class="fas fa-plus"></i>
                            </button>
                        </div>
                        <!-- Interruptor de permiso para admin -->
                        <div id="plataformaPermisoContainer" class="permiso-plataforma-container" style="display: none;">
                            <div class="form-check form-switch">
                                <input class="form-check-input toggle-permiso-plataforma"
                                    type="checkbox"
                                    role="switch"
                                    id="togglePermisoPlataforma">
                                <label class="form-check-label" for="togglePermisoPlataforma">
                                    <strong>Restringir esta plataforma</strong>
                                </label>
                            </div>
                        </div>
                    </div>

                    <!-- Botón -->
                    <div class="d-grid">
                        <button type="submit" class="btn btn-success">Enviar</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Modal para nueva plataforma -->
    <div class="modal fade" id="modalNuevaPlataforma" tabindex="-1" aria-labelledby="modalNuevaPlataformaLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="modalNuevaPlataformaLabel">Agregar Nueva Plataforma</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <form id="formNuevaPlataforma">
                        <div class="mb-3">
                            <label for="nuevoMetodoPago" class="form-label required-field">Método de Pago</label>
                            <select class="form-select" id="nuevoMetodoPago" name="nuevoMetodoPago" required>
                                <option value="" selected disabled>Selecciona un método</option>
                                <option value="SPEI/EFECTIVO">SPEI/EFECTIVO</option>
                                <option value="TDC">Tarjeta de Crédito (TDC)</option>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label for="nuevaPlataformaSpei" class="form-label required-field">Nombre de la Plataforma</label>
                            <input type="text" class="form-control" id="nuevaPlataformaSpei" name="nuevaPlataformaSpei" required>
                        </div>
                        <div class="mb-3">
                            <label for="nuevoNombreAncronimo" class="form-label required-field">Nombre/Acrónimo</label>
                            <input type="text" class="form-control" id="nuevoNombreAncronimo" name="nuevoNombreAncronimo" maxlength="20" required>
                        </div>
                        <div class="row mb-3">
                            <div class="col-md-4">
                                <label for="nuevoPorcentFront" class="form-label required-field">% Front</label>
                                <input type="number" class="form-control" id="nuevoPorcentFront" name="nuevoPorcentFront" min="0" max="100" required>
                            </div>
                            <div class="col-md-4">
                                <label for="nuevoPorcentLinea" class="form-label required-field">% Línea</label>
                                <input type="number" class="form-control" id="nuevoPorcentLinea" name="nuevoPorcentLinea" min="0" max="100" required>
                            </div>
                            <div class="col-md-4">
                                <label for="nuevoPorcentCierre" class="form-label required-field">% Cierre</label>
                                <input type="number" class="form-control" id="nuevoPorcentCierre" name="nuevoPorcentCierre" min="0" max="100" required>
                            </div>
                        </div>
                    </form>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="button" class="btn btn-primary" id="btnGuardarPlataforma">Guardar Plataforma</button>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>

    <script>
        // Variable global para saber si es admin con permiso de plataforma
        const esAdminConPermisoPlataforma = <?= ($es_admin && $tiene_permiso_plataforma) ? 'true' : 'false' ?>;
    </script>
    <script src="JS/facturacion.js"></script>
</body>
</html>