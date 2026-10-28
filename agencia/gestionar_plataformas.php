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

// Verificar si es admin y tiene permiso_plataforma
$es_admin = ($_SESSION['rol'] ?? '') === 'admin';
$tiene_permiso_plataforma = false;

if ($es_admin) {
    try {
        $conn = conexionPDO();

        $stmt = $conn->prepare("SELECT permiso_plataforma FROM usuarios_facturacion WHERE id = ?");
        $stmt->execute([$_SESSION['usuario_id']]);
        $usuario = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($usuario && $usuario['permiso_plataforma'] == 1) {
            $tiene_permiso_plataforma = true;
        }
    } catch (PDOException $e) {
        error_log("Error al obtener permiso plataforma: " . $e->getMessage());
        $tiene_permiso_plataforma = false;
    }
}

header('Access-Control-Allow-Origin: *');
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/php_errors.log');

set_exception_handler(function ($exception) {
    error_log("Excepción no capturada: " . $exception->getMessage());
    http_response_code(500);
    die("Error interno del servidor. Por favor, intente más tarde.");
});

// Configuración
$registros_por_pagina = 10;

// Parámetros
$pagina_actual = max(1, (int)($_GET['pagina'] ?? 1));
$busqueda = trim($_GET['busqueda'] ?? '');
$mostrar_inactivos = (bool)($_GET['inactivos'] ?? false);

// Inicializar variables
$plataformas = [];
$total_registros = 0;
$total_paginas = 0;
$mostrar_paginacion = false;
$error = null;

// Conexión y consulta
try {
    $conn = conexionPDO();
} catch (PDOException $e) {
    $conn = null;
    $error = "Error de conexión: " . $e->getMessage();
}

if ($conn) {
    try {
        // Construir WHERE conditions
        $conditions = [];
        $params = [];

        if ($busqueda) {
            $conditions[] = "(nombre_ancronimo LIKE ? OR plataformaspei LIKE ? OR metodo_pago LIKE ?)";
            $search_param = "%$busqueda%";
            array_push($params, $search_param, $search_param, $search_param);
        }

        if (!$mostrar_inactivos) {
            $conditions[] = "activo = 1";
        }

        $where_sql = $conditions ? 'WHERE ' . implode(' AND ', $conditions) : '';

        // Contar total
        $sql_count = "SELECT COUNT(*) as total FROM plataforma $where_sql";
        $stmt = $conn->prepare($sql_count);
        $stmt->execute($params);
        $total_registros = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

        // Calcular paginación
        $total_paginas = ceil($total_registros / $registros_por_pagina);
        $mostrar_paginacion = $total_registros > $registros_por_pagina;
        $offset = ($pagina_actual - 1) * $registros_por_pagina;

        // Obtener datos
        $sql = "SELECT * FROM plataforma $where_sql 
                ORDER BY activo DESC, metodo_pago, nombre_ancronimo 
                LIMIT ? OFFSET ?";

        $stmt = $conn->prepare($sql);
        $pos = 1;
        foreach ($params as $param) {
            $stmt->bindValue($pos++, $param);
        }
        $stmt->bindValue($pos++, (int)$registros_por_pagina, PDO::PARAM_INT);
        $stmt->bindValue($pos++, (int)$offset, PDO::PARAM_INT);

        $stmt->execute();
        $plataformas = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        $error = "Error al cargar las plataformas: " . $e->getMessage();
    }
}
?>

