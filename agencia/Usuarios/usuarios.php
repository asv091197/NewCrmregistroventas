<?php
require_once __DIR__ . '/../../includes/db.php';
header('Access-Control-Allow-Origin: *');
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

require_once __DIR__ . '/../../session_config.php'; session_start();

// Verificación de sesión mejorada
if (
  !isset($_SESSION['usuario_id']) || empty($_SESSION['rol']) ||
  ($_SESSION['rol'] !== 'admin' && $_SESSION['rol'] !== 'superadmin')
) {
  header("Location: ../login.php");
  exit;
}

// Headers HTTP apropiados
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

// Conexión a base de datos con mejor manejo de errores

try {
  $pdo = conexionPDO();
} catch (PDOException $e) {
  error_log("Error de conexión a BD: " . $e->getMessage());
  die("Error de conexión a la base de datos. Por favor, intente más tarde.");
}

// OBTENER INFORMACIÓN DEL ADMIN LOGEADO
$admin_id = $_SESSION['usuario_id'];
try {
  $stmtAdmin = $pdo->prepare("SELECT acceso_todas_salas, salas_permitidas FROM usuarios WHERE id = ?");
  $stmtAdmin->execute([$admin_id]);
  $adminData = $stmtAdmin->fetch(PDO::FETCH_ASSOC);

  $admin_acceso_todas = $adminData['acceso_todas_salas'] ?? 0;
  $admin_salas_permitidas = $adminData['salas_permitidas'] ?? '[]';

  // Convertir salas permitidas a array
  $admin_salas_array = json_decode($admin_salas_permitidas, true) ?? [];
} catch (PDOException $e) {
  error_log("Error al obtener datos del admin: " . $e->getMessage());
  die("Error al cargar permisos del administrador.");
}

// Obtener parámetros de filtrado
$fechaInicio = isset($_GET['fecha_inicio']) ? $_GET['fecha_inicio'] : '';
$fechaFin = isset($_GET['fecha_fin']) ? $_GET['fecha_fin'] : '';
$salaFiltro = isset($_GET['sala']) ? $_GET['sala'] : '';
$mostrar_inactivos = isset($_GET['mostrar_inactivos']) ? (bool)$_GET['mostrar_inactivos'] : false;
$filtro_aplicado = isset($_GET['filtrar']) ? true : false;

// Configuración de paginación
$usuariosPorPagina = 10;
$paginaActual = isset($_GET['pagina']) ? (int)$_GET['pagina'] : 1;
if ($paginaActual < 1) $paginaActual = 1;

// Construir consulta base con filtros
$whereConditions = [];
$params = [];

// Primero determinar si mostrar activos o inactivos
if ($mostrar_inactivos) {
  // Cuando se marca el checkbox: mostrar usuarios inactivos
  $whereConditions[] = "u.activo = 0";
} else {
  // Por defecto: mostrar usuarios activos
  $whereConditions[] = "u.activo = 1";
}

// LÓGICA DE VISUALIZACIÓN SEGÚN PERMISOS DEL ADMIN
// SOLO admin con acceso_todas_salas = 1 ve TODOS los usuarios
// Otros (admin limitado) solo ven usuarios de sus salas
if ($admin_acceso_todas == 0 && !empty($admin_salas_array)) {
  // Crear condición para las salas del admin
  $salaConditions = [];

  foreach ($admin_salas_array as $index => $salaId) {
    $paramName = ":admin_sala_" . $index;
    $salaConditions[] = "JSON_CONTAINS(u.salas_permitidas, $paramName)";
    $params[$paramName] = json_encode($salaId);
  }

  // Condición: usuarios que compartan al menos una sala con el admin
  // NOTA: NO incluimos "u.acceso_todas_salas = 1" aquí porque SOLO los admin totales ven a esos usuarios
  if (!empty($salaConditions)) {
    $whereConditions[] = "(" . implode(" OR ", $salaConditions) . ")";
  } else {
    // Si el admin no tiene salas, no verá a nadie (excepto quizás a sí mismo)
    $whereConditions[] = "u.id = :self_id";
    $params[':self_id'] = $admin_id;
  }
}

// Aplicar filtros SOLO si se hizo clic en "Filtrar" (botón con name="filtrar")
if ($filtro_aplicado) {
  // Aplicar filtros de fecha solo si se hizo clic en "Filtrar"
  if (!empty($fechaInicio)) {
    $whereConditions[] = "u.fecha_registro >= :fecha_inicio";
    $params[':fecha_inicio'] = $fechaInicio . ' 00:00:00';
  }

  if (!empty($fechaFin)) {
    $whereConditions[] = "u.fecha_registro <= :fecha_fin";
    $params[':fecha_fin'] = $fechaFin . ' 23:59:59';
  }

  // Filtro para sala - SOLO si se hizo clic en "Filtrar"
  if (!empty($salaFiltro) && $salaFiltro !== 'todas') {
    // Para admin total: puede filtrar por cualquier sala
    // Para admin limitado: solo puede filtrar por sus salas
    if ($admin_acceso_todas == 1) {
      $whereConditions[] = "(u.acceso_todas_salas = 1 OR JSON_CONTAINS(u.salas_permitidas, :sala_filtro))";
      $params[':sala_filtro'] = json_encode($salaFiltro);
    } else {
      // Admin limitado solo puede filtrar por sus salas
      if (in_array($salaFiltro, $admin_salas_array)) {
        $whereConditions[] = "JSON_CONTAINS(u.salas_permitidas, :sala_filtro)";
        $params[':sala_filtro'] = json_encode($salaFiltro);
      }
    }
  } elseif ($salaFiltro === 'todas' && $admin_acceso_todas == 1) {
    // Solo admin total puede filtrar por "todas las salas"
    $whereConditions[] = "u.acceso_todas_salas = 1";
  }
}

$whereClause = '';
if (!empty($whereConditions)) {
  $whereClause = 'WHERE ' . implode(' AND ', $whereConditions);
}

