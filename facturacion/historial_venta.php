<?php
date_default_timezone_set('America/Mexico_City');

// Configuración de manejo de errores
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/php_errors.log');

// Iniciar sesión
require_once __DIR__ . '/../session_config.php'; session_start();

// Verificar que el usuario esté logueado
if (!isset($_SESSION['usuario_id'])) {
    header('Location: login.php');
    exit;
}

// Verificar que se haya proporcionado el ID de venta
if (!isset($_GET['venta_id']) || empty($_GET['venta_id'])) {
    die("Error: No se especificó el ID de la venta");
}

$venta_id = intval($_GET['venta_id']);

// Conexión a la base de datos
$servername = "65.99.205.112";
$username = "noreplyc_alexsanchez09";
$password = "SWn_VrAc3K7lq;LS";
$dbname = "noreplyc_crmregistroventas";

try {
    $conn = new mysqli($servername, $username, $password, $dbname);
    
    if ($conn->connect_error) {
        throw new Exception("Conexión fallida: " . $conn->connect_error);
    }
    
    $conn->set_charset("utf8");

    // 1. Obtener información básica de la venta
    $sql_venta = "SELECT v.*, 
                 CONCAT(v.cliente, ' ', v.apepat, ' ', COALESCE(v.apemat, '')) AS cliente_completo,
                 p.nombre_ancronimo AS plataforma_nombre
                 FROM ventas_facturacion v
                 LEFT JOIN plataforma p ON v.plataforma_id = p.id
                 WHERE v.id = ?";
    
    $stmt_venta = $conn->prepare($sql_venta);
    $stmt_venta->bind_param("i", $venta_id);
    $stmt_venta->execute();
    $result_venta = $stmt_venta->get_result();
    $venta = $result_venta->fetch_assoc();
    $stmt_venta->close();

    if (!$venta) {
        die("Error: No se encontró la venta con ID: " . $venta_id);
    }

    // 2. Obtener el historial completo de la venta
    $sql_historial = "SELECT h.*, 
                     CONCAT(u.nombre, ' ', u.apepat, ' ', COALESCE(u.apemat, '')) AS usuario_nombre,
                     u.id AS usuario_id
                     FROM ventas_historial h
                     LEFT JOIN usuarios u ON h.usuario_id = u.id
                     WHERE h.venta_id = ?
                     ORDER BY h.fecha_accion DESC";
    
    $stmt_historial = $conn->prepare($sql_historial);
    $stmt_historial->bind_param("i", $venta_id);
    $stmt_historial->execute();
    $result_historial = $stmt_historial->get_result();
    $historial = $stmt_historial->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt_historial->close();

    $conn->close();

} catch (Exception $e) {
    error_log("ERROR en historial_venta.php: " . $e->getMessage());
    die("Error al cargar el historial: " . $e->getMessage());
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Historial de Venta #<?= $venta_id ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        .badge-crear { background-color: #198754; }
        .badge-actualizar { background-color: #fd7e14; }
        .cambio-item { 
            padding: 8px 12px; 
            margin-bottom: 5px; 
            border-radius: 4px;
            border-left: 4px solid #0d6efd;
        }
        .campo-anterior { 
            background-color: #fff3cd; 
            border-left-color: #ffc107;
        }
        .campo-nuevo { 
            background-color: #d1e7dd; 
            border-left-color: #198754;
        }
        .table-hover tbody tr:hover {
            background-color: rgba(0, 0, 0, 0.075);
        }
    </style>
</head>
<body class="bg-light">
    <nav class="navbar navbar-expand-lg navbar-light bg-white shadow-sm fixed-top border-bottom">
        <div class="container-fluid">
            <div class="d-flex align-items-center ms-auto">
                <ul class="navbar-nav me-3">
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
                </ul>
                <span class="navbar-text text-muted me-3">
                    <?= htmlspecialchars($_SESSION['nombre'] ?? 'Usuario') ?>
                </span>
                <a href="logout.php" class="btn btn-sm btn-outline-danger">
                    <i class="bi bi-box-arrow-right"></i> Salir
                </a>
            </div>
        </div>
    </nav>

    <div style="height: 70px;"></div>

    <div class="container mt-4">
        <div class="card shadow-sm rounded-4">
            <div class="card-header bg-dark text-white d-flex justify-content-between align-items-center">
                <div>
                    <h5 class="mb-0">
                        <i class="bi bi-clock-history"></i> 
                        Historial de Venta #<?= $venta_id ?>
                    </h5>
                    <small class="text-light">
                        Cliente: <?= htmlspecialchars($venta['cliente_completo']) ?> | 
                        Producto: <?= htmlspecialchars($venta['producto']) ?> | 
                        Total: $<?= number_format($venta['precio'], 2) ?>
                    </small>
                </div>
                <a href="ventas.php" class="btn btn-light btn-sm">
                    <i class="bi bi-arrow-left"></i> Volver a Ventas
                </a>
            </div>
            <div class="card-body">
                <?php if (empty($historial)): ?>
                    <div class="alert alert-info text-center">
                        <i class="bi bi-info-circle"></i> No hay historial registrado para esta venta.
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-bordered table-hover align-middle">
                            <thead class="table-secondary">
                                <tr>
                                    <th>Fecha y Hora</th>
                                    <th>Usuario</th>
                                    <th>Acción</th>
                                    <th>Detalles</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($historial as $registro): ?>
                                <tr>
                                    <td class="text-nowrap">
                                        <i class="bi bi-calendar-event text-muted"></i>
                                        <?= date('d/m/Y H:i', strtotime($registro['fecha_accion'])) ?>
                                    </td>
                                    <td>
                                        <strong><?= htmlspecialchars($registro['usuario_nombre'] ?? 'Usuario no encontrado') ?></strong>
                                        <br>
                                        <small class="text-muted">ID: <?= $registro['usuario_id'] ?></small>
                                    </td>
                                    <td>
                                        <span class="badge <?= $registro['accion'] == 'crear' ? 'badge-crear' : 'badge-actualizar' ?>">
                                            <i class="bi <?= $registro['accion'] == 'crear' ? 'bi-plus-circle' : 'bi-arrow-repeat' ?>"></i>
                                            <?= ucfirst($registro['accion']) ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php if ($registro['accion'] == 'crear'): ?>
                                            <span class="text-success">
                                                <i class="bi bi-check-circle"></i> Venta creada inicialmente
                                            </span>
                                        <?php else: ?>
                                            <button class="btn btn-sm btn-outline-primary" 
                                                    onclick="mostrarCambios(<?= $registro['id'] ?>)">
                                                <i class="bi bi-arrow-left-right"></i> Ver Cambios
                                            </button>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Modal para mostrar cambios -->
    <div class="modal fade" id="modalCambios" tabindex="-1" aria-labelledby="modalCambiosLabel" aria-hidden="true">
        <div class="modal-dialog modal-xl">
            <div class="modal-content">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title" id="modalCambiosLabel">
                        <i class="bi bi-arrow-left-right"></i> Detalle de Cambios
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body" id="cambiosContent">
                    <!-- Aquí se cargarán los cambios -->
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cerrar</button>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
    function mostrarCambios(historialId) {
        // Mostrar loading
        document.getElementById('cambiosContent').innerHTML = `
            <div class="text-center py-4">
                <div class="spinner-border text-primary" role="status">
                    <span class="visually-hidden">Cargando...</span>
                </div>
                <p class="mt-2">Cargando detalles de cambios...</p>
            </div>
        `;

        // Mostrar modal inmediatamente
        const modal = new bootstrap.Modal(document.getElementById('modalCambios'));
        modal.show();

        // Hacer petición para obtener cambios
        fetch('obtener_cambios.php?historial_id=' + historialId)
            .then(response => {
                if (!response.ok) {
                    throw new Error('Error en la respuesta del servidor');
                }
                return response.json();
            })
            .then(data => {
                if (data.success) {
                    document.getElementById('cambiosContent').innerHTML = data.html;
                } else {
                    document.getElementById('cambiosContent').innerHTML = `
                        <div class="alert alert-danger">
                            <i class="bi bi-exclamation-triangle"></i> Error: ${data.message}
                        </div>
                    `;
                }
            })
            .catch(error => {
                console.error('Error:', error);
                document.getElementById('cambiosContent').innerHTML = `
                    <div class="alert alert-danger">
                        <i class="bi bi-exclamation-triangle"></i> Error al cargar los cambios: ${error.message}
                    </div>
                `;
            });
    }
    </script>
</body>
</html>