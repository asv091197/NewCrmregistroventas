<?php
// includes/db.php - Conexión PDO única para todo el sistema.
// Uso: require_once __DIR__ . '/../includes/db.php';  $pdo = conexionPDO();
require_once __DIR__ . '/db_config.php';

/**
 * Devuelve una conexión PDO reutilizable (una por petición y por base de datos).
 *
 * @param string|null $dbname Base de datos; por defecto DB_NAME
 * @throws PDOException si no se puede conectar
 */
function conexionPDO($dbname = null) {
    static $conexiones = [];
    $dbname = $dbname ?? DB_NAME;

    if (!isset($conexiones[$dbname])) {
        $conexiones[$dbname] = new PDO(
            "mysql:host=" . DB_HOST . ";dbname=" . $dbname . ";charset=utf8mb4",
            DB_USER,
            DB_PASS,
            [
                PDO::ATTR_ERRMODE          => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::ATTR_TIMEOUT          => 30,
            ]
        );
    }
    return $conexiones[$dbname];
}
