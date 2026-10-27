<?php
// Configuración para sesión persistente
ini_set('session.cookie_lifetime', 0);
ini_set('session.gc_maxlifetime', 86400 * 30);
ini_set('session.gc_probability', 1);
ini_set('session.gc_divisor', 1000);
ini_set('session.cookie_secure', 0);
ini_set('session.cookie_httponly', 1);
ini_set('session.use_strict_mode', 1);

require_once __DIR__ . '/../session_config.php'; session_start();

// VERIFICACIÓN DE SESIÓN MANTENIDA - SOLO ADMIN Y SUPERADMIN
if (!isset($_SESSION['usuario_id']) || !in_array($_SESSION['rol'] ?? '', ['admin', 'superadmin'], true)) {
  session_unset();
  session_destroy();
  header("Location: ./login.php");
  exit;
}

// Configuración de manejo de errores
header('Access-Control-Allow-Origin: *');
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

// Obtener información adicional del usuario
$accesoTodasSalas = 1;
$salasPermitidas = [];
$tiene_permiso_plataforma = false;

// Mostrar mensaje de éxito si existe en la URL
$mensajeExito = '';
if (isset($_GET['success'])) {
  $mensajeExito = $_GET['success'];
}

// Conexión a base de datos con mejor manejo de errores
$host = '65.99.205.112';
$dbname = 'noreplyc_crmregistroventas';
$username = 'noreplyc_alexsanchez09';
$password = 'SWn_VrAc3K7lq;LS';

try {
  $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8", $username, $password, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_TIMEOUT => 30,
    PDO::ATTR_PERSISTENT => false,
    PDO::ATTR_EMULATE_PREPARES => false
  ]);
} catch (PDOException $e) {
  error_log("Error de conexión a BD: " . $e->getMessage());
  die("Error de conexión a la base de datos. Por favor, intente más tarde.");
}

// Obtener información del usuario actual
try {
  $sqlUsuario = "SELECT acceso_todas_salas, salas_permitidas, permiso_plataforma FROM usuarios_facturacion WHERE id = ? AND activo = 1";
  $stmtUsuario = $pdo->prepare($sqlUsuario);
  $stmtUsuario->execute([$_SESSION['usuario_id']]);
  $usuarioData = $stmtUsuario->fetch(PDO::FETCH_ASSOC);

  if ($usuarioData) {
    $accesoTodasSalas = $usuarioData['acceso_todas_salas'];
    $salasPermitidas = json_decode($usuarioData['salas_permitidas'] ?? '[]', true) ?? [];
    $tiene_permiso_plataforma = $usuarioData['permiso_plataforma'] == 1;
  }
} catch (PDOException $e) {
  error_log("Error al obtener datos del usuario: " . $e->getMessage());
}

// Determinar el rango de fechas del mes en curso para usuarios no admin O admin sin acceso a todas salas
$fechaInicioMes = '';
$fechaFinMes = '';
$esAdmin = ($_SESSION['rol'] === 'admin' || $_SESSION['rol'] === 'superadmin');
$tieneAccesoTodasSalas = ($esAdmin && $accesoTodasSalas == 1);

// Si es admin pero NO tiene acceso a todas salas, tratarlo como usuario restringido
if ($esAdmin && $accesoTodasSalas == 0) {
  $tieneAccesoTodasSalas = false;

  if (!empty($salasPermitidas)) {
    $salaUsuarioId = intval($salasPermitidas[0]);
  }
} elseif (!$esAdmin) {
  $fechaInicioMes = date('Y-m-01');
  $fechaFinMes = date('Y-m-t');
}

// Obtener la sala del usuario actual (si no es admin con acceso a todas salas)
$salaUsuarioId = null;
$salaUsuarioNombre = 'No asignada';

if (!$tieneAccesoTodasSalas && isset($_SESSION['sala_id'])) {
  $salaUsuarioId = $_SESSION['sala_id'];

  try {
    $sqlSalaUsuario = "SELECT nombre FROM salas WHERE id = ? AND activo = 1";
    $stmtSala = $pdo->prepare($sqlSalaUsuario);
    $stmtSala->execute([$salaUsuarioId]);
    $salaData = $stmtSala->fetch(PDO::FETCH_ASSOC);

    if ($salaData) {
      $salaUsuarioNombre = $salaData['nombre'];
    }
  } catch (PDOException $e) {
    error_log("Error al obtener sala del usuario: " . $e->getMessage());
  }
}

// Parámetros de filtrado
$fechaInicio = isset($_GET['fecha_inicio']) ? $_GET['fecha_inicio'] : '';
$fechaFin = isset($_GET['fecha_fin']) ? $_GET['fecha_fin'] : '';
$busqueda = isset($_GET['busqueda']) ? $_GET['busqueda'] : '';
$plataformaIds = isset($_GET['plataforma_id']) ? (array)$_GET['plataforma_id'] : [];
$tipoVenta = isset($_GET['tipo_venta']) ? $_GET['tipo_venta'] : '';
$salaId = isset($_GET['sala_id']) ? intval($_GET['sala_id']) : 0;

// Para usuarios no admin O admin sin acceso a todas salas, si no hay fechas seleccionadas, usar el mes actual
if (!$tieneAccesoTodasSalas && !$esAdmin) {
  if (empty($fechaInicio)) {
    $fechaInicio = $fechaInicioMes;
  }
  if (empty($fechaFin)) {
    $fechaFin = $fechaFinMes;
  }

  if (!empty($fechaInicio) && $fechaInicio < $fechaInicioMes) {
    $fechaInicio = $fechaInicioMes;
  }
  if (!empty($fechaInicio) && $fechaInicio > $fechaFinMes) {
    $fechaInicio = $fechaInicioMes;
  }
  if (!empty($fechaFin) && $fechaFin > $fechaFinMes) {
    $fechaFin = $fechaFinMes;
  }
  if (!empty($fechaFin) && $fechaFin < $fechaInicioMes) {
    $fechaFin = $fechaFinMes;
  }
  if (!empty($fechaInicio) && !empty($fechaFin) && $fechaInicio > $fechaFin) {
    $fechaInicio = $fechaInicioMes;
    $fechaFin = $fechaFinMes;
  }
}

