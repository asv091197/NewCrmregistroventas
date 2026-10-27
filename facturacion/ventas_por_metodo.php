<?php
date_default_timezone_set('America/Mexico_City');

// Conexión
$conn = new mysqli("65.99.205.112", "noreplyc_alexsanchez09", "SWn_VrAc3K7lq;LS", "noreplyc_crmregistroventas");
$conn->set_charset("utf8mb4");

if ($conn->connect_error) {
  die("Error de conexión: " . $conn->connect_error);
}

// Paginación
$por_pagina = 10;
$pagina = isset($_GET['pagina']) ? max(1, intval($_GET['pagina'])) : 1;
$offset = ($pagina - 1) * $por_pagina;

// Total de registros
$total_sql = "SELECT COUNT(*) AS total FROM ventas";
$total_result = $conn->query($total_sql);
$total = $total_result->fetch_assoc()['total'];
$total_paginas = ceil($total / $por_pagina);

// Consulta principal con JOINs
$sql = "SELECT v.*, 
        CONCAT(v.cliente, ' ', v.apepat, ' ', COALESCE(v.apemat, '')) AS cliente_nombre,
        CONCAT(liner.nombre, ' ', liner.apepat, ' ', COALESCE(liner.apemat, '')) AS liner_nombre,
        CONCAT(c1.nombre, ' ', c1.apepat, ' ', COALESCE(c1.apemat, '')) AS closer1_nombre,
        CONCAT(c2.nombre, ' ', c2.apepat, ' ', COALESCE(c2.apemat, '')) AS closer2_nombre
        FROM ventas v
        LEFT JOIN usuarios liner ON v.liner_id = liner.id
        LEFT JOIN usuarios c1 ON v.closer1_id = c1.id
        LEFT JOIN usuarios c2 ON v.closer2_id = c2.id
        ORDER BY v.fecha DESC
        LIMIT ? OFFSET ?";

$stmt = $conn->prepare($sql);
$stmt->bind_param("ii", $por_pagina, $offset);
$stmt->execute();
$result = $stmt->get_result();
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <title>Ventas Registradas</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
  <div class="container mt-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
      <h3>Ventas Registradas</h3>
      <span class="badge bg-secondary">Total: <?= $total ?> ventas</span>
    </div>

    <!-- 🔽 Botón de exportación a Excel -->
    <!-- <a href="exportar_excel.php" class="btn btn-success mb-3">📤 Exportar a Excel</a> -->

    <?php if ($result->num_rows > 0): ?>
      <table class="table table-bordered table-hover">
        <thead class="table-dark">
          <tr>
            <th>Folio</th>
            <th>Fecha</th>
            <th>Ganancia Bruto</th>
            <th>Ganancia Neto</th>
            <th>Cliente</th>
            <th>Producto</th>
            <th>Liner</th>
            <th>Closer 1</th>
            <th>Closer 2</th>
            <th>Plataforma</th>
            <th>Banco</th>
          </tr>
        </thead>
        <tbody>
          <?php while ($row = $result->fetch_assoc()): ?>
            <tr>
              <td><?= htmlspecialchars($row['folio']) ?></td>
              <td><?= date('d/m/Y', strtotime($row['fecha'])) ?></td>
              <td>$<?= number_format($row['precio'], 2) ?></td>
              <td>$<?= number_format($row['precio_neto'], 2) ?></td>
              <td><?= htmlspecialchars($row['cliente_nombre']) ?></td>
              <td><?= htmlspecialchars($row['producto']) ?></td>
              <td><?= htmlspecialchars($row['liner_nombre']) ?></td>
              <td><?= htmlspecialchars($row['closer1_nombre']) ?></td>
              <td><?= htmlspecialchars($row['closer2_nombre']) ?></td>
              <td><?= htmlspecialchars($row['metodo_pago']) ?></td>
              <td><?= htmlspecialchars($row['banco']) ?></td>
            </tr>
          <?php endwhile; ?>
        </tbody>
      </table>

      <!-- Navegación -->
      <nav>
        <ul class="pagination justify-content-center">
          <?php for ($i = 1; $i <= $total_paginas; $i++): ?>
            <li class="page-item <?= $i === $pagina ? 'active' : '' ?>">
              <a class="page-link" href="?pagina=<?= $i ?>"><?= $i ?></a>
            </li>
          <?php endfor; ?>
        </ul>
      </nav>

    <?php else: ?>
      <div class="alert alert-warning">No hay ventas registradas.</div>
    <?php endif; ?>
  </div>
</body>
</html>
<?php
$stmt->close();
$conn->close();
?>
