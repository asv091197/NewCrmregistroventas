<?php
session_start();
require_once __DIR__ . '/../includes/registro_acciones.php';
if (isset($_SESSION['usuario_id'])) {
    registrarAccion(null, 'LOGOUT');
}
session_unset();
session_destroy();
header("Location: ./login.php");
exit;
