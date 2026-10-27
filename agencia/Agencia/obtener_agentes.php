<?php
session_start();
date_default_timezone_set('America/Mexico_City');

// Conexión a la base de datos
$pdo = new PDO("mysql:host=65.99.205.112;dbname=noreplyc_crmregistroventas;charset=utf8", "noreplyc_alexsanchez09", "SWn_VrAc3K7lq;LS");
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// Forzar respuesta JSON incluso si hay errores
header('Content-Type: application/json');

try {
    // Obtener todos los agentes (usuarios)
    $stmt = $pdo->prepare("SELECT id, nombre, apepat, apemat FROM usuarios ORDER BY nombre, apepat");
    $stmt->execute();
    $agentes = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'success' => true,
        'agentes' => $agentes,
        'count' => count($agentes)
    ]);
} catch (PDOException $e) {
    echo json_encode([
        'success' => false,
        'error' => 'Error en la base de datos: ' . $e->getMessage(),
        'agentes' => []
    ]);
}
