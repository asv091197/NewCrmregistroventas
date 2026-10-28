<?php
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../session_config.php'; session_start();
date_default_timezone_set('America/Mexico_City');

if (!isset($_SESSION['usuario_id']) || ($_SESSION['rol'] !== 'admin' && $_SESSION['rol'] !== 'usuario')) {
  header("Location: ../login.php");
  exit;
}


  try {
    $pdo = conexionPDO('marketinpro');

  $sql = "
    SELECT 
      uc.cliente_id,
      CONCAT(c.nombre, ' ', c.apepat, ' ', c.apemat) AS nombre_completo_cliente,
      MAX(CASE WHEN uc.rol_venta = 'Liner' THEN CONCAT(u.nombre, ' ', u.apepat, ' ', u.apemat) END) AS nombre_liner,
      MAX(CASE WHEN uc.rol_venta = 'Cerrador' THEN CONCAT(u.nombre, ' ', u.apepat, ' ', u.apemat) END) AS nombre_cerrador
    FROM 
      UsuarioCliente uc
    JOIN 
      usuariosa u ON uc.usuario_id = u.Id
    JOIN 
      cliente c ON uc.cliente_id = c.id
    GROUP BY 
      uc.cliente_id, nombre_completo_cliente
  ";

  $stmt = $pdo->query($sql);
  $clientes = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
  die("Error de conexión: " . $e->getMessage());
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <title>Lista de Clientes</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
    <nav class="navbar navbar-expand-lg navbar-light bg-white shadow-sm fixed-top border-bottom">
    <div class="container-fluid">
      <div class="d-flex align-items-center ms-auto">
        <?php if ($_SESSION['rol'] === 'admin'): ?>
          <ul class="navbar-nav me-3">
            <li class="nav-item">
              <a class="nav-link text-dark" href="../Usuarios/usuarios.php" style="text-decoration: none;">
                👤 Usuarios
              </a>
            </li>
          </ul>
        <?php endif; ?>
        <span class="navbar-text text-muted me-3">
          <?= htmlspecialchars($_SESSION['correo']) ?>
        </span>
        <a href="../logout.php" class="btn btn-sm btn-outline-danger">
          <i class="bi bi-box-arrow-right"></i> Salir
        </a>
      </div>
    </div>
  </nav>
    <div style="height: 70px;"></div>
  <div class="container mt-5">
    <h2 class="mb-4 text-center">Lista de Clientes</h2>
    <table class="table table-bordered table-hover">
      <thead class="table-dark">
        <tr>
         
          <th>Nombre Cliente</th>
          <th>Liner</th>
          <th>Cerrador</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($clientes as $cliente): ?>
          <tr>
          
            <td><?= htmlspecialchars($cliente['nombre_completo_cliente']) ?></td>
            <td><?= htmlspecialchars($cliente['nombre_liner'] ?? '—') ?></td>
            <td><?= htmlspecialchars($cliente['nombre_cerrador'] ?? '—') ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</body>
</html>
