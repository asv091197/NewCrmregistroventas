<?php
header('Access-Control-Allow-Origin: *');
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

require_once __DIR__ . '/../../session_config.php'; session_start();

// Función para limpiar símbolos de los campos de texto
function limpiarTexto($texto) {
    if ($texto === null || $texto === '') {
        return $texto;
    }
    return preg_replace('/[^\p{L}\p{N}\s]/u', '', $texto);
}

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

// Determinar el rango de fechas del mes en curso para usuarios no admin
$fechaInicioMes = '';
$fechaFinMes = '';
$esAdmin = true; // Por defecto asumimos que es admin

if (isset($_SESSION['rol']) && $_SESSION['rol'] !== 'admin') {
    $esAdmin = false;
    $fechaInicioMes = date('Y-m-01'); // Primer día del mes
    $fechaFinMes = date('Y-m-t'); // Último día del mes
}

// En la sección donde obtienes los parámetros de filtrado
$fechaInicio = isset($_GET['fecha_inicio']) ? $_GET['fecha_inicio'] : '';
$fechaFin = isset($_GET['fecha_fin']) ? $_GET['fecha_fin'] : '';
$busqueda = isset($_GET['busqueda']) ? $_GET['busqueda'] : '';
$plataformaIds = isset($_GET['plataforma_id']) ? (array)$_GET['plataforma_id'] : [];

// Para usuarios no admin, si no hay fechas seleccionadas, usar el mes actual
if (!$esAdmin) {
    if (empty($fechaInicio)) {
        $fechaInicio = $fechaInicioMes;
    }
    if (empty($fechaFin)) {
        $fechaFin = $fechaFinMes;
    }
    
    // Validar y ajustar fechas si están fuera del mes actual
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
    // Validar que fecha inicio no sea mayor que fecha fin
    if (!empty($fechaInicio) && !empty($fechaFin) && $fechaInicio > $fechaFin) {
        $fechaInicio = $fechaInicioMes;
        $fechaFin = $fechaFinMes;
    }
}

// Construir consulta base con filtros
$whereConditions = [];
$params = [];

