<?php
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../session_config.php'; session_start();
date_default_timezone_set('America/Mexico_City');
if (!isset($_SESSION['usuario_id']) || ($_SESSION['rol'] !== 'admin' && $_SESSION['rol'] !== 'usuario' && $_SESSION['rol'] !== 'superadmin')) {
    header("Location: ../login.php");
    exit;
}

// Conexión a la base de datos
$pdo = conexionPDO();

// Obtener permisos del usuario si es admin o superadmin
$acceso_todas_salas = 1;
$salas_permitidas = [];

if ($_SESSION['rol'] === 'superadmin') {
    $acceso_todas_salas = 1;
    $salas_permitidas = [];
} elseif ($_SESSION['rol'] === 'admin') {
    $sqlPermisos = "SELECT acceso_todas_salas, salas_permitidas FROM usuarios WHERE id = :usuario_id";
    $stmtPermisos = $pdo->prepare($sqlPermisos);
    $stmtPermisos->execute([':usuario_id' => $_SESSION['usuario_id']]);
    $permisos = $stmtPermisos->fetch(PDO::FETCH_ASSOC);
    
    if ($permisos) {
        $acceso_todas_salas = (int)$permisos['acceso_todas_salas'];
        if (!empty($permisos['salas_permitidas'])) {
            $salas_permitidas = json_decode($permisos['salas_permitidas'], true);
            if (!is_array($salas_permitidas)) {
                $salas_permitidas = [];
            }
        }
    }
}

// Obtener parámetros de filtrado
$fechaInicio = isset($_GET['fecha_inicio']) ? $_GET['fecha_inicio'] : '';
$fechaFin = isset($_GET['fecha_fin']) ? $_GET['fecha_fin'] : '';

if (($_SESSION['rol'] === 'admin' || $_SESSION['rol'] === 'superadmin') && $acceso_todas_salas === 0 && isset($_SESSION['sala_id']) && !empty($_SESSION['sala_id'])) {
    $salaFiltro = isset($_GET['sala_id']) ? (int)$_GET['sala_id'] : $_SESSION['sala_id'];
} else {
    $salaFiltro = isset($_GET['sala_id']) ? (int)$_GET['sala_id'] : '';
}

// Obtener la lista de salas para el filtro
$salas = [];
if ($_SESSION['rol'] === 'admin' || $_SESSION['rol'] === 'superadmin') {
    if ($acceso_todas_salas === 0 && isset($_SESSION['sala_id']) && !empty($_SESSION['sala_id'])) {
        $sqlSalas = "SELECT id, nombre FROM salas WHERE id = :sala_id AND activo = 1";
        $stmtSalas = $pdo->prepare($sqlSalas);
        $stmtSalas->execute([':sala_id' => $_SESSION['sala_id']]);
        $salas = $stmtSalas->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $sqlSalas = "SELECT id, nombre FROM salas WHERE activo = 1";
        
        if ($acceso_todas_salas === 0 && !empty($salas_permitidas)) {
            $placeholders = implode(',', array_fill(0, count($salas_permitidas), '?'));
            $sqlSalas .= " AND id IN ($placeholders)";
        }
        
        $sqlSalas .= " ORDER BY nombre";
        
        $stmtSalas = $pdo->prepare($sqlSalas);
        
        if ($acceso_todas_salas === 0 && !empty($salas_permitidas)) {
            foreach ($salas_permitidas as $key => $sala_id) {
                $stmtSalas->bindValue($key + 1, $sala_id, PDO::PARAM_INT);
            }
        }
        
        $stmtSalas->execute();
        $salas = $stmtSalas->fetchAll(PDO::FETCH_ASSOC);
    }
}

// Configuración de paginación
$clientesPorPagina = 10;
$paginaActual = isset($_GET['pagina']) ? (int)$_GET['pagina'] : 1;
if ($paginaActual < 1) $paginaActual = 1;

$whereConditions = [];
$params = [];

if ($_SESSION['rol'] !== 'admin' && $_SESSION['rol'] !== 'superadmin' && isset($_SESSION['sala_id']) && !empty($_SESSION['sala_id'])) {
    $whereConditions[] = "c.sala_id = :sala_id";
    $params[':sala_id'] = $_SESSION['sala_id'];
}

if (($_SESSION['rol'] === 'admin' || $_SESSION['rol'] === 'superadmin') && $acceso_todas_salas === 0 && isset($_SESSION['sala_id']) && !empty($_SESSION['sala_id'])) {
    $whereConditions[] = "c.sala_id = :sala_asignada";
    $params[':sala_asignada'] = $_SESSION['sala_id'];
}

if (($_SESSION['rol'] === 'admin' || $_SESSION['rol'] === 'superadmin') && $acceso_todas_salas === 0 && !empty($salas_permitidas) && (!isset($_SESSION['sala_id']) || empty($_SESSION['sala_id']))) {
    $placeholders = [];
    foreach ($salas_permitidas as $index => $sala_id) {
        $param_name = ":sala_permitida_" . $index;
        $placeholders[] = $param_name;
        $params[$param_name] = $sala_id;
    }
    $whereConditions[] = "c.sala_id IN (" . implode(',', $placeholders) . ")";
}

if (($_SESSION['rol'] === 'admin' || $_SESSION['rol'] === 'superadmin') && $acceso_todas_salas === 1 && !empty($salaFiltro)) {
    $whereConditions[] = "c.sala_id = :sala_filtro";
    $params[':sala_filtro'] = $salaFiltro;
}

