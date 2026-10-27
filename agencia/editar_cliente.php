<?php
session_start();
if (!isset($_SESSION['usuario_id']) || $_SESSION['rol'] !== 'usuario') {
    header("Location: login.php");
    exit;
}


$pdo = new PDO("mysql:host=65.99.205.112;dbname=marketinpro;charset=utf8", "noreplyc_alexsanchez09", "SWn_VrAc3K7lq;LS");
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$id = isset($_GET['id']) ? intval($_GET['id']) : 0;
$mensaje = '';

if ($id > 0) {
    $stmt = $pdo->prepare("SELECT nombre, numero1, numero2, asunto, descripcion FROM cliente WHERE id = ?");
    $stmt->execute([$id]);
    $cliente = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$cliente) {
        die("Cliente no encontrado.");
    }
} else {
    die("ID inválido.");
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nombre = $_POST['nombre'];
    $numero1 = $_POST['numero1'];
    $numero2 = $_POST['numero2'];
    $asunto = $_POST['asunto'];
    $descripcion = $_POST['descripcion'];

    $stmt = $pdo->prepare("UPDATE cliente SET nombre = ?, numero1 = ?, numero2 = ?, asunto = ?, descripcion = ? WHERE id = ?");
    $stmt->execute([$nombre, $numero1, $numero2, $asunto, $descripcion, $id]);
    $mensaje = "Cliente actualizado correctamente.";

    // Recargar datos actualizados
    $stmt = $pdo->prepare("SELECT nombre, numero1, numero2, asunto, descripcion FROM cliente WHERE id = ?");
    $stmt->execute([$id]);
    $cliente = $stmt->fetch(PDO::FETCH_ASSOC);
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Editar Cliente</title>
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
<div class="container py-5">
    <div class="row justify-content-center">
        <div class="col-md-8">
            <div class="card shadow-sm">
                <div class="card-header bg-primary text-white text-center">
                    <h4>Editar Cliente</h4>
                </div>
                <div class="card-body">
                    <?php if ($mensaje): ?>
                        <div class="alert alert-success"><?= $mensaje ?></div>
                    <?php endif; ?>
                    <form method="POST">
                        <div class="mb-3">
                            <label for="nombre" class="form-label">Nombre completo</label>
                            <input type="text" class="form-control" id="nombre" name="nombre" value="<?= htmlspecialchars($cliente['nombre']) ?>" required>
                        </div>
                        <div class="mb-3">
                            <label for="numero1" class="form-label">Número 1</label>
                            <input type="text" class="form-control" id="numero1" name="numero1" value="<?= htmlspecialchars($cliente['numero1']) ?>" required>
                        </div>
                        <div class="mb-3">
                            <label for="numero2" class="form-label">Número 2</label>
                            <input type="text" class="form-control" id="numero2" name="numero2" value="<?= htmlspecialchars($cliente['numero2']) ?>" required>
                        </div>
                        <div class="mb-3">
                            <label for="asunto" class="form-label">Asunto</label>
                            <input type="text" class="form-control" id="asunto" name="asunto" value="<?= htmlspecialchars($cliente['asunto']) ?>" required>
                        </div>
                        <div class="mb-3">
                            <label for="descripcion" class="form-label">Descripción</label>
                            <textarea class="form-control" id="descripcion" name="descripcion" rows="4" required><?= htmlspecialchars($cliente['descripcion']) ?></textarea>
                        </div>
                        <div class="d-grid">
                            <button type="submit" class="btn btn-primary">Guardar Cambios</button>
                        </div>
                    </form>
                </div>
                <div class="card-footer text-muted text-center">
                    <a href="agencia.php" class="btn btn-sm btn-secondary mt-2">Volver a la lista</a>
                </div>
            </div>
        </div>
    </div>
</div>
</body>
</html>
