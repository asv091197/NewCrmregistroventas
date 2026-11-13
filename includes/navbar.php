<?php
/**
 * Navbar unificada para Agencia y Facturación.
 *
 * Uso (después de session_start(), dentro de <body>):
 *   <?php require __DIR__ . '/../includes/navbar.php'; ?>
 *
 * - Detecta el módulo (agencia / facturacion) por la carpeta de la página actual.
 * - "Usuarios" y "Salir" apuntan al módulo en el que se está.
 * - Los enlaces son absolutos desde la raíz del proyecto (calculada con SCRIPT_NAME),
 *   así funcionan en cualquier carpeta del servidor y con URLs reescritas como "agencia/Logs".
 * - Todo vive dentro de una función para no pisar variables de la página ($rol, $esAdmin, etc.).
 */

if (!function_exists('renderNavbarCrm')) {
    function renderNavbarCrm(): void
    {
        $rol = $_SESSION['rol'] ?? '';
        $esSuperadmin = $rol === 'superadmin';
        $esAdmin = $rol === 'admin' || $esSuperadmin;

        // Ruta de la página actual relativa a la raíz del proyecto (ej. "agencia/Agencia/agencia.php")
        $raiz = str_replace('\\', '/', (string)realpath(__DIR__ . '/..'));
        $script = str_replace('\\', '/', (string)realpath($_SERVER['SCRIPT_FILENAME'] ?? ''));
        $rutaActual = '';
        if ($raiz !== '' && $script !== '' && stripos($script, $raiz . '/') === 0) {
            $rutaActual = substr($script, strlen($raiz) + 1);
        }

        // Prefijo hacia la raíz del proyecto. Se usa la ruta absoluta (ej. "/" o "/NewCrmregistroventas/")
        // para que los enlaces funcionen aunque la URL visible sea distinta al archivo (ej. "agencia/Logs").
        $scriptName = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');
        if ($rutaActual !== '' && strcasecmp(substr($scriptName, -strlen('/' . $rutaActual)), '/' . $rutaActual) === 0) {
            $base = substr($scriptName, 0, strlen($scriptName) - strlen($rutaActual));
        } else {
            $base = str_repeat('../', substr_count($rutaActual, '/'));
        }

        // Módulo según la carpeta; si no se puede saber, según el login
        $carpeta = strtolower(explode('/', $rutaActual)[0] ?? '');
        if ($carpeta === 'agencia' || $carpeta === 'facturacion') {
            $modulo = $carpeta;
        } else {
            $modulo = ($_SESSION['log_origen'] ?? '') === 'facturacion' ? 'facturacion' : 'agencia';
        }
        $enAgencia = $modulo === 'agencia';

        $url = fn(string $ruta) => htmlspecialchars($base . $ruta);
        // Páginas que se abren con otra URL (ruta del enlace => archivo real)
        $alias = ['agencia/Logs' => 'agencia/ver_logs.php'];
        $esActivo = fn(string $ruta) => strcasecmp($ruta, $rutaActual) === 0
            || (isset($alias[$ruta]) && strcasecmp($alias[$ruta], $rutaActual) === 0);

        // Enlaces: [ruta, texto, ícono, visible]
        $enlaces = [
            ['agencia/opcionesAdmin.php', 'Dashboard', 'bi-speedometer2', $esSuperadmin || ($esAdmin && $enAgencia)],
            ['agencia/Agencia/agencia.php', 'Agentes', 'bi-person-badge', $enAgencia || $esSuperadmin],
            [$enAgencia ? 'agencia/Usuarios/usuarios.php' : 'facturacion/Usuarios/usuarios.php', 'Usuarios', 'bi-people', $esAdmin],
            ['facturacion/ingresarfacturacion.php', 'Nueva Venta', 'bi-plus-circle', !$enAgencia],
            ['facturacion/ventas.php', 'Ventas', 'bi-receipt', $esAdmin],
            ['facturacion/gestionar_plataformas.php', 'Plataformas', 'bi-credit-card', $esAdmin || !$enAgencia],
            ['agencia/Logs', 'Logs', 'bi-shield-lock', $esSuperadmin],
        ];

        // Inicio de la marca: la página principal del módulo para el rol
        if ($enAgencia) {
            $inicio = $esAdmin ? 'agencia/opcionesAdmin.php' : 'agencia/Agencia/agencia.php';
        } else {
            $inicio = $esAdmin ? 'facturacion/ventas.php' : 'facturacion/ingresarfacturacion.php';
        }

        $nombre = 'Usuario';
        foreach (['apemat', 'nombre', 'usuario'] as $clave) {
            if (!empty($_SESSION[$clave])) {
                $nombre = $_SESSION[$clave];
                break;
            }
        }

        $logout = $enAgencia ? 'agencia/logout.php' : 'facturacion/logout.php';
        $etiquetaModulo = $enAgencia ? 'Agencia' : 'Facturación';
        $claseModulo = $enAgencia ? 'bg-primary' : 'bg-success';
        ?>
        <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet">
        <style>
            .navbar-crm .navbar-brand { font-weight: 600; }
            .navbar-crm .badge-modulo { font-size: 0.65rem; vertical-align: middle; }
            .navbar-crm .nav-link { color: #212529; }
            .navbar-crm .nav-link.active { color: #0d6efd; font-weight: 600; }
            @media (max-width: 991.98px) {
                .navbar-crm .navbar-collapse { background-color: #fff; padding: 0 0.5rem 0.5rem; }
                .navbar-crm .navbar-brand { font-size: 1rem; }
            }
        </style>
        <nav class="navbar navbar-expand-lg navbar-light bg-white shadow-sm fixed-top border-bottom navbar-crm">
            <div class="container-fluid">
                <a class="navbar-brand" href="<?= $url($inicio) ?>">
                    <i class="bi bi-graph-up"></i> CRM Registro Ventas
                    <span class="badge <?= $claseModulo ?> badge-modulo ms-1"><?= $etiquetaModulo ?></span>
                </a>
                <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarCrm"
                    aria-controls="navbarCrm" aria-expanded="false" aria-label="Abrir menú">
                    <span class="navbar-toggler-icon"></span>
                </button>
                <div class="collapse navbar-collapse" id="navbarCrm">
                    <div class="d-lg-flex align-items-lg-center ms-auto py-2 py-lg-0">
                        <ul class="navbar-nav me-lg-3">
                            <?php foreach ($enlaces as [$ruta, $texto, $icono, $visible]): ?>
                                <?php if (!$visible) continue; ?>
                                <?php $activo = $esActivo($ruta); ?>
                                <li class="nav-item">
                                    <a class="nav-link fw-medium<?= $activo ? ' active' : '' ?>" href="<?= $url($ruta) ?>"<?= $activo ? ' aria-current="page"' : '' ?>>
                                        <i class="bi <?= $icono ?> me-1"></i><?= $texto ?>
                                    </a>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                        <span class="navbar-text text-muted me-3 d-block d-lg-inline">
                            <i class="bi bi-person-circle me-1"></i><?= htmlspecialchars($nombre) ?>
                        </span>
                        <a href="<?= $url($logout) ?>" class="btn btn-sm btn-outline-danger mt-2 mt-lg-0">
                            <i class="bi bi-box-arrow-right me-1"></i>Salir
                        </a>
                    </div>
                </div>
            </div>
        </nav>
        <?php
    }
}

renderNavbarCrm();
