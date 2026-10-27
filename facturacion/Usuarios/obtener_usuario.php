<?php
session_start();
date_default_timezone_set('America/Mexico_City');
if (!isset($_SESSION['usuario_id']) || ($_SESSION['rol'] !== 'admin' && $_SESSION['rol'] !== 'usuario' && $_SESSION['rol'] !== 'superadmin')) {
    header("Location: login.php");
    exit;
}

$pdo = new PDO("mysql:host=65.99.205.112;dbname=noreplyc_crmregistroventas;charset=utf8", "noreplyc_alexsanchez09", "SWn_VrAc3K7lq;LS");
$id = isset($_GET['id']) ? intval($_GET['id']) : 0;
$stmt = $pdo->prepare("SELECT nombre, apepat, apemat, correo, rol, acceso_todas_salas, salas_permitidas FROM usuarios_facturacion WHERE id = ?");
$stmt->execute([$id]);
$result = $stmt->fetch(PDO::FETCH_ASSOC);

if ($result) {
    echo json_encode($result);
} else {
    echo json_encode(['error' => 'Usuario no encontrado']);
}
?>