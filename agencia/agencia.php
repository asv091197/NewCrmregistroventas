<?php
require_once __DIR__ . '/../includes/db.php';
ini_set('session.gc_probability', 1);
ini_set('session.gc_divisor', 1000); // Solo 0.1% de probabilidad de limpieza
ini_set('session.cookie_secure', 0);
ini_set('session.cookie_httponly', 1);
ini_set('session.use_strict_mode', 1);
header('Access-Control-Allow-Origin: *');

require_once __DIR__ . '/../session_config.php'; session_start();

// Verificación de sesión (superadmin tiene los mismos permisos que admin)
$rol = $_SESSION['rol'] ?? '';
if (!isset($_SESSION['usuario_id']) || !in_array($rol, ['admin', 'superadmin', 'usuario'], true)) {
    header("Location: ../login.php");
    exit;
}
$esAdmin = $rol === 'admin' || $rol === 'superadmin';
// Configuración de manejo de errores
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/php_errors.log');

// Manejo de excepciones global
set_exception_handler(function($exception) {
    error_log("Excepción no capturada: " . $exception->getMessage());
    http_response_code(500);
    die("Error interno del servidor. Por favor, intente más tarde.");
});

// Conexión a base de datos con mejor manejo de errores

try {
    $pdo = conexionPDO();
} catch (PDOException $e) {
    error_log("Error de conexión a BD: " . $e->getMessage());
    die("Error de conexión a la base de datos. Por favor, intente más tarde.");
}

// Obtener parámetros de filtrado
$fechaInicio = $_GET['fecha_inicio'] ?? '';
$fechaFin = $_GET['fecha_fin'] ?? '';

// Configuración de paginación
$clientesPorPagina = 10;
$paginaActual = max(1, (int)($_GET['pagina'] ?? 1));

// Valores por defecto si la consulta falla
$clientes = [];
$totalClientes = 0;
$totalPaginas = 0;

// Construir consulta base con filtros
$whereConditions = [];
$params = [];

// Si el usuario no es admin, filtrar solo sus clientes
if (!$esAdmin) {
    $whereConditions[] = "c.usuario_id = :usuario_actual";
    $params[':usuario_actual'] = $_SESSION['usuario_id'];
}

if (!empty($fechaInicio)) {
    $whereConditions[] = "c.fecha_registro >= :fecha_inicio";
    $params[':fecha_inicio'] = $fechaInicio . ' 00:00:00';
}

if (!empty($fechaFin)) {
    $whereConditions[] = "c.fecha_registro <= :fecha_fin";
    $params[':fecha_fin'] = $fechaFin . ' 23:59:59';
}

$whereClause = '';
if (!empty($whereConditions)) {
    $whereClause = 'WHERE ' . implode(' AND ', $whereConditions);
}

