<?php
// session_config.php - Configuración común de sesión (incluir ANTES de session_start()).
//
// La sesión dura 8 horas sin actividad. Las sesiones se guardan en una carpeta propia
// (/sesiones) para que el recolector de basura de PHP de otros scripts/sitios del servidor,
// que usa el valor por defecto de 1440 s (24 min), no borre nuestras sesiones.

const SESION_DURACION = 28800; // 8 horas en segundos

if (session_status() === PHP_SESSION_NONE) {
    $dirSesiones = __DIR__ . '/sesiones';
    if (!is_dir($dirSesiones)) {
        @mkdir($dirSesiones, 0700, true);
    }
    if (is_dir($dirSesiones) && is_writable($dirSesiones)) {
        session_save_path($dirSesiones);
    }
    ini_set('session.gc_maxlifetime', SESION_DURACION);
    ini_set('session.gc_probability', 1);
    ini_set('session.gc_divisor', 100);
}
