<?php
// agencia/ver_logs.php
require_once __DIR__ . '/../session_config.php'; session_start();
date_default_timezone_set('America/Mexico_City');

if (!isset($_SESSION['usuario_id'])) {
    header("Location: ../login.php");
    exit;
}
// Solo el superadmin puede ver los logs
if (($_SESSION['rol'] ?? '') !== 'superadmin') {
    header("Location: opAdmin.php");
    exit;
}

$conn = new mysqli("65.99.205.112", "noreplyc_alexsanchez09", "SWn_VrAc3K7lq;LS", "noreplyc_crmregistroventas");
if ($conn->connect_error) die("Error de conexión");
$conn->set_charset("utf8mb4");

require_once __DIR__ . '/logs_filtros.php';

$sql  = "SELECT * FROM logs_login WHERE " . implode(' AND ', $where) . " ORDER BY fecha_intento DESC LIMIT 500";
$stmt = $conn->prepare($sql);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$logs = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Conteo de acciones por login exitoso
$conteoAcciones = [];
$idsExitosos = array_map('intval', array_column(array_filter($logs, fn($l) => $l['exito']), 'id'));
if ($idsExitosos) {
    try {
        $res = $conn->query("
            SELECT login_id, SUM(accion <> 'LOGOUT') AS acciones, MAX(accion = 'LOGOUT') AS cerro
            FROM logs_acciones
            WHERE login_id IN (" . implode(',', $idsExitosos) . ")
            GROUP BY login_id
        ");
        while ($r = $res->fetch_assoc()) {
            $conteoAcciones[$r['login_id']] = $r;
        }
        // Última acción (sin contar LOGOUT) de cada login
        $res = $conn->query("
            SELECT la.login_id, la.accion, la.tabla, la.fecha
            FROM logs_acciones la
            JOIN (
                SELECT login_id, MAX(id) AS id
                FROM logs_acciones
                WHERE login_id IN (" . implode(',', $idsExitosos) . ") AND accion <> 'LOGOUT'
                GROUP BY login_id
            ) u ON u.id = la.id
        ");
        while ($r = $res->fetch_assoc()) {
            $conteoAcciones[$r['login_id']]['ultima'] = $r;
        }
    } catch (Throwable $e) {
        error_log("ver_logs conteo acciones: " . $e->getMessage());
    }
}

// Línea de tiempo de una sesión: login -> acciones -> logout
$sesionId = (int)($_GET['sesion'] ?? 0);
$sesion   = null;
$acciones = [];
if ($sesionId > 0) {
    $stmt = $conn->prepare("SELECT * FROM logs_login WHERE id = ?");
    $stmt->bind_param("i", $sesionId);
    $stmt->execute();
    $sesion = $stmt->get_result()->fetch_assoc();
    try {
        $stmt = $conn->prepare("SELECT * FROM logs_acciones WHERE login_id = ? ORDER BY fecha, id");
        $stmt->bind_param("i", $sesionId);
        $stmt->execute();
        $acciones = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    } catch (Throwable $e) {
        error_log("ver_logs acciones sesión: " . $e->getMessage());
    }
}

// Paginación de la línea de tiempo (10 acciones por página)
$porPagina     = 10;
$totalPaginas  = max(1, (int)ceil(count($acciones) / $porPagina));
$pagina        = min(max(1, (int)($_GET['pagina'] ?? 1)), $totalPaginas);
$accionesPagina = array_slice($acciones, ($pagina - 1) * $porPagina, $porPagina);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light only">
    <title>Logs de Inicio de Sesión</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        :root {
            color-scheme: light only;
            --bg: #eef2f7;
            --bg-accent: #f8fafc;
            --card: #ffffff;
            --text: #1f2937;
            --text-strong: #111827;
            --muted: #6b7280;
            --muted-light: #9ca3af;
            --border: #e5e7eb;
            --border-strong: #d1d5db;
            --primary: #4f46e5;
            --primary-hover: #4338ca;
            --primary-soft: #eef2ff;
            --primary-text: #4338ca;
            --success-soft: #dcfce7;
            --success-text: #166534;
            --danger-soft: #fee2e2;
            --danger-text: #991b1b;
            --warning-soft: #fef3c7;
            --warning-text: #92400e;
            --info-soft: #cffafe;
            --info-text: #155e75;
            --shadow-sm: 0 1px 2px rgba(16,24,40,.06), 0 1px 3px rgba(16,24,40,.08);
            --shadow-md: 0 4px 6px -1px rgba(16,24,40,.06), 0 2px 4px -2px rgba(16,24,40,.06);
            --shadow-lg: 0 10px 25px -5px rgba(16,24,40,.10);
        }
        * { box-sizing: border-box; }

        html, body {
            background: var(--bg) !important;
            color: var(--text) !important;
        }
        body {
            padding: 24px;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
            min-height: 100vh;
            /* Fondo con un degradado muy sutil para dar profundidad */
            background:
                radial-gradient(1200px 600px at 100% -10%, #e0e7ff 0%, transparent 60%),
                radial-gradient(900px 500px at -10% 110%, #dbeafe 0%, transparent 55%),
                var(--bg) !important;
            background-attachment: fixed;
        }
        .app-shell { max-width: 1400px; margin: 0 auto; }

        /* ===== Header ===== */
        .page-header {
            display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between;
            gap: 16px; margin-bottom: 24px;
        }
        .page-title { display: flex; align-items: center; gap: 14px; }
        .page-title .icon-box {
            width: 48px; height: 48px; border-radius: 14px;
            background: linear-gradient(135deg, #6366f1, #8b5cf6);
            color: #fff; display: grid; place-items: center; font-size: 1.4rem;
            box-shadow: 0 8px 20px -6px rgba(99,102,241,.55);
        }
        .page-title h1 { margin: 0; font-size: 1.35rem; font-weight: 700; letter-spacing: -.02em; color: var(--text-strong); }
        .page-title p { margin: 2px 0 0; color: var(--muted); font-size: .85rem; }
        .btn-ghost {
            background: var(--card); color: var(--text); border: 1px solid var(--border);
            border-radius: 10px; padding: 8px 14px; font-size: .85rem; font-weight: 500;
            text-decoration: none; display: inline-flex; align-items: center; gap: 6px;
            transition: all .18s ease; box-shadow: var(--shadow-sm);
        }
        .btn-ghost:hover { background: var(--primary-soft); color: var(--primary-text); border-color: var(--primary); }

        /* ===== Panels ===== */
        .panel {
            background: var(--card);
            border: 1px solid var(--border);
            border-radius: 16px;
            box-shadow: var(--shadow-sm);
            overflow: hidden;
        }
        .panel-body { padding: 20px; }
        .panel-header {
            padding: 16px 20px; border-bottom: 1px solid var(--border);
            display: flex; align-items: center; justify-content: space-between; gap: 12px; flex-wrap: wrap;
            background: var(--bg-accent);
        }
        .panel-header h2 {
            margin: 0; font-size: .85rem; font-weight: 700; text-transform: uppercase;
            letter-spacing: .06em; color: var(--muted); display: flex; align-items: center; gap: 8px;
        }

        /* ===== Forms ===== */
        .form-label { font-size: .72rem; font-weight: 700; color: var(--muted); text-transform: uppercase; letter-spacing: .05em; margin-bottom: 6px; }
        .form-control, .form-select {
            background: var(--card) !important; color: var(--text) !important; border: 1px solid var(--border);
            border-radius: 10px; padding: 8px 12px; font-size: .875rem; transition: all .15s;
        }
        .form-control:focus, .form-select:focus {
            border-color: var(--primary); box-shadow: 0 0 0 3px rgba(79,70,229,.15);
            background: var(--card) !important; color: var(--text) !important;
        }
        .form-control::placeholder { color: var(--muted-light); }
        .btn-primary-soft {
            background: var(--primary); color: #fff; border: none; border-radius: 10px;
            padding: 9px 18px; font-weight: 600; font-size: .875rem;
            display: inline-flex; align-items: center; gap: 6px; transition: all .18s;
            box-shadow: 0 4px 12px -4px rgba(79,70,229,.5);
        }
        .btn-primary-soft:hover { background: var(--primary-hover); color: #fff; }
        .btn-outline-soft {
            background: var(--card); color: var(--muted); border: 1px solid var(--border);
            border-radius: 10px; padding: 9px 16px; font-size: .875rem; font-weight: 500;
            display: inline-flex; align-items: center; gap: 6px; transition: all .18s;
            text-decoration: none;
        }
        .btn-outline-soft:hover { color: var(--text); border-color: var(--border-strong); background: var(--bg-accent); }

        .export-btns { display: flex; gap: 8px; flex-wrap: wrap; }
        .btn-export {
            border-radius: 10px; padding: 8px 14px; font-size: .82rem; font-weight: 600;
            display: inline-flex; align-items: center; gap: 6px; border: 1px solid transparent;
            text-decoration: none; transition: all .18s;
        }
        .btn-export-excel { background: var(--success-soft); color: var(--success-text); }
        .btn-export-excel:hover { background: #bbf7d0; color: var(--success-text); }
        .btn-export-pdf { background: var(--danger-soft); color: var(--danger-text); }
        .btn-export-pdf:hover { background: #fecaca; color: var(--danger-text); }

        /* ===== Table ===== */
        .tabla-logs { max-height: 640px; overflow: auto; background: var(--card); }
        .tabla-logs table { width: 100%; border-collapse: separate; border-spacing: 0; }
        .tabla-logs thead th {
            position: sticky; top: 0; z-index: 2;
            background: var(--bg-accent); color: var(--muted);
            font-size: .7rem; font-weight: 700; text-transform: uppercase; letter-spacing: .06em;
            padding: 12px 14px; border-bottom: 1px solid var(--border); white-space: nowrap;
        }
        .tabla-logs tbody td {
            padding: 12px 14px; font-size: .85rem;
            border-bottom: 1px solid var(--border); vertical-align: middle;
            background: var(--card);
        }
        .tabla-logs tbody tr { transition: background .15s; cursor: pointer; }
        .tabla-logs tbody tr:hover td { background: var(--primary-soft); }
        .tabla-logs tbody tr:last-child td { border-bottom: none; }
        .correo-cell { overflow-wrap: anywhere; min-width: 160px; font-weight: 500; color: var(--text-strong); }
        .avatar {
            display: inline-grid; place-items: center;
            width: 30px; height: 30px; border-radius: 50%;
            background: linear-gradient(135deg, #6366f1, #8b5cf6);
            color: #fff; font-weight: 700; font-size: .78rem; margin-right: 8px;
            flex-shrink: 0;
        }
        .correo-wrap { display: flex; align-items: center; }
        .badge-soft {
            display: inline-flex; align-items: center; gap: 4px;
            padding: 4px 10px; border-radius: 999px; font-size: .72rem; font-weight: 600;
            white-space: nowrap;
        }
        .badge-ok { background: var(--success-soft); color: var(--success-text); }
        .badge-fail { background: var(--danger-soft); color: var(--danger-text); }
        .badge-origen-agencia { background: var(--primary-soft); color: var(--primary-text); }
        .badge-origen-facturacion { background: var(--warning-soft); color: var(--warning-text); }
        .chip {
            display: inline-flex; align-items: center; gap: 4px;
            padding: 4px 10px; border-radius: 8px; font-size: .72rem; font-weight: 600;
            text-decoration: none; border: 1px solid transparent; transition: all .15s;
        }
        .chip-acciones {
            background: var(--primary-soft); color: var(--primary-text); border-color: rgba(79,70,229,.2);
        }
        .chip-acciones:hover { background: var(--primary); color: #fff; border-color: var(--primary); }
        .accion-badge {
            display: inline-block; padding: 3px 9px; border-radius: 6px;
            font-size: .7rem; font-weight: 700; letter-spacing: .02em;
        }
        .text-muted-sm { color: var(--muted); font-size: .75rem; }
        .ip-code {
            font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
            font-size: .78rem; background: var(--bg-accent); padding: 2px 8px; border-radius: 6px;
            color: var(--text); border: 1px solid var(--border);
        }
        .empty-state { text-align: center; padding: 60px 20px; color: var(--muted); }
        .empty-state i { font-size: 2.5rem; opacity: .4; display: block; margin-bottom: 10px; }

        /* ===== Session summary ===== */
        .sesion-header { display: flex; flex-wrap: wrap; align-items: center; gap: 16px; }
        .sesion-avatar {
            width: 56px; height: 56px; border-radius: 16px;
            background: linear-gradient(135deg, #6366f1, #8b5cf6);
            color: #fff; display: grid; place-items: center;
            font-size: 1.4rem; font-weight: 700; flex-shrink: 0;
            box-shadow: 0 8px 20px -6px rgba(99,102,241,.55);
        }
        .sesion-info h2 { margin: 0; font-size: 1.1rem; font-weight: 700; overflow-wrap: anywhere; color: var(--text-strong); }
        .sesion-meta {
            display: flex; flex-wrap: wrap; gap: 8px 16px; margin-top: 6px;
            font-size: .8rem; color: var(--muted);
        }
        .sesion-meta span { display: inline-flex; align-items: center; gap: 4px; }
        .sesion-meta code {
            background: var(--bg-accent); padding: 1px 6px; border-radius: 4px;
            border: 1px solid var(--border); color: var(--text);
        }

        /* ===== Timeline ===== */
        .timeline { position: relative; margin: 8px 0 0; padding: 0; list-style: none; }
        .timeline::before {
            content: ''; position: absolute; left: 15px; top: 8px; bottom: 8px;
            width: 2px; background: var(--border);
        }
        .timeline-item { position: relative; padding-left: 44px; padding-bottom: 18px; }
        .timeline-item:last-child { padding-bottom: 0; }
        .timeline-dot {
            position: absolute; left: 6px; top: 2px;
            width: 20px; height: 20px; border-radius: 50%;
            background: var(--dot, var(--muted)); color: #fff;
            display: grid; place-items: center; font-size: .65rem;
            box-shadow: 0 0 0 4px var(--card);
        }
        .timeline-card {
            background: var(--bg-accent); border: 1px solid var(--border);
            border-radius: 12px; padding: 12px 14px; transition: all .15s;
        }
        .timeline-card:hover { box-shadow: var(--shadow-md); border-color: var(--border-strong); background: var(--card); }
        .timeline-time { font-size: .72rem; color: var(--muted); font-weight: 500; margin-bottom: 4px; }
        .timeline-title { font-size: .9rem; font-weight: 600; display: flex; align-items: center; gap: 8px; flex-wrap: wrap; color: var(--text-strong); }
        .detalle-accion {
            font-size: .78rem; color: var(--muted); margin-top: 6px;
            overflow-wrap: anywhere; line-height: 1.5;
        }
        .detalle-accion code { background: var(--card); padding: 1px 5px; border-radius: 4px; font-size: .72rem; border: 1px solid var(--border); }

        /* ===== Pagination ===== */
        .pagination { gap: 4px; }
        .page-link {
            border: 1px solid var(--border) !important; border-radius: 8px !important;
            color: var(--text); background: var(--card); font-size: .8rem; padding: 5px 11px;
            transition: all .15s;
        }
        .page-link:hover { background: var(--primary-soft); color: var(--primary-text); border-color: var(--primary) !important; }
        .page-item.active .page-link { background: var(--primary) !important; border-color: var(--primary) !important; color: #fff; }
        .page-item.disabled .page-link { opacity: .4; }

        /* ===== Responsive ===== */
        @media (max-width: 767.98px) {
            body { padding: 12px; }
            .panel-body { padding: 14px; }
            .page-title h1 { font-size: 1.1rem; }
            .page-title .icon-box { width: 40px; height: 40px; font-size: 1.15rem; }
            .tabla-logs { max-height: 65vh; }
            .tabla-logs thead th, .tabla-logs tbody td { padding: 8px 10px; font-size: .78rem; }
            .timeline-item { padding-left: 38px; }
            .sesion-avatar { width: 44px; height: 44px; font-size: 1.1rem; }
        }
    </style>
</head>
<body>
<div class="app-shell">

    <!-- ===== Header ===== -->
    <div class="page-header">
        <div class="page-title">
            <div class="icon-box"><i class="bi bi-shield-lock"></i></div>
            <div>
                <h1>Logs de Inicio de Sesión</h1>
                <p><?= $sesionId > 0 ? 'Detalle de la sesión' : 'Auditoría de accesos y actividad' ?></p>
            </div>
        </div>
        <?php if ($sesionId > 0): ?>
            <?php $diaSesion = $sesion ? date('Y-m-d', strtotime($sesion['fecha_intento'])) : ''; ?>
            <a href="?fecha_inicio=<?= $diaSesion ?>&fecha_fin=<?= $diaSesion ?>" class="btn-ghost">
                <i class="bi bi-arrow-left"></i> Volver a la lista
            </a>
        <?php else: ?>
            <a href="opAdmin.php" class="btn-ghost">
                <i class="bi bi-arrow-left"></i> Volver al Dashboard
            </a>
        <?php endif; ?>
    </div>

<?php if ($sesionId > 0): ?>
    <?php if (!$sesion): ?>
        <div class="panel"><div class="panel-body">
            <div class="empty-state">
                <i class="bi bi-exclamation-triangle"></i>
                No se encontró el inicio de sesión #<?= $sesionId ?>.
            </div>
        </div></div>
    <?php else: ?>
        <?php
        $hexes  = ['success' => '#22c55e', 'primary' => '#6366f1', 'danger' => '#ef4444', 'secondary' => '#64748b', 'info' => '#0ea5e9', 'warning' => '#f59e0b'];
        $ultima  = end($acciones) ?: null;
        $cerro   = $ultima && $ultima['accion'] === 'LOGOUT';
        $fin     = $ultima ? strtotime($ultima['fecha']) : strtotime($sesion['fecha_intento']);
        $duracion = $fin - strtotime($sesion['fecha_intento']);
        $numAcciones = count(array_filter($acciones, fn($a) => $a['accion'] !== 'LOGOUT'));
        $inicial = strtoupper(mb_substr($sesion['correo'], 0, 1));
        ?>

        <!-- ===== Resumen de sesión ===== -->
        <div class="panel mb-3">
            <div class="panel-body">
                <div class="sesion-header">
                    <div class="sesion-avatar"><?= htmlspecialchars($inicial) ?></div>
                    <div class="sesion-info flex-grow-1">
                        <h2>
                            <?= htmlspecialchars($sesion['correo']) ?>
                            <span class="badge-soft badge-origen-<?= $sesion['origen'] === 'facturacion' ? 'facturacion' : 'agencia' ?>">
                                <i class="bi bi-<?= $sesion['origen'] === 'facturacion' ? 'cash-coin' : 'people' ?>"></i>
                                <?= htmlspecialchars(ucfirst($sesion['origen'])) ?>
                            </span>
                        </h2>
                        <div class="sesion-meta">
                            <span><i class="bi bi-hash"></i> Sesión #<?= (int)$sesion['id'] ?></span>
                            <span><i class="bi bi-activity"></i> <?= $numAcciones ?> acciones</span>
                            <span><i class="bi bi-stopwatch"></i> <?= gmdate('H:i:s', max(0, $duracion)) ?></span>
                            <span><i class="bi bi-geo-alt"></i> <?= htmlspecialchars(trim(($sesion['pais'] ?? '') . ' ' . ($sesion['ciudad'] ?? '')) ?: '—') ?></span>
                            <span><i class="bi bi-pc-display"></i> <?= htmlspecialchars($sesion['dispositivo'] ?? '—') ?> / <?= htmlspecialchars($sesion['navegador'] ?? '—') ?></span>
                            <span><i class="bi bi-router"></i> <code><?= htmlspecialchars($sesion['ip']) ?></code></span>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- ===== Timeline ===== -->
        <div class="panel">
            <div class="panel-header">
                <h2><i class="bi bi-clock-history"></i> Línea de tiempo</h2>
                <span class="text-muted-sm"><?= count($acciones) ?> eventos registrados</span>
            </div>
            <div class="panel-body">
                <ol class="timeline">
                    <?php if ($pagina === 1): ?>
                    <li class="timeline-item">
                        <span class="timeline-dot" style="--dot:<?= $sesion['exito'] ? '#22c55e' : '#ef4444' ?>">
                            <i class="bi bi-<?= $sesion['exito'] ? 'box-arrow-in-right' : 'x-lg' ?>"></i>
                        </span>
                        <div class="timeline-card">
                            <div class="timeline-time"><?= date('d/m/Y H:i:s', strtotime($sesion['fecha_intento'])) ?></div>
                            <div class="timeline-title">
                                <?php if ($sesion['exito']): ?>
                                    <span class="badge-soft badge-ok"><i class="bi bi-check-circle"></i> LOGIN exitoso</span>
                                <?php else: ?>
                                    <span class="badge-soft badge-fail"><i class="bi bi-x-circle"></i> LOGIN fallido</span>
                                    <span class="text-muted-sm"><?= htmlspecialchars($sesion['motivo_fallo'] ?? '') ?></span>
                                <?php endif; ?>
                            </div>
                            <div class="detalle-accion">
                                IP <code><?= htmlspecialchars($sesion['ip']) ?></code>
                                · <?= htmlspecialchars(trim(($sesion['pais'] ?? '') . ' ' . ($sesion['ciudad'] ?? '')) ?: '—') ?>
                                · <?= htmlspecialchars($sesion['dispositivo'] ?? '') ?> / <?= htmlspecialchars($sesion['navegador'] ?? '') ?>
                            </div>
                        </div>
                    </li>
                    <?php endif; ?>

                    <?php if ($numAcciones === 0 && $pagina === 1): ?>
                        <li class="timeline-item">
                            <span class="timeline-dot" style="--dot:#94a3b8"><i class="bi bi-info"></i></span>
                            <div class="timeline-card">
                                <div class="text-muted-sm fst-italic"><i class="bi bi-info-circle"></i> No hay acciones registradas en esta sesión</div>
                            </div>
                        </li>
                    <?php endif; ?>

                    <?php foreach ($accionesPagina as $a): $c = $colores[$a['accion']] ?? 'secondary'; ?>
                        <li class="timeline-item">
                            <span class="timeline-dot" style="--dot:<?= $hexes[$c] ?? '#64748b' ?>">
                                <i class="bi bi-<?= $a['accion'] === 'LOGOUT' ? 'box-arrow-right' : 'pencil-square' ?>"></i>
                            </span>
                            <div class="timeline-card">
                                <div class="timeline-time"><?= date('d/m/Y H:i:s', strtotime($a['fecha'])) ?></div>
                                <div class="timeline-title">
                                    <?php if ($a['accion'] === 'LOGOUT'): ?>
                                        <span class="badge-soft" style="background:var(--bg);color:var(--muted)"><i class="bi bi-box-arrow-right"></i> LOGOUT</span>
                                        <span class="text-muted-sm">Cierre de sesión</span>
                                    <?php else: ?>
                                        <span class="accion-badge" style="background:<?= $hexes[$c] ?? '#64748b' ?>22;color:<?= $hexes[$c] ?? '#64748b' ?>">
                                            <?= htmlspecialchars($etiquetas[$a['accion']] ?? $a['accion']) ?>
                                        </span>
                                        <?php if ($a['registro_id'] !== null): ?>
                                            <span class="text-muted-sm">Registro #<?= htmlspecialchars($a['registro_id']) ?></span>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </div>
                                <?php if (!empty($a['detalle'])): ?>
                                    <div class="detalle-accion"><?= formatearDetalle($a['detalle']) ?></div>
                                <?php endif; ?>
                            </div>
                        </li>
                    <?php endforeach; ?>

                    <?php if ($sesion['exito'] && !$cerro && $pagina === $totalPaginas): ?>
                        <li class="timeline-item">
                            <span class="timeline-dot" style="--dot:#94a3b8"><i class="bi bi-hourglass-split"></i></span>
                            <div class="timeline-card">
                                <div class="text-muted-sm fst-italic">Sin cierre de sesión registrado (expiró o se cerró el navegador)</div>
                            </div>
                        </li>
                    <?php endif; ?>
                </ol>

                <?php if ($totalPaginas > 1): ?>
                    <?php
                    $urlPagina = fn($p) => '?sesion=' . (int)$sesion['id'] . '&pagina=' . $p;
                    $desde = max(1, $pagina - 2);
                    $hasta = min($totalPaginas, $pagina + 2);
                    ?>
                    <nav class="mt-4 d-flex flex-wrap justify-content-between align-items-center gap-2" aria-label="Paginación de acciones">
                        <small class="text-muted-sm">
                            Mostrando <?= ($pagina - 1) * $porPagina + 1 ?>–<?= ($pagina - 1) * $porPagina + count($accionesPagina) ?> de <?= count($acciones) ?>
                        </small>
                        <ul class="pagination pagination-sm mb-0">
                            <li class="page-item <?= $pagina === 1 ? 'disabled' : '' ?>">
                                <a class="page-link" href="<?= $urlPagina($pagina - 1) ?>">&laquo;</a>
                            </li>
                            <?php if ($desde > 1): ?>
                                <li class="page-item"><a class="page-link" href="<?= $urlPagina(1) ?>">1</a></li>
                                <?php if ($desde > 2): ?><li class="page-item disabled"><span class="page-link">…</span></li><?php endif; ?>
                            <?php endif; ?>
                            <?php for ($p = $desde; $p <= $hasta; $p++): ?>
                                <li class="page-item <?= $p === $pagina ? 'active' : '' ?>">
                                    <a class="page-link" href="<?= $urlPagina($p) ?>"><?= $p ?></a>
                                </li>
                            <?php endfor; ?>
                            <?php if ($hasta < $totalPaginas): ?>
                                <?php if ($hasta < $totalPaginas - 1): ?><li class="page-item disabled"><span class="page-link">…</span></li><?php endif; ?>
                                <li class="page-item"><a class="page-link" href="<?= $urlPagina($totalPaginas) ?>"><?= $totalPaginas ?></a></li>
                            <?php endif; ?>
                            <li class="page-item <?= $pagina === $totalPaginas ? 'disabled' : '' ?>">
                                <a class="page-link" href="<?= $urlPagina($pagina + 1) ?>">&raquo;</a>
                            </li>
                        </ul>
                    </nav>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>
<?php else: ?>

    <!-- ===== Filtros ===== -->
    <div class="panel mb-3">
        <div class="panel-header">
            <h2><i class="bi bi-funnel"></i> Filtros</h2>
        </div>
        <div class="panel-body">
            <form method="GET" class="row g-3 align-items-end">
                <div class="col-6 col-md-4 col-lg-2">
                    <label class="form-label">Fecha inicio</label>
                    <input type="date" name="fecha_inicio" id="fecha_inicio" class="form-control" required
                           value="<?= htmlspecialchars($filtroFechaInicio) ?>" max="<?= htmlspecialchars($filtroFechaFin) ?>">
                </div>
                <div class="col-6 col-md-4 col-lg-2">
                    <label class="form-label">Fecha fin</label>
                    <input type="date" name="fecha_fin" id="fecha_fin" class="form-control" required
                           value="<?= htmlspecialchars($filtroFechaFin) ?>" min="<?= htmlspecialchars($filtroFechaInicio) ?>">
                </div>
                <div class="col-6 col-md-4 col-lg-2">
                    <label class="form-label">Origen</label>
                    <select name="origen" class="form-select">
                        <option value="">Todos</option>
                        <option value="agencia" <?= $filtroOrigen === 'agencia' ? 'selected' : '' ?>>Agencia</option>
                        <option value="facturacion" <?= $filtroOrigen === 'facturacion' ? 'selected' : '' ?>>Facturación</option>
                    </select>
                </div>
                <div class="col-6 col-md-4 col-lg-2">
                    <label class="form-label">Estado</label>
                    <select name="exito" class="form-select">
                        <option value="">Todos</option>
                        <option value="1" <?= $filtroExito === '1' ? 'selected' : '' ?>>Exitosos</option>
                        <option value="0" <?= $filtroExito === '0' ? 'selected' : '' ?>>Fallidos</option>
                    </select>
                </div>
                <div class="col-6 col-md-4 col-lg-2">
                    <label class="form-label">Actividad</label>
                    <select name="actividad" class="form-select">
                        <option value="">Todos</option>
                        <option value="con" <?= $filtroActividad === 'con' ? 'selected' : '' ?>>Con actividad</option>
                        <option value="sin" <?= $filtroActividad === 'sin' ? 'selected' : '' ?>>Sin actividad</option>
                    </select>
                </div>
                <div class="col-12 col-md-8 col-lg-4">
                    <label class="form-label">Correo</label>
                    <input type="text" name="correo" class="form-control" value="<?= htmlspecialchars($filtroCorreo) ?>" placeholder="usuario@ejemplo.com">
                </div>
                <div class="col-12 col-lg-8 d-flex flex-wrap gap-2 justify-content-end">
                    <button class="btn-primary-soft"><i class="bi bi-search"></i> Filtrar</button>
                    <a href="ver_logs.php" class="btn-outline-soft"><i class="bi bi-x-circle"></i> Limpiar</a>
                </div>
            </form>
        </div>
    </div>

    <!-- ===== Exportar ===== -->
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
        <small class="text-muted-sm">
            <i class="bi bi-info-circle"></i> Se muestran hasta 500 registros. Haz clic en una fila para ver el detalle.
        </small>
        <div class="export-btns">
            <a href="exportar_logs.php?<?= htmlspecialchars(http_build_query($filtrosQuery + ['formato' => 'excel'])) ?>" class="btn-export btn-export-excel">
                <i class="bi bi-file-earmark-excel"></i> Excel
            </a>
            <a href="exportar_logs.php?<?= htmlspecialchars(http_build_query($filtrosQuery + ['formato' => 'pdf'])) ?>" class="btn-export btn-export-pdf">
                <i class="bi bi-file-earmark-pdf"></i> PDF
            </a>
        </div>
    </div>

    <!-- ===== Tabla ===== -->
    <div class="panel">
        <div class="panel-header">
            <h2><i class="bi bi-list-ul"></i> Registros</h2>
            <span class="text-muted-sm"><?= count($logs) ?> resultados</span>
        </div>
        <div class="tabla-logs">
            <table>
                <thead>
                    <tr>
                        <th>Fecha</th>
                        <th class="d-none d-sm-table-cell">Origen</th>
                        <th>Estado</th>
                        <th>Correo</th>
                        <th class="d-none d-lg-table-cell">IP</th>
                        <th class="d-none d-md-table-cell">Dispositivo</th>
                        <th class="d-none d-lg-table-cell">Navegador</th>
                        <th class="d-none d-md-table-cell">Motivo fallo</th>
                        <th class="d-none d-md-table-cell">Actividad</th>
                        <th>Última acción</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($logs)): ?>
                        <tr><td colspan="10">
                            <div class="empty-state">
                                <i class="bi bi-inbox"></i>
                                No hay registros para los filtros seleccionados
                            </div>
                        </td></tr>
                    <?php else: ?>
                        <?php foreach ($logs as $log): ?>
                            <?php $inicial = strtoupper(mb_substr($log['correo'], 0, 1)); ?>
                            <tr class="fila-log" data-id="<?= (int)$log['id'] ?>">
                                <td class="text-nowrap">
                                    <div style="font-weight:600;font-size:.82rem;color:var(--text-strong)"><?= date('d/m/Y', strtotime($log['fecha_intento'])) ?></div>
                                    <div class="text-muted-sm"><?= date('H:i:s', strtotime($log['fecha_intento'])) ?></div>
                                </td>
                                <td class="d-none d-sm-table-cell">
                                    <?php if ($log['origen'] === 'facturacion'): ?>
                                        <span class="badge-soft badge-origen-facturacion">
                                            <i class="bi bi-cash-coin"></i> Facturación
                                        </span>
                                    <?php else: ?>
                                        <span class="badge-soft badge-origen-agencia">
                                            <i class="bi bi-people"></i> Agencia
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($log['exito']): ?>
                                        <span class="badge-soft badge-ok"><i class="bi bi-check-circle"></i> OK</span>
                                    <?php else: ?>
                                        <span class="badge-soft badge-fail"><i class="bi bi-x-circle"></i> Fallo</span>
                                    <?php endif; ?>
                                </td>
                                <td class="correo-cell">
                                    <div class="correo-wrap">
                                        <span class="avatar"><?= htmlspecialchars($inicial) ?></span>
                                        <span><?= htmlspecialchars($log['correo']) ?></span>
                                    </div>
                                </td>
                                <td class="d-none d-lg-table-cell"><span class="ip-code"><?= htmlspecialchars($log['ip']) ?></span></td>
                                <td class="d-none d-md-table-cell text-nowrap">
                                    <?php
                                    $icono = match($log['dispositivo']) {
                                        'PC' => 'bi-pc-display',
                                        'Móvil' => 'bi-phone',
                                        'Tablet' => 'bi-tablet',
                                        default => 'bi-question-circle'
                                    };
                                    ?>
                                    <span class="text-muted-sm"><i class="bi <?= $icono ?>"></i> <?= htmlspecialchars($log['dispositivo']) ?></span>
                                </td>
                                <td class="d-none d-lg-table-cell"><span class="text-muted-sm"><?= htmlspecialchars($log['navegador'] ?? '—') ?></span></td>
                                <td class="d-none d-md-table-cell">
                                    <?php if (!empty($log['motivo_fallo'])): ?>
                                        <span class="text-muted-sm" style="color:var(--danger-text)"><?= htmlspecialchars($log['motivo_fallo']) ?></span>
                                    <?php else: ?>
                                        <span class="text-muted-sm">—</span>
                                    <?php endif; ?>
                                </td>
                                <td class="d-none d-md-table-cell text-nowrap">
                                    <?php if ($log['exito']): $act = $conteoAcciones[$log['id']] ?? null; ?>
                                        <a href="?sesion=<?= (int)$log['id'] ?>" class="chip chip-acciones">
                                            <i class="bi bi-clock-history"></i> <?= (int)($act['acciones'] ?? 0) ?> acciones
                                        </a>
                                        <?php if (!empty($act['cerro'])): ?>
                                            <i class="bi bi-box-arrow-right text-muted-sm" title="Cerró sesión"></i>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <span class="text-muted-sm">—</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-nowrap">
                                    <?php if ($ultima = $conteoAcciones[$log['id']]['ultima'] ?? null): ?>
                                        <?php $c = $colores[$ultima['accion']] ?? 'secondary'; ?>
                                        <span class="accion-badge" style="background:<?= $hexes[$c] ?? '#64748b' ?>22;color:<?= $hexes[$c] ?? '#64748b' ?>">
                                            <?= htmlspecialchars($etiquetas[$ultima['accion']] ?? $ultima['accion']) ?>
                                        </span>
                                        <?php if (!empty($ultima['tabla'])): ?>
                                            <div class="text-muted-sm"><?= htmlspecialchars($ultima['tabla']) ?></div>
                                        <?php endif; ?>
                                        <div class="text-muted-sm"><?= date('d/m H:i', strtotime($ultima['fecha'])) ?></div>
                                    <?php elseif ($log['exito']): ?>
                                        <span class="text-muted-sm fst-italic">Sin acciones</span>
                                    <?php else: ?>
                                        <span class="text-muted-sm">—</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endif; ?>
</div>

<script>
// Validación del rango de fechas
const fechaInicio = document.getElementById('fecha_inicio');
const fechaFin    = document.getElementById('fecha_fin');
if (fechaInicio && fechaFin) {
    fechaInicio.addEventListener('change', () => {
        fechaFin.min = fechaInicio.value;
        if (fechaFin.value && fechaInicio.value > fechaFin.value) fechaFin.value = fechaInicio.value;
    });
    fechaFin.addEventListener('change', () => {
        fechaInicio.max = fechaFin.value;
        if (fechaInicio.value && fechaInicio.value > fechaFin.value) fechaInicio.value = fechaFin.value;
    });
}

// Click en fila abre el detalle
document.querySelectorAll('.fila-log').forEach(fila => {
    fila.addEventListener('click', e => {
        if (e.target.closest('a, button')) return;
        window.location.href = 'ver_logs.php?sesion=' + fila.dataset.id;
    });
});
</script>
</body>
</html>