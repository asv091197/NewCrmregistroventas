<?php
header('Access-Control-Allow-Origin: *');
session_start();
date_default_timezone_set('America/Mexico_City');
require_once __DIR__ . '/../../includes/registro_acciones.php';

// Restaurar datos del formulario si existen
if (isset($_SESSION['form_data'])) {
    foreach ($_SESSION['form_data'] as $key => $value) {
        if ($key !== 'actualizar_closers') {
            $_POST[$key] = $value;
        }
    }
    unset($_SESSION['form_data']);
}

// Validación de sesión y rol
if (!isset($_SESSION['usuario_id']) || ($_SESSION['rol'] !== 'admin' && $_SESSION['rol'] !== 'usuario')) {
  header("Location: ../login.php");
  exit;
}

// Conexión a la base de datos
$host = '65.99.205.112';
$dbname = 'noreplyc_crmregistroventas';
$username = 'noreplyc_alexsanchez09';
$password = 'SWn_VrAc3K7lq;LS';

try {
  $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8", $username, $password);
  $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
  die("Error de conexión: " . $e->getMessage());
}

// Obtener información completa del usuario actual
$usuario_actual = [];
try {
    $sql_usuario_actual = "SELECT id, acceso_todas_salas, salas_permitidas FROM usuarios WHERE id = :id";
    $stmt_usuario_actual = $pdo->prepare($sql_usuario_actual);
    $stmt_usuario_actual->execute([':id' => $_SESSION['usuario_id']]);
    $usuario_actual = $stmt_usuario_actual->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Error al obtener datos del usuario actual: " . $e->getMessage());
}

// Obtener lista de salas solo para administradores con acceso_todas_salas = 1
$salas = [];
if ($_SESSION['rol'] === 'admin' && isset($usuario_actual['acceso_todas_salas']) && $usuario_actual['acceso_todas_salas'] == 1) {
    try {
        $sql_salas = "SELECT id, nombre FROM salas WHERE activo = 1 ORDER BY nombre";
        $stmt_salas = $pdo->query($sql_salas);
        $salas = $stmt_salas->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Error al obtener salas: " . $e->getMessage());
    }
}

// Obtener lista de usuarios para el selector de closer FILTRADO POR SALA
$usuarios = [];
$sala_id_filtro = null;

// Determinar la sala para filtro de usuarios (closers)
if ($_SESSION['rol'] === 'admin') {
    if (isset($usuario_actual['acceso_todas_salas']) && $usuario_actual['acceso_todas_salas'] == 1) {
        // Administradores con acceso a todas las salas pueden seleccionar sala
        if (isset($_POST['sala_id_admin']) && !empty($_POST['sala_id_admin'])) {
            $sala_id_filtro = intval($_POST['sala_id_admin']);
        } elseif (isset($_SESSION['sala_id']) && intval($_SESSION['sala_id']) > 0) {
            $sala_id_filtro = intval($_SESSION['sala_id']);
        }
    } else {
        // Administradores con acceso limitado usan solo la sala de sesión
        if (isset($_SESSION['sala_id']) && intval($_SESSION['sala_id']) > 0) {
            $sala_id_filtro = intval($_SESSION['sala_id']);
        }
    }
} else {
    // Usuarios normales usan la sala de sesión
    if (isset($_SESSION['sala_id']) && intval($_SESSION['sala_id']) > 0) {
        $sala_id_filtro = intval($_SESSION['sala_id']);
    }
}