// Construir consulta base con filtros
$whereConditions = [];
$params = [];

// Si el usuario NO tiene acceso a todas salas (incluye usuarios normales Y admin sin acceso)
if (!$tieneAccesoTodasSalas) {
  if (!$esAdmin) {
    $whereConditions[] = "v.fecha >= :fecha_inicio_mes";
    $whereConditions[] = "v.fecha <= :fecha_fin_mes";
    $params[':fecha_inicio_mes'] = $fechaInicioMes . ' 00:00:00';
    $params[':fecha_fin_mes'] = $fechaFinMes . ' 23:59:59';
  }

  if (!empty($salasPermitidas)) {
    $placeholders = [];
    foreach ($salasPermitidas as $index => $salaIdPerm) {
      $placeholder = ":sala_permitida_" . $index;
      $placeholders[] = $placeholder;
      $params[$placeholder] = $salaIdPerm;
    }
    $whereConditions[] = "v.sala_id IN (" . implode(',', $placeholders) . ")";
  } elseif ($salaUsuarioId) {
    $whereConditions[] = "v.sala_id = :sala_usuario_id";
    $params[':sala_usuario_id'] = $salaUsuarioId;
  } else {
    $whereConditions[] = "1 = 0";
  }
}

// Los filtros normales se aplican además del filtro del mes
if (!empty($fechaInicio)) {
  $whereConditions[] = "v.fecha >= :fecha_inicio";
  $params[':fecha_inicio'] = $fechaInicio . ' 00:00:00';
}

if (!empty($fechaFin)) {
  $whereConditions[] = "v.fecha <= :fecha_fin";
  $params[':fecha_fin'] = $fechaFin . ' 23:59:59';
}

// Añadir condición de plataforma si existe
if (!empty($plataformaIds)) {
  $placeholders = [];
  foreach ($plataformaIds as $index => $platId) {
    $placeholder = ":plataforma_id_" . $index;
    $placeholders[] = $placeholder;
    $params[$placeholder] = $platId;
  }
  $whereConditions[] = "v.plataforma_id IN (" . implode(',', $placeholders) . ")";
}

// Añadir condición de búsqueda si existe
if (!empty($busqueda)) {
  $whereConditions[] = "(v.folio LIKE :busqueda OR 
                          v.cliente LIKE :busqueda OR 
                          v.apepat LIKE :busqueda OR 
                          v.apemat LIKE :busqueda OR 
                          v.producto LIKE :busqueda OR 
                          CONCAT(v.cliente, ' ', v.apepat, ' ', COALESCE(v.apemat, '')) LIKE :busqueda)";
  $params[':busqueda'] = "%$busqueda%";
}

// Añadir condición de tipo_venta si existe
if (!empty($tipoVenta)) {
  $whereConditions[] = "v.tipo_venta = :tipo_venta";
  $params[':tipo_venta'] = $tipoVenta;
}

// MODIFICACIÓN: Para admin CON ACCESO A TODAS SALAS, aplicar filtro de sala si existe
if ($tieneAccesoTodasSalas && $salaId > 0) {
  $whereConditions[] = "v.sala_id = :sala_id";
  $params[':sala_id'] = $salaId;
}

// NUEVO: Para admin SIN ACCESO A TODAS SALAS, aplicar filtro de sala si existe (solo si está en sus salas permitidas)
if ($esAdmin && !$tieneAccesoTodasSalas && !empty($salasPermitidas)) {
  if ($salaId > 0 && in_array($salaId, $salasPermitidas)) {
    $whereConditions[] = "v.sala_id = :sala_id_admin";
    $params[':sala_id_admin'] = $salaId;
  }
}

$whereClause = '';
if (!empty($whereConditions)) {
  $whereClause = 'WHERE ' . implode(' AND ', $whereConditions);
}

// Obtener las plataformas para el select
try {
  $sqlPlataformas = "SELECT id, nombre_ancronimo, plataformaspei FROM plataforma ORDER BY nombre_ancronimo";
  $stmtPlataformas = $pdo->query($sqlPlataformas);
  $plataformas = $stmtPlataformas->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
  error_log("Error al obtener plataformas: " . $e->getMessage());
  $plataformas = [];
}

// Obtener las salas para el filtro (solo para admin CON acceso a todas salas Y admin sin acceso con salas permitidas)
$salas = [];
if ($tieneAccesoTodasSalas) {
  try {
    $sqlSalas = "SELECT id, nombre FROM salas WHERE activo = 1 ORDER BY nombre";
    $stmtSalas = $pdo->query($sqlSalas);
    $salas = $stmtSalas->fetchAll(PDO::FETCH_ASSOC);
  } catch (PDOException $e) {
    error_log("Error al obtener salas: " . $e->getMessage());
    $salas = [];
  }
} elseif ($esAdmin && !empty($salasPermitidas)) {
  try {
    $placeholders = str_repeat('?,', count($salasPermitidas) - 1) . '?';
    $sqlSalas = "SELECT id, nombre FROM salas WHERE id IN ($placeholders) AND activo = 1 ORDER BY nombre";
    $stmtSalas = $pdo->prepare($sqlSalas);
    $stmtSalas->execute($salasPermitidas);
    $salas = $stmtSalas->fetchAll(PDO::FETCH_ASSOC);
  } catch (PDOException $e) {
    error_log("Error al obtener salas permitidas: " . $e->getMessage());
    $salas = [];
  }
}

