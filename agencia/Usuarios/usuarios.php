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

// Verificación de sesión
$rolSesion = $_SESSION['rol'] ?? '';
if (!isset($_SESSION['usuario_id']) || ($rolSesion !== 'admin' && $rolSesion !== 'superadmin')) {
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

  // Superadmin siempre tiene acceso a todas las salas
  $admin_acceso_todas = $rolSesion === 'superadmin' ? 1 : (int)($adminData['acceso_todas_salas'] ?? 0);

  // Convertir salas permitidas a array
  $admin_salas_array = json_decode($adminData['salas_permitidas'] ?? '[]', true);
  if (!is_array($admin_salas_array)) {
    $admin_salas_array = [];
  }
} catch (PDOException $e) {
  error_log("Error al obtener datos del admin: " . $e->getMessage());
  die("Error al cargar permisos del administrador.");
}

// Obtener parámetros de filtrado
$fechaInicio = (string)($_GET['fecha_inicio'] ?? '');
$fechaFin = (string)($_GET['fecha_fin'] ?? '');
$salaFiltro = (string)($_GET['sala'] ?? '');
$mostrar_inactivos = !empty($_GET['mostrar_inactivos']);
$filtro_aplicado = isset($_GET['filtrar']);

// Configuración de paginación
$usuariosPorPagina = 10;
$paginaActual = isset($_GET['pagina']) ? (int)$_GET['pagina'] : 1;
if ($paginaActual < 1) $paginaActual = 1;

// Construir consulta base con filtros
$whereConditions = [];
$params = [];

// Solo un superadmin puede ver superadmins
if ($rolSesion !== 'superadmin') {
  $whereConditions[] = "u.rol <> 'superadmin'";
}

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
if ($admin_acceso_todas == 0) {
  // Crear condición para las salas del admin
  $salaConditions = [];

  foreach (array_values($admin_salas_array) as $index => $salaId) {
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
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Usuarios</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet">
  <link href="CSS/usuarios.css?v=<?= filemtime(__DIR__ . '/CSS/usuarios.css') ?>" rel="stylesheet">
</head>

<body class="bg-light" data-rol="<?= htmlspecialchars($rolSesion) ?>" data-admin-acceso="<?= (int)$admin_acceso_todas ?>">
  <?php require __DIR__ . '/../../includes/navbar.php'; ?>

  <div class="espaciador-navbar" style="height: 70px;"></div>
  <div class="container-lg mt-5 contenedor-usuarios">
    <div class="card shadow-sm rounded-4">
      <div class="card-header bg-dark text-white fw-semibold fs-5 d-flex flex-wrap gap-2 justify-content-between align-items-center">
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
        <div class="mb-3 text-end acciones-usuarios">
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
                    echo "<option value='" . (int)$sala['id'] . "' $selected>" . htmlspecialchars($sala['nombre']) . "</option>";
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

                  // Valores escapados para HTML
                  $uid = (int)$usuario['id'];
                  $nombre = htmlspecialchars($usuario['nombre'] ?? '');
                  $apepat = htmlspecialchars($usuario['apepat'] ?? '');
                  $apemat = htmlspecialchars($usuario['apemat'] ?? '');

                  echo "<tr data-id='{$uid}' class='{$estadoClass}'>";
                  echo "<td style='cursor: pointer;'> {$apemat}</td>";
                  echo "<td style='cursor: pointer;'>{$nombre} {$apepat} </td>";
                  echo "<td style='cursor: pointer;'>" . htmlspecialchars($usuario['correo'] ?? '') . "</td>";
                  echo "<td class='text-center' style='cursor: pointer;'><span class='badge {$badgeClass} rol-badge'>" . htmlspecialchars($usuario['rol'] ?? '') . "</span></td>";

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

                      $totalSalas = count($nombresSalas);
                      if ($totalSalas > 0) {
                        // Más de 2 salas: mostrar las 2 primeras (recortadas a 12) y el resto como contador
                        $largo = $totalSalas > 2 ? 12 : 15;
                        echo "<div class='multi-salas-container'>";
                        foreach (array_slice($nombresSalas, 0, 2) as $nombreSala) {
                          echo "<span class='sala-mini-badge' title='" . htmlspecialchars($nombreSala) . "'>" . htmlspecialchars(mb_substr($nombreSala, 0, $largo)) . "</span>";
                        }
                        if ($totalSalas > 2) {
                          echo "<span class='sala-mini-badge'>+ " . ($totalSalas - 2) . " más</span>";
                        }
                        echo "</div>";
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
                  if ($admin_acceso_todas == 0 && $usuario['acceso_todas_salas'] == 1) {
                    // Admin limitado NO puede editar usuarios con acceso a todas las salas
                    echo "<span class='badge bg-secondary'>Sin permiso</span>";
                  } else {
                    [$btnClass, $btnAccion, $btnIcono, $btnTexto] = $usuario['activo'] == 1
                      ? ['btn-warning', 'desactivar', 'bi-pause-circle', 'Desactivar']
                      : ['btn-success', 'activar', 'bi-play-circle', 'Activar'];
                    echo "<button class='btn btn-sm {$btnClass} btn-cambiar-estado' data-id='{$uid}' data-nombre='{$nombre} {$apepat} {$apemat}' data-accion='{$btnAccion}'>";
                    echo "<i class='bi {$btnIcono}'></i> {$btnTexto}";
                    echo "</button>";
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
                  <?php if ($rolSesion === 'superadmin'): ?>
                  <option value="superadmin">Superadministrador</option>
                  <?php endif; ?>
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
                      // Salas asignables según el tipo de admin (mismas que las del filtro)
                      if (count($salasFiltro) > 0) {
                        foreach ($salasFiltro as $sala) {
                          $salaId = (int)$sala['id'];
                          echo "<div class='form-check'>";
                          echo "<input class='form-check-input' type='radio' name='salas_seleccionadas' value='{$salaId}' id='editar_sala_{$salaId}'>";
                          echo "<label class='form-check-label' for='editar_sala_{$salaId}'>" . htmlspecialchars($sala['nombre']) . "</label>";
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
      // Datos del servidor para el JS de esta pagina
      const filtrosActivos = <?= $filtros_activos ? 'true' : 'false' ?>;
      const totalUsuariosInicial = <?= (int)$totalUsuarios ?>;
      const mostrarInactivos = <?= $mostrar_inactivos ? 'true' : 'false' ?>;
      const filtrosAplicados = <?= json_encode($filtro_aplicado ? array_filter(['fecha_inicio' => $fechaInicio, 'fecha_fin' => $fechaFin, 'sala' => $salaFiltro], 'strlen') : new stdClass(), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
  </script>
  <script src="JS/usuarios-pagina.js?v=<?= filemtime(__DIR__ . '/JS/usuarios-pagina.js') ?>"></script>
</body>

</html>