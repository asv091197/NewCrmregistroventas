<?php
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../session_config.php'; session_start();
date_default_timezone_set('America/Mexico_City');

// Forzar respuesta JSON incluso si hay errores
header('Content-Type: application/json');

try {
    $pdo = conexionPDO();

    // Obtener todos los agentes (usuarios)
    $agentes = $pdo->query("SELECT id, nombre, apepat, apemat FROM usuarios ORDER BY nombre, apepat")->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'success' => true,
        'agentes' => $agentes,
        'count' => count($agentes)
    ]);
} catch (PDOException $e) {
    error_log("Error en obtener_agentes.php: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'error' => 'Error en la base de datos',
        'agentes' => []
    ]);
}