// Si el usuario no es admin, forzar el filtro del mes en curso
if (!$esAdmin) {
    $whereConditions[] = "v.fecha >= :fecha_inicio_mes";
    $whereConditions[] = "v.fecha <= :fecha_fin_mes";
    $params[':fecha_inicio_mes'] = $fechaInicioMes . ' 00:00:00';
    $params[':fecha_fin_mes'] = $fechaFinMes . ' 23:59:59';
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

// Obtener TODAS las ventas (sin paginación) para búsqueda del lado del cliente
try {
    // Consulta para obtener TODAS las ventas
    $sqlTodasVentas = "SELECT v.*, 
        CONCAT(v.cliente, ' ', v.apepat, ' ', COALESCE(v.apemat, '')) AS cliente_nombre,
        liner.apemat AS liner_nombre,
        c1.apemat AS closer1_nombre,
        c2.apemat AS closer2_nombre,
        fs.apemat AS full_stack_nombre,
        CONCAT(p.nombre_ancronimo, ' (', p.plataformaspei, ')') AS plataforma_nombre_completo,
        p.metodo_pago AS metodo_pago_plataforma
        FROM ventas_facturacion v
        LEFT JOIN usuarios liner ON v.liner_id = liner.id
        LEFT JOIN usuarios c1 ON v.closer1_id = c1.id
        LEFT JOIN usuarios c2 ON v.closer2_id = c2.id
        LEFT JOIN usuarios fs ON v.full_stack_id = fs.id
        LEFT JOIN plataforma p ON v.plataforma_id = p.id
        $whereClause
        ORDER BY v.fecha_registro ASC";

    $stmtTodasVentas = $pdo->prepare($sqlTodasVentas);
    foreach ($params as $key => $value) {
        $stmtTodasVentas->bindValue($key, $value);
    }
    $stmtTodasVentas->execute();
    $todasLasVentas = $stmtTodasVentas->fetchAll(PDO::FETCH_ASSOC);
    
    // Aplicar limpieza de símbolos a los campos de nombres
    foreach ($todasLasVentas as &$venta) {
        $venta['cliente_nombre'] = limpiarTexto($venta['cliente_nombre']);
        $venta['liner_nombre'] = limpiarTexto($venta['liner_nombre']);
        $venta['closer1_nombre'] = limpiarTexto($venta['closer1_nombre']);
        $venta['closer2_nombre'] = limpiarTexto($venta['closer2_nombre']);
        $venta['full_stack_nombre'] = limpiarTexto($venta['full_stack_nombre']);
        $venta['cliente'] = limpiarTexto($venta['cliente']);
        $venta['apepat'] = limpiarTexto($venta['apepat']);
        $venta['apemat'] = limpiarTexto($venta['apemat']);
        $venta['producto'] = limpiarTexto($venta['producto']);
    }
    unset($venta); // Romper la referencia
    
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

      /* Estilo para el mensaje de restricción */
      .alert-sm {
          padding: 0.5rem 1rem;
          font-size: 0.875rem;
      }

      .badge.bg-warning {
          font-size: 0.75rem;
      }

      /* Destacar visualmente el modo restringido */
      .card-header .bg-warning {
          animation: pulse 2s infinite;
      }

      /* Estilos para inputs de fecha en modo restringido */
      input[type="date"]:read-only {
          background-color: #e9ecef !important;
          cursor: not-allowed;
      }

      /* Estilos para las etiquetas de fecha mín/máx */
      .text-muted small {
          font-size: 0.75rem;
          display: block;
          margin-top: 2px;
      }

      /* Estilos para selects buscables */
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

      /* Mejoras visuales para los selects en el modal */
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

      /* ESTILOS CORREGIDOS PARA modal-linerClosersDiv */
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

      /* Ajustes para los selects dentro de modal-linerClosersDiv */
      #modal-linerClosersDiv .select-buscable {
          min-height: 120px;
          height: 120px;
      }

      /* Ajustes para el Full Stack */
      #modal-fullStackDiv .col-12 {
          width: 100%;
      }

      #modal-fullStackDiv .select-buscable {
          min-height: 120px;
          height: 120px;
      }

      /* Asegurar que los divs principales tengan display flex/none correctamente */
      #modal-linerClosersDiv[style*="display: none"] {
          display: none !important;
      }

      #modal-fullStackDiv[style*="display: none"] {
          display: none !important;
      }

      /* Asegurar que los campos internos sean siempre visibles cuando su contenedor lo sea */
      #modal-linerClosersDiv:not([style*="display: none"]) .col-md-4 {
          display: block !important;
      }

      #modal-fullStackDiv:not([style*="display: none"]) .col-12 {
          display: block !important;
      }

      /* Asegurar que los elementos se mantengan en línea en pantallas pequeñas */
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

      @keyframes pulse {
          0% { opacity: 1; }
          50% { opacity: 0.8; }
          100% { opacity: 1; }
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
          <?php if (!$esAdmin): ?>
            <span class="badge bg-warning text-dark me-2">
              <i class="bi bi-calendar-month"></i> Mes Actual
            </span>
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
      <!-- Solo mostrar botones de exportación para admin -->
      <a href="exportar_excel.php?<?= http_build_query($_GET) ?>" class="btn btn-success ms-2" id="exportar-excel-btn">
          <i class="bi bi-file-earmark-excel-fill"></i> Exportar a Excel
      </a>
      <a href="exportar_pdf.php?<?= http_build_query($_GET) ?>" class="btn btn-danger ms-2" id="exportar-pdf-btn">
          <i class="bi bi-file-earmark-pdf-fill"></i> Exportar a PDF
      </a>
  <?php else: ?>
      <!-- Botón "Ver Excel" para usuarios no admin -->
      <a href="ver_excel.php?<?= http_build_query($_GET) ?>" class="btn btn-info ms-2" id="ver-excel-btn">
          <i class="bi bi-file-earmark-excel-fill"></i> Ver Excel
      </a>
  <?php endif; ?>
</div>

        <div class="filter-section mb-4">
          <div class="filter-header" onclick="toggleFilters()">
            <i class="bi bi-funnel"></i> Filtros de Fecha
            <i class="bi bi-chevron-down float-end"></i>
          </div>
          <div class="filter-content" id="filterContent">
            <?php if (!$esAdmin): ?>
            <div class="alert alert-info alert-sm mb-3">
              <i class="bi bi-info-circle"></i> 
              <strong>Modo restringido:</strong> Solo puedes ver y filtrar ventas del mes en curso 
              (<?= date('F Y') ?>). Los filtros de fecha se aplicarán dentro de este rango.
            </div>
            <?php endif; ?>
            
            <form method="GET" action="" class="row g-3" id="filtroForm">
              <input type="hidden" name="pagina" value="1">
              
              <div class="col-md-3">
                <label for="fecha_inicio" class="form-label">Fecha Inicio</label>
                <input type="date" class="form-control" id="fecha_inicio" name="fecha_inicio" 
                      value="<?= htmlspecialchars($fechaInicio) ?>" 
                      min="<?= $esAdmin ? '' : $fechaInicioMes ?>" 
                      max="<?= $esAdmin ? '' : $fechaFinMes ?>"
                      <?php if (!$esAdmin): ?>onchange="validarFechasMesActual()"<?php endif; ?>>
                <?php if (!$esAdmin): ?>
                <small class="text-muted">Mín: <?= date('d/m/Y', strtotime($fechaInicioMes)) ?></small>
                <?php endif; ?>
              </div>
              
              <div class="col-md-3">
                <label for="fecha_fin" class="form-label">Fecha Fin</label>
                <input type="date" class="form-control" id="fecha_fin" name="fecha_fin" 
                      value="<?= htmlspecialchars($fechaFin) ?>"
                      min="<?= $esAdmin ? '' : $fechaInicioMes ?>" 
                      max="<?= $esAdmin ? '' : $fechaFinMes ?>"
                      <?php if (!$esAdmin): ?>onchange="validarFechasMesActual()"<?php endif; ?>>
                <?php if (!$esAdmin): ?>
                <small class="text-muted">Máx: <?= date('d/m/Y', strtotime($fechaFinMes)) ?></small>
                <?php endif; ?>
              </div>
              
              <div class="col-md-3">
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
              
              <div class="col-md-3 d-flex align-items-center">
                <div class="btn-group w-100">
                  <button type="submit" class="btn btn-primary">
                    <i class="bi bi-search"></i> Filtrar
                  </button>
                  <a href="ventas.php" class="btn btn-outline-secondary">
                    <i class="bi bi-x-circle"></i> Limpiar
                  </a>
                </div>
              </div>

              <?php if (!$esAdmin): ?>
              <!-- Mostrar las fechas del mes actual como información -->
              <div class="col-12">
                <small class="text-muted">
                  Rango del mes actual: 
                  <strong><?= date('d/m/Y', strtotime($fechaInicioMes)) ?></strong> al 
                  <strong><?= date('d/m/Y', strtotime($fechaFinMes)) ?></strong>
                </small>
              </div>
              <?php endif; ?>
            </form>
            
            <?php if (!empty($fechaInicio) || !empty($fechaFin) || !empty($busqueda) || !empty($plataformaIds)): ?>
            <div class="mt-3">
              <small class="text-muted">
                Filtros activos: 
                <?php if (!empty($fechaInicio)) echo "Desde: " . htmlspecialchars($fechaInicio) . " "; ?>
                <?php if (!empty($fechaFin)) echo "Hasta: " . htmlspecialchars($fechaFin); ?>
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
          <?php if (!empty($fechaInicio) || !empty($fechaFin) || !empty($busqueda) || !empty($plataformaIds)): ?>
            Mostrando ventas filtradas
          <?php else: ?>
            Escribe al menos un caracter para buscar o usa los filtros de fecha
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
              </tr>
            </thead>
            <tbody id="tabla-ventas">
              <?php
              if (count($todasLasVentas) > 0) {
                // Mostrar solo las primeras 10 ventas inicialmente
                $ventasParaMostrar = array_slice($todasLasVentas, 0, 10);
                foreach ($ventasParaMostrar as $venta) {
                  // CORRECCIÓN: Ajustar la fecha para mostrar correctamente
                  $fecha = new DateTime($venta['fecha']);
                
                  $fechaFormateada = $fecha->format('d/m/Y H:i');
                  
                  // Aplicar limpieza final por si acaso
                  $clienteNombre = limpiarTexto($venta['cliente_nombre']);
                  $producto = limpiarTexto($venta['producto']);
                  $linerNombre = limpiarTexto($venta['liner_nombre'] ?? 'No asignado');
                  $closer1Nombre = limpiarTexto($venta['closer1_nombre'] ?? 'No asignado');
                  $closer2Nombre = limpiarTexto($venta['closer2_nombre'] ?? 'No asignado');
                  $fullStackNombre = limpiarTexto($venta['full_stack_nombre'] ?? 'No asignado');
                  $plataformaNombre = limpiarTexto($venta['plataforma_nombre_completo'] ?? 'N/A');
                  
                  echo "<tr data-venta='" . htmlspecialchars(json_encode($venta)) . "' style='cursor: pointer;'>";
                  echo "<td>{$fechaFormateada}</td>";
                  echo "<td>$" . number_format($venta['precio'], 2) . "</td>";
                  echo "<td>$" . number_format($venta['precio_neto'], 2) . "</td>";
                  echo "<td>" . htmlspecialchars($clienteNombre) . "</td>";
                  echo "<td>" . htmlspecialchars($producto) . "</td>";
                  echo "<td>" . htmlspecialchars($linerNombre) . "</td>";
                  echo "<td>" . htmlspecialchars($closer1Nombre) . "</td>";
                  echo "<td>" . htmlspecialchars($closer2Nombre) . "</td>";
                  echo "<td>" . htmlspecialchars($fullStackNombre) . "</td>";
                  echo "<td>" . htmlspecialchars($plataformaNombre) . "</td>";
                  echo "</tr>";
                }
              } else {
                echo "<tr><td colspan='10' class='text-center'>No hay ventas registradas</td></tr>";
              }
              ?>
            </tbody>
          </table>
        </div>
        
        <nav aria-label="Paginación de ventas" id="paginacion-container">
          <ul class="pagination justify-content-center" id="paginacion-cliente">
            <!-- La paginación se generará con JavaScript -->
          </ul>
        </nav>
      </div>
    </div>
  </div>

  <div class="modal fade" id="ventaModal" tabindex="-1" aria-labelledby="ventaModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title" id="ventaModalLabel">Actualizar Venta</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body">
          <form id="formActualizarVenta" method="POST" action="actualizar_venta.php">
            <input type="hidden" id="venta-id" name="id">
            
            <div class="text-center mb-4">
              <span class="badge bg-secondary folio-badge">
                <i class="bi bi-file-earmark-text"></i> ID: <span id="modal-id-texto"></span>
              </span>
            </div>
            
            <div class="row mb-3">
              <div class="col-md-6">
                <label for="modal-fecha" class="form-label required-field">Fecha</label>
                <input type="date" class="form-control" id="modal-fecha" name="fecha" required>
              </div>
              <div class="col-md-6">
                <label for="modal-precio" class="form-label required-field">Precio</label>
                <div class="input-group">
                  <span class="input-group-text">$</span>
                  <input type="text" class="form-control" id="modal-precio" name="precio" required>
                </div>
              </div>
            </div>

            <div class="row mb-3">
              <div class="col-md-4">
                <label for="modal-cliente" class="form-label required-field">Nombre del Cliente</label>
                <input type="text" class="form-control" id="modal-cliente" name="cliente" required>
              </div>
              <div class="col-md-4">
                <label for="modal-apepat" class="form-label required-field">Apellido Paterno</label>
                <input type="text" class="form-control" id="modal-apepat" name="apepat" required>
              </div>
              <div class="col-md-4">
                <label for="modal-apemat" class="form-label">Apellido Materno</label>
                <input type="text" class="form-control" id="modal-apemat" name="apemat">
              </div>
            </div>
            
            <div class="row mb-3">
              <div class="col-md-6">
                <label for="modal-celular" class="form-label required-field">Número celular</label>
                <input type="text" class="form-control" id="modal-celular" name="celular" placeholder="55xxxxxxxx" required>
              </div>
              <div class="col-md-6">
                <label for="modal-correo" class="form-label required-field">Correo del Cliente</label>
                <input type="text" class="form-control" id="modal-correo" name="correo">
              </div>
            </div>

            <div class="mb-3">
              <label for="modal-producto" class="form-label required-field">Producto</label>
              <input type="text" class="form-control" id="modal-producto" name="producto" required>
            </div>

            <!-- TIPO DE VENTA -->
            <div class="mb-3">
              <label class="form-label required-field">Tipo de Venta</label>
              <div class="form-check form-check-inline">
                <input class="form-check-input" type="radio" name="tipo_venta" id="modal-tipoNormal" value="normal" checked required>
                <label class="form-check-label" for="modal-tipoNormal">Venta Normal</label>
              </div>
              <div class="form-check form-check-inline">
                <input class="form-check-input" type="radio" name="tipo_venta" id="modal-tipoFullStack" value="fullstack" required>
                <label class="form-check-label" for="modal-tipoFullStack">Front</label>
              </div>
            </div>

            <!-- LINER Y CLOSERS (VISIBLE POR DEFECTO) - ESTRUCTURA CORREGIDA -->
            <div class="mb-3" id="modal-linerClosersDiv">
              <div class="col-md-4">
                <label for="modal-liner" class="form-label required-field">Liner</label>
                <select class="form-select select-buscable" id="modal-liner" name="liner_id">
                  <option value="">Selecciona un liner</option>
                </select>
              </div>
              <div class="col-md-4">
                <label for="modal-closer1" class="form-label">Closer</label>
                <select class="form-select select-buscable" id="modal-closer1" name="closer1_id">
                  <option value="">Selecciona un closer</option>
                </select>
              </div>
              <div class="col-md-4">
                <label for="modal-closer2" class="form-label">Closer 2</label>
                <select class="form-select select-buscable" id="modal-closer2" name="closer2_id">
                  <option value="">Selecciona un closer</option>
                </select>
              </div>
            </div>

            <!-- FULL STACK (OCULTO INICIALMENTE) -->
            <div class="mb-3" id="modal-fullStackDiv" style="display:none;">
              <div class="col-12">
                <label for="modal-fullstack" class="form-label required-field">Front/Back</label>
                <select class="form-select select-buscable" id="modal-fullstack" name="fullstack_id">
                  <option value="" disabled>Selecciona un Front/Back</option>
                </select>
              </div>
            </div>

            <!-- MÉTODO DE PAGO -->
            <div class="mb-3">
              <label class="form-label required-field">Método de pago</label>
              <div class="form-check form-check-inline">
                <input class="form-check-input" type="radio" name="metodo_pago" id="modal-pagoSpei" value="SPEI" required>
                <label class="form-check-label" for="modal-pagoSpei">SPEI/EFECTIVO</label>
              </div>
              <div class="form-check form-check-inline">
                <input class="form-check-input" type="radio" name="metodo_pago" id="modal-pagoTarjeta" value="TDC">
                <label class="form-check-label" for="modal-pagoTarjeta">Tarjeta de crédito (TDC)</label>
              </div>
            </div>
            
            <!-- PLATAFORMA -->
            <div class="mb-3" id="modal-plataformaDiv" style="display:none;">
              <label for="modal-plataforma" class="form-label required-field">Plataforma</label>
              <select class="form-select select-buscable" id="modal-plataforma" name="plataforma">
                <option value="" disabled>Selecciona una plataforma</option>
              </select>
            </div>
          </form>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
          <button type="button" class="btn btn-update text-white" id="btnActualizar">
            <i class="bi bi-check-circle-fill"></i> Actualizar
          </button>
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
    const esUsuarioAdmin = <?= $esAdmin ? 'true' : 'false'; ?>;
    const fechaInicioMes = "<?= $fechaInicioMes ?>";
    const fechaFinMes = "<?= $fechaFinMes ?>";
    
    // Inicializar la aplicación
    document.addEventListener('DOMContentLoaded', function() {
        if (typeof inicializarAplicacion === 'function') {
            inicializarAplicacion(totalVentas, datosVentas, busquedaInicial, plataformaIdsInicial, esUsuarioAdmin, fechaInicioMes, fechaFinMes);
        }
    });
  </script>
</body>
</html>