// Obtener TODAS las ventas (sin paginación) para búsqueda del lado del cliente
try {
  $sqlTodasVentas = "SELECT v.*, 
        CONCAT(v.cliente, ' ', v.apepat, ' ', COALESCE(v.apemat, '')) AS cliente_nombre,
        CASE 
          WHEN liner.apemat IS NOT NULL AND TRIM(liner.apemat) != '' 
          THEN REGEXP_REPLACE(liner.apemat, '[^a-zA-ZáéíóúÁÉÍÓÚñÑ\\s]', '')
          ELSE COALESCE(v.liner_nombre_manual, 'No asignado')
        END AS liner_nombre,
        CASE 
          WHEN c1.apemat IS NOT NULL AND TRIM(c1.apemat) != '' 
          THEN REGEXP_REPLACE(c1.apemat, '[^a-zA-ZáéíóúÁÉÍÓÚñÑ\\s]', '')
          ELSE COALESCE(v.closer1_nombre_manual, 'No asignado')
        END AS closer1_nombre,
        CASE 
          WHEN c2.apemat IS NOT NULL AND TRIM(c2.apemat) != '' 
          THEN REGEXP_REPLACE(c2.apemat, '[^a-zA-ZáéíóúÁÉÍÓÚñÑ\\s]', '')
          ELSE COALESCE(v.closer2_nombre_manual, 'No asignado')
        END AS closer2_nombre,
        CASE 
          WHEN fs.apemat IS NOT NULL AND TRIM(fs.apemat) != '' 
          THEN REGEXP_REPLACE(fs.apemat, '[^a-zA-ZáéíóúÁÉÍÓÚñÑ\\s]', '')
          ELSE COALESCE(v.full_stack_nombre_manual, 'No asignado')
        END AS full_stack_nombre,
        CONCAT(p.nombre_ancronimo, ' (', p.plataformaspei, ')') AS plataforma_nombre_completo,
        p.metodo_pago AS metodo_pago_plataforma,
        p.permiso_plataforma AS plataforma_permiso,
        CONCAT(uc.nombre) AS usuario_creacion_nombre,
        s.nombre AS sala_nombre
        FROM ventas_facturacion v
        LEFT JOIN usuarios liner ON v.liner_id = liner.id
        LEFT JOIN usuarios c1 ON v.closer1_id = c1.id
        LEFT JOIN usuarios c2 ON v.closer2_id = c2.id
        LEFT JOIN usuarios fs ON v.full_stack_id = fs.id
        LEFT JOIN plataforma p ON v.plataforma_id = p.id
        LEFT JOIN usuarios_facturacion uc ON v.usuario_creacion_id = uc.id
        LEFT JOIN salas s ON v.sala_id = s.id
        $whereClause
        ORDER BY v.fecha_registro ASC";

  $stmtTodasVentas = $pdo->prepare($sqlTodasVentas);
  foreach ($params as $key => $value) {
    $stmtTodasVentas->bindValue($key, $value);
  }
  $stmtTodasVentas->execute();
  $todasLasVentas = $stmtTodasVentas->fetchAll(PDO::FETCH_ASSOC);

  $totalVentas = count($todasLasVentas);
} catch (PDOException $e) {
  error_log("Error en consulta: " . $e->getMessage());
  $todasLasVentas = [];
  $totalVentas = 0;
}
?>
<!DOCTYPE html>
<html lang="es">

