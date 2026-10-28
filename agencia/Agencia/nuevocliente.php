<?php
require_once __DIR__ . '/../../includes/db.php';
header('Access-Control-Allow-Origin: *');
require_once __DIR__ . '/../../session_config.php'; session_start();
date_default_timezone_set('America/Mexico_City');
require_once __DIR__ . '/../../includes/registro_acciones.php';

// Restaurar datos del formulario si existen (tras cambiar de sala)
if (isset($_SESSION['form_data'])) {
    unset($_SESSION['form_data']['actualizar_closers']);
    $_POST = $_SESSION['form_data'] + $_POST;
    unset($_SESSION['form_data']);
}

// Validación de sesión y rol
$rol = $_SESSION['rol'] ?? '';
if (!isset($_SESSION['usuario_id']) || !in_array($rol, ['admin', 'superadmin', 'usuario'], true)) {
  header("Location: ../login.php");
  exit;
}
$esAdmin = $rol === 'admin' || $rol === 'superadmin';

try {
  $pdo = conexionPDO();
} catch (PDOException $e) {
  die("Error de conexión: " . $e->getMessage());
}

// Superadmin siempre tiene acceso a todas las salas; admin según su registro
$accesoTodasSalas = $rol === 'superadmin';
if ($rol === 'admin') {
    try {
        $stmt = $pdo->prepare("SELECT acceso_todas_salas FROM usuarios WHERE id = :id");
        $stmt->execute([':id' => $_SESSION['usuario_id']]);
        $accesoTodasSalas = (int)$stmt->fetchColumn() === 1;
    } catch (PDOException $e) {
        error_log("Error al obtener datos del usuario actual: " . $e->getMessage());
    }
}
$puedeElegirSala = $esAdmin && $accesoTodasSalas;

// Lista de salas solo para quien puede elegir sala
$salas = [];
if ($puedeElegirSala) {
    try {
        $salas = $pdo->query("SELECT id, nombre FROM salas WHERE activo = 1 ORDER BY nombre")->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Error al obtener salas: " . $e->getMessage());
    }
}

// Sala del cliente (y filtro de closers): la elegida si puede elegir, si no la de sesión
$salaSesion = intval($_SESSION['sala_id'] ?? 0) ?: null;
$sala_id = ($puedeElegirSala && !empty($_POST['sala_id_admin'])) ? intval($_POST['sala_id_admin']) : $salaSesion;

// ¿El usuario tiene acceso a la sala? salas_permitidas puede ser JSON ([1,3]) o texto ("1,3")
function usuarioTieneSala(array $usuario, int $salaId): bool {
    if ($usuario['acceso_todas_salas'] == 1) {
        return true;
    }
    $salas = json_decode($usuario['salas_permitidas'] ?? '', true);
    if (!is_array($salas)) {
        preg_match_all('/\d+/', (string)($usuario['salas_permitidas'] ?? ''), $m);
        $salas = $m[0];
    }
    return in_array($salaId, array_map('intval', $salas), true);
}

