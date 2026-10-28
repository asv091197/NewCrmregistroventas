<?php
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../session_config.php'; session_start();
if (!isset($_SESSION['usuario_id']) || $_SESSION['rol'] !== 'usuario') {
    exit;
}

$pdo = conexionPDO('marketinpro');

$buscar = isset($_GET['buscar']) ? trim($_GET['buscar']) : '';

if (strlen($buscar) < 3) {
    exit; // No mostrar nada si la búsqueda es menor a 3 caracteres
}

$stmt = $pdo->prepare("SELECT id, nombre, numero1, numero2, asunto, descripcion FROM cliente WHERE nombre LIKE :buscar1 OR asunto LIKE :buscar2 OR descripcion LIKE :buscar3");
$stmt->execute(['buscar1' => "%$buscar%", 'buscar2' => "%$buscar%", 'buscar3' => "%$buscar%"]);
$clientes = $stmt->fetchAll(PDO::FETCH_ASSOC);

foreach ($clientes as $cliente) {
    echo "<tr>
        <td>{$cliente['id']}</td>
        <td>{$cliente['nombre']}</td>
        <td>{$cliente['numero1']}</td>
        <td>{$cliente['numero2']}</td>
        <td>{$cliente['asunto']}</td>
        <td>{$cliente['descripcion']}</td>
        <td><a href='editar_cliente.php?id={$cliente['id']}' class='btn btn-sm btn-warning'>Editar</a></td>
    </tr>";
}
?>
