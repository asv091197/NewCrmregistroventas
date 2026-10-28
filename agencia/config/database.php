<?php
require_once __DIR__ . '/../../includes/db.php';
// config/database.php

try {
    $conn = conexionPDO();
} catch (PDOException $e) {
    error_log("Error al conectar con la base de datos: " . $e->getMessage());
    die("Error al conectar con la base de datos.");
}
?>
