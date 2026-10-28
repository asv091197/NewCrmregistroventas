<?php
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../session_config.php'; session_start();
date_default_timezone_set('America/Mexico_City');
$rol = $_SESSION['rol'] ?? '';
if (!isset($_SESSION['usuario_id']) || ($rol !== 'admin' && $rol !== 'superadmin')) {
    header("Location: ../login.php");
    exit;
}

header('Content-Type: application/json');

$pdo = conexionPDO();
$id = isset($_GET['id']) ? intval($_GET['id']) : 0;
$stmt = $pdo->prepare("SELECT nombre, apepat, apemat, correo, rol, acceso_todas_salas, salas_permitidas FROM usuarios WHERE id = ?");
$stmt->execute([$id]);
$result = $stmt->fetch(PDO::FETCH_ASSOC);

echo json_encode($result ?: ['error' => 'Usuario no encontrado']);
