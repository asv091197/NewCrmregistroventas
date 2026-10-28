<?php
require_once __DIR__ . '/db.php';
// includes/registro_acciones.php
// Registra en logs_acciones cada acción sobre la BD, ligada al login (logs_login.id) de la sesión.

/**
 * @param PDO|null     $db      Conexión existente; si es null se usa conexionPDO()
 * @param string       $accion  INSERT | UPDATE | DELETE | LOGOUT
 * @param array|string $detalle Datos relevantes (no incluir contraseñas)
 */
function registrarAccion($db, $accion, $tabla = null, $registroId = null, $detalle = null) {
    try {
        $s = $_SESSION ?? [];
        $script = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');
        $origen = $s['log_origen'] ?? (stripos($script, '/facturacion/') !== false ? 'facturacion' : 'agencia');

        $params = [
            $s['log_login_id'] ?? null,
            null,
            $s['usuario_id'] ?? null,
            $s['correo'] ?? null,
            $origen,
            $accion,
            $tabla,
            $registroId !== null ? (string)$registroId : null,
            is_array($detalle) ? json_encode($detalle, JSON_UNESCAPED_UNICODE) : $detalle,
            ipAccion(),
            null,
        ];

        $sql = "INSERT INTO logs_acciones
                    (login_id, session_id, usuario_id, correo, origen, accion, tabla, registro_id, detalle, ip, url, fecha)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())";

        $db = $db instanceof PDO ? $db : conexionPDO();
        $db->prepare($sql)->execute($params);
    } catch (Throwable $e) {
        // La bitácora nunca debe romper la operación principal
        error_log("registrarAccion: " . $e->getMessage());
    }
}

function ipAccion() {
    foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'HTTP_CLIENT_IP', 'REMOTE_ADDR'] as $h) {
        if (!empty($_SERVER[$h])) {
            $ip = trim(explode(',', $_SERVER[$h])[0]);
            if (filter_var($ip, FILTER_VALIDATE_IP)) return $ip;
        }
    }
    return $_SERVER['REMOTE_ADDR'] ?? null;
}