// Closers disponibles, filtrados por sala si hay una
$usuarios = [];
try {
    $usuarios = $pdo->query("SELECT id, nombre, apepat, apemat, salas_permitidas, acceso_todas_salas
                             FROM usuarios
                             WHERE rol = 'usuario' AND activo = 1
                             ORDER BY nombre, apepat")->fetchAll(PDO::FETCH_ASSOC);
    if ($sala_id) {
        $usuarios = array_values(array_filter($usuarios, fn($u) => usuarioTieneSala($u, $sala_id)));
    }
} catch (PDOException $e) {
    error_log("Error al obtener usuarios: " . $e->getMessage());
}

$mensaje = "";

// Procesamiento del formulario
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    // Si es solo para actualizar closers, guardar el formulario y recargar
    if (isset($_POST['actualizar_closers'])) {
        $_SESSION['form_data'] = $_POST;
        header("Location: " . $_SERVER['PHP_SELF']);
        exit;
    }

    $nombre = trim($_POST['nombre'] ?? '');
    $apepat = trim($_POST['apepat'] ?? '');
    $apemat = trim($_POST['apemat'] ?? '');
    $numero1 = trim($_POST['numero1'] ?? '');
    $numero2 = trim($_POST['numero2'] ?? '');
    $asunto = trim($_POST['asunto'] ?? '');
    $descripcion = trim($_POST['descripcion'] ?? '');
    $usuario_closer = !empty($_POST['usuario_closer']) ? intval($_POST['usuario_closer']) : null;

    try {
        $stmt = $pdo->prepare("INSERT INTO cliente (
            nombre, apepat, apemat, numero1, numero2, asunto, descripcion,
            fecha_registro, usuario_id, usuario_closer, sala_id
        ) VALUES (
            :nombre, :apepat, :apemat, :numero1, :numero2, :asunto, :descripcion,
            :fecha_registro, :usuario_id, :usuario_closer, :sala_id
        )");
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

// Valor previo de un campo del formulario, escapado para HTML
$old = fn($campo) => htmlspecialchars($_POST[$campo] ?? '');
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
  </style>
</head>

<body>
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
          <?= htmlspecialchars($_SESSION['apemat'] ?? '') ?>
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
              <div class="alert alert-info text-center p-2"><?= htmlspecialchars($mensaje) ?></div>
            <?php endif; ?>

            <!-- Información de depuración -->
            <div class="debug-info mb-2">
              <small>
                Sala actual para filtro: <?= $sala_id ?? 'No especificada' ?> |
                Usuarios encontrados: <?= count($usuarios) ?>
              </small>
            </div>

            <form method="POST" id="clienteForm">
              <div class="row g-2">
                <div class="col-6">
                  <input type="text" class="form-control form-control-sm" name="nombre" placeholder="Nombre(s)" value="<?= $old('nombre') ?>" required>
                </div>
                <div class="col-6">
                  <input type="text" class="form-control form-control-sm" name="apepat" placeholder="Apellido Paterno" value="<?= $old('apepat') ?>" required>
                </div>
                <div class="col-6">
                  <input type="text" class="form-control form-control-sm" name="apemat" placeholder="Apellido Materno" value="<?= $old('apemat') ?>">
                </div>
                <div class="col-6">
                  <input type="text" class="form-control form-control-sm" name="numero1" placeholder="Número 1" maxlength="12" value="<?= $old('numero1') ?>" required>
                </div>
                <div class="col-6">
                  <input type="text" class="form-control form-control-sm" name="numero2" placeholder="Número 2" maxlength="12" value="<?= $old('numero2') ?>">
                </div>
                <div class="col-12">
                  <input type="text" class="form-control form-control-sm" name="asunto" placeholder="Asunto" maxlength="50" value="<?= $old('asunto') ?>" required>
                </div>
                <div class="col-12">
                  <textarea class="form-control form-control-sm" name="descripcion" rows="3" placeholder="Descripción" maxlength="2000"><?= $old('descripcion') ?></textarea>
                </div>

                <!-- Sección para administradores con acceso a todas las salas: seleccionar sala -->
                <?php if ($puedeElegirSala): ?>
                <div class="col-12 mt-2">
                  <button type="button" id="btnSeleccionarSala" class="btn btn-sm btn-outline-info">
                    <i class="bi bi-building"></i> Asignar Sala
                  </button>
                </div>

                <div id="salaAdminSection" class="sala-admin-section col-12 mt-2">
                  <label class="form-label small mb-1">Seleccionar Sala:</label>
                  <select class="form-select form-select-sm" name="sala_id_admin" id="salaSelectAdmin">
                    <option value="">-- Usar sala de sesión (<?= $salaSesion ?? 'Sin sala' ?>) --</option>
                    <?php foreach ($salas as $sala): $seleccionada = $sala_id == $sala['id']; ?>
                      <option value="<?= $sala['id'] ?>" <?= $seleccionada ? 'selected' : '' ?>>
                        <?= htmlspecialchars($sala['nombre']) ?> (ID: <?= $sala['id'] ?>)<?= $seleccionada && $usuarios ? ' — ' . count($usuarios) . ' users' : '' ?>
                      </option>
                    <?php endforeach; ?>
                  </select>
                  <small class="text-muted">Seleccione una sala específica o deje en blanco para usar la sala de su sesión.
                  Al cambiar la sala se actualizarán los closers disponibles.</small>
                </div>
                <?php endif; ?>

                <!-- Sección para agregar closer -->
                <div class="col-12 mt-2">
                  <?php if ($usuarios): ?>
                  <button type="button" id="btnAgregarCloser" class="btn btn-sm btn-outline-secondary">
                    <i class="bi bi-plus-circle"></i> Agregar Closer
                  </button>
                  <?php else: ?>
                  <button type="button" class="btn btn-sm btn-outline-secondary text-muted" disabled title="No hay closers disponibles para esta sala">
                    <i class="bi bi-plus-circle"></i> Agregar Closer
                  </button>
                  <?php endif; ?>
                </div>

                <div id="closerSection" class="closer-section col-12 mt-2">
                  <label class="form-label small mb-1">Seleccionar Closer:</label>
                  <select class="form-select form-select-sm" name="usuario_closer" id="selectCloser">
                    <option value="">-- Seleccione un closer --</option>
                    <?php foreach ($usuarios as $usuario): ?>
                      <option value="<?= $usuario['id'] ?>" <?= ($_POST['usuario_closer'] ?? '') == $usuario['id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars(trim("{$usuario['nombre']} {$usuario['apepat']} {$usuario['apemat']}")) ?>
                      </option>
                    <?php endforeach; ?>
                  </select>
                  <?php if (!$usuarios && $sala_id): ?>
                    <small class="text-danger">No se encontraron usuarios con acceso a la sala <?= $sala_id ?></small>
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
      // Enlaza un botón con su sección plegable; la abre si su select ya tiene un valor
      function plegable(idBoton, idSeccion, idSelect, textoMostrar, textoOcultar) {
        const boton = document.getElementById(idBoton);
        const seccion = document.getElementById(idSeccion);
        const select = document.getElementById(idSelect);
        if (!boton || !seccion) return;

        const actualizarTexto = () => {
          boton.innerHTML = seccion.classList.contains('show') ? textoOcultar : textoMostrar;
        };
        if (select && select.value !== '') seccion.classList.add('show');
        actualizarTexto();
        boton.addEventListener('click', () => {
          seccion.classList.toggle('show');
          actualizarTexto();
        });
      }

      plegable('btnAgregarCloser', 'closerSection', 'selectCloser',
        '<i class="bi bi-plus-circle"></i> Agregar Closer', '<i class="bi bi-dash-circle"></i> Ocultar Closer');
      plegable('btnSeleccionarSala', 'salaAdminSection', 'salaSelectAdmin',
        '<i class="bi bi-building"></i> Asignar Sala', '<i class="bi bi-building-slash"></i> Ocultar Sala');

      // Al cambiar la sala, recargar el formulario para actualizar los closers
      const salaSelectAdmin = document.getElementById('salaSelectAdmin');
      if (salaSelectAdmin) {
        salaSelectAdmin.addEventListener('change', function() {
          const clienteForm = document.getElementById('clienteForm');
          const updateInput = document.createElement('input');
          updateInput.type = 'hidden';
          updateInput.name = 'actualizar_closers';
          updateInput.value = '1';
          clienteForm.appendChild(updateInput);
          clienteForm.submit();
        });
      }
    });
  </script>
</body>
</html>