<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <title>Gestionar Plataformas</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <style>
        .table-actions {
            white-space: nowrap;
            width: 140px;
        }

        .badge-spei {
            background-color: #198754;
        }

        .badge-tdc {
            background-color: #0d6efd;
        }

        .table-inactive {
            opacity: 0.6;
            background-color: #f8f9fa;
        }

        .status-badge {
            font-size: 0.75em;
        }

        .required-field::after {
            content: " *";
            color: red;
        }

        .pagination {
            margin-bottom: 0;
        }

        .search-container {
            position: relative;
            max-width: 400px;
        }

        .search-clear {
            position: absolute;
            right: 10px;
            top: 50%;
            transform: translateY(-50%);
            cursor: pointer;
            color: #6c757d;
            z-index: 3;
        }

        .search-clear:hover {
            color: #dc3545;
        }

        .search-loading {
            position: absolute;
            right: 35px;
            top: 50%;
            transform: translateY(-50%);
        }

        .form-check-input:checked {
            background-color: #0d6efd;
            border-color: #0d6efd;
        }

        .form-switch .form-check-input {
            width: 3em;
            height: 1.5em;
            margin-right: 8px;
        }

        .form-switch .form-check-input:checked {
            background-color: #0d6efd;
            border-color: #0d6efd;
        }

        .form-switch .form-check-input:focus {
            box-shadow: 0 0 0 0.25rem rgba(13, 110, 253, 0.25);
        }

        .table-actions {
            white-space: nowrap;
            width: 160px;
        }

        .permiso-column {
            width: 150px;
        }
    </style>
</head>