// Obtener el total de usuarios con filtros
try {
  $sqlTotal = "SELECT COUNT(*) as total FROM usuarios u $whereClause";
  $stmtTotal = $pdo->prepare($sqlTotal);
  foreach ($params as $key => $value) {
    $stmtTotal->bindValue($key, $value);
  }
  $stmtTotal->execute();
  $totalUsuarios = $stmtTotal->fetch(PDO::FETCH_ASSOC)['total'];
  $totalPaginas = ceil($totalUsuarios / $usuariosPorPagina);

  // Asegurar que la página actual no exceda el total de páginas
  if ($paginaActual > $totalPaginas && $totalPaginas > 0) {
    $paginaActual = $totalPaginas;
  }

  // Calcular el offset para la consulta
  $offset = ($paginaActual - 1) * $usuariosPorPagina;

  // Consulta optimizada para obtener usuarios
  $sqlUsuarios = "
        SELECT u.id, u.nombre, u.apepat, u.apemat, u.correo, 
               u.rol, u.fecha_registro, u.acceso_todas_salas, u.salas_permitidas, u.activo,
               CONCAT(u.nombre, ' ', u.apepat, ' ', COALESCE(u.apemat, '')) as nombre_completo
        FROM usuarios u 
        $whereClause
        ORDER BY u.id DESC 
        LIMIT :limit OFFSET :offset
    ";

  $stmtUsuarios = $pdo->prepare($sqlUsuarios);
  foreach ($params as $key => $value) {
    $stmtUsuarios->bindValue($key, $value);
  }
  $stmtUsuarios->bindValue(':limit', $usuariosPorPagina, PDO::PARAM_INT);
  $stmtUsuarios->bindValue(':offset', $offset, PDO::PARAM_INT);
  $stmtUsuarios->execute();
  $usuarios = $stmtUsuarios->fetchAll(PDO::FETCH_ASSOC);

  // Obtener lista de salas para mostrar nombres
  $stmtSalas = $pdo->query("SELECT id, nombre FROM salas WHERE activo = 1");
  $salasMap = [];
  foreach ($stmtSalas as $sala) {
    $salasMap[$sala['id']] = $sala['nombre'];
  }

  // Obtener salas para el filtro según permisos del admin
  if ($admin_acceso_todas == 1) {
    // Admin con acceso a todas las salas: mostrar todas las salas activas
    $stmtSalasFiltro = $pdo->query("SELECT id, nombre FROM salas WHERE activo = 1 ORDER BY nombre");
  } else {
    // Admin con acceso limitado: mostrar solo sus salas permitidas
    if (!empty($admin_salas_array)) {
      $placeholders = implode(',', array_fill(0, count($admin_salas_array), '?'));
      $stmtSalasFiltro = $pdo->prepare("SELECT id, nombre FROM salas WHERE id IN ($placeholders) AND activo = 1 ORDER BY nombre");
      $stmtSalasFiltro->execute($admin_salas_array);
    } else {
      // Si no tiene salas permitidas, array vacío
      $stmtSalasFiltro = $pdo->query("SELECT id, nombre FROM salas WHERE 1=0");
    }
  }
  $salasFiltro = $stmtSalasFiltro->fetchAll(PDO::FETCH_ASSOC);

  // Liberar memoria
  unset($stmtTotal, $stmtUsuarios);
} catch (PDOException $e) {
  error_log("Error en consulta: " . $e->getMessage());
  $usuarios = [];
  $totalUsuarios = 0;
  $totalPaginas = 1;
  $salasMap = [];
  $salasFiltro = [];
}

// Determinar si hay filtros activos (solo si se hizo clic en "Filtrar")
$filtros_activos = ($filtro_aplicado && (!empty($fechaInicio) || !empty($fechaFin) || (!empty($salaFiltro) && $salaFiltro !== 'todas')));
?>
<!DOCTYPE html>
<html lang="es">

<head>
  <meta charset="UTF-8">
  <title>Usuarios</title>
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

    .rol-badge {
      font-size: 0.75rem;
      padding: 0.35em 0.65em;
    }

    .btn-cambiar-estado {
      transition: all 0.2s ease;
    }

    .btn-cambiar-estado:hover {
      transform: scale(1.05);
    }

    .toast-container {
      position: fixed;
      top: 20px;
      right: 20px;
      z-index: 9999;
    }

    .password-toggle {
      cursor: pointer;
      position: absolute;
      right: 10px;
      top: 50%;
      transform: translateY(-50%);
    }

    .salas-badge {
      font-size: 0.7rem;
      max-width: 200px;
      white-space: nowrap;
      overflow: hidden;
      text-overflow: ellipsis;
    }

    .salas-container {
      border: 1px solid #dee2e6;
      border-radius: 0.375rem;
      padding: 1rem;
      background-color: #f8f9fa;
    }

    .salas-list {
      max-height: 150px;
      overflow-y: auto;
    }

    .sala-nombre {
      font-size: 1.10rem;
      color: #6c757d;
      margin-top: 2px;
    }

    /* Estilos para el switch */
    .form-switch .form-check-input {
      height: 1.5em;
      width: 3em;
      margin-right: 10px;
    }

    .form-switch .form-check-input:checked {
      background-color: #0d6efd;
      border-color: #0d6efd;
    }

    .form-switch .form-check-label {
      cursor: pointer;
    }

    .inactive-badge {
      background-color: #ffc107 !important;
      color: #000 !important;
    }

    .inactive-row {
      background-color: #fff8e1 !important;
    }

    /* Mejorar la visualización de múltiples salas */
    .multi-salas-container {
      display: flex;
      flex-wrap: wrap;
      gap: 3px;
      justify-content: center;
    }

    .sala-mini-badge {
      font-size: 0.65rem;
      padding: 0.25em 0.4em;
      background-color: #6c757d;
      color: white;
      border-radius: 3px;
    }

    .filter-info {
      font-size: 0.9rem;
      color: #6c757d;
    }

    .filtro-inactivo {
      color: #dc3545;
      font-weight: bold;
    }

    .btn-filter-inactive {
      background-color: #ffc107;
      border-color: #ffc107;
      color: #000;
    }

    .btn-filter-inactive:hover {
      background-color: #e0a800;
      border-color: #d39e00;
      color: #000;
    }

    .filter-indicator {
      font-size: 0.8rem;
      padding: 0.2em 0.6em;
      margin-left: 5px;
    }

    .admin-type-badge {
      font-size: 0.6rem;
      vertical-align: middle;
    }

    .no-permission-alert {
      background-color: #f8d7da;
      border-color: #f5c6cb;
      color: #721c24;
    }
  </style>
</head>

