<?php
require_once __DIR__ . '/../includes/db.php';
// agencia/ver_logs.php
require_once __DIR__ . '/../session_config.php'; session_start();
date_default_timezone_set('America/Mexico_City');

if (!isset($_SESSION['usuario_id'])) {
    header("Location: ../login.php");
    exit;
}
// Solo el superadmin puede ver los logs
if (($_SESSION['rol'] ?? '') !== 'superadmin') {
    header("Location: Logs.php");
    exit;
}

try {
    $conn = conexionPDO();
} catch (PDOException $e) {
    die("Error de conexión");
}

require_once __DIR__ . '/logs_filtros.php';

$sql  = "SELECT * FROM logs_login WHERE " . implode(' AND ', $where) . " ORDER BY fecha_intento DESC LIMIT 500";
$stmt = $conn->prepare($sql);
$stmt->execute($params);
$logs = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Conteo de acciones por login exitoso
$conteoAcciones = [];
$idsExitosos = array_map('intval', array_column(array_filter($logs, fn($l) => $l['exito']), 'id'));
if ($idsExitosos) {
    try {
        $listaIds = implode(',', $idsExitosos);
        $res = $conn->query("
            SELECT login_id, SUM(accion <> 'LOGOUT') AS acciones, MAX(accion = 'LOGOUT') AS cerro
            FROM logs_acciones
            WHERE login_id IN ($listaIds)
            GROUP BY login_id
        ");
        while ($r = $res->fetch(PDO::FETCH_ASSOC)) {
            $conteoAcciones[$r['login_id']] = $r;
        }
        // Última acción (sin contar LOGOUT) de cada login
        $res = $conn->query("
            SELECT la.login_id, la.accion, la.tabla, la.fecha
            FROM logs_acciones la
            JOIN (
                SELECT login_id, MAX(id) AS id
                FROM logs_acciones
                WHERE login_id IN ($listaIds) AND accion <> 'LOGOUT'
                GROUP BY login_id
            ) u ON u.id = la.id
        ");
        while ($r = $res->fetch(PDO::FETCH_ASSOC)) {
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
    $stmt->execute([$sesionId]);
    $sesion = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    try {
        $stmt = $conn->prepare("SELECT * FROM logs_acciones WHERE login_id = ? ORDER BY fecha, id");
        $stmt->execute([$sesionId]);
        $acciones = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        error_log("ver_logs acciones sesión: " . $e->getMessage());
    }
}

// Paginación de la línea de tiempo (10 acciones por página)
$porPagina     = 10;
$totalPaginas  = max(1, (int)ceil(count($acciones) / $porPagina));
$pagina        = min(max(1, (int)($_GET['pagina'] ?? 1)), $totalPaginas);
$accionesPagina = array_slice($acciones, ($pagina - 1) * $porPagina, $porPagina);

// Colores hex por clase Bootstrap (los usan la lista y la línea de tiempo)
$hexes = ['success' => '#22c55e', 'primary' => '#6366f1', 'danger' => '#ef4444', 'secondary' => '#64748b', 'info' => '#0ea5e9', 'warning' => '#f59e0b'];
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
    <link href="CSS/ver_logs.css?v=<?= filemtime(__DIR__ . '/CSS/ver_logs.css') ?>" rel="stylesheet">
</head>
<body>
<?php require __DIR__ . '/../includes/navbar.php'; ?>
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
            <a href="../opcionesAdmin.php" class="btn-ghost">
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
                    <label class="form-label">Origen Login</label>
                    <select name="origen" class="form-select">
                        <option value="">Todos</option>
                        <option value="agencia" <?= $filtroOrigen === 'agencia' ? 'selected' : '' ?>>Agencia</option>
                        <option value="facturacion" <?= $filtroOrigen === 'facturacion' ? 'selected' : '' ?>>Facturación</option>
                    </select>
                </div>
                <div class="col-6 col-md-4 col-lg-2">
                    <label class="form-label">Estado Login</label>
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
                    <a href="Logs" class="btn-outline-soft"><i class="bi bi-x-circle"></i> Limpiar</a>
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
                                    <span class="text-muted-sm"><i class="bi <?= $icono ?>"></i> <?= htmlspecialchars($log['dispositivo'] ?? '') ?></span>
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

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="JS/ver_logs.js?v=<?= filemtime(__DIR__ . '/JS/ver_logs.js') ?>"></script>
</body>
</html>