<?php
date_default_timezone_set('America/Mexico_City');

// Configuración de manejo de errores
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/php_errors.log');

// Iniciar sesión
session_start();

// Verificar que el usuario esté logueado
if (!isset($_SESSION['usuario_id'])) {
    header('Location: login.php');
    exit;
}

// Conexión a la base de datos
$servername = "65.99.205.112";
$username = "noreplyc_alexsanchez09";
$password = "SWn_VrAc3K7lq;LS";
$dbname = "noreplyc_crmregistroventas";

// Parámetros de filtrado
$fecha_inicio = isset($_GET['fecha_inicio']) ? $_GET['fecha_inicio'] : '';
$fecha_fin = isset($_GET['fecha_fin']) ? $_GET['fecha_fin'] : '';
$usuario_id = isset($_GET['usuario_id']) ? intval($_GET['usuario_id']) : '';
$accion = isset($_GET['accion']) ? $_GET['accion'] : '';

try {
    $conn = new mysqli($servername, $username, $password, $dbname);
    
    if ($conn->connect_error) {
        throw new Exception("Conexión fallida: " . $conn->connect_error);
    }
    
    $conn->set_charset("utf8");

    // Construir consulta con filtros
    $whereConditions = [];
    $params = [];
    $types = "";

    if (!empty($fecha_inicio)) {
        $whereConditions[] = "h.fecha_accion >= ?";
        $params[] = $fecha_inicio . ' 00:00:00';
        $types .= "s";
    }

    if (!empty($fecha_fin)) {
        $whereConditions[] = "h.fecha_accion <= ?";
        $params[] = $fecha_fin . ' 23:59:59';
        $types .= "s";
    }

    if (!empty($usuario_id)) {
        $whereConditions[] = "h.usuario_id = ?";
        $params[] = $usuario_id;
        $types .= "i";
    }

    if (!empty($accion)) {
        $whereConditions[] = "h.accion = ?";
        $params[] = $accion;
        $types .= "s";
    }

    $whereClause = '';
    if (!empty($whereConditions)) {
        $whereClause = 'WHERE ' . implode(' AND ', $whereConditions);
    }

    // Obtener el historial completo
    $sql_historial = "SELECT h.*, 
                     CONCAT(u.nombre, ' ', u.apepat, ' ', COALESCE(u.apemat, '')) AS usuario_nombre,
                     u.id AS usuario_id,
                     v.cliente,
                     v.apepat,
                     v.apemat,
                     v.producto,
                     v.precio
                     FROM ventas_historial h
                     LEFT JOIN usuarios u ON h.usuario_id = u.id
                     LEFT JOIN ventas_facturacion v ON h.venta_id = v.id
                     $whereClause
                     ORDER BY h.fecha_accion DESC
                     LIMIT 1000";

    $stmt_historial = $conn->prepare($sql_historial);
    
    if (!empty($params)) {
        $stmt_historial->bind_param($types, ...$params);
    }
    
    $stmt_historial->execute();
    $result_historial = $stmt_historial->get_result();
    
    $historial = [];
    while ($row = $result_historial->fetch_assoc()) {
        $historial[] = $row;
    }
    $stmt_historial->close();

    // Obtener lista de usuarios para el filtro
    $sql_usuarios = "SELECT id, CONCAT(nombre, ' ', apepat, ' ', COALESCE(apemat, '')) as nombre_completo 
                     FROM usuarios 
                     ORDER BY nombre";
    $usuarios = $conn->query($sql_usuarios)->fetch_all(MYSQLI_ASSOC);

    $conn->close();

} catch (Exception $e) {
    error_log("ERROR en lista_historial.php: " . $e->getMessage());
    die("Error al cargar el historial: " . $e->getMessage());
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Historial Completo de Ventas</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        .badge-crear { background-color: #198754; }
        .badge-actualizar { background-color: #fd7e14; }
        .filter-section {
            background-color: #f8f9fa;
            border-radius: 8px;
            padding: 20px;
            margin-bottom: 20px;
        }
        .filter-header {
            cursor: pointer;
            padding: 10px;
            background-color: #e9ecef;
            border-radius: 5px;
            margin-bottom: 15px;
        }
        .stats-card {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 20px;
        }
        .stats-number {
            font-size: 2.5rem;
            font-weight: bold;
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
                    <li class="nav-item">
                        <a class="nav-link text-primary" href="./lista_historial.php" style="text-decoration: none;">
                            📊 Historial
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
        <div class="row">
            <div class="col-12">
                <div class="card shadow-sm rounded-4 mb-4">
                    <div class="card-header bg-dark text-white d-flex justify-content-between align-items-center">
                        <div>
                            <h5 class="mb-0">
                                <i class="bi bi-clock-history"></i> 
                                Historial Completo de Ventas
                            </h5>
                            <small class="text-light">
                                Registro de todas las acciones del sistema
                            </small>
                        </div>
                        <div>
                            <a href="ventas.php" class="btn btn-light btn-sm me-2">
                                <i class="bi bi-arrow-left"></i> Volver a Ventas
                            </a>
                            <span class="badge bg-warning text-dark">
                                Total: <?= count($historial) ?> registros
                            </span>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Estadísticas -->
        <div class="row mb-4">
            <div class="col-md-3">
                <div class="stats-card text-center">
                    <i class="bi bi-plus-circle fs-1"></i>
                    <div class="stats-number">
                        <?= count(array_filter($historial, function($h) { return $h['accion'] == 'crear'; })) ?>
                    </div>
                    <div>Ventas Creadas</div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stats-card text-center" style="background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);">
                    <i class="bi bi-arrow-repeat fs-1"></i>
                    <div class="stats-number">
                        <?= count(array_filter($historial, function($h) { return $h['accion'] == 'actualizar'; })) ?>
                    </div>
                    <div>Actualizaciones</div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stats-card text-center" style="background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);">
                    <i class="bi bi-people fs-1"></i>
                    <div class="stats-number">
                        <?= count(array_unique(array_column($historial, 'usuario_id'))) ?>
                    </div>
                    <div>Usuarios Activos</div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stats-card text-center" style="background: linear-gradient(135deg, #43e97b 0%, #38f9d7 100%);">
                    <i class="bi bi-cart-check fs-1"></i>
                    <div class="stats-number">
                        <?= count(array_unique(array_column($historial, 'venta_id'))) ?>
                    </div>
                    <div>Ventas Únicas</div>
                </div>
            </div>
        </div>

        <!-- Filtros -->
        <div class="filter-section">
            <div class="filter-header" onclick="toggleFilters()">
                <i class="bi bi-funnel"></i> Filtros de Búsqueda
                <i class="bi bi-chevron-down float-end"></i>
            </div>
            <div class="filter-content" id="filterContent">
                <form method="GET" action="" class="row g-3">
                    <div class="col-md-3">
                        <label for="fecha_inicio" class="form-label">Fecha Inicio</label>
                        <input type="date" class="form-control" id="fecha_inicio" name="fecha_inicio" 
                               value="<?= htmlspecialchars($fecha_inicio) ?>">
                    </div>
                    <div class="col-md-3">
                        <label for="fecha_fin" class="form-label">Fecha Fin</label>
                        <input type="date" class="form-control" id="fecha_fin" name="fecha_fin" 
                               value="<?= htmlspecialchars($fecha_fin) ?>">
                    </div>
                    <div class="col-md-3">
                        <label for="usuario_id" class="form-label">Usuario</label>
                        <select class="form-select" id="usuario_id" name="usuario_id">
                            <option value="">Todos los usuarios</option>
                            <?php foreach ($usuarios as $usuario): ?>
                                <option value="<?= $usuario['id'] ?>" 
                                    <?= $usuario_id == $usuario['id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($usuario['nombre_completo']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label for="accion" class="form-label">Acción</label>
                        <select class="form-select" id="accion" name="accion">
                            <option value="">Todas las acciones</option>
                            <option value="crear" <?= $accion == 'crear' ? 'selected' : '' ?>>Crear</option>
                            <option value="actualizar" <?= $accion == 'actualizar' ? 'selected' : '' ?>>Actualizar</option>
                        </select>
                    </div>
                    <div class="col-12">
                        <div class="btn-group">
                            <button type="submit" class="btn btn-primary">
                                <i class="bi bi-search"></i> Filtrar
                            </button>
                            <a href="lista_historial.php" class="btn btn-outline-secondary">
                                <i class="bi bi-x-circle"></i> Limpiar
                            </a>
                        </div>
                    </div>
                </form>
            </div>
        </div>

        <!-- Lista del Historial -->
        <div class="card shadow-sm rounded-4">
            <div class="card-body">
                <?php if (empty($historial)): ?>
                    <div class="alert alert-info text-center">
                        <i class="bi bi-info-circle"></i> No hay registros en el historial con los filtros aplicados.
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-bordered table-hover align-middle">
                            <thead class="table-dark">
                                <tr>
                                    <th>Fecha/Hora</th>
                                    <th>Usuario</th>
                                    <th>Acción</th>
                                    <th>Cliente</th>
                                    <th>Producto</th>
                                    <th>Monto</th>
                                    <th>Detalles</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($historial as $registro): ?>
                                <tr>
                                    <td class="text-nowrap">
                                        <small>
                                            <i class="bi bi-calendar-event text-muted"></i>
                                            <?= date('d/m/Y', strtotime($registro['fecha_accion'])) ?><br>
                                            <i class="bi bi-clock text-muted"></i>
                                            <?= date('H:i', strtotime($registro['fecha_accion'])) ?>
                                        </small>
                                    </td>
                                    <td>
                                        <strong><?= htmlspecialchars($registro['usuario_nombre'] ?? 'N/A') ?></strong>
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
                                        <?= htmlspecialchars($registro['cliente'] . ' ' . $registro['apepat'] . ' ' . ($registro['apemat'] ?? '')) ?>
                                    </td>
                                    <td>
                                        <?= htmlspecialchars($registro['producto'] ?? 'N/A') ?>
                                    </td>
                                    <td class="text-nowrap">
                                        <strong>$<?= number_format($registro['precio'] ?? 0, 2) ?></strong>
                                    </td>
                                    <td>
                                        <?php if ($registro['accion'] == 'crear'): ?>
                                            <span class="text-success">
                                                <i class="bi bi-check-circle"></i> Creada
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

                    <!-- Información de filtros activos -->
                    <?php if (!empty($fecha_inicio) || !empty($fecha_fin) || !empty($usuario_id) || !empty($accion)): ?>
                    <div class="mt-3">
                        <small class="text-muted">
                            Filtros activos: 
                            <?php if (!empty($fecha_inicio)) echo "Desde: " . htmlspecialchars($fecha_inicio) . " "; ?>
                            <?php if (!empty($fecha_fin)) echo "Hasta: " . htmlspecialchars($fecha_fin) . " "; ?>
                            <?php if (!empty($usuario_id)) echo " | Usuario ID: " . htmlspecialchars($usuario_id) . " "; ?>
                            <?php if (!empty($accion)) echo " | Acción: " . htmlspecialchars($accion); ?>
                        </small>
                    </div>
                    <?php endif; ?>

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
    function toggleFilters() {
        const filterContent = document.getElementById('filterContent');
        filterContent.style.display = filterContent.style.display === 'none' ? 'block' : 'none';
    }

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

    // Inicializar filtros ocultos
    document.addEventListener('DOMContentLoaded', function() {
        // Los filtros están visibles por defecto en este diseño
    });
    </script>
</body>
</html>