try {
    if ($sala_id_filtro) {
        // Primero, obtener todos los usuarios activos
        $sql_usuarios = "SELECT id, nombre, apepat, apemat, salas_permitidas, acceso_todas_salas 
                         FROM usuarios 
                         WHERE rol = 'usuario' 
                         AND activo = 1 
                         ORDER BY nombre, apepat";
        
        $stmt_usuarios = $pdo->query($sql_usuarios);
        $todos_usuarios = $stmt_usuarios->fetchAll(PDO::FETCH_ASSOC);
        
        // Filtrar manualmente en PHP basado en salas_permitidas
        $usuarios = array_filter($todos_usuarios, function($usuario) use ($sala_id_filtro) {
            // Si tiene acceso a todas las salas
            if ($usuario['acceso_todas_salas'] == 1) {
                return true;
            }
            
            // Si salas_permitidas está vacío o nulo, no tiene acceso a ninguna sala específica
            if (empty($usuario['salas_permitidas']) || $usuario['salas_permitidas'] === 'null') {
                return false;
            }
            
            // Intentar interpretar salas_permitidas como JSON
            $salas_permitidas = json_decode($usuario['salas_permitidas'], true);
            
            if (json_last_error() === JSON_ERROR_NONE) {
                // Es un JSON válido, buscar el ID en el array
                if (is_array($salas_permitidas)) {
                    return in_array($sala_id_filtro, $salas_permitidas);
                } elseif (is_string($salas_permitidas) && strpos($salas_permitidas, (string)$sala_id_filtro) !== false) {
                    // En caso de que sea string que contiene el ID
                    return true;
                }
            } else {
                // No es JSON válido, verificar si es una cadena que contiene el ID
                if (strpos($usuario['salas_permitidas'], (string)$sala_id_filtro) !== false) {
                    return true;
                }
            }
            
            return false;
        });
        
        // Reiniciar índices del array
        $usuarios = array_values($usuarios);
        
        error_log("Sala filtro: " . $sala_id_filtro . " | Usuarios encontrados: " . count($usuarios));
    } else {
        // Si no hay sala, mostrar todos los usuarios activos
        $sql_usuarios = "SELECT id, nombre, apepat, apemat 
                         FROM usuarios 
                         WHERE rol = 'usuario' AND activo = 1 
                         ORDER BY nombre, apepat";
        $stmt_usuarios = $pdo->query($sql_usuarios);
        $usuarios = $stmt_usuarios->fetchAll(PDO::FETCH_ASSOC);
        error_log("Sin filtro de sala | Usuarios: " . count($usuarios));
    }
} catch (PDOException $e) {
    error_log("Error al obtener usuarios: " . $e->getMessage());
    // Mostrar usuarios sin filtrar si hay error
    $sql_usuarios = "SELECT id, nombre, apepat, apemat 
                     FROM usuarios 
                     WHERE rol = 'usuario' AND activo = 1 
                     ORDER BY nombre, apepat";
    $stmt_usuarios = $pdo->query($sql_usuarios);
    $usuarios = $stmt_usuarios->fetchAll(PDO::FETCH_ASSOC);
}

$mensaje = "";

// Procesamiento del formulario
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // Si es solo para actualizar closers, no insertar cliente
    if (isset($_POST['actualizar_closers'])) {
        // Mantener los valores del formulario en sesión para no perderlos
        $_SESSION['form_data'] = $_POST;
        
        // Redirigir de nuevo al formulario
        header("Location: " . $_SERVER['PHP_SELF']);
        exit;
    }
    
    $nombre = trim($_POST['nombre']);
    $apepat = trim($_POST['apepat']);
    $apemat = trim($_POST['apemat'] ?? '');
    $numero1 = trim($_POST['numero1']);
    $numero2 = trim($_POST['numero2'] ?? '');
    $asunto = trim($_POST['asunto']);
    $descripcion = trim($_POST['descripcion'] ?? '');
    $usuario_closer = !empty($_POST['usuario_closer']) ? intval($_POST['usuario_closer']) : null;
    
    // Determinar sala_id basado en rol y permisos
    if ($_SESSION['rol'] === 'admin') {
        // Si es admin con acceso a todas las salas, usa el selector
        if (isset($usuario_actual['acceso_todas_salas']) && $usuario_actual['acceso_todas_salas'] == 1) {
            $sala_id = !empty($_POST['sala_id_admin']) ? intval($_POST['sala_id_admin']) : 
                       (isset($_SESSION['sala_id']) && intval($_SESSION['sala_id']) > 0 ? intval($_SESSION['sala_id']) : null);
        } else {
            // Si es admin con acceso limitado, usa solo la sala de sesión
            $sala_id = isset($_SESSION['sala_id']) && intval($_SESSION['sala_id']) > 0 ? intval($_SESSION['sala_id']) : null;
        }
    } else {
        // Si es usuario normal, usa solo el valor de sesión
        $sala_id = isset($_SESSION['sala_id']) && intval($_SESSION['sala_id']) > 0 ? intval($_SESSION['sala_id']) : null;
    }

    // Insertar cliente con usuario_id, usuario_closer y sala_id
    $sql = "INSERT INTO cliente (
        nombre, apepat, apemat, numero1, numero2, asunto, descripcion, 
        fecha_registro, usuario_id, usuario_closer, sala_id
    ) VALUES (
        :nombre, :apepat, :apemat, :numero1, :numero2, :asunto, :descripcion, 
        :fecha_registro, :usuario_id, :usuario_closer, :sala_id
    )";

    $stmt = $pdo->prepare($sql);

    try {
        $stmt->execute([
            ':nombre' => $nombre,
            ':apepat' => $apepat,
            ':apemat' => $apemat,
            ':numero1' => $numero1,
            ':numero2' => $numero2,
            ':asunto' => $asunto,
            ':descripcion' => $descripcion,
            ':fecha_registro' => date('Y-m-d H:i:s'),
            ':usuario_id' => $_SESSION['usuario_id'],
            ':usuario_closer' => $usuario_closer,
            ':sala_id' => $sala_id  // Puede ser NULL si no tiene sala
        ]);

        registrarAccion($pdo, 'INSERT', 'cliente', $pdo->lastInsertId(), [
            'nombre' => trim("$nombre $apepat $apemat"),
            'numero1' => $numero1,
            'asunto' => $asunto,
            'usuario_closer' => $usuario_closer,
            'sala_id' => $sala_id
        ]);

        $mensaje = "✅ Cliente registrado y asociado correctamente.";
        header("refresh:1;url=agencia.php");
    } catch (PDOException $e) {
        $mensaje = "❌ Error al guardar los datos: " . $e->getMessage();
        error_log("Error en nuevo cliente: " . $e->getMessage() . " - sala_id: " . ($sala_id ?? 'NULL'));
    }
}
?>