// Obtener el total de clientes con filtros
try {
    $sqlTotal = "SELECT COUNT(*) as total FROM cliente c $whereClause";
    $stmtTotal = $pdo->prepare($sqlTotal);
    foreach ($params as $key => $value) {
        $stmtTotal->bindValue($key, $value);
    }
    $stmtTotal->execute();
    $totalClientes = (int)$stmtTotal->fetchColumn();
    $totalPaginas = (int)ceil($totalClientes / $clientesPorPagina);

    // Asegurar que la página actual no exceda el total de páginas
    if ($paginaActual > $totalPaginas && $totalPaginas > 0) {
        $paginaActual = $totalPaginas;
    }

    // Calcular el offset para la consulta
    $offset = ($paginaActual - 1) * $clientesPorPagina;

    // Consulta optimizada para obtener clientes con información del closer
    $sqlClientes = "
        SELECT c.id, c.nombre, c.apepat, c.apemat, c.asunto, 
               LEFT(c.descripcion, 100) as descripcion_corta,
               c.fecha_registro, c.usuario_closer,
               u.nombre as usuario_nombre, 
               u.apepat as usuario_apepat, u.apemat as usuario_apemat,
               COALESCE(CONCAT(uc.nombre, ' ', uc.apepat, ' ', COALESCE(uc.apemat, '')), 'No asignado') as closer_nombre_completo
        FROM cliente c 
        LEFT JOIN usuarios u ON c.usuario_id = u.id 
        LEFT JOIN usuarios uc ON c.usuario_closer = uc.id
        $whereClause
        ORDER BY c.id DESC 
        LIMIT :limit OFFSET :offset
    ";

    $stmtClientes = $pdo->prepare($sqlClientes);
    foreach ($params as $key => $value) {
        $stmtClientes->bindValue($key, $value);
    }
    $stmtClientes->bindValue(':limit', $clientesPorPagina, PDO::PARAM_INT);
    $stmtClientes->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmtClientes->execute();
    $clientes = $stmtClientes->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Error en consulta: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <title>Clientes</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet">
  <link href="CSS/agencia.css?v=<?= filemtime(__DIR__ . '/CSS/agencia.css') ?>" rel="stylesheet">
</head>

<body class="bg-light" data-rol="<?= htmlspecialchars($rol) ?>">
  <nav class="navbar navbar-expand-lg navbar-light bg-white shadow-sm fixed-top border-bottom">
    <div class="container-fluid">
      <div class="d-flex align-items-center ms-auto">
        <ul class="navbar-nav me-3">
          <?php if ($esAdmin): ?>
          <li class="nav-item">
            <a class="nav-link text-dark" href="../opcionesAdmin.php" style="text-decoration: none;">
             Dashboard
            </a>
          </li>
          <li class="nav-item">
            <a class="nav-link text-dark" href="../Usuarios/usuarios.php" style="text-decoration: none;">
              👤 Usuarios
            </a>
          </li>
          <?php endif; ?>   
          <li class="nav-item">
            <a class="nav-link text-dark" href="agencia.php" style="text-decoration: none;">
              🕵️ Agentes
            </a>
          </li>
        </ul>

        <span class="navbar-text text-muted me-3">
          <?= htmlspecialchars($_SESSION['nombre'] ?? '') ?>
        </span>

        <a href="../logout.php" class="btn btn-sm btn-outline-danger">
          <i class="bi bi-box-arrow-right"></i> Salir
        </a>
      </div>
    </div>
  </nav>

  <div style="height: 70px;"></div>
  <div class="container-lg mt-5">
    <div class="card shadow-sm rounded-4">
      <div class="card-header bg-dark text-white fw-semibold fs-5 d-flex justify-content-between align-items-center">
        <span>Clientes Registrados</span>
        <span class="badge bg-light text-dark badge-search" id="contador-clientes">Total: <?= $totalClientes ?></span>
      </div>
      <div class="card-body">
        <div class="mb-3 text-end">
          <a href="nuevocliente.php" class="btn btn-primary">
            <i class="bi bi-person-plus-fill"></i> Agregar Cliente
          </a>
        </div>

        <!-- Filtros - SOLO PARA ADMIN -->
        <?php if ($esAdmin): ?>
        <div class="filter-section mb-4">
          <div class="filter-header" onclick="toggleFilters()">
            <i class="bi bi-funnel"></i> Filtros de Fecha
            <i class="bi bi-chevron-down float-end"></i>
          </div>
          <div class="filter-content" id="filterContent">
            <form method="GET" action="" class="row g-3">
              <input type="hidden" name="pagina" value="1">
              
              <div class="col-md-4">
                <label for="fecha_inicio" class="form-label">Fecha Inicio</label>
                <input type="date" class="form-control" id="fecha_inicio" name="fecha_inicio" 
                       value="<?= htmlspecialchars($fechaInicio) ?>">
              </div>
              
              <div class="col-md-4">
                <label for="fecha_fin" class="form-label">Fecha Fin</label>
                <input type="date" class="form-control" id="fecha_fin" name="fecha_fin" 
                       value="<?= htmlspecialchars($fechaFin) ?>">
              </div>
              
              <div class="col-md-4 d-flex align-items-end">
                <div class="btn-group w-100">
                  <button type="submit" class="btn btn-primary">
                    <i class="bi bi-search"></i> Filtrar
                  </button>
                  <a href="agencia.php" class="btn btn-outline-secondary">
                    <i class="bi bi-x-circle"></i> Limpiar
                  </a>
                </div>
              </div>
            </form>
            
            <?php if (!empty($fechaInicio) || !empty($fechaFin)): ?>
            <div class="mt-3">
              <small class="text-muted">
                Filtros activos: 
                <?php if (!empty($fechaInicio)) echo "Desde: " . htmlspecialchars($fechaInicio) . " "; ?>
                <?php if (!empty($fechaFin)) echo "Hasta: " . htmlspecialchars($fechaFin); ?>
              </small>
            </div>
            <?php endif; ?>
          </div>
        </div>
        <?php endif; ?>

        <div class="search-container mb-4">
          <input class="form-control me-2" type="text" id="buscar" placeholder="Buscar cliente..." aria-label="Buscar">
          <span class="search-clear" id="clear-search">
            <i class="bi bi-x-circle"></i>
          </span>
        </div>
        
        <div id="mensaje" class="text-center text-muted mb-3">
          <?php if (!empty($fechaInicio) || !empty($fechaFin)): ?>
            Mostrando clientes filtrados por fecha
          <?php else: ?>
            Escribe al menos un caracter para buscar o usa los filtros de fecha
          <?php endif; ?>
        </div>
        
        <div class="table-responsive loading-table" id="tabla-container">
          <table class="table table-bordered table-hover align-middle">
            <thead class="table-dark text-center">
              <tr>
                <th>Nombre</th>
                <th>Asunto</th>
                <th>Descripción</th>
                <th>Liner Asignado</th>
                <th>Closer Asignado</th>
                <th>Fecha Registro</th>
              </tr>
            </thead>
            <tbody id="tabla-clientes">
              <?php
              $h = fn($v) => htmlspecialchars((string)($v ?? ''), ENT_QUOTES);
              if (count($clientes) > 0) {
                foreach ($clientes as $cliente) {
                  $fechaRegistro = date('d/m/Y H:i', strtotime($cliente['fecha_registro']));
                  $closerClass = ($cliente['closer_nombre_completo'] === 'No asignado') ? 'no-asignado' : '';
                  $descripcion = $h($cliente['descripcion_corta']);

                  echo "<tr data-id='" . (int)$cliente['id'] . "'>";
                  echo "<td>" . $h($cliente['nombre']) . " " . $h($cliente['apepat']) . " " . $h($cliente['apemat']) . "</td>";
                  echo "<td>" . $h($cliente['asunto']) . "</td>";
                  echo "<td title='{$descripcion}'>{$descripcion}" .
                       (strlen($cliente['descripcion_corta'] ?? '') >= 100 ? '...' : '') . "</td>";
                  echo "<td>" . $h($cliente['usuario_nombre']) . " " . $h($cliente['usuario_apepat']) . " " . $h($cliente['usuario_apemat']) . "</td>";
                  echo "<td class='{$closerClass}'>" . $h($cliente['closer_nombre_completo']) . "</td>";
                  echo "<td class='text-center'>{$fechaRegistro}</td>";
                  echo "</tr>";
                }
              } else {
                echo "<tr><td colspan='6' class='text-center'>No hay clientes registrados" . 
                     ((!empty($fechaInicio) || !empty($fechaFin)) ? " con los filtros aplicados" : "") . 
                     "</td></tr>";
              }
              ?>
            </tbody>
          </table>
        </div>
        
        <!-- Paginación -->
        <?php if ($totalPaginas > 1): ?>
        <nav aria-label="Paginación de clientes">
          <ul class="pagination justify-content-center">
            <!-- Botón Anterior -->
            <li class="page-item <?= $paginaActual == 1 ? 'disabled' : '' ?>">
              <a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['pagina' => $paginaActual - 1])) ?>" aria-label="Anterior">
                <span aria-hidden="true">&laquo;</span>
              </a>
            </li>
            
            <!-- Página inicial -->
            <?php if ($paginaActual > 3): ?>
            <li class="page-item">
              <a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['pagina' => 1])) ?>">1</a>
            </li>
            <?php if ($paginaActual > 4): ?>
            <li class="page-item disabled">
              <a class="page-link" href="#">...</a>
            </li>
            <?php endif; ?>
            <?php endif; ?>
            
            <!-- Páginas alrededor de la actual -->
            <?php for ($i = max(1, $paginaActual - 2); $i <= min($totalPaginas, $paginaActual + 2); $i++): ?>
            <li class="page-item <?= $i == $paginaActual ? 'active' : '' ?>">
              <a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['pagina' => $i])) ?>"><?= $i ?></a>
            </li>
            <?php endfor; ?>
            
            <!-- Página final -->
            <?php if ($paginaActual < $totalPaginas - 2): ?>
            <?php if ($paginaActual < $totalPaginas - 3): ?>
            <li class="page-item disabled">
              <a class="page-link" href="#">...</a>
            </li>
            <?php endif; ?>
            <li class="page-item">
              <a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['pagina' => $totalPaginas])) ?>"><?= $totalPaginas ?></a>
            </li>
            <?php endif; ?>
            
            <!-- Botón Siguiente -->
            <li class="page-item <?= $paginaActual == $totalPaginas ? 'disabled' : '' ?>">
              <a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['pagina' => $paginaActual + 1])) ?>" aria-label="Siguiente">
                <span aria-hidden="true">&raquo;</span>
              </a>
            </li>
          </ul>
        </nav>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <!-- Modal de edición/visualización - MODIFICADO PARA PERMITIR CLOSER A TODOS -->
  <div class="modal fade" id="modalEditar" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
      <div class="modal-content">
        <form id="formEditar">
          <div class="modal-header">
            <h5 class="modal-title"><?= $esAdmin ? 'Editar' : 'Ver' ?> Cliente</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
          </div>

          <div class="modal-body">
            <input type="hidden" id="editar_id" name="id">

            <!-- Datos principales (solo lectura para no admin) -->
            <div class="row">
              <div class="col-md-4 mb-2">
                <label class="form-label small">Nombre:</label>
                <input type="text" id="editar_nombre" name="nombre" class="form-control form-control-sm <?= !$esAdmin ? 'readonly-field' : '' ?>" 
                       <?= !$esAdmin ? 'readonly' : '' ?>>
              </div>
              <div class="col-md-4 mb-2">
                <label class="form-label small">Apellido Paterno:</label>
                <input type="text" id="editar_apepat" name="apepat" class="form-control form-control-sm <?= !$esAdmin ? 'readonly-field' : '' ?>"
                       <?= !$esAdmin ? 'readonly' : '' ?>>
              </div>
              <div class="col-md-4 mb-2">
                <label class="form-label small">Apellido Materno:</label>
                <input type="text" id="editar_apemat" name="apemat" class="form-control form-control-sm <?= !$esAdmin ? 'readonly-field' : '' ?>"
                       <?= !$esAdmin ? 'readonly' : '' ?>>
              </div>
            </div>

            <div class="row">
              <div class="col-md-6 mb-2">
                <label class="form-label small">Número 1:</label>
                <input type="text" id="editar_numero1" name="numero1" class="form-control form-control-sm <?= !$esAdmin ? 'readonly-field' : '' ?>"
                       <?= !$esAdmin ? 'readonly' : '' ?>>
              </div>
              <div class="col-md-6 mb-2">
                <label class="form-label small">Número 2:</label>
                <input type="text" id="editar_numero2" name="numero2" class="form-control form-control-sm <?= !$esAdmin ? 'readonly-field' : '' ?>"
                       <?= !$esAdmin ? 'readonly' : '' ?>>
              </div>
            </div>

            <div class="mb-2">
              <label class="form-label small">Asunto:</label>
              <input type="text" id="editar_asunto" name="asunto" class="form-control form-control-sm <?= !$esAdmin ? 'readonly-field' : '' ?>"
                     <?= !$esAdmin ? 'readonly' : '' ?>>
            </div>

            <div class="mb-2">
              <label class="form-label small">Descripción:</label>
              <textarea id="editar_descripcion" name="descripcion" class="form-control form-control-sm <?= !$esAdmin ? 'readonly-field' : '' ?>" rows="3"
                        <?= !$esAdmin ? 'readonly' : '' ?>></textarea>
            </div>

            <!-- Usuario asignado (solo para admin) -->
            <?php if ($esAdmin): ?>
            <div class="mb-2">
              <label class="form-label small">Asignado a Usuario:</label>
              <select id="usuarios_id" name="usuarios_id" class="form-select form-select-sm">
                <option value="">-- Selecciona un usuario --</option>
              </select>
            </div>
            <?php endif; ?>

            <!-- Sección de Closer (AHORA DISPONIBLE PARA TODOS LOS USUARIOS) -->
            <div class="col-12 mt-2">
              <button type="button" id="btnAgregarCloser" class="btn btn-sm btn-outline-secondary">
                <i class="bi bi-plus-circle"></i> Agregar/Modificar Closer
              </button>
            </div>

            <div id="closerSection" class="closer-section col-12 mt-2" style="display:none;">
              <label class="form-label small mb-1">Seleccionar Closer:</label>
              <select class="form-select form-select-sm" id="usuario_closer" name="usuario_closer">
                <option value="">-- Seleccione un closer --</option>
              </select>
            </div>
          </div>

          <div class="modal-footer">
            <!-- Botón de guardar solo para admin, pero asignación de closer para todos -->
            <?php if ($esAdmin): ?>
              <button type="submit" class="btn btn-primary btn-sm">Guardar cambios</button>
            <?php else: ?>
              <button type="button" id="btnGuardarCloser" class="btn btn-primary btn-sm">Guardar Closer</button>
            <?php endif; ?>
            <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cerrar</button>
          </div>
        </form>
      </div>
    </div>
  </div>

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
  <script>
      // Datos del servidor para el JS de esta pagina
      const usuarioIdSesion = <?= (int)$_SESSION['usuario_id'] ?>;
      const rolSesion = <?= json_encode($rol, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
      const esAdmin = <?= $esAdmin ? 'true' : 'false' ?>;
      const totalClientesInicial = <?= $totalClientes ?>;
  </script>
  <script src="JS/agencia.js?v=<?= filemtime(__DIR__ . '/JS/agencia.js') ?>"></script>
</body>
</html>