<?php
// config/database.php

$host = '65.99.205.112';
$dbname = 'noreplyc_crmregistroventas';
$username = 'noreplyc_alexsanchez09';
$password = 'SWn_VrAc3K7lq;LS';

try {
    $conn = new mysqli($host, $username, $password, $dbname);
    
    // Verificar conexi贸n
    if ($conn->connect_error) {
        throw new Exception("Error de conexi贸n: " . $conn->connect_error);
    }
    
    // Establecer el charset
    $conn->set_charset("utf8");
    
} catch (Exception $e) {
    die("Error al conectar con la base de datos: " . $e->getMessage());
}
?>