if (($_SESSION['rol'] === 'admin' || $_SESSION['rol'] === 'superadmin') && $acceso_todas_salas === 0 && !empty($salaFiltro) && (!isset($_SESSION['sala_id']) || empty($_SESSION['sala_id']))) {
    if (in_array($salaFiltro, $salas_permitidas)) {
        $whereConditions[] = "c.sala_id = :sala_filtro";
        $params[':sala_filtro'] = $salaFiltro;
    } else {
        $whereConditions[] = "1 = 0";
    }
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

try {
    $sqlTotal = "SELECT COUNT(*) as total FROM cliente c $whereClause";
    $stmtTotal = $pdo->prepare($sqlTotal);
    foreach ($params as $key => $value) {
        $stmtTotal->bindValue($key, $value);
    }
    $stmtTotal->execute();
    $totalClientes = $stmtTotal->fetch(PDO::FETCH_ASSOC)['total'];
    $totalPaginas = ceil($totalClientes / $clientesPorPagina);

    if ($paginaActual > $totalPaginas && $totalPaginas > 0) {
        $paginaActual = $totalPaginas;
    }

    $offset = ($paginaActual - 1) * $clientesPorPagina;

    $sqlClientes = "
    SELECT c.id, c.nombre, c.apepat, c.apemat, c.asunto, 
           LEFT(c.descripcion, 100) as descripcion_corta,
           c.fecha_registro, c.usuario_closer, c.sala_id, c.usuario_id,
           u.nombre as usuario_nombre, 
           u.apemat as usuario_apemat,
           s.nombre as sala_nombre,
           uc.apemat as closer_apemat
    FROM cliente c 
    LEFT JOIN usuarios u ON c.usuario_id = u.id 
    LEFT JOIN usuarios uc ON c.usuario_closer = uc.id
    LEFT JOIN salas s ON c.sala_id = s.id
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
    die("Error al cargar los clientes. Por favor, intente más tarde.");
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <title>Clientes</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet">
  <style>
    .search-clear {
      position: absolute;
      right: 10px;
      top: 50%;
      transform: translateY(-50%);
      cursor: pointer;
      display: none;
      z-index: 10;
    }
    .search-container {
      position: relative;
    }
    .table-hover tbody tr:hover {
      background-color: rgba(0, 0, 0, 0.075);
      cursor: pointer;
    }
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
    .filter-content {
      display: none;
    }
    .filter-content.show {
      display: block;
    }
    .spinner-border-sm {
      width: 1rem;
      height: 1rem;
      margin-right: 0.5rem;
    }
    .badge-search {
      transition: all 0.3s ease;
    }
    .closer-section, .sala-admin-section {
      margin-top: 15px;
      padding-top: 15px;
      border-top: 1px solid #dee2e6;
    }
    .loading {
      opacity: 0.7;
      pointer-events: none;
    }
    .no-asignado {
      color: #6c757d;
      font-style: italic;
    }
    .loading-table {
      position: relative;
      min-height: 100px;
    }
    .loading-table::before {
      content: "";
      position: absolute;
      top: 0;
      left: 0;
      right: 0;
      bottom: 0;
      background: rgba(255, 255, 255, 0.8);
      z-index: 1;
      display: none;
    }
    .loading-table.loading::before {
      display: block;
    }
    .loading-table::after {
      content: "";
      position: absolute;
      top: 50%;
      left: 50%;
      margin: -15px 0 0 -15px;
      width: 30px;
      height: 30px;
      border: 3px solid #f3f3f3;
      border-top: 3px solid #007bff;
      border-radius: 50%;
      animation: spin 1s linear infinite;
      display: none;
      z-index: 2;
    }
    .loading-table.loading::after {
      display: block;
    }
    @keyframes spin {
      0% { transform: rotate(0deg); }
      100% { transform: rotate(360deg); }
    }
    .readonly-field {
      background-color: #f8f9fa;
      cursor: not-allowed;
    }
    #mensaje div {
      padding: 8px 12px;
      border-radius: 4px;
      margin-bottom: 10px;
    }
    .search-feedback {
      font-size: 0.875rem;
      margin-top: 5px;
    }
    .result-count {
      font-size: 0.9rem;
      font-weight: 500;
    }
    .filter-section .row {
      margin-bottom: 15px;
    }
    .filter-section select.form-select {
      height: 38px;
    }
    .filter-section small.text-muted {
      background-color: #e9ecef;
      padding: 5px 10px;
      border-radius: 4px;
      display: inline-block;
      margin-top: 5px;
    }
    .sala-admin-section {
      display: none;
    }
    .sala-admin-section.show {
      display: block;
    }
    .closer-section {
      display: none;
    }
    .closer-section.show {
      display: block;
    }
    .badge-usuarios {
      font-size: 0.7rem;
      margin-left: 5px;
    }
    .permiso-info {
      font-size: 0.85rem;
      padding: 5px 10px;
      border-radius: 4px;
      margin-top: 5px;
    }
    .sin-permisos {
      background-color: #fff3cd;
      color: #856404;
      border: 1px solid #ffeaa7;
      padding: 10px;
      border-radius: 5px;
      margin-top: 10px;
    }
    .readonly-admin {
      background-color: #f8f9fa;
    }
    .disabled-select {
      background-color: #e9ecef;
      cursor: not-allowed;
    }
  </style>
</head>

<body class="bg-light" 
  data-rol="<?= htmlspecialchars($_SESSION['rol']) ?>"
  data-acceso-todas-salas="<?= $acceso_todas_salas ?>"
  data-salas-permitidas="<?= htmlspecialchars(json_encode($salas_permitidas)) ?>"
  data-usuario-id="<?= htmlspecialchars($_SESSION['usuario_id']) ?>"
  data-sala-id="<?= isset($_SESSION['sala_id']) ? htmlspecialchars($_SESSION['sala_id']) : '' ?>">
  
  <nav class="navbar navbar-expand-lg navbar-light bg-white shadow-sm fixed-top border-bottom">
    <div class="container-fluid">
      <div class="d-flex align-items-center ms-auto">
        <ul class="navbar-nav me-3">
          <?php if ($_SESSION['rol'] === 'admin' || $_SESSION['rol'] === 'superadmin'): ?>
          <li class="nav-item">
            <a class="nav-link text-dark" href="../opAdmin.php" style="text-decoration: none;">
             Dashboard
            </a>
          </li>
          <?php endif; ?> 
          <?php if ($_SESSION['rol'] === 'admin' || $_SESSION['rol'] === 'superadmin'): ?>
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
          
          <?php if ($_SESSION['rol'] === 'admin' || $_SESSION['rol'] === 'superadmin'): ?>
          <a href="../exportar_excel.php?<?= http_build_query($_GET) ?>" class="btn btn-success ms-2" id="exportar-excel-btn">
            <i class="bi bi-file-earmark-excel-fill"></i> Exportar a Excel
          </a>
          <?php endif; ?>
        </div>

        <?php if ($_SESSION['rol'] === 'admin' || $_SESSION['rol'] === 'superadmin'): ?>
        <div class="filter-section mb-4">
          <div class="filter-header" onclick="toggleFilters()">
            <i class="bi bi-funnel"></i> Filtros Avanzados
            <i class="bi bi-chevron-down float-end"></i>
          </div>
          <div class="filter-content" id="filterContent">
            <form method="GET" action="" class="row g-3">
              <input type="hidden" name="pagina" value="1">
              
              <div class="col-md-3">
                <label for="sala_id" class="form-label">Sala</label>
                <select class="form-select <?= ($acceso_todas_salas === 0 && isset($_SESSION['sala_id']) && !empty($_SESSION['sala_id'])) ? 'disabled-select' : '' ?>" 
                        id="sala_id" name="sala_id" 
                        <?= ($acceso_todas_salas === 0 && isset($_SESSION['sala_id']) && !empty($_SESSION['sala_id'])) ? 'disabled' : '' ?>>
                  <?php if ($acceso_todas_salas === 0 && isset($_SESSION['sala_id']) && !empty($_SESSION['sala_id'])): ?>
                    <?php 
                    $salaUsuario = '';
                    foreach ($salas as $sala) {
                        if ($sala['id'] == $_SESSION['sala_id']) {
                            $salaUsuario = $sala['nombre'];
                            break;
                        }
                    }
                    ?>
                    <option value="<?= htmlspecialchars($_SESSION['sala_id']) ?>" selected>
                      <?= htmlspecialchars($salaUsuario ?: 'Sala asignada') ?>
                    </option>
                  <?php else: ?>
                    <option value="">-- Todas las salas <?= $acceso_todas_salas === 0 ? 'permitidas' : '' ?> --</option>
                    <?php foreach ($salas as $sala): ?>
                      <option value="<?= htmlspecialchars($sala['id']) ?>" 
                        <?= ($salaFiltro == $sala['id']) ? 'selected' : '' ?>>
                        <?= htmlspecialchars($sala['nombre']) ?>
                      </option>
                    <?php endforeach; ?>
                  <?php endif; ?>
                </select>
                
                <?php if ($acceso_todas_salas === 0): ?>
                  <?php if (isset($_SESSION['sala_id']) && !empty($_SESSION['sala_id'])): ?>
                    <input type="hidden" name="sala_id" value="<?= htmlspecialchars($_SESSION['sala_id']) ?>">
                    <small class="text-muted d-block mt-1">
                      <i class="bi bi-info-circle"></i> Acceso limitado a una sola sala
                    </small>
                  <?php else: ?>
                    <small class="text-muted d-block mt-1">
                      <i class="bi bi-info-circle"></i> Acceso limitado a <?= count($salas) ?> sala(s)
                    </small>
                  <?php endif; ?>
                <?php endif; ?>
              </div>
              
              <div class="col-md-3">
                <label for="fecha_inicio" class="form-label">Fecha Inicio</label>
                <input type="date" class="form-control" id="fecha_inicio" name="fecha_inicio" 
                       value="<?= htmlspecialchars($fechaInicio) ?>">
              </div>
              
              <div class="col-md-3">
                <label for="fecha_fin" class="form-label">Fecha Fin</label>
                <input type="date" class="form-control" id="fecha_fin" name="fecha_fin" 
                       value="<?= htmlspecialchars($fechaFin) ?>">
              </div>
              
              <div class="col-md-3 d-flex align-items-end">
                <div class="btn-group w-100">
                  <button type="submit" class="btn btn-primary">
                    <i class="bi bi-search"></i> Filtrar
                  </button>
                  <?php if ($acceso_todas_salas === 1 || (!isset($_SESSION['sala_id']) || empty($_SESSION['sala_id']))): ?>
                    <a href="agencia.php" class="btn btn-outline-secondary">
                      <i class="bi bi-x-circle"></i> Limpiar
                    </a>
                  <?php endif; ?>
                </div>
              </div>
            </form>
            
            <?php if (!empty($salaFiltro) || !empty($fechaInicio) || !empty($fechaFin)): ?>
            <div class="mt-3">
              <small class="text-muted">
                Filtros activos: 
                <?php 
                if (!empty($salaFiltro)) {
                  $salaNombre = '';
                  foreach ($salas as $sala) {
                    if ($sala['id'] == $salaFiltro) {
                      $salaNombre = $sala['nombre'];
                      break;
                    }
                  }
                  echo "Plataforma: " . htmlspecialchars($salaNombre) . " | ";
                }
                ?>
                <?php if (!empty($fechaInicio)) echo "Desde: " . htmlspecialchars($fechaInicio) . " "; ?>
                <?php if (!empty($fechaFin)) echo "Hasta: " . htmlspecialchars($fechaFin); ?>
              </small>
            </div>
            <?php endif; ?>
            
            <?php if ($acceso_todas_salas === 0): ?>
            <div class="mt-3">
              <small class="text-info permiso-info">
                <i class="bi bi-shield-lock"></i> 
                <?php if (isset($_SESSION['sala_id']) && !empty($_SESSION['sala_id'])): ?>
                  Acceso limitado a la sala: <?= htmlspecialchars($salaUsuario ?: 'Sala asignada') ?>
                <?php else: ?>
                  Tienes acceso limitado a <?= count($salas) ?> sala(s): 
                  <?php 
                  $salaNombres = array_map(function($sala) {
                      return $sala['nombre'];
                  }, $salas);
                  echo htmlspecialchars(implode(', ', $salaNombres));
                  ?>
                <?php endif; ?>
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
        
        <div id="mensaje" class="text-center mb-3">
          <?php if (!empty($fechaInicio) || !empty($fechaFin) || !empty($salaFiltro)): ?>
            <div class="text-info">Mostrando clientes filtrados</div>
          <?php else: ?>
            <div class="text-muted">Escribe al menos un carácter para buscar o usa los filtros</div>
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
                <th>Sala</th>
                <th>Fecha Registro</th>
              </tr>
            </thead>
            <tbody id="tabla-clientes">
              <?php
              if (count($clientes) > 0) {
                foreach ($clientes as $cliente) {
                    $fechaRegistro = date('d/m/Y H:i', strtotime($cliente['fecha_registro']));
                    $usuarioApemat = isset($cliente['usuario_apemat']) ? preg_replace('/[^\p{L}\p{N}\s]/u', '', $cliente['usuario_apemat']) : '';
                    $closerApemat = isset($cliente['closer_apemat']) ? preg_replace('/[^\p{L}\p{N}\s]/u', '', $cliente['closer_apemat']) : '';
                    $salaNombre = $cliente['sala_nombre'] ?? 'Sin sala';
                    
                    if (empty($usuarioApemat) && !empty($cliente['usuario_nombre'])) {
                        $usuarioApemat = $cliente['usuario_nombre'];
                    }
                    
                    $closerClass = empty($closerApemat) ? 'no-asignado' : '';
                    $linerClass = empty($usuarioApemat) ? 'no-asignado' : '';
                    
                    echo "<tr data-id='{$cliente['id']}'>";
                    echo "<td>{$cliente['nombre']} {$cliente['apepat']} {$cliente['apemat']}</td>";
                    echo "<td>{$cliente['asunto']}</td>";
                    echo "<td title='" . htmlspecialchars($cliente['descripcion_corta']) . "'>" . 
                         htmlspecialchars($cliente['descripcion_corta']) . 
                         (strlen($cliente['descripcion_corta']) >= 100 ? '...' : '') . "</td>";
                    echo "<td class='{$linerClass}'>" . ($usuarioApemat ?: 'No asignado') . "</td>";
                    echo "<td class='{$closerClass}'>" . ($closerApemat ?: 'No asignado') . "</td>";
                    echo "<td class='text-center'>{$salaNombre}</td>";
                    echo "<td class='text-center'>{$fechaRegistro}</td>";
                    echo "</tr>";
                }
              } else {
                echo "<tr><td colspan='7' class='text-center'>No hay clientes registrados" . 
                     ((!empty($fechaInicio) || !empty($fechaFin) || !empty($salaFiltro)) ? " con los filtros aplicados" : "") . 
                     "</td></tr>";
              }
              ?>
            </tbody>
          </table>
        </div>
        
        <?php if ($totalPaginas > 1): ?>
        <nav aria-label="Paginación de clientes">
          <ul class="pagination justify-content-center">
            <li class="page-item <?= $paginaActual == 1 ? 'disabled' : '' ?>">
              <a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['pagina' => $paginaActual - 1])) ?>" aria-label="Anterior">
                <span aria-hidden="true">&laquo;</span>
              </a>
            </li>
            
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
            
            <?php for ($i = max(1, $paginaActual - 2); $i <= min($totalPaginas, $paginaActual + 2); $i++): ?>
            <li class="page-item <?= $i == $paginaActual ? 'active' : '' ?>">
              <a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['pagina' => $i])) ?>"><?= $i ?></a>
            </li>
            <?php endfor; ?>
            
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

  <div class="modal fade" id="modalEditar" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
      <div class="modal-content">
        <form id="formEditar">
          <div class="modal-header">
            <h5 class="modal-title"><?= ($_SESSION['rol'] === 'admin' || $_SESSION['rol'] === 'superadmin') ? 'Editar' : 'Ver' ?> Cliente</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
          </div>

          <div class="modal-body">
            <input type="hidden" id="editar_id" name="id">
            <input type="hidden" id="editar_sala_id" name="sala_id">

            <div class="row">
              <div class="col-md-4 mb-2">
                <label class="form-label small">Nombre:</label>
                <input type="text" id="editar_nombre" name="nombre" class="form-control form-control-sm <?= ($_SESSION['rol'] !== 'admin' && $_SESSION['rol'] !== 'superadmin') ? 'readonly-field' : '' ?>" 
                       <?= ($_SESSION['rol'] !== 'admin' && $_SESSION['rol'] !== 'superadmin') ? 'readonly' : '' ?>>
              </div>
              <div class="col-md-4 mb-2">
                <label class="form-label small">Apellido Paterno:</label>
                <input type="text" id="editar_apepat" name="apepat" class="form-control form-control-sm <?= ($_SESSION['rol'] !== 'admin' && $_SESSION['rol'] !== 'superadmin') ? 'readonly-field' : '' ?>"
                       <?= ($_SESSION['rol'] !== 'admin' && $_SESSION['rol'] !== 'superadmin') ? 'readonly' : '' ?>>
              </div>
              <div class="col-md-4 mb-2">
                <label class="form-label small">Apellido Materno:</label>
                <input type="text" id="editar_apemat" name="apemat" class="form-control form-control-sm <?= ($_SESSION['rol'] !== 'admin' && $_SESSION['rol'] !== 'superadmin') ? 'readonly-field' : '' ?>"
                       <?= ($_SESSION['rol'] !== 'admin' && $_SESSION['rol'] !== 'superadmin') ? 'readonly' : '' ?>>
              </div>
            </div>

            <div class="row">
              <div class="col-md-6 mb-2">
                <label class="form-label small">Número 1:</label>
                <input type="text" id="editar_numero1" name="numero1" class="form-control form-control-sm <?= ($_SESSION['rol'] !== 'admin' && $_SESSION['rol'] !== 'superadmin') ? 'readonly-field' : '' ?>"
                       <?= ($_SESSION['rol'] !== 'admin' && $_SESSION['rol'] !== 'superadmin') ? 'readonly' : '' ?>>
              </div>
              <div class="col-md-6 mb-2">
                <label class="form-label small">Número 2:</label>
                <input type="text" id="editar_numero2" name="numero2" class="form-control form-control-sm <?= ($_SESSION['rol'] !== 'admin' && $_SESSION['rol'] !== 'superadmin') ? 'readonly-field' : '' ?>"
                       <?= ($_SESSION['rol'] !== 'admin' && $_SESSION['rol'] !== 'superadmin') ? 'readonly' : '' ?>>
              </div>
            </div>

            <div class="mb-2">
              <label class="form-label small">Asunto:</label>
              <input type="text" id="editar_asunto" name="asunto" class="form-control form-control-sm <?= ($_SESSION['rol'] !== 'admin' && $_SESSION['rol'] !== 'superadmin') ? 'readonly-field' : '' ?>"
                     <?= ($_SESSION['rol'] !== 'admin' && $_SESSION['rol'] !== 'superadmin') ? 'readonly' : '' ?>>
            </div>

            <div class="mb-2">
              <label class="form-label small">Descripción:</label>
              <textarea id="editar_descripcion" name="descripcion" class="form-control form-control-sm <?= ($_SESSION['rol'] !== 'admin' && $_SESSION['rol'] !== 'superadmin') ? 'readonly-field' : '' ?>" rows="3"
                        <?= ($_SESSION['rol'] !== 'admin' && $_SESSION['rol'] !== 'superadmin') ? 'readonly' : '' ?>></textarea>
            </div>

            <?php if ($_SESSION['rol'] === 'admin' || $_SESSION['rol'] === 'superadmin'): ?>
            <?php 
            $salasParaModal = [];
            
            if ($_SESSION['rol'] === 'superadmin') {
                $stmtTodasSalas = $pdo->query("SELECT id, nombre FROM salas WHERE activo = 1 ORDER BY nombre");
                $salasParaModal = $stmtTodasSalas->fetchAll(PDO::FETCH_ASSOC);
            } 
            elseif ($acceso_todas_salas === 1) {
                $stmtTodasSalas = $pdo->query("SELECT id, nombre FROM salas WHERE activo = 1 ORDER BY nombre");
                $salasParaModal = $stmtTodasSalas->fetchAll(PDO::FETCH_ASSOC);
            } 
            else {
                if (isset($_SESSION['sala_id']) && !empty($_SESSION['sala_id'])) {
                    $sqlSalaEspecifica = "SELECT id, nombre FROM salas WHERE id = :sala_id AND activo = 1";
                    $stmtSalaEspecifica = $pdo->prepare($sqlSalaEspecifica);
                    $stmtSalaEspecifica->execute([':sala_id' => $_SESSION['sala_id']]);
                    $salasParaModal = $stmtSalaEspecifica->fetchAll(PDO::FETCH_ASSOC);
                } 
                elseif (!empty($salas_permitidas)) {
                    $placeholders = implode(',', array_fill(0, count($salas_permitidas), '?'));
                    $sqlSalasPermitidas = "SELECT id, nombre FROM salas WHERE id IN ($placeholders) AND activo = 1 ORDER BY nombre";
                    $stmtSalasPermitidas = $pdo->prepare($sqlSalasPermitidas);
                    foreach ($salas_permitidas as $key => $sala_id) {
                        $stmtSalasPermitidas->bindValue($key + 1, $sala_id, PDO::PARAM_INT);
                    }
                    $stmtSalasPermitidas->execute();
                    $salasParaModal = $stmtSalasPermitidas->fetchAll(PDO::FETCH_ASSOC);
                } 
                else {
                    $salasParaModal = $salas;
                }
            }
            ?>
            <div class="col-12 mt-2">
              <button type="button" id="btnSeleccionarSala" class="btn btn-sm btn-outline-info">
                <i class="bi bi-building"></i> Asignar Sala
              </button>
            </div>
            
            <div id="salaAdminSection" class="sala-admin-section col-12 mt-2">
              <label class="form-label small mb-1">Seleccionar Sala:</label>
              <select class="form-select form-select-sm" name="sala_id_admin" id="salaSelectAdmin">
                <option value="">-- Usar sala actual --</option>
                <?php foreach ($salasParaModal as $sala): ?>
                  <option value="<?= $sala['id'] ?>">
                    <?= htmlspecialchars($sala['nombre']) ?> (ID: <?= $sala['id'] ?>)
                  </option>
                <?php endforeach; ?>
              </select>
              <small class="text-muted">
                Seleccione una sala específica. 
                <?php if ($_SESSION['rol'] === 'admin' && $acceso_todas_salas === 0): ?>
                <span class="text-warning">
                  <i class="bi bi-exclamation-triangle"></i> 
                  <?php if (isset($_SESSION['sala_id']) && !empty($_SESSION['sala_id'])): ?>
                    Solo puedes asignar tu sala asignada.
                  <?php else: ?>
                    Solo puedes asignar salas permitidas.
                  <?php endif; ?>
                </span>
                <?php endif; ?>
              </small>
            </div>
            <?php endif; ?>

            <?php if ($_SESSION['rol'] === 'admin' || $_SESSION['rol'] === 'superadmin'): ?>
            <div class="mb-2">
              <label class="form-label small">Asignar a Usuario:</label>
              <select id="usuarios_id" name="usuarios_id" class="form-select form-select-sm">
                <option value="">-- Selecciona un usuario --</option>
              </select>
            </div>
            <?php endif; ?>

            <?php if ($_SESSION['rol'] === 'admin' || $_SESSION['rol'] === 'superadmin' || $_SESSION['rol'] === 'usuario'): ?>
            <div class="col-12 mt-2">
              <button type="button" id="btnAgregarCloser" class="btn btn-sm btn-outline-secondary">
                <i class="bi bi-plus-circle"></i> Agregar/Modificar Closer
              </button>
            </div>

            <div id="closerSection" class="closer-section col-12 mt-2">
              <label class="form-label small mb-1">Seleccionar Closer:</label>
              <select class="form-select form-select-sm" id="usuario_closer" name="usuario_closer">
                <option value="">-- Seleccione un closer --</option>
              </select>
            </div>
            <?php endif; ?>
            
            <div id="mensajePermisos" class="sin-permisos" style="display: none;">
              <i class="bi bi-exclamation-triangle"></i> 
              <strong>Acceso limitado:</strong> Este cliente pertenece a una sala a la que no tienes acceso. Solo puedes ver la información básica del cliente.
            </div>
          </div>

          <div class="modal-footer">
            <?php if ($_SESSION['rol'] === 'admin' || $_SESSION['rol'] === 'superadmin'): ?>
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
  // ============================================================
  // VARIABLES GLOBALES
  // ============================================================
  const rolUsuario = document.body.getAttribute('data-rol');
  const acceso_todas_salas = parseInt(document.body.getAttribute('data-acceso-todas-salas') || '1');
  const salas_permitidas = JSON.parse(document.body.getAttribute('data-salas-permitidas') || '[]');
  const usuarioId = document.body.getAttribute('data-usuario-id');
  const salaIdUsuario = document.body.getAttribute('data-sala-id');
  
  let paginaActual = <?= $paginaActual ?>;
  const totalPaginas = <?= $totalPaginas ?>;
  const totalClientesInicial = <?= $totalClientes ?>;
  let valoresOriginales = {};
  let usuariosCargados = [];
  let closersCargados = [];
  let timeoutBusqueda = null;
  let busquedaEnCurso = false;
  let ultimaBusqueda = '';

  // ============================================================
  // SOLUCIÓN RACE CONDITION: AbortController + Token de secuencia
  // ============================================================
  let abortController = null;        // Para cancelar fetch en vuelo
  let secuenciaBusqueda = 0;         // Token para descartar respuestas obsoletas

  // ====================== FUNCIONES UTILITARIAS ======================
  function getElement(id) {
    const el = document.getElementById(id);
    if (!el) console.warn(`Elemento con ID ${id} no encontrado`);
    return el;
  }

  function toggleFilters() {
    const filterContent = getElement('filterContent');
    if (filterContent) {
      filterContent.classList.toggle('show');
    }
  }

  function configurarBotonLimpiar() {
    const buscarInput = getElement('buscar');
    const clearButton = getElement('clear-search');
    if (buscarInput && clearButton) {
      buscarInput.addEventListener('input', function () {
        clearButton.style.display = this.value ? 'block' : 'none';
      });
      clearButton.addEventListener('click', function () {
        buscarInput.value = '';
        clearButton.style.display = 'none';
        actualizarContadorClientes(totalClientesInicial, 'Total');
        restaurarVistaInicial();
      });
    }
  }

  function actualizarContadorClientes(cantidad, texto = 'Resultados') {
    const contador = getElement('contador-clientes');
    if (contador) contador.textContent = `${texto}: ${cantidad}`;
  }

  // ============================================================
  // BÚSQUEDA EN TIEMPO REAL - CON CANCELACIÓN Y CONTROL DE RACE CONDITION
  // ============================================================
  function configurarBusqueda() {
    const buscarInput = getElement('buscar');
    const tablaContainer = getElement('tabla-container');
    const mensaje = getElement('mensaje');
    const tablaClientes = getElement('tabla-clientes');
    
    if (!buscarInput || !tablaContainer || !mensaje || !tablaClientes) return;
    
    buscarInput.addEventListener('input', function() {
        const termino = this.value.trim();
        
        getElement('clear-search').style.display = termino ? 'block' : 'none';
        
        // Cancelar timeout pendiente
        if (timeoutBusqueda) {
            clearTimeout(timeoutBusqueda);
        }
        
        // Si está vacío, cancelar cualquier búsqueda en vuelo y restaurar
        if (termino === '') {
            // Cancelar fetch pendiente
            if (abortController) {
                abortController.abort();
                abortController = null;
            }
            restaurarVistaInicial();
            return;
        }
        
        // Debounce más alto para evitar disparar en cada tecla
        // 1 caracter: 500ms | 2+ caracteres: 350ms
        const delay = termino.length === 1 ? 500 : 350;
        
        timeoutBusqueda = setTimeout(() => {
            realizarBusqueda(termino);
        }, delay);
    });
    
    // Enter dispara búsqueda inmediata
    buscarInput.addEventListener('keypress', function(e) {
        if (e.key === 'Enter') {
            const termino = this.value.trim();
            if (termino) {
                if (timeoutBusqueda) {
                    clearTimeout(timeoutBusqueda);
                    timeoutBusqueda = null;
                }
                realizarBusqueda(termino);
            }
        }
    });
    
    function realizarBusqueda(termino) {
        // ============================================================
        // SOLUCIÓN 1: Cancelar la petición anterior si sigue en vuelo
        // ============================================================
        if (abortController) {
            abortController.abort();
        }
        abortController = new AbortController();
        const signalLocal = abortController.signal;
        
        // ============================================================
        // SOLUCIÓN 2: Token de secuencia para descartar respuestas viejas
        // ============================================================
        const miSecuencia = ++secuenciaBusqueda;
        
        ultimaBusqueda = termino;
        busquedaEnCurso = true;
        
        mensaje.innerHTML = '<div class="text-center"><span class="spinner-border spinner-border-sm" role="status"></span> Buscando clientes...</div>';
        tablaContainer.classList.add('loading');
        tablaClientes.innerHTML = '<tr><td colspan="7" class="text-center">Buscando...</td></tr>';
        
        const params = new URLSearchParams();
        params.append('buscar', termino);
        
        const salaId = getElement('sala_id')?.value || '';
        const fechaInicio = getElement('fecha_inicio')?.value || '';
        const fechaFin = getElement('fecha_fin')?.value || '';
        
        if (salaId) params.append('sala_id', salaId);
        if (fechaInicio) params.append('fecha_inicio', fechaInicio);
        if (fechaFin) params.append('fecha_fin', fechaFin);
        
        if (rolUsuario === 'admin' || rolUsuario === 'superadmin') {
          params.append('acceso_todas_salas', acceso_todas_salas);
          if (acceso_todas_salas === 0 && salas_permitidas.length > 0) {
            params.append('salas_permitidas', JSON.stringify(salas_permitidas));
          }
        }
        
        params.append('t', new Date().getTime());
        
        fetch(`buscar_clientes.php?${params.toString()}`, {
            signal: signalLocal  // ← Cancelación automática
        })
        .then(response => {
            if (!response.ok) {
                throw new Error('Error en la respuesta del servidor');
            }
            return response.text();
        })
        .then(data => {
            // ============================================================
            // VERIFICAR TOKEN: Si ya se hizo otra búsqueda, ignorar esta respuesta
            // ============================================================
            if (miSecuencia !== secuenciaBusqueda) {
                console.log(`Respuesta obsoleta ignorada (secuencia ${miSecuencia}, actual ${secuenciaBusqueda})`);
                return;
            }
            
            const contadorMatch = data.match(/<div id="resultado-contador" data-total="(\d+)"><\/div>/);
            const totalResultados = contadorMatch ? parseInt(contadorMatch[1]) : 0;
            const limiteMatch = data.match(/<div id="resultado-limite" data-limite="(\d+)" data-truncado="(\d+)"><\/div>/);
            const truncado = limiteMatch ? parseInt(limiteMatch[2]) === 1 : false;
            const limite = limiteMatch ? parseInt(limiteMatch[1]) : 0;
            
            let htmlResultados = data
                .replace(/<div id="resultado-contador" data-total="\d+"><\/div>/, '')
                .replace(/<div id="resultado-limite" data-limite="\d+" data-truncado="\d+"><\/div>/, '');
            
            tablaClientes.innerHTML = htmlResultados;
            actualizarContadorClientes(totalResultados, 'Resultados');
            
            if (totalResultados > 0) {
                let mensajeExtra = '';
                if (truncado) {
                    mensajeExtra = ` <span class="text-warning">(Mostrando solo los primeros ${limite}. Refina tu búsqueda.)</span>`;
                }
                mensaje.innerHTML = `<div class="text-success">✓ Se encontraron ${totalResultados} resultados para "${termino}"${mensajeExtra}</div>`;
            } else {
                mensaje.innerHTML = `<div class="text-warning">No se encontraron resultados para "${termino}"</div>`;
            }
            
            agregarEventosFila();
        })
        .catch(error => {
            // Ignorar errores por cancelación
            if (error.name === 'AbortError') {
                console.log('Búsqueda cancelada:', termino);
                return;
            }
            console.error('Error en la búsqueda:', error);
            
            // Solo mostrar error si sigue siendo la búsqueda actual
            if (miSecuencia === secuenciaBusqueda) {
                mensaje.innerHTML = '<div class="text-danger">✗ Error al realizar la búsqueda</div>';
                tablaClientes.innerHTML = '<tr><td colspan="7" class="text-center text-danger">Error al cargar los resultados</td></tr>';
            }
        })
        .finally(() => {
            // Solo quitar el loading si seguimos siendo la búsqueda actual
            if (miSecuencia === secuenciaBusqueda) {
                tablaContainer.classList.remove('loading');
                busquedaEnCurso = false;
            }
        });
    }
  }

  function restaurarVistaInicial() {
    const mensaje = getElement('mensaje');
    const tablaContainer = getElement('tabla-container');
    
    if (mensaje) {
        mensaje.innerHTML = '<div class="text-info">↻ Restaurando vista inicial...</div>';
    }
    
    if (tablaContainer) {
        tablaContainer.classList.add('loading');
    }
    
    setTimeout(() => {
        window.location.href = 'agencia.php';
    }, 100);
  }

  // ====================== TABLA CLIENTES ======================
  function agregarEventosFila() {
    document.querySelectorAll('#tabla-clientes tr[data-id]').forEach(fila => {
      fila.addEventListener('click', function (e) {
        if (e.target.closest('button')) return;
        const id = this.getAttribute('data-id');
        if (id) abrirModalEditar(id);
      });
    });
  }

  // ====================== USUARIOS Y CLOSERS ======================
  function cargarUsuarios(salaId = null) {
    const params = new URLSearchParams();
    if (salaId) params.append('sala_id', salaId);
    params.append('tipo', 'usuarios');
    
    if ((rolUsuario === 'admin' || rolUsuario === 'superadmin') && acceso_todas_salas === 0) {
        params.append('acceso_todas_salas', acceso_todas_salas);
        if (salas_permitidas.length > 0) {
            params.append('salas_permitidas', JSON.stringify(salas_permitidas));
        }
    }
    
    return fetch(`obtener_usuarios.php?${params.toString()}`)
      .then(res => {
        if (!res.ok) throw new Error(`Error HTTP: ${res.status}`);
        return res.json();
      })
      .then(data => {
        if (data.success && Array.isArray(data.usuarios)) {
          usuariosCargados = data.usuarios;
          return usuariosCargados;
        } else {
          throw new Error(data.message || 'Error en la respuesta');
        }
      })
      .catch(error => {
        console.error('Error cargando usuarios:', error);
        usuariosCargados = [];
        return [];
      });
  }

  function cargarClosers(salaId = null) {
    const params = new URLSearchParams();
    if (salaId) params.append('sala_id', salaId);
    params.append('tipo', 'closers');
    
    if ((rolUsuario === 'admin' || rolUsuario === 'superadmin') && acceso_todas_salas === 0) {
        params.append('acceso_todas_salas', acceso_todas_salas);
        if (salas_permitidas.length > 0) {
            params.append('salas_permitidas', JSON.stringify(salas_permitidas));
        }
    }
    
    return fetch(`obtener_usuarios.php?${params.toString()}`)
      .then(res => {
        if (!res.ok) throw new Error(`Error HTTP: ${res.status}`);
        return res.json();
      })
      .then(data => {
        if (data.success && Array.isArray(data.usuarios)) {
          closersCargados = data.usuarios;
          return closersCargados;
        } else {
          throw new Error(data.message || 'Error en la respuesta');
        }
      })
      .catch(error => {
        console.error('Error cargando closers:', error);
        closersCargados = [];
        return [];
      });
  }

  function llenarSelectUsuarios(usuarioSeleccionadoId = '') {
    const select = getElement('usuarios_id');
    if (!select) return;
    
    select.innerHTML = '<option value="">-- Selecciona un usuario --</option>';
    
    if (!Array.isArray(usuariosCargados) || usuariosCargados.length === 0) {
      select.innerHTML += '<option value="" disabled>No hay usuarios disponibles para esta sala</option>';
      return;
    }
    
    usuariosCargados.forEach(u => {
      const option = document.createElement('option');
      option.value = u.id;
      let displayText = u.apemat || `Usuario ${u.id}`;
      option.textContent = displayText;
      option.selected = (u.id == usuarioSeleccionadoId);
      select.appendChild(option);
    });
  }

  function llenarSelectClosers(closerSeleccionadoId = '') {
    const select = getElement('usuario_closer');
    if (!select) return;
    
    select.innerHTML = '<option value="">-- Seleccione un closer --</option>';
    
    if (!Array.isArray(closersCargados) || closersCargados.length === 0) {
      select.innerHTML += '<option value="" disabled>No hay closers disponibles para esta sala</option>';
      return;
    }
    
    closersCargados.forEach(c => {
      const option = document.createElement('option');
      option.value = c.id;
      let displayText = c.apemat || `Closer ${c.id}`;
      option.textContent = displayText;
      option.selected = (c.id == closerSeleccionadoId);
      select.appendChild(option);
    });
  }

  // ====================== CONFIGURACIÓN DE SALA ======================
  function configurarSelectSala() {
    const btnSeleccionarSala = getElement('btnSeleccionarSala');
    const salaAdminSection = getElement('salaAdminSection');
    const salaSelectAdmin = getElement('salaSelectAdmin');
    
    if (btnSeleccionarSala && salaAdminSection) {
      btnSeleccionarSala.addEventListener('click', () => {
        const isVisible = salaAdminSection.classList.contains('show');
        if (isVisible) {
          salaAdminSection.classList.remove('show');
          btnSeleccionarSala.innerHTML = '<i class="bi bi-building"></i> Asignar Sala';
        } else {
          salaAdminSection.classList.add('show');
          btnSeleccionarSala.innerHTML = '<i class="bi bi-building-slash"></i> Ocultar Sala';
        }
      });
    }
    
    if (salaSelectAdmin) {
      salaSelectAdmin.addEventListener('change', function() {
        const nuevaSalaId = this.value;
        
        if ((rolUsuario === 'admin' || rolUsuario === 'superadmin') && acceso_todas_salas === 0 && nuevaSalaId) {
            if (!salas_permitidas.includes(parseInt(nuevaSalaId))) {
                alert('No tienes permisos para asignar esta sala');
                this.value = '';
                return;
            }
        }
        
        const editarSalaId = getElement('editar_sala_id');
        if (editarSalaId) {
          editarSalaId.value = nuevaSalaId || '';
        }
        
        const mensajePermisos = getElement('mensajePermisos');
        if ((rolUsuario === 'admin' || rolUsuario === 'superadmin') && acceso_todas_salas === 0 && nuevaSalaId && !salas_permitidas.includes(parseInt(nuevaSalaId))) {
            if (mensajePermisos) mensajePermisos.style.display = 'block';
        } else {
            if (mensajePermisos) mensajePermisos.style.display = 'none';
        }
        
        if (rolUsuario === 'admin' || rolUsuario === 'superadmin') {
          const selectUsuario = getElement('usuarios_id');
          if (selectUsuario) {
            selectUsuario.innerHTML = '<option value="">Cargando usuarios...</option>';
          }
          
          cargarUsuarios(nuevaSalaId)
            .then(() => {
              const clienteId = getElement('editar_id').value;
              if (clienteId) {
                fetch(`obtener_cliente.php?id=${clienteId}`)
                  .then(response => response.json())
                  .then(data => {
                    if (data.success) {
                      llenarSelectUsuarios(data.data.usuario_id);
                    } else {
                      llenarSelectUsuarios();
                    }
                  })
                  .catch(() => llenarSelectUsuarios());
              } else {
                llenarSelectUsuarios();
              }
            })
            .catch(() => {
              if (selectUsuario) {
                selectUsuario.innerHTML = '<option value="">No se pueden cargar usuarios para esta sala</option>';
              }
            });
        }
        
        const selectCloser = getElement('usuario_closer');
        if (selectCloser) {
          selectCloser.innerHTML = '<option value="">Cargando closers...</option>';
        }
        
        cargarClosers(nuevaSalaId)
          .then(() => {
            const clienteId = getElement('editar_id').value;
            if (clienteId) {
              fetch(`obtener_cliente.php?id=${clienteId}`)
                .then(response => response.json())
                .then(data => {
                  if (data.success) {
                    llenarSelectClosers(data.data.usuario_closer);
                  } else {
                    llenarSelectClosers();
                  }
                })
                .catch(() => llenarSelectClosers());
            } else {
              llenarSelectClosers();
            }
          })
          .catch(() => {
            if (selectCloser) {
              selectCloser.innerHTML = '<option value="">No se pueden cargar closers para esta sala</option>';
            }
          });
      });
    }
  }

  // ====================== EDITAR CLIENTE ======================
  function abrirModalEditar(id) {
    const selectUsuario = getElement('usuarios_id');
    const selectCloser = getElement('usuario_closer');
    if (selectUsuario) selectUsuario.innerHTML = '<option value="">-- Selecciona un usuario --</option>';
    if (selectCloser) selectCloser.innerHTML = '<option value="">-- Seleccione un closer --</option>';
    
    const closerSection = getElement('closerSection');
    const salaAdminSection = getElement('salaAdminSection');
    if (closerSection) closerSection.style.display = 'none';
    if (salaAdminSection) salaAdminSection.classList.remove('show');
    
    const btnCloser = getElement('btnAgregarCloser');
    const btnSala = getElement('btnSeleccionarSala');
    if (btnCloser) btnCloser.innerHTML = '<i class="bi bi-plus-circle"></i> Agregar/Modificar Closer';
    if (btnSala) btnSala.innerHTML = '<i class="bi bi-building"></i> Asignar Sala';
    
    const mensajePermisos = getElement('mensajePermisos');
    if (mensajePermisos) mensajePermisos.style.display = 'none';
    
    fetch(`obtener_cliente.php?id=${id}`)
      .then(response => response.json())
      .then(data => {
        if (!data.success) {
          throw new Error(data.message || 'Error al cargar cliente');
        }
        
        const cliente = data.data;
        
        getElement('editar_id').value = cliente.id;
        getElement('editar_nombre').value = cliente.nombre || '';
        getElement('editar_apepat').value = cliente.apepat || '';
        getElement('editar_apemat').value = cliente.apemat || '';
        getElement('editar_numero1').value = cliente.numero1 || '';
        getElement('editar_numero2').value = cliente.numero2 || '';
        getElement('editar_asunto').value = cliente.asunto || '';
        getElement('editar_descripcion').value = cliente.descripcion || '';
        
        const editarSalaId = getElement('editar_sala_id');
        if (cliente.sala_id) {
          editarSalaId.value = cliente.sala_id;
        }
        
        const salaSelectAdmin = getElement('salaSelectAdmin');
        if (salaSelectAdmin && cliente.sala_id) {
          salaSelectAdmin.value = cliente.sala_id;
        }
        
        let salaIdParaCargar = null;
        if ((rolUsuario === 'admin' || rolUsuario === 'superadmin') && salaSelectAdmin && salaSelectAdmin.value) {
          salaIdParaCargar = salaSelectAdmin.value;
        } else if (cliente.sala_id) {
          salaIdParaCargar = cliente.sala_id;
        }
        
        const cargarUsuariosPromise = (rolUsuario === 'admin' || rolUsuario === 'superadmin') ? 
          cargarUsuarios(salaIdParaCargar) : 
          Promise.resolve([]);
        
        const cargarClosersPromise = cargarClosers(salaIdParaCargar);
        
        Promise.all([cargarUsuariosPromise, cargarClosersPromise])
          .then(([usuarios, closers]) => {
            if (rolUsuario === 'admin' || rolUsuario === 'superadmin') {
              llenarSelectUsuarios(cliente.usuario_id);
            }
            
            if (rolUsuario === 'admin' || rolUsuario === 'superadmin' || rolUsuario === 'usuario') {
              llenarSelectClosers(cliente.usuario_closer);
            }
            
            if (cliente.usuario_closer && (rolUsuario === 'admin' || rolUsuario === 'superadmin' || rolUsuario === 'usuario')) {
              if (closerSection && btnCloser) {
                closerSection.style.display = 'block';
                btnCloser.innerHTML = '<i class="bi bi-dash-circle"></i> Ocultar Closer';
              }
            }
            
            const modal = new bootstrap.Modal(getElement('modalEditar'));
            modal.show();
          })
          .catch(error => {
            console.error('Error cargando datos:', error);
            const modal = new bootstrap.Modal(getElement('modalEditar'));
            modal.show();
          });
      })
      .catch(error => {
        console.error('Error obteniendo cliente:', error);
        alert('Error al cargar los datos del cliente: ' + error.message);
      });
  }

  // ====================== FORMULARIO ======================
  function configurarFormulario() {
    const form = getElement('formEditar');
    if (!form) return;
    
    if (rolUsuario === 'admin' || rolUsuario === 'superadmin') {
      form.addEventListener('submit', e => {
        e.preventDefault();
        guardarCambios();
      });
    } else {
      const btnGuardarCloser = getElement('btnGuardarCloser');
      if (btnGuardarCloser) {
        btnGuardarCloser.addEventListener('click', e => {
          e.preventDefault();
          guardarSoloCloser();
        });
      }
      
      form.addEventListener('submit', e => {
        e.preventDefault();
      });
    }
  }

  function guardarCambios() {
    const id = getElement('editar_id').value;
    if (!id) {
      alert('Error: ID del cliente no encontrado');
      return;
    }
    
    if ((rolUsuario === 'admin' || rolUsuario === 'superadmin') && acceso_todas_salas === 0) {
        const salaId = getElement('salaSelectAdmin').value;
        if (salaId && !salas_permitidas.includes(parseInt(salaId))) {
            alert('No tienes permisos para asignar esta sala');
            return;
        }
    }
    
    const formData = new FormData(getElement('formEditar'));
    
    const submitBtn = getElement('formEditar').querySelector('button[type="submit"]');
    const originalText = submitBtn.innerHTML;
    submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Guardando...';
    submitBtn.disabled = true;
    
    fetch('actualizar_cliente.php', { 
      method: 'POST', 
      body: formData 
    })
    .then(response => response.text())
    .then(msg => { 
      alert(msg); 
      window.location.reload();
    })
    .catch(error => {
      console.error('Error:', error);
      alert('Error al actualizar el cliente');
    })
    .finally(() => {
      submitBtn.innerHTML = originalText;
      submitBtn.disabled = false;
    });
  }

  function guardarSoloCloser() {
    const id = getElement('editar_id').value;
    const usuarioCloser = getElement('usuario_closer').value;
    
    if (!id) {
      alert('Error: ID del cliente no encontrado');
      return;
    }
    
    const btnGuardarCloser = getElement('btnGuardarCloser');
    const originalText = btnGuardarCloser.innerHTML;
    btnGuardarCloser.innerHTML = '<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Guardando...';
    btnGuardarCloser.disabled = true;
    
    const formData = new FormData();
    formData.append('id', id);
    formData.append('usuario_closer', usuarioCloser);
    
    fetch('actualizar_closer.php', { 
      method: 'POST', 
      body: formData 
    })
    .then(response => response.text())
    .then(msg => { 
      alert(msg); 
      window.location.reload();
    })
    .catch(error => {
      console.error('Error:', error);
      alert('Error al actualizar el closer');
    })
    .finally(() => {
      btnGuardarCloser.innerHTML = originalText;
      btnGuardarCloser.disabled = false;
    });
  }

  // ====================== CONFIGURACIÓN DE CLOSER ======================
  function configurarBotonCloser() {
    const btnAgregarCloser = getElement('btnAgregarCloser');
    const closerSection = getElement('closerSection');
    
    if (btnAgregarCloser && closerSection) {
      btnAgregarCloser.addEventListener('click', () => {
        const isVisible = closerSection.style.display === 'block';
        closerSection.style.display = isVisible ? 'none' : 'block';
        
        const icon = btnAgregarCloser.querySelector('i');
        if (icon) {
          icon.className = isVisible ? 'bi bi-plus-circle' : 'bi bi-dash-circle';
        }
        
        btnAgregarCloser.innerHTML = isVisible ? 
          '<i class="bi bi-plus-circle"></i> Agregar/Modificar Closer' : 
          '<i class="bi bi-dash-circle"></i> Ocultar Closer';
        
        if (!isVisible && closersCargados.length === 0) {
          const salaSelectAdmin = getElement('salaSelectAdmin');
          const salaId = salaSelectAdmin ? salaSelectAdmin.value : null;
          
          cargarClosers(salaId)
            .then(() => llenarSelectClosers())
            .catch(error => console.error('Error al cargar closers:', error));
        }
      });
    }
  }

  // ====================== INICIALIZACIÓN ======================
  function inicializar() {
    configurarBotonLimpiar();
    configurarBusqueda();
    configurarSelectSala();
    configurarBotonCloser();
    configurarFormulario();
    agregarEventosFila();
  }

  document.addEventListener('DOMContentLoaded', inicializar);
  </script>
</body>
</html>