<head>
  <meta charset="UTF-8">
  <title>Ventas</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
  <link href="CSS/ventas.css" rel="stylesheet">
  <style>
    .platform-checkbox-container {
      background-color: #f8f9fa;
    }

    .platform-checkbox-container .form-check {
      margin-bottom: 5px;
      padding: 3px 8px;
      border-radius: 4px;
    }

    .platform-checkbox-container .form-check:hover {
      background-color: #e9ecef;
    }

    .platform-checkbox-container .form-check-label {
      font-size: 0.875rem;
      cursor: pointer;
    }

    .platform-checkbox-container .form-check-input {
      cursor: pointer;
    }

    .alert-sm {
      padding: 0.5rem 1rem;
      font-size: 0.875rem;
    }

    .badge.bg-warning {
      font-size: 0.75rem;
    }

    input[type="date"]:read-only {
      background-color: #e9ecef !important;
      cursor: not-allowed;
    }

    .text-muted small {
      font-size: 0.75rem;
      display: block;
      margin-top: 2px;
    }

    .select-buscable-container {
      position: relative;
    }

    .select-search-input {
      margin-bottom: 5px;
      border-radius: 0.375rem;
    }

    .select-buscable {
      height: auto;
      min-height: 120px;
      border-radius: 0.375rem;
    }

    .select-buscable option {
      padding: 8px 12px;
      border-bottom: 1px solid #eee;
      cursor: pointer;
    }

    .select-buscable option:hover {
      background-color: #f8f9fa;
    }

    .select-buscable option:checked {
      background-color: #0d6efd;
      color: white;
    }

    .select-buscable:focus {
      border-color: #86b7fe;
      outline: 0;
      box-shadow: 0 0 0 0.25rem rgba(13, 110, 253, 0.25);
    }

    .modal .select-buscable-container {
      margin-bottom: 1rem;
    }

    .modal .select-search-input:focus {
      border-color: #86b7fe;
      box-shadow: 0 0 0 0.25rem rgba(13, 110, 253, 0.25);
    }

    .required-field::after {
      content: " *";
      color: red;
    }

    #modal-linerClosersDiv {
      transition: all 0.3s ease;
      display: flex !important;
      flex-wrap: nowrap;
      gap: 15px;
      align-items: flex-start;
      margin-bottom: 1rem;
    }

    #modal-fullStackDiv {
      transition: all 0.3s ease;
      margin-bottom: 1rem;
    }

    #modal-linerClosersDiv .col-md-4 {
      flex: 1;
      min-width: 0;
      margin-bottom: 0;
      transition: all 0.3s ease;
    }

    #modal-linerClosersDiv .select-buscable-container {
      margin-bottom: 0;
    }

    #modal-linerClosersDiv .form-label {
      white-space: nowrap;
      margin-bottom: 5px;
      font-size: 0.875rem;
    }

    #modal-linerClosersDiv .select-buscable {
      min-height: 120px;
      height: 120px;
    }

    #modal-fullStackDiv .col-12 {
      width: 100%;
    }

    #modal-fullStackDiv .select-buscable {
      min-height: 120px;
      height: 120px;
    }

    #modal-linerClosersDiv[style*="display: none"] {
      display: none !important;
    }

    #modal-fullStackDiv[style*="display: none"] {
      display: none !important;
    }

    #modal-linerClosersDiv:not([style*="display: none"]) .col-md-4 {
      display: block !important;
    }

    #modal-fullStackDiv:not([style*="display: none"]) .col-12 {
      display: block !important;
    }

    @media (max-width: 768px) {
      #modal-linerClosersDiv {
        flex-wrap: wrap;
        gap: 10px;
      }

      #modal-linerClosersDiv .col-md-4 {
        flex: 0 0 calc(50% - 10px);
      }

      #modal-linerClosersDiv .select-buscable,
      #modal-fullStackDiv .select-buscable {
        min-height: 100px;
        height: 100px;
      }
    }

    .filter-row {
      display: flex;
      flex-wrap: nowrap;
      gap: 15px;
      align-items: flex-end;
      margin-bottom: 1rem;
    }

    .filter-item {
      flex: 1;
      min-width: 0;
    }

    .filter-item.plataformas {
      flex: 2;
    }

    .filter-item.acciones {
      flex: 0 0 auto;
      width: auto;
    }

    .platform-checkbox-container {
      max-height: 120px;
      overflow-y: auto;
      border: 1px solid #dee2e6;
      border-radius: 0.375rem;
      padding: 12px;
    }

    .platform-checkbox-container .form-check {
      margin-bottom: 3px;
    }

    @media (max-width: 1200px) {
      .filter-row {
        flex-wrap: wrap;
      }

      .filter-item {
        flex: 1 1 calc(50% - 15px);
      }

      .filter-item.acciones {
        flex: 1 1 100%;
        text-align: center;
      }
    }

    @media (max-width: 768px) {
      .filter-item {
        flex: 1 1 100%;
      }
    }

    #sala-actual-nombre {
      color: #198754;
      font-weight: bold;
      background-color: #f8f9fa;
      padding: 2px 6px;
      border-radius: 4px;
      border: 1px solid #dee2e6;
    }

    .form-select:disabled {
      background-color: #e9ecef;
      cursor: not-allowed;
    }

    /* NUEVO: Estilos para el interruptor de permiso de plataforma en el modal */
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

    .permiso-badge {
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

    @keyframes pulse {
      0% {
        opacity: 1;
      }

      50% {
        opacity: 0.8;
      }

      100% {
        opacity: 1;
      }
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
           <?php if (($_SESSION['rol'] ?? '') === 'superadmin'): ?>

                    <li class="nav-item">

                        <a class="nav-link text-dark" href="../agencia/ver_logs.php" style="text-decoration: none;">

                            <i class="bi bi-shield-lock me-1"></i>Logs

                        </a>

                    </li>

                    <?php endif; ?>
        </ul>
        <span class="navbar-text text-muted me-3">
          <?= htmlspecialchars($_SESSION['nombre']) ?>
        </span>
        <a href="logout.php" class="btn btn-sm btn-outline-danger">
          <i class="bi bi-box-arrow-right"></i> Salir
        </a>
      </div>
    </div>
  </nav>

  <div style="height: 70px;"></div>

  <div class="container-wider mt-4">

    <?php if (!empty($mensajeExito)): ?>
      <div class="alert alert-success alert-dismissible fade show" role="alert">
        <i class="bi bi-check-circle-fill"></i> <?= htmlspecialchars($mensajeExito) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
      </div>
    <?php endif; ?>

    <div class="card shadow-sm rounded-4">
      <div class="card-header bg-dark text-white fw-semibold fs-5 d-flex justify-content-between align-items-center">
        <span>Ventas Registradas</span>
        <div>
          <?php if (!$tieneAccesoTodasSalas): ?>
            <?php if (!$esAdmin): ?>
              <span class="badge bg-warning text-dark me-2">
                <i class="bi bi-calendar-month"></i> Mes Actual
              </span>
            <?php endif; ?>

            <?php if (!empty($salasPermitidas) || $salaUsuarioId): ?>
              <span class="badge bg-info text-white me-2">
                <i class="bi bi-building"></i>
                <?php
                if ($esAdmin && !empty($salasPermitidas)) {
                  echo "Salas Permitidas: " . count($salasPermitidas) . " sala(s)";
                } elseif ($salaUsuarioId) {
                  echo "Sala: " . htmlspecialchars($salaUsuarioNombre);
                }
                ?>
              </span>
            <?php else: ?>
              <span class="badge bg-danger text-white me-2">
                <i class="bi bi-building"></i> Sin sala asignada
              </span>
            <?php endif; ?>
          <?php endif; ?>
          <span class="badge bg-light text-dark badge-search" id="contador-ventas">Total: <?= $totalVentas ?></span>
        </div>
      </div>
      <div class="card-body">
        <div class="mb-3 text-end">
          <a href="ingresarfacturacion.php" class="btn btn-primary">
            <i class="bi bi-plus-circle-fill"></i> Agregar Venta
          </a>

          <?php if ($esAdmin): ?>
            <a href="exportar_excel.php?<?= http_build_query($_GET) ?>" class="btn btn-success ms-2" id="exportar-excel-btn">
              <i class="bi bi-file-earmark-excel-fill"></i> Exportar a Excel
            </a>
            <a href="exportar_pdf.php?<?= http_build_query($_GET) ?>" class="btn btn-danger ms-2" id="exportar-pdf-btn">
              <i class="bi bi-file-earmark-pdf-fill"></i> Exportar a PDF
            </a>
          <?php else: ?>
            <a href="ver_excel.php?<?= http_build_query($_GET) ?>" class="btn btn-info ms-2" id="ver-excel-btn">
              <i class="bi bi-file-earmark-excel-fill"></i> Ver Excel
            </a>
            <a href="ver_pdf.php?<?= http_build_query($_GET) ?>" class="btn btn-danger ms-2" id="exportar-pdf-btn">
              <i class="bi bi-file-earmark-pdf-fill"></i> Exportar a PDF
            </a>
          <?php endif; ?>
        </div>

        <div class="filter-section mb-4">
          <div class="filter-header" onclick="toggleFilters()">
            <i class="bi bi-funnel"></i> Filtros
            <i class="bi bi-chevron-down float-end"></i>
          </div>
          <div class="filter-content" id="filterContent">
            <?php if (!$tieneAccesoTodasSalas): ?>
              <div class="alert alert-info alert-sm mb-3">
                <i class="bi bi-info-circle"></i>
                <strong>Modo restringido:</strong>
                <?php if (!$esAdmin): ?>
                  Solo puedes ver y filtrar ventas del mes en curso
                  (<?= date('F Y') ?>) y de tu sala asignada.
                <?php else: ?>
                  Solo puedes ver ventas de tus salas permitidas (<?= implode(', ', $salasPermitidas) ?>).
                <?php endif; ?>
                <?php if (!$salaUsuarioId && empty($salasPermitidas)): ?>
                  <br><span class="text-danger"><strong>Advertencia:</strong> No tienes salas asignadas. No podrás ver ventas.</span>
                <?php endif; ?>
              </div>
            <?php endif; ?>

            <form method="GET" action="" id="filtroForm">
              <input type="hidden" name="pagina" value="1">

              <div class="filter-row">
                <!-- Fecha Inicio -->
                <div class="filter-item">
                  <label for="fecha_inicio" class="form-label">Fecha Inicio</label>
                  <input type="date" class="form-control" id="fecha_inicio" name="fecha_inicio"
                    value="<?= htmlspecialchars($fechaInicio) ?>"
                    <?php if (!$tieneAccesoTodasSalas && !$esAdmin): ?>
                    min="<?= $fechaInicioMes ?>"
                    max="<?= $fechaFinMes ?>"
                    onchange="validarFechasMesActual()"
                    <?php endif; ?>>
                  <?php if (!$tieneAccesoTodasSalas && !$esAdmin): ?>
                    <small class="text-muted">Mín: <?= date('d/m/Y', strtotime($fechaInicioMes)) ?></small>
                  <?php endif; ?>
                </div>

                <!-- Fecha Fin -->
                <div class="filter-item">
                  <label for="fecha_fin" class="form-label">Fecha Fin</label>
                  <input type="date" class="form-control" id="fecha_fin" name="fecha_fin"
                    value="<?= htmlspecialchars($fechaFin) ?>"
                    <?php if (!$tieneAccesoTodasSalas && !$esAdmin): ?>
                    min="<?= $fechaInicioMes ?>"
                    max="<?= $fechaFinMes ?>"
                    onchange="validarFechasMesActual()"
                    <?php endif; ?>>
                  <?php if (!$tieneAccesoTodasSalas && !$esAdmin): ?>
                    <small class="text-muted">Máx: <?= date('d/m/Y', strtotime($fechaFinMes)) ?></small>
                  <?php endif; ?>
                </div>

                <!-- Tipo Venta -->
                <div class="filter-item">
                  <label for="tipo_venta" class="form-label">Tipo Venta</label>
                  <select class="form-select" id="tipo_venta" name="tipo_venta">
                    <option value="">Todos los tipos</option>
                    <option value="nuevo" <?= (isset($_GET['tipo_venta']) && $_GET['tipo_venta'] === 'nuevo') ? 'selected' : '' ?>>Nuevo</option>
                    <option value="repack" <?= (isset($_GET['tipo_venta']) && $_GET['tipo_venta'] === 'repack') ? 'selected' : '' ?>>Repack</option>
                  </select>
                </div>

                <!-- Sala -->
                <?php if (($tieneAccesoTodasSalas || ($esAdmin && !$tieneAccesoTodasSalas)) && !empty($salas)): ?>
                  <div class="filter-item">
                    <label for="sala_id" class="form-label">Sala</label>
                    <select class="form-select" id="sala_id" name="sala_id" 
                      <?= ($esAdmin && !$tieneAccesoTodasSalas && count($salas) == 1) ? 'disabled' : '' ?>>
                      <option value="">Todas las salas</option>
                      <?php foreach ($salas as $sala): ?>
                        <option value="<?= $sala['id'] ?>" <?= ($salaId == $sala['id']) ? 'selected' : '' ?>>
                          <?= htmlspecialchars($sala['nombre']) ?>
                        </option>
                      <?php endforeach; ?>
                    </select>
                    <?php if ($esAdmin && !$tieneAccesoTodasSalas && count($salas) == 1): ?>
                      <input type="hidden" name="sala_id" value="<?= $salas[0]['id'] ?>">
                    <?php endif; ?>
                  </div>
                <?php endif; ?>

                <!-- Plataformas -->
                <div class="filter-item plataformas">
                  <label class="form-label">Plataforma</label>
                  <div class="platform-checkbox-container">
                    <div class="form-check">
                      <input class="form-check-input" type="checkbox" id="plataforma_todas"
                        <?= empty($plataformaIds) ? 'checked' : '' ?>>
                      <label class="form-check-label" for="plataforma_todas">
                        Todas las plataformas
                      </label>
                    </div>
                    <?php foreach ($plataformas as $plataforma): ?>
                      <div class="form-check">
                        <input class="form-check-input plataforma-checkbox" type="checkbox" name="plataforma_id[]"
                          id="plataforma_<?= $plataforma['id'] ?>" value="<?= $plataforma['id'] ?>"
                          <?= (in_array($plataforma['id'], $plataformaIds)) ? 'checked' : '' ?>>
                        <label class="form-check-label" for="plataforma_<?= $plataforma['id'] ?>">
                          <?= htmlspecialchars($plataforma['nombre_ancronimo'] . ' (' . $plataforma['plataformaspei'] . ')') ?>
                        </label>
                      </div>
                    <?php endforeach; ?>
                  </div>
                  <small class="form-text text-muted">Selecciona una o varias plataformas</small>
                </div>

                <!-- Botones -->
                <div class="filter-item acciones">
                  <div class="btn-group w-100">
                    <button type="submit" class="btn btn-primary">
                      <i class="bi bi-search"></i> Filtrar
                    </button>
                    <a href="ventas.php" class="btn btn-outline-secondary">
                      <i class="bi bi-x-circle"></i> Limpiar
                    </a>
                  </div>
                </div>
              </div>

              <?php if (!$tieneAccesoTodasSalas && !$esAdmin): ?>
                <div class="mt-2">
                  <small class="text-muted">
                    Rango del mes actual:
                    <strong><?= date('d/m/Y', strtotime($fechaInicioMes)) ?></strong> al
                    <strong><?= date('d/m/Y', strtotime($fechaFinMes)) ?></strong>
                  </small>
                </div>
              <?php endif; ?>
            </form>

            <?php if (!empty($fechaInicio) || !empty($fechaFin) || !empty($busqueda) || !empty($plataformaIds) || !empty($tipoVenta) || !empty($salaId)): ?>
              <div class="mt-3">
                <small class="text-muted">
                  Filtros activos:
                  <?php if (!empty($fechaInicio)) echo "Desde: " . htmlspecialchars($fechaInicio) . " "; ?>
                  <?php if (!empty($fechaFin)) echo "Hasta: " . htmlspecialchars($fechaFin); ?>
                  <?php if (!empty($tipoVenta)) echo " | Tipo: " . htmlspecialchars($tipoVenta); ?>
                  
                  <?php 
                  if (!empty($salaId)) {
                    $salaNombre = '';
                    if (!empty($salas)) {
                      foreach ($salas as $sala) {
                        if ($sala['id'] == $salaId) {
                          $salaNombre = $sala['nombre'];
                          if ($esAdmin && !$tieneAccesoTodasSalas) {
                            $salaNombre .= ' (Tu sala)';
                          }
                          break;
                        }
                      }
                    }
                    if (!empty($salaNombre)) {
                      echo " | Sala: " . htmlspecialchars($salaNombre);
                    } else {
                      echo " | Sala: ID " . htmlspecialchars($salaId);
                    }
                  } 
                  ?>
                  
                  <?php if (!empty($plataformaIds)) {
                    $plataformasNombres = [];
                    foreach ($plataformas as $plataforma) {
                      if (in_array($plataforma['id'], $plataformaIds)) {
                        $plataformasNombres[] = $plataforma['nombre_ancronimo'] . ' (' . $plataforma['plataformaspei'] . ')';
                      }
                    }
                    echo " | Plataformas: " . htmlspecialchars(implode(', ', $plataformasNombres));
                  } ?>
                  <?php if (!empty($busqueda)) echo " | Búsqueda: " . htmlspecialchars($busqueda); ?>
                </small>
              </div>
            <?php endif; ?>
          </div>
        </div>

        <div class="mb-4">
          <div class="search-container">
            <input class="form-control me-2" type="text" id="buscar" name="busqueda"
              value="<?= htmlspecialchars($busqueda) ?>"
              placeholder="Buscar venta por folio, cliente, producto..." aria-label="Buscar">
            <span class="search-clear" id="clear-search" style="display: <?= !empty($busqueda) ? 'block' : 'none' ?>;">
              <i class="bi bi-x-circle"></i>
            </span>
          </div>
        </div>

        <div id="mensaje" class="text-center text-muted mb-3">
          <?php if (!empty($fechaInicio) || !empty($fechaFin) || !empty($busqueda) || !empty($plataformaIds) || !empty($tipoVenta) || !empty($salaId)): ?>
            Mostrando ventas filtradas
          <?php elseif (!$tieneAccesoTodasSalas && !$salaUsuarioId && empty($salasPermitidas)): ?>
            <span class="text-danger">No tienes salas asignadas. No puedes ver ventas.</span>
          <?php else: ?>
            Escribe en la barra de búsqueda para buscar ventas. Usa los filtros para refinar resultados.
          <?php endif; ?>
        </div>

        <div class="table-responsive">
          <table class="table table-bordered table-hover align-middle">
            <thead class="table-dark text-center">
              <tr>
                <th>Fecha</th>
                <th>Venta Bruto</th>
                <th>Venta Neto</th>
                <th>Cliente</th>
                <th>Producto</th>
                <th>Liner</th>
                <th>Closer 1</th>
                <th>Closer 2</th>
                <th>Front</th>
                <th>Plataforma</th>
                <th>Tipo venta</th>
                <th>Usuario</th>
                <th>Sala</th>
              </tr>
            </thead>
            <tbody id="tabla-ventas">
              <?php
              if (count($todasLasVentas) > 0) {
                $ventasParaMostrar = array_slice($todasLasVentas, 0, 10);
                foreach ($ventasParaMostrar as $venta) {
                  $fecha = new DateTime($venta['fecha']);
                  $fechaFormateada = $fecha->format('d/m/Y H:i');

                  echo "<tr data-venta='" . htmlspecialchars(json_encode($venta)) . "' style='cursor: pointer;'>";
                  echo "<td>{$fechaFormateada}</td>";
                  echo "<td>$" . number_format($venta['precio'], 2) . "</td>";
                  echo "<td>$" . number_format($venta['precio_neto'], 2) . "</td>";
                  echo "<td>" . htmlspecialchars($venta['cliente_nombre']) . "</td>";
                  echo "<td>" . htmlspecialchars($venta['producto']) . "</td>";
                  echo "<td>" . htmlspecialchars($venta['liner_nombre'] ?? 'No asignado') . "</td>";
                  echo "<td>" . htmlspecialchars($venta['closer1_nombre'] ?? 'No asignado') . "</td>";
                  echo "<td>" . htmlspecialchars($venta['closer2_nombre'] ?? 'No asignado') . "</td>";
                  echo "<td>" . htmlspecialchars($venta['full_stack_nombre'] ?? 'No asignado') . "</td>";
                  echo "<td>" . htmlspecialchars($venta['plataforma_nombre_completo'] ?? 'N/A') . "</td>";
                  echo "<td>" . htmlspecialchars($venta['tipo_venta'] ?? 'N/A') . "</td>";
                  echo "<td>" . htmlspecialchars($venta['usuario_creacion_nombre'] ?? 'N/A') . "</td>";
                  echo "<td>" . htmlspecialchars($venta['sala_nombre'] ?? 'N/A') . "</td>";
                  echo "</tr>";
                }
              } else {
                echo "<tr><td colspan='13' class='text-center'>No hay ventas registradas</td></tr>";
              }
              ?>
            </tbody>
          </table>
        </div>

        <nav aria-label="Paginación de ventas" id="paginacion-container">
          <ul class="pagination justify-content-center" id="paginacion-cliente">
          </ul>
        </nav>
      </div>
    </div>
  </div>

  <!-- Modal para Actualizar Venta -->
  <div class="modal fade" id="ventaModal" tabindex="-1" aria-labelledby="ventaModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title" id="ventaModalLabel">Actualizar Venta - ID: <span id="modal-id-texto"></span></h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body">
          <form id="formActualizarVenta">
            <input type="hidden" id="venta-id" name="id">

            <!-- Seleccionar Sala -->
            <div class="mb-3">
              <label for="modal-sala" class="form-label required-field">Sala</label>

              <?php if (in_array($_SESSION['rol'], ['admin', 'superadmin'], true)): ?>
                <select class="form-select" id="modal-sala" name="sala_id">
                  <option value="" disabled>Selecciona una sala</option>
                  <?php foreach ($salas as $sala): ?>
                    <option value="<?= $sala['id'] ?>">
                      <?= htmlspecialchars($sala['nombre']) ?>
                    </option>
                  <?php endforeach; ?>
                </select>
                <small class="text-muted">Al cambiar la sala, se cargarán solo los usuarios de esa sala.</small>
              <?php else: ?>
                <div class="mt-2">
                  <small class="text-muted">
                    <i class="bi bi-info-circle"></i> Sala actual:
                    <span id="sala-actual-nombre">No seleccionada</span>
                  </small>
                </div>
                <div class="alert alert-info alert-sm mt-2">
                  <i class="bi bi-info-circle"></i>
                  Solo puedes ver y asignar usuarios de tu sala asignada.
                </div>
              <?php endif; ?>

              <input type="hidden" id="modal-sala-hidden" name="sala_id" value="">
            </div>

            <!-- Campos básicos -->
            <div class="row">
              <div class="col-md-6">
                <div class="mb-3">
                  <label for="modal-fecha" class="form-label required-field">Fecha</label>
                  <input type="date" class="form-control" id="modal-fecha" name="fecha" required>
                </div>
              </div>
              <div class="col-md-6">
                <div class="mb-3">
                  <label for="modal-precio" class="form-label required-field">Precio</label>
                  <input type="text" class="form-control" id="modal-precio" name="precio" required>
                </div>
              </div>
            </div>

            <!-- Campos de cliente -->
            <div class="row">
              <div class="col-md-6">
                <div class="mb-3">
                  <label for="modal-cliente" class="form-label required-field">Nombre</label>
                  <input type="text" class="form-control" id="modal-cliente" name="cliente" required>
                </div>
              </div>
              <div class="col-md-6">
                <div class="mb-3">
                  <label for="modal-apepat" class="form-label required-field">Apellido Paterno</label>
                  <input type="text" class="form-control" id="modal-apepat" name="apepat" required>
                </div>
              </div>
            </div>

            <div class="row">
              <div class="col-md-6">
                <div class="mb-3">
                  <label for="modal-apemat" class="form-label">Apellido Materno</label>
                  <input type="text" class="form-control" id="modal-apemat" name="apemat">
                </div>
              </div>
              <div class="col-md-6">
                <div class="mb-3">
                  <label for="modal-celular" class="form-label required-field">Celular</label>
                  <input type="text" class="form-control" id="modal-celular" name="celular" required>
                </div>
              </div>
            </div>

            <div class="row">
              <div class="col-md-6">
                <div class="mb-3">
                  <label for="modal-correo" class="form-label required-field">Correo</label>
                  <input type="email" class="form-control" id="modal-correo" name="correo" required>
                </div>
              </div>
              <div class="col-md-6">
                <div class="mb-3">
                  <label for="modal-producto" class="form-label required-field">Producto</label>
                  <input type="text" class="form-control" id="modal-producto" name="producto" required>
                </div>
              </div>
            </div>

            <!-- Tipo de Venta -->
            <div class="mb-3">
              <div class="mt-3">
                <label for="tipoCliente" class="form-label required-field">Tipo de Venta</label>
                <select class="form-select" id="tipoCliente" name="tipoCliente" required style="max-width: 200px;">
                  <option value="" selected disabled>Selecciona el tipo</option>
                  <option value="nuevo">Nuevo</option>
                  <option value="repack">Repack</option>
                  <option value="otro">Otro</option>
                </select>
              </div>
              <div class="mt-3">
                <label class="form-label required-field">Vendedor</label>
                <br>
                <div class="form-check form-check-inline">
                  <input class="form-check-input" type="radio" name="tipoVendedor" id="modal-tipoNormal" value="normal" checked required>
                  <label class="form-check-label" for="modal-tipoNormal">Closer/Liner</label>
                </div>
                <div class="form-check form-check-inline">
                  <input class="form-check-input" type="radio" name="tipoVendedor" id="modal-tipoFullStack" value="fullstack" required>
                  <label class="form-check-label" for="modal-tipoFullStack">Front</label>
                </div>
              </div>
            </div>

            <!-- Venta Normal (Liner y Closers) -->
            <div class="mb-3" id="modal-linerClosersDiv">
              <div class="row">
                <div class="col-md-4">
                  <div class="mb-3">
                    <label for="modal-liner" class="form-label">Liner</label>
                    <select class="form-select" id="modal-liner" name="liner_id">
                      <option value="" disabled>Selecciona un liner</option>
                    </select>
                  </div>
                </div>
                <div class="col-md-4">
                  <div class="mb-3">
                    <label for="modal-closer1" class="form-label">Closer 1</label>
                    <select class="form-select" id="modal-closer1" name="closer1_id">
                      <option value="">Selecciona un closer</option>
                    </select>
                  </div>
                </div>
                <div class="col-md-4">
                  <div class="mb-3">
                    <label for="modal-closer2" class="form-label">Closer 2</label>
                    <select class="form-select" id="modal-closer2" name="closer2_id">
                      <option value="">Selecciona un closer</option>
                    </select>
                  </div>
                </div>
              </div>
            </div>

            <!-- Full Stack -->
            <div class="mb-3" id="modal-fullStackDiv" style="display: none;">
              <div class="row">
                <div class="col-12">
                  <div class="mb-3">
                    <label for="modal-fullstack" class="form-label">Front</label>
                    <select class="form-select" id="modal-fullstack" name="fullstack_id">
                      <option value="" disabled>Selecciona un front</option>
                    </select>
                  </div>
                </div>
              </div>
            </div>

            <!-- Método de pago -->
            <div class="mb-3">
              <label class="form-label required-field">Método de Pago</label>
              <br>
              <div class="form-check form-check-inline">
                <input class="form-check-input" type="radio" name="metodo_pago" id="modal-pagoSpei" value="SPEI" required>
                <label class="form-check-label" for="modal-pagoSpei">SPEI/EFECTIVO</label>
              </div>
              <div class="form-check form-check-inline">
                <input class="form-check-input" type="radio" name="metodo_pago" id="modal-pagoTarjeta" value="TDC" required>
                <label class="form-check-label" for="modal-pagoTarjeta">TDC</label>
              </div>
            </div>

            <!-- Plataforma (se muestra dinámicamente) -->
            <div class="mb-3" id="modal-plataformaDiv" style="display: none;">
              <label for="modal-plataforma" class="form-label">Plataforma</label>
              <select class="form-select" id="modal-plataforma" name="plataforma_id">
                <option value="">Seleccionar</option>
              </select>
              
              <!-- NUEVO: Interruptor de permiso para admin -->
              <div id="modal-plataformaPermisoContainer" class="permiso-plataforma-container" style="display: none;">
                <div class="form-check form-switch">
                  <input class="form-check-input modal-toggle-permiso-plataforma" 
                         type="checkbox" 
                         role="switch" 
                         id="modal-togglePermisoPlataforma">
                  <label class="form-check-label" for="modal-togglePermisoPlataforma">
                    <strong>Restringir esta plataforma</strong>
                  </label>
                </div>
              </div>
            </div>
          </form>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
          <button type="button" class="btn btn-primary" id="btnActualizar">Actualizar Venta</button>
        </div>
      </div>
    </div>
  </div>

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
  <script src="JS/ventas.js"></script>
  <script>
    // Pasar datos PHP a JavaScript
    const totalVentas = <?= $totalVentas ?>;
    const datosVentas = <?= json_encode($todasLasVentas) ?>;
    const busquedaInicial = "<?= htmlspecialchars($busqueda) ?>";
    const plataformaIdsInicial = <?= json_encode($plataformaIds) ?>;
    const tipoVentaInicial = "<?= htmlspecialchars($tipoVenta) ?>";
    const salaIdInicial = <?= $salaId ?>;
    const esUsuarioAdminParam = <?= $esAdmin ? 'true' : 'false'; ?>;
    const tieneAccesoTodasSalasParam = <?= $tieneAccesoTodasSalas ? 'true' : 'false'; ?>;
    const salasPermitidasParam = <?= json_encode($salasPermitidas) ?>;
    const fechaInicioMesParam = "<?= $fechaInicioMes ?>";
    const fechaFinMesParam = "<?= $fechaFinMes ?>";
    const salaUsuarioIdParam = <?= $salaUsuarioId ?: 'null' ?>;
    const rolUsuarioParam = "<?= $_SESSION['rol'] ?? '' ?>";
    const salasDesdePHP = <?= json_encode($salas) ?>;
    const salaUsuarioNombreParam = "<?= htmlspecialchars($salaUsuarioNombre) ?>";
    const tienePermisoPlataformaParam = <?= $tiene_permiso_plataforma ? 'true' : 'false'; ?>;

    console.log('Datos PHP pasados a JS:', {
      totalVentas,
      datosVentasCount: datosVentas.length,
      esUsuarioAdminParam,
      rolUsuarioParam,
      tieneAccesoTodasSalasParam,
      salaUsuarioNombreParam,
      tienePermisoPlataformaParam,
      salasDesdePHP
    });

    // Inicializar la aplicación
    document.addEventListener('DOMContentLoaded', function() {
      if (typeof inicializarAplicacion === 'function') {
        inicializarAplicacion(
          totalVentas,
          datosVentas,
          busquedaInicial,
          plataformaIdsInicial,
          tipoVentaInicial,
          salaIdInicial,
          esUsuarioAdminParam,
          fechaInicioMesParam,
          fechaFinMesParam,
          salaUsuarioIdParam,
          rolUsuarioParam,
          tieneAccesoTodasSalasParam,
          salasPermitidasParam,
          tienePermisoPlataformaParam
        );
      }
    });
  </script>
</body>

</html>