<body class="bg-light">
    <!-- Navbar -->
    <nav class="navbar navbar-expand-lg navbar-light bg-white shadow-sm fixed-top border-bottom">
        <div class="container-fluid">
            <a class="navbar-brand" href="#"></a>
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
                        <a class="nav-link text-dark" href="./gestionar_plataformas.php" style="text-decoration: none;">
                            💳 Plataformas
                        </a>
                    </li>
                </ul>
                <span class="navbar-text text-muted me-3"><?= htmlspecialchars($_SESSION['nombre']) ?></span>
                <a href="logout.php" class="btn btn-sm btn-outline-danger">
                    <i class="bi bi-box-arrow-right"></i> Salir
                </a>
            </div>
        </div>
    </nav>

    <div style="height: 70px;"></div>

    <div class="container mt-4">
         <div style="height: 50px;"></div>
        <div class="card shadow">
            <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center">
                <h4 class="mb-0"><i class="bi bi-credit-card"></i> Gestión de Plataformas</h4>
                <button type="button" class="btn btn-light btn-sm" data-bs-toggle="modal" data-bs-target="#modalNuevaPlataforma">
                    <i class="bi bi-plus-circle"></i> Nueva Plataforma
                </button>
            </div>

            <div class="card-body">
                <!-- Alertas -->
                <?php if ($error): ?>
                    <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
                <?php endif; ?>
                <div class="alert alert-dismissible fade show d-none" role="alert" id="alertMessage">
                    <span id="alertText"></span>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>

                <!-- Búsqueda y Filtros -->
                <div class="row mb-4">
                    <div class="col-md-6">
                        <div class="search-container">
                            <input class="form-control" type="text" id="searchInput"
                                value="<?= htmlspecialchars($busqueda) ?>"
                                placeholder="Buscar por acrónimo, plataforma o método de pago..."
                                autocomplete="off">
                            <span class="search-clear" id="clearSearch" style="display: <?= $busqueda ? 'block' : 'none' ?>;">
                                <i class="bi bi-x-circle"></i>
                            </span>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="row">
                            <div class="col-md-8 text-end">
                                <div class="results-info" id="resultsInfo">
                                    Mostrando <?= count($plataformas) ?> de <?= $total_registros ?> plataformas
                                    <?php if ($busqueda): ?> - Resultados para: "<strong><?= htmlspecialchars($busqueda) ?></strong>"<?php endif; ?>
                                        <span class="badge <?= $mostrar_inactivos ? 'bg-warning text-dark' : 'bg-success' ?>">
                                            <?= $mostrar_inactivos ? 'Mostrando inactivos' : 'Solo activas' ?>
                                        </span>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="form-check form-switch">
                                    <input class="form-check-input" type="checkbox" id="showInactive" <?= $mostrar_inactivos ? 'checked' : '' ?>>
                                    <label class="form-check-label" for="showInactive">Mostrar inactivos</label>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Tabla -->
                <div class="table-responsive" id="tableContainer">
                    <table class="table table-striped table-hover">
                        <thead class="table-dark">
                            <tr>
                                <th>Acrónimo</th>
                                <th>Plataforma</th>
                                <th>Estado</th>
                                <th>Método Pago</th>
                                <th>% Front</th>
                                <th>% Línea</th>
                                <th>% Cierre</th>
                                <?php if ($tiene_permiso_plataforma): ?>
                                    <th class="permiso-column">Permiso</th>
                                <?php endif; ?>
                                <th>Acciones</th>
                            </tr>
                        </thead>
                        <tbody id="tableBody">
                            <?php foreach ($plataformas as $plataforma): ?>
                                <tr id="plataforma-<?= $plataforma['id'] ?>" class="<?= $plataforma['activo'] ? '' : 'table-inactive' ?>">
                                    <td><?= htmlspecialchars($plataforma['nombre_ancronimo']) ?></td>
                                    <td><?= htmlspecialchars($plataforma['plataformaspei']) ?></td>
                                    <td>
                                        <span class="badge <?= $plataforma['activo'] ? 'bg-success' : 'bg-secondary' ?> status-badge">
                                            <?= $plataforma['activo'] ? 'Activa' : 'Inactiva' ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="badge <?= $plataforma['metodo_pago'] === 'SPEI/EFECTIVO' ? 'badge-spei' : 'badge-tdc' ?>">
                                            <?= htmlspecialchars($plataforma['metodo_pago']) ?>
                                        </span>
                                    </td>
                                    <td><?= $plataforma['porcent_front'] ?>%</td>
                                    <td><?= $plataforma['porcent_linea'] ?>%</td>
                                    <td><?= $plataforma['porcent_cierre'] ?>%</td>
                                    <?php if ($tiene_permiso_plataforma): ?>
                                        <td>
                                            <div class="form-check form-switch">
                                                <input class="form-check-input toggle-permiso" 
                                                       type="checkbox" 
                                                       role="switch" 
                                                       id="permiso-<?= $plataforma['id'] ?>"
                                                       data-id="<?= $plataforma['id'] ?>"
                                                       data-name="<?= htmlspecialchars($plataforma['nombre_ancronimo']) ?>"
                                                       <?= $plataforma['permiso_plataforma'] ? 'checked' : '' ?>
                                                       <?= $plataforma['activo'] ? '' : 'disabled' ?>>
                                                <label class="form-check-label" for="permiso-<?= $plataforma['id'] ?>">
                                                    <span class="badge <?= $plataforma['permiso_plataforma'] ? 'bg-primary' : 'bg-secondary' ?> status-badge">
                                                        <?= $plataforma['permiso_plataforma'] ? 'Permitida' : 'Restringida' ?>
                                                    </span>
                                                </label>
                                            </div>
                                        </td>
                                    <?php endif; ?>
                                    <td class="table-actions">
                                        <?php if ($plataforma['activo']): ?>
                                            <button class="btn btn-sm btn-outline-primary edit-platform"
                                                data-id="<?= $plataforma['id'] ?>"
                                                data-plataforma='<?= htmlspecialchars(json_encode($plataforma)) ?>'>
                                                <i class="bi bi-pencil"></i>
                                            </button>
                                            <button class="btn btn-sm btn-outline-warning deactivate-platform"
                                                data-id="<?= $plataforma['id'] ?>"
                                                data-name="<?= htmlspecialchars($plataforma['nombre_ancronimo']) ?>">
                                                <i class="bi bi-pause-circle"></i>
                                            </button>
                                        <?php else: ?>
                                            <button class="btn btn-sm btn-outline-success activate-platform"
                                                data-id="<?= $plataforma['id'] ?>"
                                                data-name="<?= htmlspecialchars($plataforma['nombre_ancronimo']) ?>">
                                                <i class="bi bi-play-circle"></i>
                                            </button>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            <?php if (empty($plataformas)): ?>
                                <tr>
                                    <td colspan="<?= $tiene_permiso_plataforma ? 9 : 8 ?>" class="text-center text-muted py-4">
                                        <?php if ($busqueda): ?>
                                            No se encontraron plataformas que coincidan con "<?= htmlspecialchars($busqueda) ?>"
                                        <?php else: ?>
                                            <?= $mostrar_inactivos ? 'No hay plataformas inactivas' : 'No hay plataformas activas' ?>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Paginación -->
                <?php if ($mostrar_paginacion): ?>
                    <nav aria-label="Paginación de plataformas" id="paginationContainer">
                        <ul class="pagination justify-content-center">
                            <li class="page-item <?= $pagina_actual <= 1 ? 'disabled' : '' ?>">
                                <a class="page-link" href="?<?= http_build_query(['pagina' => $pagina_actual - 1, 'busqueda' => $busqueda, 'inactivos' => $mostrar_inactivos]) ?>" aria-label="Anterior">
                                    <span aria-hidden="true">&laquo;</span>
                                </a>
                            </li>

                            <?php for ($i = 1; $i <= $total_paginas; $i++): ?>
                                <?php if ($i == 1 || $i == $total_paginas || ($i >= $pagina_actual - 2 && $i <= $pagina_actual + 2)): ?>
                                    <li class="page-item <?= $i == $pagina_actual ? 'active' : '' ?>">
                                        <a class="page-link" href="?<?= http_build_query(['pagina' => $i, 'busqueda' => $busqueda, 'inactivos' => $mostrar_inactivos]) ?>"><?= $i ?></a>
                                    </li>
                                <?php elseif ($i == $pagina_actual - 3 || $i == $pagina_actual + 3): ?>
                                    <li class="page-item disabled"><span class="page-link">...</span></li>
                                <?php endif; ?>
                            <?php endfor; ?>

                            <li class="page-item <?= $pagina_actual >= $total_paginas ? 'disabled' : '' ?>">
                                <a class="page-link" href="?<?= http_build_query(['pagina' => $pagina_actual + 1, 'busqueda' => $busqueda, 'inactivos' => $mostrar_inactivos]) ?>" aria-label="Siguiente">
                                    <span aria-hidden="true">&raquo;</span>
                                </a>
                            </li>
                        </ul>
                    </nav>
                <?php else: ?>
                    <div id="paginationContainer"></div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- MODALES INTEGRADOS -->

    <!-- Modal Nueva/Editar Plataforma -->
    <div class="modal fade" id="modalNuevaPlataforma" tabindex="-1" aria-labelledby="modalTitle" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="modalTitle">Agregar Nueva Plataforma</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form id="formPlataforma">
                    <input type="hidden" id="plataformaId" name="id">
                    <input type="hidden" id="actionType" name="actionType" value="create">

                    <div class="modal-body">
                        <div class="mb-3">
                            <label for="metodoPago" class="form-label required-field">Método de Pago</label>
                            <select class="form-select" id="metodoPago" name="metodo_pago" required>
                                <option value="">Seleccionar método...</option>
                                <option value="SPEI/EFECTIVO">SPEI/EFECTIVO</option>
                                <option value="TDC">TARJETA CRÉDITO/DÉBITO</option>
                            </select>
                        </div>

                        <div class="mb-3">
                            <label for="plataformaSpei" class="form-label required-field">Plataforma</label>
                            <input type="text" class="form-control" id="plataformaSpei" name="plataformaspei"
                                placeholder="Ej: Mercado Pago, OXXO Pay, etc." required>
                        </div>

                        <div class="mb-3">
                            <label for="nombreAcronimo" class="form-label required-field">Acrónimo</label>
                            <input type="text" class="form-control" id="nombreAcronimo" name="nombre_ancronimo"
                                placeholder="Ej: MP, OP, etc." required>
                        </div>

                        <div class="row">
                            <div class="col-md-4">
                                <div class="mb-3">
                                    <label for="porcentFront" class="form-label required-field">% Front</label>
                                    <input type="number" class="form-control" id="porcentFront" name="porcent_front"
                                        min="0" max="100" step="0.01" required>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="mb-3">
                                    <label for="porcentLinea" class="form-label required-field">% Línea</label>
                                    <input type="number" class="form-control" id="porcentLinea" name="porcent_linea"
                                        min="0" max="100" step="0.01" required>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="mb-3">
                                    <label for="porcentCierre" class="form-label required-field">% Cierre</label>
                                    <input type="number" class="form-control" id="porcentCierre" name="porcent_cierre"
                                        min="0" max="100" step="0.01" required>
                                </div>
                            </div>
                        </div>
                        
                        <?php if ($tiene_permiso_plataforma): ?>
                            <div class="row mt-3">
                                <div class="col-md-12">
                                    <div class="mb-3">
                                        <div class="form-check form-switch">
                                            <input class="form-check-input" type="checkbox" id="permisoPlataforma" name="permiso_plataforma" value="1" checked>
                                            <label class="form-check-label" for="permisoPlataforma">
                                                Plataforma disponible para uso
                                            </label>
                                            <div class="form-text">
                                                Si está desactivada, la plataforma no aparecerá en los formularios de venta.
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                        <button type="button" class="btn btn-primary" id="btnGuardarPlataforma">Guardar</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Modal Confirmar Desactivar -->
    <div class="modal fade" id="modalConfirmarDesactivar" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Confirmar Desactivación</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p>¿Está seguro que desea desactivar la plataforma <strong id="plataformaNombreDesactivar"></strong>?</p>
                    <p class="text-warning"><small>Esta acción no eliminará la plataforma, solo la marcará como inactiva.</small></p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="button" class="btn btn-warning" id="btnConfirmarDesactivar">Desactivar</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal Confirmar Activar -->
    <div class="modal fade" id="modalConfirmarActivar" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Confirmar Activación</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p>¿Está seguro que desea activar la plataforma <strong id="plataformaNombreActivar"></strong>?</p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="button" class="btn btn-success" id="btnConfirmarActivar">Activar</button>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script>
        class PlataformaManager {
            constructor() {
                this.searchTimeout = null;
                this.currentSearch = '<?= $busqueda ?>';
                this.currentPage = <?= $pagina_actual ?>;
                this.showInactive = <?= $mostrar_inactivos ? 'true' : 'false' ?>;
                this.plataformaAProcesar = null;
                this.init();
            }

            init() {
                this.bindEvents();
                this.reasignarEventos();
            }

            bindEvents() {
                // Búsqueda
                $('#searchInput').on('input', () => this.handleSearch());
                $('#clearSearch').on('click', () => this.clearSearch());
                $('#showInactive').on('change', () => this.toggleInactive());

                // Modal nueva plataforma
                $('[data-bs-target="#modalNuevaPlataforma"]').click(() => this.resetForm());

                // Acciones de plataforma
                $('#btnGuardarPlataforma').click(() => this.guardarPlataforma());
                $('#btnConfirmarDesactivar').click(() => this.confirmarAccion('deactivate'));
                $('#btnConfirmarActivar').click(() => this.confirmarAccion('activate'));
            }

            handleSearch() {
                this.currentSearch = $('#searchInput').val().trim();
                $('#clearSearch').toggle(this.currentSearch !== '');
                this.debouncedSearch();
            }

            debouncedSearch() {
                clearTimeout(this.searchTimeout);
                this.searchTimeout = setTimeout(() => this.realizarBusqueda(), 500);
            }

            clearSearch() {
                $('#searchInput').val('').focus();
                this.currentSearch = '';
                $('#clearSearch').hide();
                this.realizarBusqueda();
            }

            toggleInactive() {
                this.showInactive = $('#showInactive').is(':checked');
                this.currentPage = 1;
                this.realizarBusqueda();
            }

            async realizarBusqueda() {
                try {
                    const params = new URLSearchParams({
                        busqueda: this.currentSearch,
                        pagina: this.currentPage,
                        inactivos: this.showInactive ? 1 : 0
                    });

                    const response = await fetch(`${window.location.pathname}?${params}`);
                    const html = await response.text();

                    this.actualizarVista(html);
                    this.actualizarURL(params);

                } catch (error) {
                    this.mostrarAlerta('Error al realizar la búsqueda: ' + error, 'danger');
                }
            }

            actualizarVista(html) {
                const tempDiv = document.createElement('div');
                tempDiv.innerHTML = html;

                ['#tableContainer', '#paginationContainer', '#resultsInfo'].forEach(selector => {
                    const element = tempDiv.querySelector(selector);
                    if (element) $(selector).html(element.innerHTML);
                });

                this.reasignarEventos();
            }

            actualizarURL(params) {
                const newUrl = `${window.location.pathname}?${params}`;
                window.history.replaceState({}, '', newUrl);
            }

            reasignarEventos() {
                // Eliminar eventos anteriores
                $('.edit-platform').off('click');
                $('.deactivate-platform').off('click');
                $('.activate-platform').off('click');
                $('.toggle-permiso').off('change');

                // Editar plataforma
                $('.edit-platform').on('click', (e) => {
                    const plataformaData = e.currentTarget.dataset.plataforma;
                    this.abrirModalEdicion(plataformaData);
                });

                // Desactivar
                $('.deactivate-platform').on('click', (e) => {
                    const btn = e.currentTarget;
                    this.plataformaAProcesar = {
                        id: btn.dataset.id,
                        name: btn.dataset.name
                    };

                    $('#plataformaNombreDesactivar').text(this.plataformaAProcesar.name);
                    $('#modalConfirmarDesactivar').modal('show');
                });

                // Activar
                $('.activate-platform').on('click', (e) => {
                    const btn = e.currentTarget;
                    this.plataformaAProcesar = {
                        id: btn.dataset.id,
                        name: btn.dataset.name
                    };

                    $('#plataformaNombreActivar').text(this.plataformaAProcesar.name);
                    $('#modalConfirmarActivar').modal('show');
                });

                // Toggle permiso
                if ($('.toggle-permiso').length > 0) {
                    $('.toggle-permiso').on('change', (e) => {
                        const checkbox = e.currentTarget;
                        const plataformaId = checkbox.dataset.id;
                        const plataformaName = checkbox.dataset.name;
                        const permisoValue = checkbox.checked ? 1 : 0;
                        
                        if (confirm(`¿Está seguro de ${permisoValue ? 'permitir' : 'restringir'} la plataforma "${plataformaName}"?`)) {
                            this.togglePermiso(plataformaId, permisoValue);
                        } else {
                            checkbox.checked = !checkbox.checked;
                        }
                    });
                }
            }

            abrirModalEdicion(plataformaJson) {
                try {
                    const plataforma = JSON.parse(plataformaJson);

                    $('#plataformaId').val(plataforma.id);
                    $('#actionType').val('update');
                    $('#metodoPago').val(plataforma.metodo_pago);
                    $('#plataformaSpei').val(plataforma.plataformaspei);
                    $('#nombreAcronimo').val(plataforma.nombre_ancronimo);
                    $('#porcentFront').val(plataforma.porcent_front);
                    $('#porcentLinea').val(plataforma.porcent_linea);
                    $('#porcentCierre').val(plataforma.porcent_cierre);
                    
                    const permisoSwitch = $('#permisoPlataforma');
                    if (permisoSwitch.length > 0) {
                        permisoSwitch.prop('checked', plataforma.permiso_plataforma == 1);
                    }
                    
                    $('#modalTitle').text('Editar Plataforma');
                    $('#modalNuevaPlataforma').modal('show');

                } catch (error) {
                    console.error('Error parsing platform data:', error);
                    this.mostrarAlerta('Error al cargar los datos de la plataforma', 'danger');
                }
            }

            resetForm() {
                $('#formPlataforma')[0].reset();
                $('#plataformaId, #actionType').val('');
                $('#actionType').val('create');
                $('#modalTitle').text('Agregar Nueva Plataforma');
                
                const permisoSwitch = $('#permisoPlataforma');
                if (permisoSwitch.length > 0) {
                    permisoSwitch.prop('checked', true);
                }
            }

            async confirmarAccion(action) {
                if (!this.plataformaAProcesar) {
                    console.error('No platform selected for action:', action);
                    return;
                }

                try {
                    const response = await fetch('guardar_plataforma.php', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json'
                        },
                        body: JSON.stringify({
                            action: action, // Enviar la acción directamente
                            id: this.plataformaAProcesar.id
                        })
                    });

                    const data = await response.json();

                    if (data.success) {
                        this.mostrarAlerta(data.message, 'success');
                        setTimeout(() => window.location.reload(), 1000);
                    } else {
                        this.mostrarAlerta(data.message || 'Error desconocido', 'danger');
                    }

                } catch (error) {
                    console.error('Error:', error);
                    this.mostrarAlerta(`Error al procesar la plataforma: ${error.message}`, 'danger');
                } finally {
                    const modalId = action === 'deactivate' ?
                        'modalConfirmarDesactivar' : 'modalConfirmarActivar';
                    $(`#${modalId}`).modal('hide');
                    this.plataformaAProcesar = null;
                }
            }

            async togglePermiso(plataformaId, permisoValue) {
                try {
                    const response = await fetch('guardar_plataforma.php', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json'
                        },
                        body: JSON.stringify({
                            action: 'toggle_permiso',
                            id: plataformaId,
                            permiso_plataforma: permisoValue
                        })
                    });

                    const data = await response.json();
                    
                    if (data.success) {
                        this.mostrarAlerta(data.message, 'success');
                        
                        const badge = $(`#permiso-${plataformaId}`).siblings('label').find('.badge');
                        badge.removeClass('bg-primary bg-secondary')
                             .addClass(permisoValue ? 'bg-primary' : 'bg-secondary')
                             .text(permisoValue ? 'Permitida' : 'Restringida');
                             
                    } else {
                        this.mostrarAlerta(data.message, 'danger');
                        $(`#permiso-${plataformaId}`).prop('checked', !permisoValue);
                    }
                    
                } catch (error) {
                    console.error('Error al cambiar permiso:', error);
                    this.mostrarAlerta('Error al cambiar el permiso: ' + error.message, 'danger');
                    $(`#permiso-${plataformaId}`).prop('checked', !permisoValue);
                }
            }

            async guardarPlataforma() {
                const form = $('#formPlataforma')[0];
                if (!form.checkValidity()) {
                    form.reportValidity();
                    return;
                }

                const formData = new FormData(form);
                const jsonData = Object.fromEntries(formData.entries());

                // Obtener el tipo de acción
                const actionType = $('#actionType').val();
                
                // Agregar el action a los datos
                jsonData.action = actionType;

                // Convertir porcentajes a números
                ['porcent_front', 'porcent_linea', 'porcent_cierre'].forEach(key => {
                    jsonData[key] = parseFloat(jsonData[key]);
                });

                // Convertir permiso_plataforma a 1 o 0
                if (jsonData.permiso_plataforma !== undefined) {
                    jsonData.permiso_plataforma = jsonData.permiso_plataforma === '1' ? 1 : 0;
                }

                try {
                    const response = await fetch('guardar_plataforma.php', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json'
                        },
                        body: JSON.stringify(jsonData)
                    });

                    const data = await response.json();

                    if (data.success) {
                        this.mostrarAlerta(data.message, 'success');
                        $('#modalNuevaPlataforma').modal('hide');
                        setTimeout(() => window.location.reload(), 1500);
                    } else {
                        this.mostrarAlerta(data.message, 'danger');
                    }

                } catch (error) {
                    this.mostrarAlerta('Error al guardar la plataforma: ' + error, 'danger');
                }
            }

            mostrarAlerta(mensaje, tipo) {
                const alerta = $('#alertMessage');
                alerta.removeClass().addClass(`alert alert-${tipo} alert-dismissible fade show`).removeClass('d-none');
                $('#alertText').text(mensaje);
                setTimeout(() => alerta.addClass('d-none'), 5000);
            }
        }

        // Inicializar cuando el documento esté listo
        $(document).ready(function() {
            new PlataformaManager();
        });
    </script>
</body>
</html>