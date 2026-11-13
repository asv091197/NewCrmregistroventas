<?php
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../session_config.php'; session_start();
date_default_timezone_set('America/Mexico_City');
$rol = $_SESSION['rol'] ?? '';
if (!isset($_SESSION['usuario_id']) || !in_array($rol, ['admin', 'usuario', 'superadmin'], true)) {
    header("Location: ../login.php");
    exit;
}
// Superadmin tiene los mismos permisos que admin
$esAdmin = $rol === 'admin' || $rol === 'superadmin';
$salaSesion = !empty($_SESSION['sala_id']) ? $_SESSION['sala_id'] : null;

// Conexión a la base de datos
$pdo = conexionPDO();

// Permisos de sala: superadmin siempre accede a todas; admin según su registro
$acceso_todas_salas = 1;
$salas_permitidas = [];

if ($rol === 'admin') {
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
$fechaInicio = $_GET['fecha_inicio'] ?? '';
$fechaFin = $_GET['fecha_fin'] ?? '';
// Filtro de pagado: '' = todos, '1' = sí, '0' = no
$pagadoFiltro = isset($_GET['pagado']) && in_array($_GET['pagado'], ['0', '1'], true) ? $_GET['pagado'] : '';

// Admin limitado a la sala de su sesión
$limitadoASalaSesion = $esAdmin && $acceso_todas_salas === 0 && $salaSesion;
// Admin limitado a sus salas permitidas (sin sala de sesión)
$limitadoASalasPermitidas = $esAdmin && $acceso_todas_salas === 0 && !$salaSesion;

if ($limitadoASalaSesion) {
    $salaFiltro = isset($_GET['sala_id']) ? (int)$_GET['sala_id'] : $salaSesion;
} else {
    $salaFiltro = isset($_GET['sala_id']) ? (int)$_GET['sala_id'] : '';
}

// Obtener la lista de salas para el filtro (y para el modal de edición)
$salas = [];
if ($esAdmin) {
    if ($limitadoASalaSesion) {
        $stmtSalas = $pdo->prepare("SELECT id, nombre FROM salas WHERE id = :sala_id AND activo = 1");
        $stmtSalas->execute([':sala_id' => $salaSesion]);
    } elseif ($acceso_todas_salas === 0 && !empty($salas_permitidas)) {
        $placeholders = implode(',', array_fill(0, count($salas_permitidas), '?'));
        $stmtSalas = $pdo->prepare("SELECT id, nombre FROM salas WHERE activo = 1 AND id IN ($placeholders) ORDER BY nombre");
        $stmtSalas->execute(array_map('intval', array_values($salas_permitidas)));
    } else {
        $stmtSalas = $pdo->query("SELECT id, nombre FROM salas WHERE activo = 1 ORDER BY nombre");
    }
    $salas = $stmtSalas->fetchAll(PDO::FETCH_ASSOC);
}

// Configuración de paginación
$clientesPorPagina = 10;
$paginaActual = isset($_GET['pagina']) ? (int)$_GET['pagina'] : 1;
if ($paginaActual < 1) $paginaActual = 1;

$whereConditions = [];
$params = [];

if (!$esAdmin && $salaSesion) {
    $whereConditions[] = "c.sala_id = :sala_id";
    $params[':sala_id'] = $salaSesion;
}

if ($limitadoASalaSesion) {
    $whereConditions[] = "c.sala_id = :sala_asignada";
    $params[':sala_asignada'] = $salaSesion;
}

if ($limitadoASalasPermitidas && !empty($salas_permitidas)) {
    $placeholders = [];
    foreach (array_values($salas_permitidas) as $index => $sala_id) {
        $param_name = ":sala_permitida_" . $index;
        $placeholders[] = $param_name;
        $params[$param_name] = $sala_id;
    }
    $whereConditions[] = "c.sala_id IN (" . implode(',', $placeholders) . ")";
}

if ($esAdmin && $acceso_todas_salas === 1 && !empty($salaFiltro)) {
    $whereConditions[] = "c.sala_id = :sala_filtro";
    $params[':sala_filtro'] = $salaFiltro;
}

if ($limitadoASalasPermitidas && !empty($salaFiltro)) {
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

if ($pagadoFiltro !== '') {
    $whereConditions[] = $pagadoFiltro === '1' ? "c.pagado = 1" : "(c.pagado = 0 OR c.pagado IS NULL)";
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
           c.fecha_registro, c.usuario_closer, c.sala_id, c.usuario_id, c.pagado,
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

// Venta bruta del día (visible para todos), con las mismas restricciones de sala que los clientes
$ventaBrutaDia = 0;
$condicionesVenta = ["DATE(v.fecha) = ?"];
$paramsVenta = [date('Y-m-d')];

if (!$esAdmin && $salaSesion) {
    $condicionesVenta[] = "v.sala_id = ?";
    $paramsVenta[] = $salaSesion;
}

if ($limitadoASalaSesion) {
    $condicionesVenta[] = "v.sala_id = ?";
    $paramsVenta[] = $salaSesion;
}

if ($limitadoASalasPermitidas && !empty($salas_permitidas)) {
    $condicionesVenta[] = "v.sala_id IN (" . implode(',', array_fill(0, count($salas_permitidas), '?')) . ")";
    $paramsVenta = array_merge($paramsVenta, array_map('intval', array_values($salas_permitidas)));
}

if ($esAdmin && $acceso_todas_salas === 1 && !empty($salaFiltro)) {
    $condicionesVenta[] = "v.sala_id = ?";
    $paramsVenta[] = $salaFiltro;
}

if ($limitadoASalasPermitidas && !empty($salaFiltro)) {
    if (in_array($salaFiltro, $salas_permitidas)) {
        $condicionesVenta[] = "v.sala_id = ?";
        $paramsVenta[] = $salaFiltro;
    } else {
        $condicionesVenta[] = "1 = 0";
    }
}

try {
    $stmtVentaDia = $pdo->prepare("SELECT COALESCE(SUM(v.precio), 0) FROM ventas_facturacion v WHERE " . implode(' AND ', $condicionesVenta));
    $stmtVentaDia->execute($paramsVenta);
    $ventaBrutaDia = (float)$stmtVentaDia->fetchColumn();
} catch (PDOException $e) {
    error_log("agencia venta bruta: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Clientes</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet">
  <link href="CSS/agencia.css?v=<?= filemtime(__DIR__ . '/CSS/agencia.css') ?>" rel="stylesheet">
</head>

<body class="bg-light" 
  data-rol="<?= htmlspecialchars($rol) ?>"
  data-acceso-todas-salas="<?= $acceso_todas_salas ?>"
  data-salas-permitidas="<?= htmlspecialchars(json_encode($salas_permitidas)) ?>"
  data-usuario-id="<?= htmlspecialchars($_SESSION['usuario_id']) ?>"
  data-sala-id="<?= isset($_SESSION['sala_id']) ? htmlspecialchars($_SESSION['sala_id']) : '' ?>">
  
  <?php require __DIR__ . '/../../includes/navbar.php'; ?>

  <div class="espaciador-navbar" style="height: 70px;"></div>
  <div class="container-lg mt-5 contenedor-agentes">
    <div class="row mb-4 justify-content-end">
      <div class="col-xl-3 col-md-6">
        <div class="card shadow-sm rounded-4 border-0 h-100">
          <div class="card-body d-flex justify-content-between">
            <div>
              <h6 class="card-title text-muted mb-2">Venta Bruta Total</h6>
              <h3 class="fw-bold text-warning mb-0">$<?= number_format($ventaBrutaDia, 2) ?></h3>
              <small class="text-muted">Acumulado del día</small>
            </div>
            <div class="align-self-center">
              <i class="bi bi-currency-dollar text-warning fs-2"></i>
            </div>
          </div>
        </div>
      </div>
    </div>

    <div class="card shadow-sm rounded-4">
      <div class="card-header bg-dark text-white fw-semibold fs-5 d-flex flex-wrap gap-2 justify-content-between align-items-center">
        <span>Clientes Registrados</span>
        <span class="badge bg-light text-dark badge-search" id="contador-clientes">Total: <?= $totalClientes ?></span>
      </div>
      <div class="card-body">
        <div class="mb-3 text-end acciones-clientes">
          <a href="nuevocliente.php" class="btn btn-primary">
            <i class="bi bi-person-plus-fill"></i> Agregar Cliente
          </a>
          
          <?php if ($esAdmin): ?>
          <a href="../exportar_excel.php?<?= http_build_query($_GET) ?>" class="btn btn-success ms-2" id="exportar-excel-btn">
            <i class="bi bi-file-earmark-excel-fill"></i> Exportar a Excel
          </a>
          <?php endif; ?>
        </div>

        <?php if ($esAdmin): ?>
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
                <select class="form-select <?= $limitadoASalaSesion ? 'disabled-select' : '' ?>" 
                        id="sala_id" name="sala_id" 
                        <?= $limitadoASalaSesion ? 'disabled' : '' ?>>
                  <?php if ($limitadoASalaSesion): ?>
                    <?php 
                    $salaUsuario = '';
                    foreach ($salas as $sala) {
                        if ($sala['id'] == $salaSesion) {
                            $salaUsuario = $sala['nombre'];
                            break;
                        }
                    }
                    ?>
                    <option value="<?= htmlspecialchars($salaSesion) ?>" selected>
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
                  <?php if ($salaSesion): ?>
                    <input type="hidden" name="sala_id" value="<?= htmlspecialchars($salaSesion) ?>">
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
              
              <div class="col-md-2">
                <label for="fecha_inicio" class="form-label">Fecha Inicio</label>
                <input type="date" class="form-control" id="fecha_inicio" name="fecha_inicio"
                       value="<?= htmlspecialchars($fechaInicio) ?>">
              </div>

              <div class="col-md-2">
                <label for="fecha_fin" class="form-label">Fecha Fin</label>
                <input type="date" class="form-control" id="fecha_fin" name="fecha_fin"
                       value="<?= htmlspecialchars($fechaFin) ?>">
              </div>

              <div class="col-md-2">
                <label for="pagado" class="form-label">Pagado</label>
                <select class="form-select" id="pagado" name="pagado">
                  <option value="">Todos</option>
                  <option value="1" <?= $pagadoFiltro === '1' ? 'selected' : '' ?>>Sí</option>
                  <option value="0" <?= $pagadoFiltro === '0' ? 'selected' : '' ?>>No</option>
                </select>
              </div>

              <div class="col-md-3 d-flex align-items-end">
                <div class="btn-group w-100">
                  <button type="submit" class="btn btn-primary">
                    <i class="bi bi-search"></i> Filtrar
                  </button>
                  <?php if (!$limitadoASalaSesion): ?>
                    <a href="agencia.php" class="btn btn-outline-secondary">
                      <i class="bi bi-x-circle"></i> Limpiar
                    </a>
                  <?php endif; ?>
                </div>
              </div>
            </form>
            
            <?php if (!empty($salaFiltro) || !empty($fechaInicio) || !empty($fechaFin) || $pagadoFiltro !== ''): ?>
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
                <?php if ($pagadoFiltro !== '') echo " | Pagado: " . ($pagadoFiltro === '1' ? 'Sí' : 'No'); ?>
              </small>
            </div>
            <?php endif; ?>
            
            <?php if ($acceso_todas_salas === 0): ?>
            <div class="mt-3">
              <small class="text-info permiso-info">
                <i class="bi bi-shield-lock"></i> 
                <?php if ($salaSesion): ?>
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
          <?php if (!empty($fechaInicio) || !empty($fechaFin) || !empty($salaFiltro) || $pagadoFiltro !== ''): ?>
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
                <th>Pagado</th>
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
                    
                    $descripcionCorta = htmlspecialchars($cliente['descripcion_corta'] ?? '');

                    echo "<tr data-id='" . (int)$cliente['id'] . "'>";
                    echo "<td>" . htmlspecialchars("{$cliente['nombre']} {$cliente['apepat']} {$cliente['apemat']}") . "</td>";
                    echo "<td>" . htmlspecialchars($cliente['asunto'] ?? '') . "</td>";
                    echo "<td title='{$descripcionCorta}'>" . $descripcionCorta .
                         (mb_strlen($cliente['descripcion_corta'] ?? '') >= 100 ? '...' : '') . "</td>";
                    echo "<td class='{$linerClass}'>" . htmlspecialchars($usuarioApemat ?: 'No asignado') . "</td>";
                    echo "<td class='{$closerClass}'>" . htmlspecialchars($closerApemat ?: 'No asignado') . "</td>";
                    echo "<td class='text-center'>" . htmlspecialchars($salaNombre) . "</td>";
                    echo "<td class='text-center'>{$fechaRegistro}</td>";
                    echo "<td class='text-center'>" . ($cliente['pagado']
                        ? "<span class='badge bg-success'>Sí</span>"
                        : "<span class='badge bg-secondary'>No</span>") . "</td>";
                    echo "</tr>";
                }
              } else {
                echo "<tr><td colspan='8' class='text-center'>No hay clientes registrados" .
                     ((!empty($fechaInicio) || !empty($fechaFin) || !empty($salaFiltro) || $pagadoFiltro !== '') ? " con los filtros aplicados" : "") .
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
            <h5 class="modal-title"><?= $esAdmin ? 'Editar' : 'Ver' ?> Cliente</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
          </div>

          <div class="modal-body">
            <input type="hidden" id="editar_id" name="id">
            <input type="hidden" id="editar_sala_id" name="sala_id">

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

            <?php if ($esAdmin): ?>
            <!-- Las salas del modal son las mismas que las del filtro -->
            <div class="col-12 mt-2">
              <button type="button" id="btnSeleccionarSala" class="btn btn-sm btn-outline-info">
                <i class="bi bi-building"></i> Asignar Sala
              </button>
            </div>
            
            <div id="salaAdminSection" class="sala-admin-section col-12 mt-2">
              <label class="form-label small mb-1">Seleccionar Sala:</label>
              <select class="form-select form-select-sm" name="sala_id_admin" id="salaSelectAdmin">
                <option value="">-- Usar sala actual --</option>
                <?php foreach ($salas as $sala): ?>
                  <option value="<?= $sala['id'] ?>">
                    <?= htmlspecialchars($sala['nombre']) ?> (ID: <?= $sala['id'] ?>)
                  </option>
                <?php endforeach; ?>
              </select>
              <small class="text-muted">
                Seleccione una sala específica. 
                <?php if ($rol === 'admin' && $acceso_todas_salas === 0): ?>
                <span class="text-warning">
                  <i class="bi bi-exclamation-triangle"></i> 
                  <?php if ($salaSesion): ?>
                    Solo puedes asignar tu sala asignada.
                  <?php else: ?>
                    Solo puedes asignar salas permitidas.
                  <?php endif; ?>
                </span>
                <?php endif; ?>
              </small>
            </div>
            <?php endif; ?>

            <?php if ($esAdmin): ?>
            <div class="mb-2">
              <label class="form-label small">Asignar a Usuario:</label>
              <select id="usuarios_id" name="usuarios_id" class="form-select form-select-sm">
                <option value="">-- Selecciona un usuario --</option>
              </select>
            </div>

            <div class="mb-2">
              <label class="form-label small">¿Pagado?</label>
              <select id="editar_pagado" name="pagado" class="form-select form-select-sm">
                <option value="0">No</option>
                <option value="1">Sí</option>
              </select>
            </div>
            <?php endif; ?>

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
            
            <div id="mensajePermisos" class="sin-permisos" style="display: none;">
              <i class="bi bi-exclamation-triangle"></i> 
              <strong>Acceso limitado:</strong> Este cliente pertenece a una sala a la que no tienes acceso. Solo puedes ver la información básica del cliente.
            </div>
          </div>

          <div class="modal-footer">
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
      const totalClientesInicial = <?= (int)$totalClientes ?>;
  </script>
  <script src="JS/agencia.js?v=<?= filemtime(__DIR__ . '/JS/agencia.js') ?>"></script>
</body>
</html>