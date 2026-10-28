<?php
// session_config.php - Configuración común de sesión (incluir ANTES de session_start()).
//
// La sesión dura 8 horas sin actividad. Las sesiones se guardan en una carpeta propia
// (/sesiones) para que el recolector de basura de PHP de otros scripts/sitios del servidor,
// que usa el valor por defecto de 1440 s (24 min), no borre nuestras sesiones.

const SESION_DURACION = 28800; // 8 horas en segundos

// gc_maxlifetime solo dice qué puede borrar el recolector de basura, que corre al azar
// (1 de cada 100 peticiones); mientras no corra, la sesión vencida seguía siendo válida.
// Aquí se descarta al leerla si lleva más de SESION_DURACION sin actividad.
class SesionConVencimiento extends SessionHandler {
    #[\ReturnTypeWillChange]
    public function read($id) {
        $archivo = rtrim(session_save_path(), '/\\') . '/sess_' . $id;
        $modificado = @filemtime($archivo);
        if ($modificado !== false && time() - $modificado > SESION_DURACION) {
            @unlink($archivo);
        }
        // La cookie también dura SESION_DURACION, contada desde la última actividad
        if (!headers_sent()) {
            $p = session_get_cookie_params();
            setcookie(session_name(), $id, [
                'expires'  => time() + SESION_DURACION,
                'path'     => $p['path'],
                'domain'   => $p['domain'],
                'secure'   => $p['secure'],
                'httponly' => $p['httponly'],
                'samesite' => $p['samesite'],
            ]);
        }
        return parent::read($id);
    }
}

if (session_status() === PHP_SESSION_NONE) {
    $dirSesiones = __DIR__ . '/sesiones';
    if (!is_dir($dirSesiones)) {
        @mkdir($dirSesiones, 0700, true);
    }
    if (is_dir($dirSesiones) && is_writable($dirSesiones)) {
        session_save_path($dirSesiones);
    }
    ini_set('session.gc_maxlifetime', SESION_DURACION);
    ini_set('session.cookie_lifetime', SESION_DURACION);
    ini_set('session.gc_probability', 1);
    ini_set('session.gc_divisor', 100);
    session_set_save_handler(new SesionConVencimiento(), true);
}
