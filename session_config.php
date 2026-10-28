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

// Login del módulo de la página actual: agencia/login.php, facturacion/login.php o login.php
function sesionLoginUrl() {
    $script = realpath($_SERVER['SCRIPT_FILENAME'] ?? '');
    if ($script === false || stripos($script, __DIR__) !== 0) {
        return null;
    }
    $rel = str_replace('\\', '/', substr($script, strlen(__DIR__) + 1));
    $scriptName = $_SERVER['SCRIPT_NAME'] ?? '';
    if (strcasecmp(substr($scriptName, -strlen($rel)), $rel) !== 0) {
        return null;
    }
    $base = substr($scriptName, 0, -strlen($rel));
    $modulo = strtolower(explode('/', $rel)[0]);
    return in_array($modulo, ['agencia', 'facturacion'], true)
        ? $base . $modulo . '/login.php'
        : $base . 'login.php';
}

// Si la página se queda abierta sin actividad, al vencer la sesión se redirige al login.
// Se inyecta un script antes de </body> en las respuestas HTML (no en JSON, Excel, PDF...).
// Cada petición (carga de página, fetch o AJAX) renueva la sesión en el servidor, así que
// también reinicia el contador; se comparte entre pestañas con localStorage.
function sesionInyectarRedireccion($html) {
    if (empty($_SESSION['usuario_id']) || stripos($html, '</body>') === false) {
        return $html;
    }
    foreach (headers_list() as $h) {
        if (stripos($h, 'Content-Type:') === 0 && stripos($h, 'text/html') === false) {
            return $html;
        }
    }
    $login = sesionLoginUrl();
    if ($login === null) {
        return $html;
    }
    $script = '<script>(function(){'
        . 'var DUR=' . (SESION_DURACION * 1000) . ',LOGIN=' . json_encode($login) . ',K="crm_ultima_actividad";'
        . 'function marcar(){var t=Date.now();try{localStorage.setItem(K,t);}catch(e){}window.__crmUlt=t;}'
        . 'function ultima(){var t=window.__crmUlt;try{t=Math.max(t,+localStorage.getItem(K)||0);}catch(e){}return t;}'
        . 'function revisar(){if(Date.now()-ultima()>DUR){location.href=LOGIN;}}'
        . 'marcar();'
        . 'if(window.fetch){var f=window.fetch;window.fetch=function(){marcar();return f.apply(this,arguments);};}'
        . 'var s=XMLHttpRequest.prototype.send;XMLHttpRequest.prototype.send=function(){marcar();return s.apply(this,arguments);};'
        . 'setInterval(revisar,30000);'
        . 'document.addEventListener("visibilitychange",function(){if(!document.hidden)revisar();});'
        . '})();</script>';
    $pos = strripos($html, '</body>');
    return substr($html, 0, $pos) . $script . substr($html, $pos);
}

if (strtolower(basename($_SERVER['SCRIPT_NAME'] ?? '')) !== 'login.php') {
    ob_start('sesionInyectarRedireccion');
}
