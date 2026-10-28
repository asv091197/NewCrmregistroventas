<?php
require_once __DIR__ . '/../includes/db.php';
// cron/rotar_logs.php
// Rotación de logs: borra de logs_login y logs_acciones los registros con más de N días (30 por defecto).
//
// Uso (solo línea de comandos / cron del hosting):
//   php rotar_logs.php              -> borra lo que tenga más de 30 días
//   php rotar_logs.php --dias=180   -> cambia el periodo de retención
//   php rotar_logs.php --simular    -> solo muestra cuántos registros se borrarían
//
// Ejemplo de cron en cPanel (todos los días a las 03:00):
//   0 3 * * * /usr/local/bin/php /home/USUARIO/public_html/NewCrmregistroventas/cron/rotar_logs.php

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Solo se puede ejecutar desde la línea de comandos.\n");
}

date_default_timezone_set('America/Mexico_City');
set_time_limit(0);

$opciones = getopt('', ['dias::', 'simular']);
$dias     = isset($opciones['dias']) ? (int)$opciones['dias'] : 30;
$simular  = isset($opciones['simular']);
$lote     = 5000;

// Evita borrar todo por un parámetro mal escrito
if ($dias < 30) {
    exit("El periodo mínimo de retención es de 30 días (se recibió: $dias).\n");
}

try {
    $conn = conexionPDO();
} catch (PDOException $e) {
    fwrite(STDERR, "Error de conexión: " . $e->getMessage() . "\n");
    exit(1);
}

$limite = date('Y-m-d 00:00:00', strtotime("-$dias days"));
$inicio = microtime(true);
echo "[" . date('Y-m-d H:i:s') . "] Rotación de logs: se eliminan registros anteriores a $limite ($dias días)" . ($simular ? " [SIMULACIÓN]" : "") . "\n";

if ($simular) {
    $stmt = $conn->prepare("SELECT COUNT(*) FROM logs_login WHERE fecha_intento < ?");
    $stmt->execute([$limite]);
    $logins = $stmt->fetchColumn();

    $stmt = $conn->prepare("
        SELECT COUNT(*) FROM logs_acciones la
        LEFT JOIN logs_login ll ON ll.id = la.login_id
        WHERE la.fecha < ? OR ll.fecha_intento < ?
    ");
    $stmt->execute([$limite, $limite]);
    $acciones = $stmt->fetchColumn();

    echo "Se borrarían $logins inicios de sesión y $acciones acciones.\n";
    exit(0);
}

$totalLogins   = 0;
$totalAcciones = 0;

try {
    // 1) Inicios de sesión antiguos junto con todas sus acciones, por lotes para no bloquear las tablas
    $stmtIds = $conn->prepare("SELECT id FROM logs_login WHERE fecha_intento < ? ORDER BY id LIMIT $lote");
    while (true) {
        $stmtIds->execute([$limite]);
        $ids = array_map('intval', $stmtIds->fetchAll(PDO::FETCH_COLUMN, 0));
        if (!$ids) break;

        $lista = implode(',', $ids);
        $conn->beginTransaction();
        $totalAcciones += $conn->exec("DELETE FROM logs_acciones WHERE login_id IN ($lista)");
        $totalLogins += $conn->exec("DELETE FROM logs_login WHERE id IN ($lista)");
        $conn->commit();
    }

    // 2) Acciones antiguas que hayan quedado sin login (login_id NULL o de sesiones ya borradas)
    $stmtAcc = $conn->prepare("DELETE FROM logs_acciones WHERE fecha < ? LIMIT $lote");
    do {
        $stmtAcc->execute([$limite]);
        $borradas = $stmtAcc->rowCount();
        $totalAcciones += $borradas;
    } while ($borradas > 0);
} catch (Throwable $e) {
    try { if ($conn->inTransaction()) $conn->rollBack(); } catch (Throwable $ignorar) {}
    fwrite(STDERR, "Error durante la rotación: " . $e->getMessage() . "\n");
    echo "Borrado antes del error: $totalLogins inicios de sesión y $totalAcciones acciones.\n";
    exit(1);
}

printf("Listo: %d inicios de sesión y %d acciones eliminados en %.1f s.\n", $totalLogins, $totalAcciones, microtime(true) - $inicio);
