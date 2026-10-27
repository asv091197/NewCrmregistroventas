<?php
require_once __DIR__ . '/../session_config.php'; session_start();
if (!isset($_SESSION['usuario_id']) || $_SESSION['rol'] !== 'admin') {
    header("Location: login.php");
    exit;
}



$host = '65.99.205.112';
$dbname = 'marketinpro';
$username = 'noreplyc_alexsanchez09';
$password = 'SWn_VrAc3K7lq;LS';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("Error de conexión: " . $e->getMessage());
}

$id = isset($_POST['id']) ? intval($_POST['id']) : 0;
$mensaje = '';

// Obtener datos actuales del usuario
if ($id > 0) {
    $stmt = $pdo->prepare("SELECT nombre,apepat,apemat, correo, rol FROM usuariosA WHERE id = ?");
    $stmt->execute([$id]);
    $usuario = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$usuario) {
        die("Usuario no encontrado.");
    }
} else {
    die("ID inválido.");
}

// Procesar formulario
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST["nombre"])) {
    $nombre = trim($_POST["nombre"]);
     $apepat = trim($_POST["apepat"]);
      $apemat = trim($_POST["apemat"]);
    $correo = trim($_POST["correo"]);
    $rol = $_POST["rol"];
    $password = $_POST["password"];

    if (!empty($nombre) && filter_var($correo, FILTER_VALIDATE_EMAIL) && !empty($rol)) {
        try {
            if (!empty($password)) {
                $hash = password_hash($password, PASSWORD_DEFAULT);
                $stmt = $pdo->prepare("UPDATE usuariosA SET nombre = ?,apepat = ?,apemat = ? ,correo = ?, rol = ?, contraseña = ? WHERE id = ?");
                $stmt->execute([$nombre,$apemat,$apemat, $correo, $rol, $hash, $id]);
            } else {
                $stmt = $pdo->prepare("UPDATE usuariosA SET nombre = ?,apepat = ?,apemat = ?, correo = ?, rol = ? WHERE id = ?");
                $stmt->execute([$nombre,$apemat,$apemat, $correo, $rol, $id]);
            }
            $mensaje = "<div class='alert alert-success'>Usuario actualizado correctamente.</div>";
            $usuario['nombre'] = $nombre;
            $usuario['correo'] = $correo;
            $usuario['rol'] = $rol;
        } catch (PDOException $e) {
            $mensaje = "<div class='alert alert-danger'>Error: " . $e->getMessage() . "</div>";
        }
    } else {
        $mensaje = "<div class='alert alert-warning'>Completa todos los campos correctamente.</div>";
    }
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Editar Usuario</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet">
</head>
<body class="bg-light">
    <nav class="navbar navbar-expand-lg navbar-light bg-white shadow-sm fixed-top border-bottom">
  <div class="container-fluid">
    
    <div class="navbar-brand fw-bold text-primary ms-auto d-flex align-items-center">
      <span class="me-3 text-muted"><?= htmlspecialchars($_SESSION['nombre']) ?></span>
      <a href="logout.php" class="btn btn-sm btn-outline-danger">
        <i class="bi bi-box-arrow-right"></i> Salir
      </a>
    </div>
  </div>
</nav>

<div style="height: 70px;"></div>

<div class="container mt-5">
    <div class="row justify-content-center">
        <div class="col-md-6 col-lg-5">
            <div class="card shadow">
                <div class="card-header bg-warning text-dark">
                    <h4>Editar Usuario</h4>
                </div>
                <div class="card-body">
                    <?= $mensaje ?>
                    <form method="POST">
                        <input type="hidden" name="id" value="<?= $id ?>">
                        <div class="mb-3">
                            <label for="nombre" class="form-label">Nombre</label>
                            <input type="text" class="form-control" id="nombre" name="nombre" value="<?= htmlspecialchars($usuario['nombre']) ?>" required>
                        </div>
                         <div class="mb-3">
                            <label for="apepat" class="form-label">Apellido Paterno</label>
                            <input type="text" class="form-control" id="apepat" name="apepat" value="<?= htmlspecialchars($usuario['apepat']) ?>" required>
                        </div>
                         <div class="mb-3">
                            <label for="nombre" class="form-label">Apellido Materno</label>
                            <input type="text" class="form-control" id="apemat" name="apemat" value="<?= htmlspecialchars($usuario['apemat']) ?>" required>
                        </div>
                        <div class="mb-3">
                            <label for="correo" class="form-label">Correo electrónico</label>
                            <input type="email" class="form-control" id="correo" name="correo" value="<?= htmlspecialchars($usuario['correo']) ?>" required>
                        </div>
                        <div class="mb-3">
                            <label for="rol" class="form-label">Rol de usuario</label>
                            <select class="form-select" id="rol" name="rol" required>
                                <option value="admin" <?= $usuario['rol'] == 'admin' ? 'selected' : '' ?>>Administrador</option>
                                <option value="usuario" <?= $usuario['rol'] == 'usuario' ? 'selected' : '' ?>>Usuario</option>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label for="password" class="form-label">Nueva contraseña (opcional)</label>
                            <input type="password" class="form-control" id="password" name="password">
                        </div>
                        <button type="submit" class="btn btn-primary w-100">Guardar Cambios</button>
                        <a href="usuarios.php" class="btn btn-secondary w-100 mt-2">Volver</a>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>
</body>
</html>