<!DOCTYPE html>
<html lang="es">

<head>
  <meta charset="UTF-8">
  <title>Registro Clientes</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
  <style>
    .closer-section, .sala-admin-section {
      display: none;
      transition: all 0.3s ease;
    }
    .closer-section.show, .sala-admin-section.show {
      display: block;
    }
    .debug-info {
      font-size: 0.8rem;
      color: #666;
      background: #f8f9fa;
      padding: 5px;
      border-radius: 3px;
      margin-top: 5px;
    }
    .badge-usuarios {
      font-size: 0.7rem;
      margin-left: 5px;
    }
  </style>
</head>

<body>
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
          <ul class="navbar-nav me-3">
            <li class="nav-item">
              <a class="nav-link text-dark" href="agencia.php" style="text-decoration: none;">
                🕵️ Agentes
              </a>
            </li>
          </ul>
        </ul>

        <span class="navbar-text text-muted me-3">
          <?= htmlspecialchars($_SESSION['apemat']) ?>
        </span>

        <a href="../logout.php" class="btn btn-sm btn-outline-danger">
          <i class="bi bi-box-arrow-right"></i> Salir
        </a>
      </div>
    </div>
  </nav>
  <div style="height: 70px;"></div>
  <div class="container py-4">
    <div class="row justify-content-center">
      <div class="col-md-6">
        <div class="card shadow-sm">
          <div class="card-header bg-primary text-white text-center py-2">
            <h5 class="mb-0">Contacto</h5>
          </div>
          <div class="card-body p-3">
            <?php if ($mensaje): ?>
              <div class="alert alert-info text-center p-2"><?= $mensaje ?></div>
            <?php endif; ?>
            
            <!-- Información de depuración -->
            <div class="debug-info mb-2">
              <small>
                Sala actual para filtro: <?= $sala_id_filtro ?? 'No especificada' ?> | 
                Usuarios encontrados: <?= count($usuarios) ?>
              </small>
            </div>
            
            <form method="POST" id="clienteForm">
              <div class="row g-2">
                <div class="col-6">
                  <input type="text" class="form-control form-control-sm" name="nombre" placeholder="Nombre(s)" 
                         value="<?= isset($_POST['nombre']) ? htmlspecialchars($_POST['nombre']) : '' ?>" required>
                </div>
                <div class="col-6">
                  <input type="text" class="form-control form-control-sm" name="apepat" placeholder="Apellido Paterno" 
                         value="<?= isset($_POST['apepat']) ? htmlspecialchars($_POST['apepat']) : '' ?>" required>
                </div>
                <div class="col-6">
                  <input type="text" class="form-control form-control-sm" name="apemat" placeholder="Apellido Materno"
                         value="<?= isset($_POST['apemat']) ? htmlspecialchars($_POST['apemat']) : '' ?>">
                </div>
                <div class="col-6">
                  <input type="text" class="form-control form-control-sm" name="numero1" placeholder="Número 1" maxlength="12"
                         value="<?= isset($_POST['numero1']) ? htmlspecialchars($_POST['numero1']) : '' ?>" required>
                </div>
                <div class="col-6">
                  <input type="text" class="form-control form-control-sm" name="numero2" placeholder="Número 2" maxlength="12"
                         value="<?= isset($_POST['numero2']) ? htmlspecialchars($_POST['numero2']) : '' ?>">
                </div>
                <div class="col-12">
                  <input type="text" class="form-control form-control-sm" name="asunto" placeholder="Asunto" maxlength="50"
                         value="<?= isset($_POST['asunto']) ? htmlspecialchars($_POST['asunto']) : '' ?>" required>
                </div>
                <div class="col-12">
                  <textarea class="form-control form-control-sm" name="descripcion" rows="3" placeholder="Descripción" maxlength="2000"><?= isset($_POST['descripcion']) ? htmlspecialchars($_POST['descripcion']) : '' ?></textarea>
                </div>
                
                <!-- Sección para administradores con acceso a todas las salas: seleccionar sala -->
                <?php if ($_SESSION['rol'] === 'admin' && isset($usuario_actual['acceso_todas_salas']) && $usuario_actual['acceso_todas_salas'] == 1): ?>
                <div class="col-12 mt-2">
                  <button type="button" id="btnSeleccionarSala" class="btn btn-sm btn-outline-info">
                    <i class="bi bi-building"></i> Asignar Sala
                  </button>
                </div>
                
                <div id="salaAdminSection" class="sala-admin-section col-12 mt-2">
                  <label class="form-label small mb-1">Seleccionar Sala:</label>
                  <select class="form-select form-select-sm" name="sala_id_admin" id="salaSelectAdmin">
                    <option value="">-- Usar sala de sesión (<?= isset($_SESSION['sala_id']) ? $_SESSION['sala_id'] : 'Sin sala' ?>) --</option>
                    <?php foreach ($salas as $sala): 
                      // Contar usuarios para esta sala (opcional)
                      $usuarios_sala = 0;
                      if ($sala_id_filtro == $sala['id']) {
                        $usuarios_sala = count($usuarios);
                      }
                    ?>
                      <option value="<?= $sala['id'] ?>" 
                        <?= (isset($sala_id_filtro) && $sala_id_filtro == $sala['id']) ? 'selected' : '' ?>>
                        <?= htmlspecialchars($sala['nombre']) ?> (ID: <?= $sala['id'] ?>)
                        <?php if ($usuarios_sala > 0): ?>
                          <span class="badge bg-success badge-usuarios"><?= $usuarios_sala ?> users</span>
                        <?php endif; ?>
                      </option>
                    <?php endforeach; ?>
                  </select>
                  <small class="text-muted">Seleccione una sala específica o deje en blanco para usar la sala de su sesión. 
                  Al cambiar la sala se actualizarán los closers disponibles.</small>
                </div>
                <?php endif; ?>
                
                <!-- Sección para agregar closer -->
                <div class="col-12 mt-2">
                  <button type="button" id="btnAgregarCloser" class="btn btn-sm btn-outline-secondary">
                    <i class="bi bi-plus-circle"></i> Agregar Closer
                  </button>
                </div>
                
                <div id="closerSection" class="closer-section col-12 mt-2">
                  <label class="form-label small mb-1">Seleccionar Closer:</label>
                  <select class="form-select form-select-sm" name="usuario_closer" id="selectCloser">
                    <option value="">-- Seleccione un closer --</option>
                    <?php 
                    if (!empty($usuarios)): 
                      foreach ($usuarios as $usuario): 
                        // Aplicar preg_replace para limpiar símbolos del apellido materno
                        $apematLimpio = $usuario['apemat'] ? preg_replace('/[^\p{L}\p{N}\s]/u', '', $usuario['apemat']) : '';
                        $nombreCompleto = trim($usuario['nombre'] . ' ' . $usuario['apepat'] . ' ' . $usuario['apemat']);
                    ?>
                      <option value="<?= $usuario['id'] ?>" 
                        <?= (isset($_POST['usuario_closer']) && $_POST['usuario_closer'] == $usuario['id']) ? 'selected' : '' ?>>
                        <?= htmlspecialchars($nombreCompleto) ?>
                      </option>
                    <?php 
                      endforeach;
                    else: 
                    ?>
                      <option value="">-- No hay closers disponibles para esta sala --</option>
                    <?php endif; ?>
                  </select>
                  <?php if (empty($usuarios) && $sala_id_filtro): ?>
                    <small class="text-danger">No se encontraron usuarios con acceso a la sala <?= $sala_id_filtro ?></small>
                  <?php endif; ?>
                </div>
                
                <div class="col-12 mt-3">
                  <button type="submit" class="btn btn-sm btn-primary w-100">Enviar</button>
                </div>
              </div>
            </form>
          </div>
        </div>
      </div>
    </div>
  </div>

  <script>
    document.addEventListener('DOMContentLoaded', function() {
      // Botón para agregar closer
      const btnAgregarCloser = document.getElementById('btnAgregarCloser');
      const closerSection = document.getElementById('closerSection');
      const salaSelectAdmin = document.getElementById('salaSelectAdmin');
      const clienteForm = document.getElementById('clienteForm');
      const selectCloser = document.getElementById('selectCloser');
      
      // Mostrar sección de closers si ya hay un closer seleccionado
      if (selectCloser && selectCloser.value !== '') {
        if (closerSection) {
          closerSection.classList.add('show');
        }
        if (btnAgregarCloser) {
          btnAgregarCloser.innerHTML = '<i class="bi bi-dash-circle"></i> Ocultar Closer';
        }
      }
      
      // Mostrar sección de sala si ya hay una sala seleccionada
      if (salaSelectAdmin && salaSelectAdmin.value !== '') {
        const salaAdminSection = document.getElementById('salaAdminSection');
        const btnSeleccionarSala = document.getElementById('btnSeleccionarSala');
        if (salaAdminSection) {
          salaAdminSection.classList.add('show');
        }
        if (btnSeleccionarSala) {
          btnSeleccionarSala.innerHTML = '<i class="bi bi-building-slash"></i> Ocultar Sala';
        }
      }
      
      if (btnAgregarCloser) {
        btnAgregarCloser.addEventListener('click', function() {
          closerSection.classList.toggle('show');
          
          // Cambiar el texto del botón
          if (closerSection.classList.contains('show')) {
            this.innerHTML = '<i class="bi bi-dash-circle"></i> Ocultar Closer';
          } else {
            this.innerHTML = '<i class="bi bi-plus-circle"></i> Agregar Closer';
          }
        });
      }
      
      // Botón para seleccionar sala (solo para admin con acceso a todas salas)
      const btnSeleccionarSala = document.getElementById('btnSeleccionarSala');
      const salaAdminSection = document.getElementById('salaAdminSection');
      
      if (btnSeleccionarSala) {
        btnSeleccionarSala.addEventListener('click', function() {
          salaAdminSection.classList.toggle('show');
          
          // Cambiar el texto del botón
          if (salaAdminSection.classList.contains('show')) {
            this.innerHTML = '<i class="bi bi-building-slash"></i> Ocultar Sala';
          } else {
            this.innerHTML = '<i class="bi bi-building"></i> Asignar Sala';
          }
        });
      }
      
      // Si cambia la selección de sala, enviar el formulario para actualizar los closers
      if (salaSelectAdmin) {
        salaSelectAdmin.addEventListener('change', function() {
          // Crear un input oculto para indicar que es solo para actualizar closers
          let updateInput = document.createElement('input');
          updateInput.type = 'hidden';
          updateInput.name = 'actualizar_closers';
          updateInput.value = '1';
          clienteForm.appendChild(updateInput);
          
          // Enviar el formulario
          clienteForm.submit();
        });
      }
      
      // Actualizar automáticamente los closers si no hay ninguno disponible
      if (selectCloser && selectCloser.options.length === 1 && selectCloser.options[0].value === '') {
        // Si solo hay la opción por defecto, ocultar la sección
        closerSection.classList.remove('show');
        if (btnAgregarCloser) {
          btnAgregarCloser.innerHTML = '<i class="bi bi-plus-circle"></i> Agregar Closer';
          btnAgregarCloser.disabled = true;
          btnAgregarCloser.title = 'No hay closers disponibles para esta sala';
          btnAgregarCloser.classList.add('text-muted');
        }
      }
    });
  </script>
</body>
</html>