<body class="bg-light" data-rol="<?= htmlspecialchars($_SESSION['rol']) ?>" data-admin-acceso="<?= $admin_acceso_todas ?>">
  <nav class="navbar navbar-expand-lg navbar-light bg-white shadow-sm fixed-top border-bottom">
    <div class="container-fluid">
      <div class="d-flex align-items-center ms-auto">
        <ul class="navbar-nav me-3">
          <?php if ($_SESSION['rol'] === 'admin' || $_SESSION['rol'] === 'superadmin'): ?>
            <li class="nav-item">
              <a class="nav-link text-dark" href="../opcionesAdmin.php" style="text-decoration: none;">
                Dashboard
              </a>
            </li>
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
  <div class="container-lg mt-5">
    <div class="card shadow-sm rounded-4">
      <div class="card-header bg-dark text-white fw-semibold fs-5 d-flex justify-content-between align-items-center">
        <span>
          Usuarios Registrados
          <?php if ($admin_acceso_todas == 1): ?>
            <span class="badge bg-success admin-type-badge">Vista Total</span>
          <?php else: ?>
            <span class="badge bg-warning admin-type-badge">Vista por Salas</span>
          <?php endif; ?>
        </span>
        <span class="badge bg-light text-dark badge-search" id="contador-usuarios">
          <?php if ($mostrar_inactivos): ?>
            Inactivos: <?= $totalUsuarios ?>
          <?php else: ?>
            Activos: <?= $totalUsuarios ?>
          <?php endif; ?>
        </span>
      </div>
      <div class="card-body">
        <div class="mb-3 text-end">
          <a href="nuevousuario.php" class="btn btn-primary">
            <i class="bi bi-person-plus-fill"></i> Agregar Usuario
          </a>
        </div>

        <!-- Filtros -->
        <div class="filter-section mb-4">
          <div class="filter-header" onclick="toggleFilters()">
            <i class="bi bi-funnel"></i> Filtros
            <i class="bi bi-chevron-down float-end"></i>
          </div>
          <div class="filter-content" id="filterContent">
            <form method="GET" action="" class="row g-3">
              <input type="hidden" name="pagina" value="1">
              <input type="hidden" name="filtrar" value="1">

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

              <div class="col-md-4">
                <label for="sala" class="form-label">Filtrar por Sala</label>
                <select class="form-select" id="sala" name="sala">
                  <?php if ($admin_acceso_todas == 1): ?>
                    <option value="todas" <?= $salaFiltro === 'todas' || empty($salaFiltro) ? 'selected' : '' ?>>Todas las salas</option>
                  <?php endif; ?>
                  <?php
                  foreach ($salasFiltro as $sala) {
                    $selected = $salaFiltro == $sala['id'] ? 'selected' : '';
                    echo "<option value='{$sala['id']}' $selected>{$sala['nombre']}</option>";
                  }
                  ?>
                </select>
                <?php if ($admin_acceso_todas == 0): ?>
                  <div class="form-text">
                    <i class="bi bi-info-circle"></i> Solo puedes filtrar por tus salas permitidas
                  </div>
                <?php endif; ?>
              </div>

              <!-- Switch para mostrar inactivos -->
              <div class="col-md-12">
                <div class="form-check form-switch">
                  <input class="form-check-input" type="checkbox" id="mostrar_inactivos" name="mostrar_inactivos"
                    value="1" <?= $mostrar_inactivos ? 'checked' : '' ?>
                    onchange="toggleInactivos(this)">
                  <label class="form-check-label" for="mostrar_inactivos">
                    <strong><?= $mostrar_inactivos ? 'Mostrando SOLO usuarios inactivos' : 'Mostrar usuarios inactivos' ?></strong>
                  </label>
                  <div class="form-text">
                    <i class="bi bi-info-circle"></i> Al marcar esta opción, verás todos los usuarios inactivos. Luego puedes aplicar filtros específicos haciendo clic en "Filtrar".
                  </div>
                </div>
              </div>

              <div class="col-12 d-flex align-items-end">
                <div class="btn-group w-100">
                  <button type="submit" class="btn <?= $mostrar_inactivos ? 'btn-filter-inactive' : 'btn-primary' ?>">
                    <i class="bi bi-funnel-fill"></i> Filtrar
                    <?php if ($filtro_aplicado): ?>
                      <span class="badge bg-success filter-indicator">Aplicado</span>
                    <?php endif; ?>
                  </button>
                  <a href="usuarios.php<?= $mostrar_inactivos ? '?mostrar_inactivos=1' : '' ?>" class="btn btn-outline-secondary">
                    <i class="bi bi-x-circle"></i> Limpiar
                  </a>
                </div>
              </div>
            </form>

            <?php if ($filtros_activos): ?>
              <div class="mt-3 p-2 bg-light border rounded">
                <small class="text-muted d-flex align-items-center">
                  <i class="bi bi-funnel-fill me-2"></i>
                  <span>
                    <strong>Filtros aplicados:</strong>
                    <?php if (!empty($fechaInicio)): ?>
                      <span class="badge bg-info text-dark ms-1">Desde: <?= htmlspecialchars($fechaInicio) ?></span>
                    <?php endif; ?>

                    <?php if (!empty($fechaFin)): ?>
                      <span class="badge bg-info text-dark ms-1">Hasta: <?= htmlspecialchars($fechaFin) ?></span>
                    <?php endif; ?>

                    <?php if (!empty($salaFiltro) && $salaFiltro !== 'todas'): ?>
                      <?php
                      $nombreSala = 'Sala no encontrada';
                      foreach ($salasFiltro as $sala) {
                        if ($sala['id'] == $salaFiltro) {
                          $nombreSala = $sala['nombre'];
                          break;
                        }
                      }
                      ?>
                      <span class="badge bg-info text-dark ms-1">Sala: <?= htmlspecialchars($nombreSala) ?></span>
                    <?php elseif ($salaFiltro === 'todas'): ?>
                      <span class="badge bg-info text-dark ms-1">Sala: Todas las salas</span>
                    <?php endif; ?>
                  </span>
                </small>
              </div>
            <?php endif; ?>

            <?php if ($admin_acceso_todas == 0): ?>
              <div class="mt-3 p-2 bg-warning border rounded">
                <small class="text-muted d-flex align-items-center">
                  <i class="bi bi-exclamation-triangle me-2 text-warning"></i>
                  <span class="text-dark">
                    <strong>Vista Limitada:</strong> Solo puedes ver usuarios que tengan acceso a tus mismas salas.
                    Los usuarios con acceso a todas las salas no son visibles para ti.
                  </span>
                </small>
              </div>
            <?php endif; ?>
          </div>
        </div>

        <div class="search-container mb-4">
          <input class="form-control me-2" type="text" id="buscar" placeholder="Buscar usuario..." aria-label="Buscar">
          <span class="search-clear" id="clear-search">
            <i class="bi bi-x-circle"></i>
          </span>
        </div>

        <div class="table-responsive">
          <table class="table table-bordered table-hover align-middle">
            <thead class="table-dark text-center">
              <tr>
                <th>Nombre Agente</th>
                <th>Nombre Asesor</th>
                <th>Correo</th>
                <th>Rol</th>
                <th>Salas</th>
                <th>Estado</th>
                <th>Fecha Registro</th>
                <th>Acciones</th>
              </tr>
            </thead>
            <tbody id="tabla-usuarios">
              <?php
              if (count($usuarios) > 0) {
                foreach ($usuarios as $usuario) {
                  $fechaRegistro = date('d/m/Y H:i', strtotime($usuario['fecha_registro']));
                  $badgeClass = $usuario['rol'] === 'admin' ? 'bg-danger' : 'bg-primary';

                  // Clase para estado inactivo
                  $estadoClass = $usuario['activo'] == 1 ? '' : 'inactive-row';

                  echo "<tr data-id='{$usuario['id']}' class='{$estadoClass}'>";
                  echo "<td style='cursor: pointer;'> {$usuario['apemat']}</td>";
                  echo "<td style='cursor: pointer;'>{$usuario['nombre']} {$usuario['apepat']} </td>";
                  echo "<td style='cursor: pointer;'>{$usuario['correo']}</td>";
                  echo "<td class='text-center' style='cursor: pointer;'><span class='badge {$badgeClass} rol-badge'>{$usuario['rol']}</span></td>";

                  // Columna de salas - MEJORADA
                  echo "<td class='text-center' style='cursor: pointer;'>";
                  if ($usuario['acceso_todas_salas'] == 1) {
                    echo "<span class='badge bg-success salas-badge' title='Todas las salas'>Todas las salas</span>";
                    // Si es admin limitado y ve un usuario con todas las salas, mostrar advertencia
                    if ($admin_acceso_todas == 0) {
                      echo "<br><small class='text-danger'><i class='bi bi-exclamation-triangle'></i> No puedes editar este usuario</small>";
                    }
                  } elseif (!empty($usuario['salas_permitidas'])) {
                    $salasIds = json_decode($usuario['salas_permitidas'], true);
                    $nombresSalas = [];

                    if (is_array($salasIds)) {
                      foreach ($salasIds as $salaId) {
                        if (isset($salasMap[$salaId])) {
                          $nombresSalas[] = $salasMap[$salaId];
                        }
                      }

                      if (count($nombresSalas) > 0) {
                        if (count($nombresSalas) > 2) {
                          echo "<div class='multi-salas-container'>";
                          for ($i = 0; $i < min(2, count($nombresSalas)); $i++) {
                            echo "<span class='sala-mini-badge' title='{$nombresSalas[$i]}'>" . substr($nombresSalas[$i], 0, 12) . "</span>";
                          }
                          echo "<span class='sala-mini-badge'>+ " . (count($nombresSalas) - 2) . " más</span>";
                          echo "</div>";
                        } else {
                          echo "<div class='multi-salas-container'>";
                          foreach ($nombresSalas as $nombreSala) {
                            echo "<span class='sala-mini-badge' title='{$nombreSala}'>" . substr($nombreSala, 0, 15) . "</span>";
                          }
                          echo "</div>";
                        }
                      } else {
                        echo "<span class='badge bg-secondary salas-badge'>Sin salas</span>";
                      }
                    } else {
                      echo "<span class='badge bg-secondary salas-badge'>Formato inválido</span>";
                    }
                  } else {
                    echo "<span class='badge bg-secondary salas-badge'>Sin salas</span>";
                  }
                  echo "</td>";

                  // COLUMNA: Estado
                  $estadoBadge = $usuario['activo'] == 1 ?
                    '<span class="badge bg-success">Activo</span>' :
                    '<span class="badge bg-warning text-dark inactive-badge">Inactivo</span>';
                  echo "<td class='text-center' style='cursor: pointer;'>{$estadoBadge}</td>";

                  echo "<td class='text-center' style='cursor: pointer;'>{$fechaRegistro}</td>";

                  // COLUMNA ACCIONES
                  echo "<td class='text-center'>";
                  // Verificar si el admin puede editar este usuario
                  $puedeEditar = true;
                  if ($admin_acceso_todas == 0 && $usuario['acceso_todas_salas'] == 1) {
                    // Admin limitado NO puede editar usuarios con acceso a todas las salas
                    $puedeEditar = false;
                    echo "<span class='badge bg-secondary'>Sin permiso</span>";
                  } else {
                    if ($usuario['activo'] == 1) {
                      echo "<button class='btn btn-sm btn-warning btn-cambiar-estado' data-id='{$usuario['id']}' data-nombre='{$usuario['nombre']} {$usuario['apepat']} {$usuario['apemat']}' data-accion='desactivar'>";
                      echo "<i class='bi bi-pause-circle'></i> Desactivar";
                      echo "</button>";
                    } else {
                      echo "<button class='btn btn-sm btn-success btn-cambiar-estado' data-id='{$usuario['id']}' data-nombre='{$usuario['nombre']} {$usuario['apepat']} {$usuario['apemat']}' data-accion='activar'>";
                      echo "<i class=\"bi bi-play-circle\"></i> Activar";
                      echo "</button>";
                    }
                  }
                  echo "</td>";
                  echo "</tr>";
                }
              } else {
                // Mensaje personalizado según el filtro
                $mensajeNoResultados = "";
                if ($mostrar_inactivos) {
                  $mensajeNoResultados = "No hay usuarios inactivos";
                  if ($filtros_activos) {
                    $mensajeNoResultados .= " que coincidan con los filtros aplicados";
                  }
                } else {
                  $mensajeNoResultados = "No hay usuarios activos";
                  if ($filtros_activos) {
                    $mensajeNoResultados .= " que coincidan con los filtros aplicados";
                  }
                }

                if (!empty($fechaInicio) || !empty($fechaFin)) {
                  $mensajeNoResultados .= " en el rango de fechas seleccionado";
                }

                if ($admin_acceso_todas == 0) {
                  $mensajeNoResultados .= " en tus salas permitidas";
                }

                echo "<tr><td colspan='8' class='text-center py-4'>
                        <div class='alert alert-light border'>
                          <i class='bi bi-search me-2'></i>
                          {$mensajeNoResultados}
                        </div>
                      </td></tr>";
              }
              ?>
            </tbody>
          </table>
        </div>

        <!-- Paginación -->
        <?php if ($totalPaginas > 1): ?>
          <nav aria-label="Paginación de usuarios">
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

  <!-- Modal de edición -->
  <div class="modal fade" id="modalEditar" tabindex="-1" aria-labelledby="modalEditarLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
      <div class="modal-content">
        <form id="formEditar">
          <div class="modal-header">
            <h5 class="modal-title" id="modalEditarLabel">Editar Usuario</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
          </div>
          <div class="modal-body">
            <input type="hidden" id="editar_id" name="id">
            <div class="row g-3">
              <div class="col-md-6">
                <label for="editar_apemat" class="form-label">Nombre Agente<span class="text-danger">*</span></label>
                <input type="text" class="form-control" id="editar_apemat" name="apemat" maxlength="50" required>
              </div>
              <div class="col-md-6">
                <label for="editar_nombre" class="form-label">Nombre Asesor <span class="text-danger">*</span></label>
                <input type="text" class="form-control" id="editar_nombre" name="nombre" maxlength="30" required>
              </div>
              <div class="col-md-6">
                <label for="editar_apepat" class="form-label">Apellido Asesor</label>
                <input type="text" class="form-control" id="editar_apepat" name="apepat" maxlength="50">
                <div class="form-text">Opcional</div>
              </div>
              <div class="col-md-6">
                <label for="editar_correo" class="form-label">Correo Electrónico <span class="text-danger">*</span></label>
                <input type="email" class="form-control" id="editar_correo" name="correo" maxlength="100" required>
              </div>
              <div class="col-md-6">
                <label for="editar_rol" class="form-label">Rol de usuario <span class="text-danger">*</span></label>
                <select class="form-select" id="editar_rol" name="rol" required>
                  <option value="admin">Administrador</option>
                  <option value="usuario">Usuario</option>
                </select>
              </div>
              <div class="col-md-6">
                <label for="editar_password" class="form-label">Nueva contraseña</label>
                <div class="position-relative">
                  <input type="password" class="form-control" id="editar_password" name="password" maxlength="100" placeholder="Dejar en blanco para mantener la actual">
                  <span class="password-toggle" onclick="togglePasswordVisibility('editar_password')">
                    <i class="bi bi-eye"></i>
                  </span>
                </div>
                <div class="form-text">Opcional - Mínimo 6 caracteres si se ingresa</div>
              </div>

              <!-- Sección de salas MODIFICADA según permisos -->
              <div class="col-12">
                <label class="form-label">Acceso a salas <span class="text-danger">*</span></label>
                <div class="salas-container">
                  <?php if ($admin_acceso_todas == 1): ?>
                    <div class="form-check mb-3">
                      <input class="form-check-input" type="radio" name="acceso_salas" id="editar_todas_salas" value="todas">
                      <label class="form-check-label fw-bold" for="editar_todas_salas">
                        <i class="bi bi-building-check"></i> Todas las salas
                      </label>
                      <div class="form-text">El usuario tendrá acceso a todas las salas existentes y futuras</div>
                    </div>

                    <div class="form-check mb-3">
                      <input class="form-check-input" type="radio" name="acceso_salas" id="editar_salas_especificas" value="especificas">
                      <label class="form-check-label fw-bold" for="editar_salas_especificas">
                        <i class="bi bi-building"></i> Salas específicas
                      </label>
                    </div>
                  <?php else: ?>
                    <div class="alert alert-info">
                      <i class="bi bi-info-circle"></i> Como administrador limitado, solo puedes asignar acceso a tus salas permitidas.
                    </div>
                    <input type="hidden" name="acceso_salas" value="especificas">
                  <?php endif; ?>

                  <div id="editar_selector_salas" style="<?= $admin_acceso_todas == 1 ? 'display: none;' : '' ?> margin-top: 15px;">
                    <div class="salas-list">
                      <?php
                      // Mostrar solo las salas permitidas según el tipo de admin
                      if ($admin_acceso_todas == 1) {
                        $stmtSalas = $pdo->query("SELECT id, nombre FROM salas WHERE activo = 1 ORDER BY nombre");
                      } else {
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
                          echo "<div class='form-check'>";
                          echo "<input class='form-check-input' type='radio' name='salas_seleccionadas' value='{$sala['id']}' id='editar_sala_{$sala['id']}'>";
                          echo "<label class='form-check-label' for='editar_sala_{$sala['id']}'>{$sala['nombre']}</label>";
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
            </div>
          </div>
          <div class="modal-footer justify-content-end">
            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
            <button type="submit" class="btn btn-primary" id="btnGuardar">Guardar</button>
          </div>
        </form>
      </div>
    </div>
  </div>

  <!-- Modal de confirmación para activar/desactivar -->
  <div class="modal fade" id="modalConfirmarEstado" tabindex="-1" aria-labelledby="modalConfirmarEstadoLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title" id="modalConfirmarEstadoLabel">Confirmar Cambio de Estado</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
        </div>
        <div class="modal-body">
          <p id="mensaje-cambio-estado">¿Está seguro de que desea <span id="accion-texto">activar/desactivar</span> al usuario <strong id="nombre-usuario-estado"></strong>?</p>
          <p class="text-warning"><strong>Nota:</strong> Los usuarios inactivos no podrán iniciar sesión en el sistema.</p>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
          <button type="button" class="btn" id="btn-confirmar-estado">
            <i class="bi bi-check-circle"></i> Confirmar
          </button>
        </div>
      </div>
    </div>
  </div>

  <!-- Toast para notificaciones -->
  <div class="toast-container">
    <div id="liveToast" class="toast" role="alert" aria-live="assertive" aria-atomic="true">
      <div class="toast-header">
        <strong class="me-auto" id="toast-title">Notificación</strong>
        <button type="button" class="btn-close" data-bs-dismiss="toast" aria-label="Close"></button>
      </div>
      <div class="toast-body" id="toast-message">
        Mensaje de notificación
      </div>
    </div>
  </div>

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
  <script>
    // usuarios.js - Gestión de usuarios con paginación y filtros
    const rolUsuario = document.body.getAttribute('data-rol');
    const adminAcceso = parseInt(document.body.getAttribute('data-admin-acceso'));
    let paginaActual = <?= $paginaActual ?>;
    const totalPaginas = <?= $totalPaginas ?>;
    const totalUsuariosInicial = <?= $totalUsuarios ?>;
    const mostrarInactivos = <?= $mostrar_inactivos ? 'true' : 'false' ?>;

    // Variables para almacenar los valores originales
    let valoresOriginales = {};
    let estadoOriginalSalas = {
      acceso_todas: false,
      salas_seleccionadas: []
    };

    // Función para mostrar notificaciones toast
    function showToast(title, message, type = 'info') {
      const toastEl = document.getElementById('liveToast');
      const toastTitle = document.getElementById('toast-title');
      const toastMessage = document.getElementById('toast-message');

      // Configurar colores según el tipo
      toastEl.classList.remove('bg-success', 'bg-danger', 'bg-info', 'bg-warning');
      switch (type) {
        case 'success':
          toastEl.classList.add('bg-success', 'text-white');
          break;
        case 'error':
          toastEl.classList.add('bg-danger', 'text-white');
          break;
        case 'warning':
          toastEl.classList.add('bg-warning', 'text-dark');
          break;
        default:
          toastEl.classList.add('bg-info', 'text-white');
      }

      toastTitle.textContent = title;
      toastMessage.textContent = message;

      const toast = new bootstrap.Toast(toastEl);
      toast.show();
    }

    // Función para mostrar/ocultar contraseña
    function togglePasswordVisibility(inputId) {
      const passwordInput = document.getElementById(inputId);
      const toggleIcon = passwordInput.nextElementSibling.querySelector('i');

      if (passwordInput.type === 'password') {
        passwordInput.type = 'text';
        toggleIcon.classList.remove('bi-eye');
        toggleIcon.classList.add('bi-eye-slash');
      } else {
        passwordInput.type = 'password';
        toggleIcon.classList.remove('bi-eye-slash');
        toggleIcon.classList.add('bi-eye');
      }
    }

    // Función para mostrar/ocultar filtros
    function toggleFilters() {
      const filterContent = document.getElementById('filterContent');
      filterContent.classList.toggle('show');
    }

    // Función segura para obtener elementos del DOM
    function getElement(id) {
      const element = document.getElementById(id);
      if (!element) {
        console.warn(`Elemento con ID ${id} no encontrado`);
      }
      return element;
    }

    // Función para verificar si un elemento existe
    function elementExists(id) {
      return document.getElementById(id) !== null;
    }

    // Función para volver a usuarios.php manteniendo el filtro de inactivos
    function volverAUsuarios() {
      let url = 'usuarios.php';
      if (mostrarInactivos) {
        url += '?mostrar_inactivos=1';
      }
      window.location.href = url;
    }

    // Función para alternar entre mostrar activos/inactivos SIN enviar filtros
    function toggleInactivos(checkbox) {
      const form = checkbox.closest('form');
      const formData = new FormData(form);

      // Crear nueva URL solo con el parámetro mostrar_inactivos
      const params = new URLSearchParams();
      params.append('mostrar_inactivos', checkbox.checked ? '1' : '0');

      // No incluir otros parámetros de filtro
      window.location.href = 'usuarios.php?' + params.toString();
    }

    // Configurar el botón de limpiar búsqueda
    function configurarBotonLimpiar() {
      const buscarInput = getElement('buscar');
      const clearButton = getElement('clear-search');

      if (buscarInput && clearButton) {
        // Mostrar/ocultar botón de limpiar según si hay texto
        buscarInput.addEventListener('input', function() {
          clearButton.style.display = this.value ? 'block' : 'none';
        });

        // Limpiar búsqueda y volver a usuarios al hacer clic en la X
        clearButton.addEventListener('click', function() {
          buscarInput.value = '';
          clearButton.style.display = 'none';
          actualizarContadorUsuarios(totalUsuariosInicial, 'Total');
          volverAUsuarios();
        });

        // También volver a usuarios cuando se borra todo el texto manualmente
        buscarInput.addEventListener('keyup', function(e) {
          if (this.value === '' && e.key !== 'Tab' && e.key !== 'Shift') {
            actualizarContadorUsuarios(totalUsuariosInicial, 'Total');
            volverAUsuarios();
          }
        });
      }
    }

    // Función para actualizar el contador de usuarios
    function actualizarContadorUsuarios(cantidad, texto = 'Resultados') {
      const contador = getElement('contador-usuarios');
      if (contador) {
        if (mostrarInactivos) {
          contador.textContent = `Inactivos: ${cantidad}`;
          contador.classList.remove('bg-light', 'text-dark');
          contador.classList.add('bg-warning', 'text-dark');
        } else {
          contador.textContent = `Activos: ${cantidad}`;
          contador.classList.remove('bg-warning', 'text-dark');
          contador.classList.add('bg-light', 'text-dark');
        }

        // Cambiar color temporalmente para indicar actualización
        if (texto === 'Resultados') {
          contador.classList.add('bg-info', 'text-white');

          setTimeout(() => {
            contador.classList.remove('bg-info', 'text-white');
            if (mostrarInactivos) {
              contador.classList.add('bg-warning', 'text-dark');
            } else {
              contador.classList.add('bg-light', 'text-dark');
            }
          }, 1000);
        }
      }
    }

    function agregarEventosFila() {
      document.querySelectorAll('#tabla-usuarios tr[data-id]').forEach(fila => {
        // Verificar si el usuario tiene acceso a todas las salas y el admin es limitado
        const salasCell = fila.querySelector('td:nth-child(5)');
        const tieneTodasSalas = salasCell && salasCell.textContent.includes('Todas las salas');

        // Evento para hacer clic en la fila (excepto en botones)
        fila.querySelectorAll('td:not(:last-child)').forEach(td => {
          td.style.cursor = 'pointer';
          td.addEventListener('click', function(e) {
            if (e.target.closest('button')) return;

            // Si el admin es limitado y el usuario tiene todas las salas, no permitir edición
            if (adminAcceso === 0 && tieneTodasSalas) {
              showToast('Permiso denegado', 'No tienes permiso para editar usuarios con acceso a todas las salas', 'warning');
              return;
            }

            const id = fila.getAttribute('data-id');
            if (id) abrirModalEditar(id);
          });
        });

        // Evento para botones de cambiar estado
        const btnEstado = fila.querySelector('.btn-cambiar-estado');
        if (btnEstado) {
          btnEstado.addEventListener('click', function(e) {
            e.stopPropagation(); // Prevenir que se active el evento de la fila

            // Si el admin es limitado y el usuario tiene todas las salas, no permitir cambio de estado
            if (adminAcceso === 0 && tieneTodasSalas) {
              showToast('Permiso denegado', 'No tienes permiso para cambiar el estado de usuarios con acceso a todas las salas', 'warning');
              return;
            }

            const id = this.getAttribute('data-id');
            const nombre = this.getAttribute('data-nombre');
            const accion = this.getAttribute('data-accion');
            confirmarCambioEstado(id, nombre, accion);
          });
        }
      });
    }

    // Configurar evento de búsqueda
    function configurarBusqueda() {
      const buscarInput = getElement('buscar');
      if (buscarInput) {
        buscarInput.addEventListener('input', function() {
          const query = this.value.trim();
          if (query.length >= 1) {
            // Mostrar indicador de carga
            const mensaje = getElement('mensaje');
            if (mensaje) mensaje.innerHTML = '<div class="spinner-border spinner-border-sm" role="status"></div> Buscando...';

            // Agregar filtros de fecha y sala a la búsqueda si existen
            const params = new URLSearchParams();
            params.append('buscar', query);
            <?php if (!empty($fechaInicio) && $filtro_aplicado): ?>params.append('fecha_inicio', '<?= $fechaInicio ?>');
          <?php endif; ?>
          <?php if (!empty($fechaFin) && $filtro_aplicado): ?>params.append('fecha_fin', '<?= $fechaFin ?>');
          <?php endif; ?>
          <?php if (!empty($salaFiltro) && $filtro_aplicado): ?>params.append('sala', '<?= $salaFiltro ?>');
          <?php endif; ?>
          <?php if ($mostrar_inactivos): ?>params.append('mostrar_inactivos', '1');
          <?php endif; ?>

          fetch(`buscar_usuario.php?${params.toString()}`)
            .then(response => response.text())
            .then(data => {
              const tablaUsuarios = getElement('tabla-usuarios');
              const mensaje = getElement('mensaje');
              const paginacion = document.querySelector('.pagination');

              if (tablaUsuarios) tablaUsuarios.innerHTML = data;

              // Extraer el contador de resultados
              const contadorDiv = tablaUsuarios.querySelector('#resultado-contador');
              if (contadorDiv) {
                const totalResultados = contadorDiv.getAttribute('data-total');
                actualizarContadorUsuarios(totalResultados, 'Resultados');
                // Eliminar el div contador para no afectar la visualización
                contadorDiv.remove();
              }

              if (mensaje) {
                if (data.includes('No se encontraron')) {
                  mensaje.textContent = 'No se encontraron resultados.';
                } else if (data.includes('Ingrese un término')) {
                  mensaje.textContent = 'Ingrese un término de búsqueda o use los filtros';
                } else {
                  mensaje.textContent = '';
                }
              }

              if (paginacion) paginacion.style.display = 'none';
              agregarEventosFila();
            })
            .catch(error => {
              console.error('Error en la búsqueda:', error);
              const mensaje = getElement('mensaje');
              if (mensaje) mensaje.textContent = 'Error al realizar la búsqueda';
            });
          } else {
            // Si la búsqueda está vacía, volver a usuarios.php
            actualizarContadorUsuarios(totalUsuariosInicial, 'Total');
            volverAUsuarios();
          }
        });
      }
    }

    // Configurar eventos para salas en el modal de edición
    function configurarSalasModal() {
      const todasSalas = getElement('editar_todas_salas');
      const salasEspecificas = getElement('editar_salas_especificas');
      const selectorSalas = getElement('editar_selector_salas');

      // Solo configurar si el admin tiene acceso total
      if (adminAcceso === 1 && todasSalas && salasEspecificas) {
        const radioSalas = document.querySelectorAll('#editar_selector_salas input[type="radio"]');

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
          validarCamposEditar();
        }

        // Event listeners
        todasSalas.addEventListener('change', toggleSalasSelector);
        salasEspecificas.addEventListener('change', toggleSalasSelector);

        // Validar cuando cambien los radio buttons
        radioSalas.forEach(radio => {
          radio.addEventListener('change', validarCamposEditar);
        });
      }
    }

    // FUNCIÓN PRINCIPAL DE VALIDACIÓN - MODIFICADA PARA INCLUIR CONTRASEÑA
    function validarCamposEditar() {
      // Solo si el modal existe
      if (!elementExists('formEditar')) return;

      // Campos obligatorios (deben tener al menos un carácter)
      const camposObligatorios = [
        'editar_nombre',
        'editar_apemat',
        'editar_correo',
        'editar_rol'
      ];

      // Verificar que todos los campos obligatorios tengan al menos un carácter
      const todosLlenos = camposObligatorios.every(id => {
        const campo = getElement(id);
        const valor = campo ? campo.value.trim() : '';
        return valor.length > 0;
      });

      // Validar contraseña (opcional pero debe tener 6+ caracteres si se ingresa)
      const passwordInput = getElement('editar_password');
      let passwordValida = true;

      if (passwordInput && passwordInput.value.length > 0) {
        passwordValida = passwordInput.value.length >= 6;
        // Opcional: mostrar feedback visual
        if (!passwordValida) {
          passwordInput.classList.add('is-invalid');
        } else {
          passwordInput.classList.remove('is-invalid');
        }
      } else {
        // Si está vacío, es válido (opcional)
        if (passwordInput) {
          passwordInput.classList.remove('is-invalid');
        }
      }

      // Verificar salas según tipo de admin
      let salasValidas = false;
      let estadoActualSalas = {
        acceso_todas: false,
        salas_seleccionadas: []
      };

      if (adminAcceso === 1) {
        // Admin total: verificar radio buttons
        const todasSalas = getElement('editar_todas_salas');
        const salasEspecificas = getElement('editar_salas_especificas');

        if (todasSalas && salasEspecificas) {
          if (todasSalas.checked) {
            salasValidas = true;
            estadoActualSalas.acceso_todas = true;
          } else if (salasEspecificas.checked) {
            const radioSeleccionado = document.querySelector('#editar_selector_salas input[type="radio"]:checked');
            salasValidas = radioSeleccionado !== null;
            estadoActualSalas.acceso_todas = false;
            estadoActualSalas.salas_seleccionadas = radioSeleccionado ? [radioSeleccionado.value] : [];
          }
        }
      } else {
        // Admin limitado: siempre salas específicas, verificar radio button seleccionado
        const radioSeleccionado = document.querySelector('#editar_selector_salas input[type="radio"]:checked');
        salasValidas = radioSeleccionado !== null;
        estadoActualSalas.acceso_todas = false;
        estadoActualSalas.salas_seleccionadas = radioSeleccionado ? [radioSeleccionado.value] : [];
      }

      // Verificar si hay cambios en los campos (comparar con valores originales)
      // Excluimos 'editar_password' de esta validación porque es un caso especial
      const camposParaValidarCambios = Object.keys(valoresOriginales).filter(key => key !== 'editar_password');

      const hayCambiosEnCampos = camposParaValidarCambios.some(key => {
        const campo = getElement(key);
        return campo && campo.value !== valoresOriginales[key];
      });

      // Verificar si hay cambios en la contraseña (si se ingresó algo)
      const hayCambiosEnPassword = passwordInput && passwordInput.value.length > 0;

      // Verificar si hay cambios en las salas
      const hayCambiosEnSalas =
        estadoActualSalas.acceso_todas !== estadoOriginalSalas.acceso_todas ||
        JSON.stringify(estadoActualSalas.salas_seleccionadas.sort()) !==
        JSON.stringify(estadoOriginalSalas.salas_seleccionadas.sort());

      const hayCambiosTotales = hayCambiosEnCampos || hayCambiosEnSalas || hayCambiosEnPassword;

      // Habilitar el botón solo si:
      // 1. Todos los campos obligatorios están llenos
      // 2. Hay cambios (en campos obligatorios, salas O contraseña)
      // 3. Las salas son válidas
      // 4. La contraseña es válida (si se ingresó)
      const submitButton = document.querySelector('#formEditar button[type="submit"]');
      if (submitButton) {
        submitButton.disabled = !(todosLlenos && hayCambiosTotales && salasValidas && passwordValida);
      }
    }

    function abrirModalEditar(id) {
      fetch(`obtener_usuario.php?id=${id}`)
        .then(res => {
          if (!res.ok) throw new Error('Error al cargar usuario');
          return res.json();
        })
        .then(data => {
          if (data.error) {
            throw new Error(data.error);
          }

          // Verificar permisos: si el admin es limitado y el usuario tiene acceso a todas las salas, no permitir edición
          if (adminAcceso === 0 && data.acceso_todas_salas == 1) {
            showToast('Permiso denegado', 'No tienes permiso para editar usuarios con acceso a todas las salas', 'warning');
            return;
          }

          // Almacenar valores originales
          valoresOriginales = {
            'editar_nombre': data.nombre,
            'editar_apepat': data.apepat || '',
            'editar_apemat': data.apemat || '',
            'editar_correo': data.correo,
            'editar_rol': data.rol
          };

          // Almacenar estado original de salas
          let salasPermitidas = [];
          if (data.salas_permitidas) {
            try {
              salasPermitidas = JSON.parse(data.salas_permitidas);
              if (!Array.isArray(salasPermitidas)) {
                salasPermitidas = [salasPermitidas];
              }
            } catch (e) {
              console.error('Error al parsear salas_permitidas:', e);
              salasPermitidas = [];
            }
          }

          estadoOriginalSalas = {
            acceso_todas: data.acceso_todas_salas == 1,
            salas_seleccionadas: salasPermitidas
          };

          // Establecer valores en los campos
          const campos = {
            'editar_id': id,
            'editar_nombre': data.nombre,
            'editar_apepat': data.apepat || '',
            'editar_apemat': data.apemat || '',
            'editar_correo': data.correo,
            'editar_rol': data.rol,
            'editar_password': ''
          };

          Object.keys(campos).forEach(key => {
            const element = getElement(key);
            if (element) element.value = campos[key];
          });

          // Configurar salas según tipo de admin
          if (adminAcceso === 1) {
            // Admin total: puede asignar todas las salas o específicas
            const todasSalas = getElement('editar_todas_salas');
            const salasEspecificas = getElement('editar_salas_especificas');
            const selectorSalas = getElement('editar_selector_salas');

            if (data.acceso_todas_salas == 1) {
              todasSalas.checked = true;
              selectorSalas.style.display = 'none';
            } else {
              salasEspecificas.checked = true;
              selectorSalas.style.display = 'block';

              // Marcar la sala permitida
              if (salasPermitidas.length > 0) {
                const salaId = salasPermitidas[0];
                const radio = document.getElementById(`editar_sala_${salaId}`);
                if (radio) {
                  radio.checked = true;
                }
              }
            }
          } else {
            // Admin limitado: solo puede asignar salas específicas de sus permisos
            const selectorSalas = getElement('editar_selector_salas');
            selectorSalas.style.display = 'block';

            // Marcar la sala permitida si existe
            if (salasPermitidas.length > 0) {
              const salaId = salasPermitidas[0];
              const radio = document.getElementById(`editar_sala_${salaId}`);
              if (radio) {
                radio.checked = true;
              }
            }
          }

          // Validaciones de formato
          ['editar_nombre', 'editar_apepat', 'editar_apemat'].forEach(id => {
            const input = getElement(id);
            if (input) {
              input.oninput = function() {
                this.value = this.value.replace(/[^A-Za-zÁÉÍÓÚáéíóúÑñ\s]/g, '');
                validarCamposEditar();
              };
            }
          });

          // Validación de email
          const emailInput = getElement('editar_correo');
          if (emailInput) {
            emailInput.oninput = function() {
              validarCamposEditar();
            };
          }

          // Validación mejorada para contraseña
          const passwordInput = getElement('editar_password');
          if (passwordInput) {
            passwordInput.oninput = function() {
              validarCamposEditar(); // Esto ahora manejará la validación y habilitación
            };

            // También agregar evento blur para feedback visual adicional
            passwordInput.addEventListener('blur', function() {
              if (this.value.length > 0 && this.value.length < 6) {
                this.classList.add('is-invalid');
              } else {
                this.classList.remove('is-invalid');
              }
            });
          }

          // Deshabilitar el botón inicialmente
          const submitButton = document.querySelector('#formEditar button[type="submit"]');
          if (submitButton) {
            submitButton.disabled = true;
          }

          // Agregar eventos de validación (incluyendo la contraseña)
          ['editar_nombre', 'editar_apepat', 'editar_apemat', 'editar_correo', 'editar_rol', 'editar_password'].forEach(id => {
            const input = getElement(id);
            if (input) {
              input.addEventListener('input', validarCamposEditar);
            }
          });

          // Configurar eventos de salas
          configurarSalasModal();

          const modal = new bootstrap.Modal(getElement('modalEditar'));
          modal.show();

          // Enfocar el primer campo cuando el modal esté completamente visible
          getElement('modalEditar').addEventListener('shown.bs.modal', function() {
            const primerCampo = document.querySelector('#modalEditar input:not([disabled])');
            if (primerCampo) {
              primerCampo.focus();
            }
          }, {
            once: true
          });
        })
        .catch(error => {
          console.error('Error al cargar datos del usuario:', error);
          showToast('Error', 'Error al cargar los datos del usuario', 'error');
        });
    }

    // Función para confirmar cambio de estado
    function confirmarCambioEstado(id, nombre, accion) {
      const modal = new bootstrap.Modal(getElement('modalConfirmarEstado'));
      const nombreElement = getElement('nombre-usuario-estado');
      const accionElement = getElement('accion-texto');
      const btnConfirmar = getElement('btn-confirmar-estado');
      const mensaje = getElement('mensaje-cambio-estado');

      if (nombreElement) nombreElement.textContent = nombre;
      if (accionElement) accionElement.textContent = accion === 'activar' ? 'activar' : 'desactivar';

      // Configurar el botón según la acción
      if (btnConfirmar) {
        if (accion === 'activar') {
          btnConfirmar.classList.remove('btn-warning');
          btnConfirmar.classList.add('btn-success');
          btnConfirmar.innerHTML = '<i class="bi bi-check-circle"></i> Activar';
        } else {
          btnConfirmar.classList.remove('btn-success');
          btnConfirmar.classList.add('btn-warning');
          btnConfirmar.innerHTML = '<i class="bi bi-check-circle"></i> Desactivar';
        }
      }

      // Configurar evento para el botón de confirmar
      if (btnConfirmar) {
        const newBtnConfirmar = btnConfirmar.cloneNode(true);
        btnConfirmar.parentNode.replaceChild(newBtnConfirmar, btnConfirmar);

        newBtnConfirmar.addEventListener('click', function() {
          cambiarEstadoUsuario(id, accion);
        });
      }

      modal.show();
    }

    // Función para cambiar estado del usuario
    function cambiarEstadoUsuario(id, accion) {
      const btnConfirmar = getElement('btn-confirmar-estado');
      if (btnConfirmar) {
        btnConfirmar.disabled = true;
        btnConfirmar.innerHTML = '<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Procesando...';
      }

      const formData = new FormData();
      formData.append('id', id);
      formData.append('accion', accion);

      fetch(`cambiar_estado_usuario.php`, {
          method: 'POST',
          body: formData
        })
        .then(response => response.json())
        .then(data => {
          const modal = bootstrap.Modal.getInstance(getElement('modalConfirmarEstado'));
          if (modal) modal.hide();

          if (data.success) {
            showToast('Éxito', data.message, 'success');

            if (getElement('buscar').value.trim() !== '') {
              getElement('buscar').dispatchEvent(new Event('input'));
            } else {
              let url = 'usuarios.php';
              if (mostrarInactivos) {
                url += '?mostrar_inactivos=1';
              }
              <?php if ($filtro_aplicado && $filtros_activos): ?>
                url += '&<?= http_build_query(['fecha_inicio' => $fechaInicio, 'fecha_fin' => $fechaFin, 'sala' => $salaFiltro, 'filtrar' => 1]) ?>';
              <?php endif; ?>
              window.location.href = url;
            }
          } else {
            showToast('Error', data.message || 'No se pudo cambiar el estado del usuario', 'error');
          }
        })
        .catch(error => {
          console.error('Error al cambiar estado:', error);
          showToast('Error', 'Error al cambiar el estado del usuario', 'error');

          if (btnConfirmar) {
            btnConfirmar.disabled = false;
            btnConfirmar.innerHTML = '<i class="bi bi-check-circle"></i> Confirmar';
          }
        });
    }

    // Configurar envío del formulario
    function configurarFormulario() {
      if (!elementExists('formEditar')) return;

      const formEditar = getElement('formEditar');
      if (formEditar) {
        formEditar.addEventListener('submit', function(e) {
          e.preventDefault();

          // Validación adicional antes de enviar
          const passwordInput = getElement('editar_password');
          if (passwordInput && passwordInput.value.length > 0 && passwordInput.value.length < 6) {
            showToast('Error', 'La contraseña debe tener al menos 6 caracteres', 'error');
            passwordInput.focus();
            return;
          }

          const submitButton = this.querySelector('button[type="submit"]');
          const originalText = submitButton.innerHTML;
          submitButton.disabled = true;
          submitButton.innerHTML = '<span class="spinner-border spinner-border-sm" role="status"></span> Guardando...';

          const formData = new FormData(this);

          fetch('actualizar_usuario.php', {
              method: 'POST',
              body: formData
            })
            .then(response => response.json())
            .then(data => {
              submitButton.disabled = false;
              submitButton.innerHTML = originalText;

              if (data.success) {
                showToast('Éxito', data.message, 'success');
                const modal = bootstrap.Modal.getInstance(getElement('modalEditar'));
                if (modal) modal.hide();

                let url = 'usuarios.php';
                if (mostrarInactivos) {
                  url += '?mostrar_inactivos=1';
                }
                <?php if ($filtro_aplicado && $filtros_activos): ?>
                  url += '&<?= http_build_query(['fecha_inicio' => $fechaInicio, 'fecha_fin' => $fechaFin, 'sala' => $salaFiltro, 'filtrar' => 1]) ?>';
                <?php endif; ?>
                window.location.href = url;
              } else {
                showToast('Error', data.message, 'error');
              }
            })
            .catch(error => {
              console.error('Error al actualizar:', error);
              submitButton.disabled = false;
              submitButton.innerHTML = originalText;
              showToast('Error', 'Error al actualizar el usuario', 'error');
            });
        });
      }
    }

    // Gestionar el foco cuando se cierra el modal
    function configurarCierreModal() {
      if (!elementExists('modalEditar')) return;

      const modalEditar = getElement('modalEditar');
      if (modalEditar) {
        modalEditar.addEventListener('hidden.bs.modal', function() {
          valoresOriginales = {};
          estadoOriginalSalas = {
            acceso_todas: false,
            salas_seleccionadas: []
          };

          setTimeout(() => {
            const buscarInput = getElement('buscar');
            if (buscarInput) buscarInput.focus();
          }, 100);
        });
      }
    }

    // Inicializar la aplicación
    function inicializar() {
      configurarBotonLimpiar();
      configurarBusqueda();

      if (elementExists('modalEditar')) {
        configurarFormulario();
        configurarCierreModal();
      }

      agregarEventosFila();
    }

    // Iniciar cuando el DOM esté completamente cargado
    document.addEventListener('DOMContentLoaded', inicializar);
  </script>
</body>

</html>