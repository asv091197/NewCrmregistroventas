<?php
ini_set('session.gc_probability', 1);
ini_set('session.gc_divisor', 1000); // Solo 0.1% de probabilidad de limpieza
ini_set('session.cookie_secure', 0);
ini_set('session.cookie_httponly', 1);
ini_set('session.use_strict_mode', 1);
header('Access-Control-Allow-Origin: *');

require_once __DIR__ . '/../session_config.php'; session_start();

// Verificación de sesión mejorada
if (!isset($_SESSION['usuario_id']) || empty($_SESSION['rol']) || 
    !in_array($_SESSION['rol'], ['admin', 'usuario'])) {
    header("Location: ../login.php");
    exit;
}
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

// Obtener parámetros de filtrado
$fechaInicio = isset($_GET['fecha_inicio']) ? $_GET['fecha_inicio'] : '';
$fechaFin = isset($_GET['fecha_fin']) ? $_GET['fecha_fin'] : '';

// Configuración de paginación
$clientesPorPagina = 10;
$paginaActual = isset($_GET['pagina']) ? (int)$_GET['pagina'] : 1;
if ($paginaActual < 1) $paginaActual = 1;

// Construir consulta base con filtros
$whereConditions = [];
$params = [];

// Si el usuario no es admin, filtrar solo sus clientes
if ($_SESSION['rol'] !== 'admin') {
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
    $totalClientes = $stmtTotal->fetch(PDO::FETCH_ASSOC)['total'];
    $totalPaginas = ceil($totalClientes / $clientesPorPagina);

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
    
    // Liberar memoria
    unset($stmtTotal, $stmtClientes);
    
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
    .closer-section {
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
  </style>
</head>

<body class="bg-light" data-rol="<?= htmlspecialchars($_SESSION['rol']) ?>">
  <nav class="navbar navbar-expand-lg navbar-light bg-white shadow-sm fixed-top border-bottom">
    <div class="container-fluid">
      <div class="d-flex align-items-center ms-auto">
        <ul class="navbar-nav me-3">
          <?php if ($_SESSION['rol'] === 'admin'): ?>
          <li class="nav-item">
            <a class="nav-link text-dark" href="../opcionesAdmin.php" style="text-decoration: none;">
             Dashboard
            </a>
          </li>
          <?php endif; ?> 
          <?php if ($_SESSION['rol'] === 'admin'): ?>
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
          <?= htmlspecialchars($_SESSION['nombre']) ?>
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
        <?php if ($_SESSION['rol'] === 'admin'): ?>
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
              if (count($clientes) > 0) {
                foreach ($clientes as $cliente) {
                  $fechaRegistro = date('d/m/Y H:i', strtotime($cliente['fecha_registro']));
                  $closerClass = ($cliente['closer_nombre_completo'] === 'No asignado') ? 'no-asignado' : '';
                  
                  echo "<tr data-id='{$cliente['id']}'>";
                  echo "<td>{$cliente['nombre']} {$cliente['apepat']} {$cliente['apemat']}</td>";
                  echo "<td>{$cliente['asunto']}</td>";
                  echo "<td title='" . htmlspecialchars($cliente['descripcion_corta']) . "'>" . 
                       htmlspecialchars($cliente['descripcion_corta']) . 
                       (strlen($cliente['descripcion_corta']) >= 100 ? '...' : '') . "</td>";
                  echo "<td>{$cliente['usuario_nombre']} {$cliente['usuario_apepat']} {$cliente['usuario_apemat']}</td>";
                  echo "<td class='{$closerClass}'>{$cliente['closer_nombre_completo']}</td>";
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
            <h5 class="modal-title"><?= $_SESSION['rol'] === 'admin' ? 'Editar' : 'Ver' ?> Cliente</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
          </div>

          <div class="modal-body">
            <input type="hidden" id="editar_id" name="id">

            <!-- Datos principales (solo lectura para no admin) -->
            <div class="row">
              <div class="col-md-4 mb-2">
                <label class="form-label small">Nombre:</label>
                <input type="text" id="editar_nombre" name="nombre" class="form-control form-control-sm <?= $_SESSION['rol'] !== 'admin' ? 'readonly-field' : '' ?>" 
                       <?= $_SESSION['rol'] !== 'admin' ? 'readonly' : '' ?>>
              </div>
              <div class="col-md-4 mb-2">
                <label class="form-label small">Apellido Paterno:</label>
                <input type="text" id="editar_apepat" name="apepat" class="form-control form-control-sm <?= $_SESSION['rol'] !== 'admin' ? 'readonly-field' : '' ?>"
                       <?= $_SESSION['rol'] !== 'admin' ? 'readonly' : '' ?>>
              </div>
              <div class="col-md-4 mb-2">
                <label class="form-label small">Apellido Materno:</label>
                <input type="text" id="editar_apemat" name="apemat" class="form-control form-control-sm <?= $_SESSION['rol'] !== 'admin' ? 'readonly-field' : '' ?>"
                       <?= $_SESSION['rol'] !== 'admin' ? 'readonly' : '' ?>>
              </div>
            </div>

            <div class="row">
              <div class="col-md-6 mb-2">
                <label class="form-label small">Número 1:</label>
                <input type="text" id="editar_numero1" name="numero1" class="form-control form-control-sm <?= $_SESSION['rol'] !== 'admin' ? 'readonly-field' : '' ?>"
                       <?= $_SESSION['rol'] !== 'admin' ? 'readonly' : '' ?>>
              </div>
              <div class="col-md-6 mb-2">
                <label class="form-label small">Número 2:</label>
                <input type="text" id="editar_numero2" name="numero2" class="form-control form-control-sm <?= $_SESSION['rol'] !== 'admin' ? 'readonly-field' : '' ?>"
                       <?= $_SESSION['rol'] !== 'admin' ? 'readonly' : '' ?>>
              </div>
            </div>

            <div class="mb-2">
              <label class="form-label small">Asunto:</label>
              <input type="text" id="editar_asunto" name="asunto" class="form-control form-control-sm <?= $_SESSION['rol'] !== 'admin' ? 'readonly-field' : '' ?>"
                     <?= $_SESSION['rol'] !== 'admin' ? 'readonly' : '' ?>>
            </div>

            <div class="mb-2">
              <label class="form-label small">Descripción:</label>
              <textarea id="editar_descripcion" name="descripcion" class="form-control form-control-sm <?= $_SESSION['rol'] !== 'admin' ? 'readonly-field' : '' ?>" rows="3"
                        <?= $_SESSION['rol'] !== 'admin' ? 'readonly' : '' ?>></textarea>
            </div>

            <!-- Usuario asignado (solo para admin) -->
            <?php if ($_SESSION['rol'] === 'admin'): ?>
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
            <?php if ($_SESSION['rol'] === 'admin'): ?>
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
  // Variables globales
  const rolUsuario = document.body.getAttribute('data-rol');
  let paginaActual = <?= $paginaActual ?>;
  const totalPaginas = <?= $totalPaginas ?>;
  const totalClientesInicial = <?= $totalClientes ?>;
  let valoresOriginales = {};
  let usuariosCargados = [];
  let closersCargados = [];
  let timeoutBusqueda = null;

  // ====================== FUNCIONES UTILITARIAS ======================
  function getElement(id) {
    const el = document.getElementById(id);
    if (!el) console.warn(`Elemento con ID ${id} no encontrado`);
    return el;
  }

  function volverAAgencia() {
    window.location.href = 'agencia.php';
  }

  function toggleFilters() {
    const filterContent = getElement('filterContent');
    if (filterContent) {
      filterContent.classList.toggle('show');
    }
  }

  // ====================== BOTÓN LIMPIAR BÚSQUEDA ======================
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
        volverAAgencia();
      });
    }
  }

  function actualizarContadorClientes(cantidad, texto = 'Resultados') {
    const contador = getElement('contador-clientes');
    if (contador) contador.textContent = `${texto}: ${cantidad}`;
  }

  // ====================== BÚSQUEDA EN TIEMPO REAL ======================
  function configurarBusqueda() {
    const buscarInput = getElement('buscar');
    const tablaContainer = getElement('tabla-container');
    const mensaje = getElement('mensaje');
    
    if (!buscarInput || !tablaContainer || !mensaje) return;
    
    buscarInput.addEventListener('input', function() {
      const termino = this.value.trim();
      
      // Mostrar/ocultar botón de limpiar
      getElement('clear-search').style.display = termino ? 'block' : 'none';
      
      // Cancelar búsqueda anterior si todavía está pendiente
      if (timeoutBusqueda) {
        clearTimeout(timeoutBusqueda);
      }
      
      // Si el término está vacío, restaurar vista inicial
      if (termino === '') {
        restaurarVistaInicial();
        return;
      }
      
      // Mostrar indicador de carga
      mensaje.innerHTML = '<span class="spinner-border spinner-border-sm" role="status"></span> Buscando...';
      tablaContainer.classList.add('loading');
      
      // Esperar 300ms después de que el usuario deje de escribir
      timeoutBusqueda = setTimeout(() => {
        buscarClientes(termino);
      }, 300);
    });
  }

  function restaurarVistaInicial() {
    window.location.reload(); // La forma más simple de restaurar
  }

  function buscarClientes(termino) {
    const tablaClientes = getElement('tabla-clientes');
    const mensaje = getElement('mensaje');
    const tablaContainer = getElement('tabla-container');
    const params = new URLSearchParams();
    
    params.append('buscar', termino);
    
    // También incluir filtros de fecha si están activos
    const fechaInicio = getElement('fecha_inicio')?.value || '';
    const fechaFin = getElement('fecha_fin')?.value || '';
    
    if (fechaInicio) params.append('fecha_inicio', fechaInicio);
    if (fechaFin) params.append('fecha_fin', fechaFin);
    
    // Incluir información del usuario para el filtrado
    params.append('usuario_id', <?= $_SESSION['usuario_id'] ?>);
    params.append('rol', '<?= $_SESSION['rol'] ?>');
    
    fetch(`buscar_clientes.php?${params.toString()}`)
      .then(response => response.text())
      .then(data => {
        // Extraer el contador de resultados si está presente
        const contadorMatch = data.match(/<div id="resultado-contador" data-total="(\d+)"><\/div>/);
        const totalResultados = contadorMatch ? parseInt(contadorMatch[1]) : 0;
        
        // Remover el div del contador para no mostrarlo en la tabla
        const htmlResultados = data.replace(/<div id="resultado-contador" data-total="\d+"><\/div>/, '');
        
        // Actualizar tabla y contador
        tablaClientes.innerHTML = htmlResultados;
        actualizarContadorClientes(totalResultados, 'Resultados');
        
        // Actualizar mensaje
        if (totalResultados > 0) {
          mensaje.textContent = `Se encontraron ${totalResultados} resultados`;
        } else {
          mensaje.textContent = 'No se encontraron resultados';
        }
        
        // Reagregar eventos a las filas
        agregarEventosFila();
      })
      .catch(error => {
        console.error('Error en la búsqueda:', error);
        mensaje.textContent = 'Error al realizar la búsqueda';
      })
      .finally(() => {
        tablaContainer.classList.remove('loading');
      });
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

  // ====================== USUARIOS ======================
  function cargarUsuarios() {
    return fetch('obtener_usuarios.php')
      .then(res => res.json())
      .then(data => { usuariosCargados = data; })
      .catch(() => { usuariosCargados = []; });
  }

  function llenarSelectUsuarios(usuarioSeleccionadoId = '') {
    const select = getElement('usuarios_id');
    if (!select) return;
    select.innerHTML = '<option value="">-- Selecciona un usuario --</option>';
    usuariosCargados.forEach(u => {
      const option = document.createElement('option');
      option.value = u.id;
      option.textContent = u.nombre_completo || `${u.nombre} ${u.apepat} ${u.apemat || ''}`.trim();
      option.selected = (u.id == usuarioSeleccionadoId);
      select.appendChild(option);
    });
  }

  // ====================== CLOSERS ======================
  function cargarClosers() {
    return fetch('obtener_usuarios.php')
      .then(res => res.json())
      .then(data => { closersCargados = data; })
      .catch(() => { closersCargados = []; });
  }

  function llenarSelectClosers(closerSeleccionadoId = '') {
    const select = getElement('usuario_closer');
    if (!select) return;
    select.innerHTML = '<option value="">-- Seleccione un closer --</option>';
    closersCargados.forEach(c => {
      const option = document.createElement('option');
      option.value = c.id;
      option.textContent = c.nombre_completo || `${c.nombre} ${c.apepat} ${c.apemat || ''}`.trim();
      option.selected = (c.id == closerSeleccionadoId);
      select.appendChild(option);
    });
  }

  function configurarBotonAgregarCloser() {
    const btn = getElement('btnAgregarCloser');
    const section = getElement('closerSection');
    if (btn && section) {
      btn.addEventListener('click', () => {
        section.style.display = (section.style.display === 'none' || section.style.display === '') ? 'block' : 'none';
      });
    }
  }

  // ====================== EDITAR CLIENTE ======================
  function abrirModalEditar(id) {
    // Cargar closers para todos los usuarios
    if (closersCargados.length === 0) {
      cargarClosers().then(() => {
        // Solo cargar usuarios si es admin
        if (rolUsuario === 'admin' && usuariosCargados.length === 0) {
          cargarUsuarios().then(() => obtenerDatosCliente(id));
        } else {
          obtenerDatosCliente(id);
        }
      });
    } else {
      obtenerDatosCliente(id);
    }
  }

  function obtenerDatosCliente(id) {
    fetch(`obtener_cliente.php?id=${id}`)
      .then(res => res.json())
      .then(data => {
        getElement('editar_id').value = id;
        
        valoresOriginales = {
          'editar_nombre': data.nombre,
          'editar_apepat': data.apepat,
          'editar_apemat': data.apemat,
          'editar_numero1': data.numero1,
          'editar_numero2': data.numero2 || '',
          'editar_asunto': data.asunto,
          'editar_descripcion': data.descripcion,
          'usuarios_id': data.usuario_id,
          'usuario_closer': data.usuario_closer || ''
        };
        
        Object.keys(valoresOriginales).forEach(k => {
          const el = getElement(k);
          if (el) el.value = valoresOriginales[k];
        });
        
        // Llenar select de usuarios solo si es admin
        if (rolUsuario === 'admin') {
          llenarSelectUsuarios(data.usuario_id);
        }
        
        // Llenar select de closers para todos los usuarios
        llenarSelectClosers(data.usuario_closer);
        
        const modal = new bootstrap.Modal(getElement('modalEditar'));
        modal.show();
      })
      .catch(error => {
        console.error('Error al obtener datos del cliente:', error);
        
      });
  }

  // ====================== FORMULARIO ======================
  function configurarFormulario() {
    const form = getElement('formEditar');
    if (!form) return;
    
    // Configurar el envío del formulario para administradores
    if (rolUsuario === 'admin') {
      form.addEventListener('submit', e => {
        e.preventDefault();
        guardarCambios();
      });
    } else {
      // Para usuarios no admin, configurar el botón de guardar closer
      const btnGuardarCloser = getElement('btnGuardarCloser');
      if (btnGuardarCloser) {
        btnGuardarCloser.addEventListener('click', e => {
          e.preventDefault();
          guardarSoloCloser();
        });
      }
      
      // Prevenir envío del formulario completo
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
    
    const formData = new FormData(getElement('formEditar'));
    
    // Mostrar indicador de carga
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
      // Restaurar botón
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
    
    // Mostrar indicador de carga
    const btnGuardarCloser = getElement('btnGuardarCloser');
    const originalText = btnGuardarCloser.innerHTML;
    btnGuardarCloser.innerHTML = '<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Guardando...';
    btnGuardarCloser.disabled = true;
    
    // Enviar solo el campo del closer
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
      // Restaurar botón
      btnGuardarCloser.innerHTML = originalText;
      btnGuardarCloser.disabled = false;
    });
  }

  // ====================== INICIALIZACIÓN ======================
  function inicializar() {
    configurarBotonLimpiar();
    configurarBusqueda();
    configurarBotonAgregarCloser(); // Ahora disponible para todos
    cargarClosers(); // Cargar closers para todos los usuarios
    
    // Solo cargar usuarios si es admin
    if (rolUsuario === 'admin') {
      cargarUsuarios();
    }
    
    configurarFormulario();
    agregarEventosFila();
  }

  document.addEventListener('DOMContentLoaded', inicializar);
  </script>
</body>
</html>