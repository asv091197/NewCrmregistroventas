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
    <title>Logs de Inicio de Sesión</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        body { background: #f5f7fa; padding: 20px; }
        .table-sm td, .table-sm th { font-size: 0.85rem; vertical-align: middle; }
        .ua-text { font-size: 0.7rem; color: #6c757d; max-width: 250px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .badge-origen-agencia { background: #0d6efd; }
        .badge-origen-facturacion { background: #fd7e14; }
        .timeline { border-left: 3px solid #dee2e6; margin-left: 10px; padding-left: 20px; }
        .timeline-item { position: relative; margin-bottom: 14px; }
        .timeline-item::before { content: ''; position: absolute; left: -28px; top: 6px; width: 13px; height: 13px; border-radius: 50%; background: var(--punto, #6c757d); border: 2px solid #fff; }
        .detalle-accion { font-size: 0.8rem; color: #495057; overflow-wrap: anywhere; }
        .tabla-logs { max-height: 600px; overflow: auto; }
        .correo-cell { overflow-wrap: anywhere; min-width: 140px; }
        .fila-log { cursor: pointer; }
        @media (max-width: 767.98px) {
            body { padding: 10px; }
            h3 { font-size: 1.2rem; }
            .tabla-logs { max-height: 70vh; }
            .table-sm td, .table-sm th { font-size: 0.78rem; }
            .timeline { margin-left: 4px; padding-left: 16px; }
            .timeline-item::before { left: -24px; }
        }
    </style>
</head>
<body>
<div class="container-fluid">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
        <h3 class="mb-0"><i class="bi bi-shield-lock"></i> Logs de Inicio de Sesión</h3>
        <a href="opAdmin.php" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-arrow-left"></i> Volver<span class="d-none d-sm-inline"> al Dashboard</span>
        </a>
    </div>

<?php if ($sesionId > 0): ?>
    <?php if (!$sesion): ?>
        <div class="alert alert-warning">No se encontró el inicio de sesión #<?= $sesionId ?>.</div>
    <?php else: ?>
        <?php
        $hexes  = ['success' => '#198754', 'primary' => '#0d6efd', 'danger' => '#dc3545', 'secondary' => '#6c757d'];
        $ultima  = end($acciones) ?: null;
        $cerro   = $ultima && $ultima['accion'] === 'LOGOUT';
        $fin     = $ultima ? strtotime($ultima['fecha']) : strtotime($sesion['fecha_intento']);
        $duracion = $fin - strtotime($sesion['fecha_intento']);
        $numAcciones = count(array_filter($acciones, fn($a) => $a['accion'] !== 'LOGOUT'));
        ?>
        <?php $diaSesion = date('Y-m-d', strtotime($sesion['fecha_intento'])); ?>
        <a href="?fecha_inicio=<?= $diaSesion ?>&fecha_fin=<?= $diaSesion ?>" class="btn btn-sm btn-outline-primary mb-3">
            <i class="bi bi-arrow-left"></i> Volver a la lista
        </a>
        <div class="card mb-3">
            <div class="card-body">
                <h5 class="mb-1" style="overflow-wrap:anywhere"><i class="bi bi-person-badge"></i> <?= htmlspecialchars($sesion['correo']) ?>
                    <span class="badge badge-origen-<?= $sesion['origen'] === 'facturacion' ? 'facturacion' : 'agencia' ?>"><?= htmlspecialchars(ucfirst($sesion['origen'])) ?></span>
                </h5>
                <small class="text-muted">
                    Sesión #<?= (int)$sesion['id'] ?> ·
                    <?= $numAcciones ?> acciones ·
                    duración <?= gmdate('H:i:s', max(0, $duracion)) ?>
                </small>
            </div>
        </div>

        <div class="card">
            <div class="card-body">
                <div class="timeline">
                    <?php if ($pagina === 1): ?>
                    <div class="timeline-item" style="--punto:<?= $sesion['exito'] ? '#198754' : '#dc3545' ?>">
                        <div class="small text-muted"><?= date('d/m/Y H:i:s', strtotime($sesion['fecha_intento'])) ?></div>
                        <div><span class="badge bg-dark"><i class="bi bi-box-arrow-in-right"></i> LOGIN</span>
                            <?= $sesion['exito'] ? 'Inicio de sesión exitoso' : 'Intento fallido: ' . htmlspecialchars($sesion['motivo_fallo'] ?? '') ?>
                        </div>
                        <div class="detalle-accion">
                            IP <code><?= htmlspecialchars($sesion['ip']) ?></code>
                            · <?= htmlspecialchars(trim(($sesion['pais'] ?? '') . ' ' . ($sesion['ciudad'] ?? '')) ?: '—') ?>
                            · <?= htmlspecialchars($sesion['dispositivo'] ?? '') ?> / <?= htmlspecialchars($sesion['navegador'] ?? '') ?>
                        </div>
                    </div>
                    <?php endif; ?>

                    <?php if ($numAcciones === 0): ?>
                        <div class="timeline-item">
                            <div class="text-muted fst-italic"><i class="bi bi-info-circle"></i> No hay acciones</div>
                        </div>
                    <?php endif; ?>

                    <?php foreach ($accionesPagina as $a): $c = $colores[$a['accion']] ?? 'secondary'; ?>
                        <div class="timeline-item" style="--punto:<?= $hexes[$c] ?>">
                            <div class="small text-muted"><?= date('d/m/Y H:i:s', strtotime($a['fecha'])) ?></div>
                            <?php if ($a['accion'] === 'LOGOUT'): ?>
                                <div><span class="badge bg-secondary"><i class="bi bi-box-arrow-right"></i> LOGOUT</span> Cierre de sesión</div>
                            <?php else: ?>
                                <div>
                                    <span class="badge bg-<?= $c ?>"><?= htmlspecialchars($etiquetas[$a['accion']] ?? $a['accion']) ?></span>
                                    <?php if ($a['registro_id'] !== null): ?>#<?= htmlspecialchars($a['registro_id']) ?><?php endif; ?>                                </div>
                                <?php if (!empty($a['detalle'])): ?>
                                    <div class="detalle-accion"><?= formatearDetalle($a['detalle']) ?></div>
                                <?php endif; ?>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>

                    <?php if ($sesion['exito'] && !$cerro && $pagina === $totalPaginas): ?>
                        <div class="timeline-item">
                            <div class="text-muted small fst-italic">Sin cierre de sesión registrado (expiró o se cerró el navegador)</div>
                        </div>
                    <?php endif; ?>
                </div>

                <?php if ($totalPaginas > 1): ?>
                    <?php
                    $urlPagina = fn($p) => '?sesion=' . (int)$sesion['id'] . '&pagina=' . $p;
                    $desde = max(1, $pagina - 2);
                    $hasta = min($totalPaginas, $pagina + 2);
                    ?>
                    <nav class="mt-3 d-flex flex-wrap justify-content-between align-items-center gap-2" aria-label="Paginación de acciones">
                        <small class="text-muted">
                            Mostrando <?= ($pagina - 1) * $porPagina + 1 ?>–<?= ($pagina - 1) * $porPagina + count($accionesPagina) ?> de <?= count($acciones) ?>
                        </small>
                        <ul class="pagination pagination-sm mb-0">
                            <li class="page-item <?= $pagina === 1 ? 'disabled' : '' ?>">
                                <a class="page-link" href="<?= $urlPagina($pagina - 1) ?>" aria-label="Anterior">&laquo;</a>
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
                                <a class="page-link" href="<?= $urlPagina($pagina + 1) ?>" aria-label="Siguiente">&raquo;</a>
                            </li>
                        </ul>
                    </nav>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>
<?php else: ?>

    <div class="card mb-3">
        <div class="card-body">
            <form method="GET" class="row g-2 align-items-end">
                <div class="col-6 col-md-4 col-lg-2">
                    <label class="form-label small">Fecha inicio</label>
                    <input type="date" name="fecha_inicio" id="fecha_inicio" class="form-control form-control-sm" required
                           value="<?= htmlspecialchars($filtroFechaInicio) ?>" max="<?= htmlspecialchars($filtroFechaFin) ?>">
                </div>
                <div class="col-6 col-md-4 col-lg-2">
                    <label class="form-label small">Fecha fin</label>
                    <input type="date" name="fecha_fin" id="fecha_fin" class="form-control form-control-sm" required
                           value="<?= htmlspecialchars($filtroFechaFin) ?>" min="<?= htmlspecialchars($filtroFechaInicio) ?>">
                </div>
                <div class="col-6 col-md-4 col-lg-2">
                    <label class="form-label small">Origen Login</label>
                    <select name="origen" class="form-select form-select-sm">
                        <option value="">Todos</option>
                        <option value="agencia" <?= $filtroOrigen === 'agencia' ? 'selected' : '' ?>>Agencia</option>
                        <option value="facturacion" <?= $filtroOrigen === 'facturacion' ? 'selected' : '' ?>>Facturación</option>
                    </select>
                </div>
                <div class="col-6 col-md-4 col-lg-2">
                    <label class="form-label small">Estado Login</label>
                    <select name="exito" class="form-select form-select-sm">
                        <option value="">Todos</option>
                        <option value="1" <?= $filtroExito === '1' ? 'selected' : '' ?>>Exitosos</option>
                        <option value="0" <?= $filtroExito === '0' ? 'selected' : '' ?>>Fallidos</option>
                    </select>
                </div>
                <div class="col-6 col-md-4 col-lg-2">
                    <label class="form-label small">Actividad</label>
                    <select name="actividad" class="form-select form-select-sm">
                        <option value="">Todos</option>
                        <option value="con" <?= $filtroActividad === 'con' ? 'selected' : '' ?>>Con actividad</option>
                        <option value="sin" <?= $filtroActividad === 'sin' ? 'selected' : '' ?>>Sin actividad</option>
                    </select>
                </div>
                <div class="col-12 col-md-4 col-lg-2">
                    <label class="form-label small">Correo</label>
                    <input type="text" name="correo" class="form-control form-control-sm" value="<?= htmlspecialchars($filtroCorreo) ?>" placeholder="usuario@...">
                </div>
                <div class="col-12 col-md-4 col-lg-3 ms-auto d-flex gap-2">
                    <button class="btn btn-primary btn-sm flex-fill"><i class="bi bi-search"></i> Filtrar</button>
                    <a href="ver_logs.php" class="btn btn-outline-secondary btn-sm flex-fill"><i class="bi bi-x-circle"></i> Borrar<span class="d-lg-none d-xl-inline"> filtro</span></a>
                </div>
            </form>
        </div>
    </div>

    <div class="d-flex flex-wrap justify-content-end gap-2 mb-2">
        <a href="exportar_logs.php?<?= htmlspecialchars(http_build_query($filtrosQuery + ['formato' => 'excel'])) ?>" class="btn btn-success btn-sm">
            <i class="bi bi-file-earmark-excel"></i> Exportar Excel
        </a>
        <a href="exportar_logs.php?<?= htmlspecialchars(http_build_query($filtrosQuery + ['formato' => 'pdf'])) ?>" class="btn btn-danger btn-sm">
            <i class="bi bi-file-earmark-pdf"></i> Exportar PDF
        </a>
    </div>

    <div class="card">
        <div class="card-body p-0">
            <div class="table-responsive tabla-logs">
                <table class="table table-sm table-hover mb-0">
                    <thead class="table-dark sticky-top">
                        <tr>
                            <th>Fecha</th>
                            <th class="d-none d-sm-table-cell">Origen</th>
                            <th>Estado</th>
                            <th>Correo</th>
                            <th class="d-none d-lg-table-cell">IP</th>
                            <th class="d-none d-md-table-cell">Dispositivo</th>
                            <th class="d-none d-lg-table-cell">Navegador</th>
                            <th class="d-none d-md-table-cell">Motivo Fallo</th>
                            <th class="d-none d-md-table-cell">Actividad</th>
                            <th>Última acción</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($logs)): ?>
                            <tr><td colspan="10"class="text-center text-muted py-4">No hay registros para los filtros seleccionados</td></tr>
                        <?php else: ?>
                            <?php foreach ($logs as $log): ?>
                                <tr class="fila-log" data-id="<?= (int)$log['id'] ?>">
                                    <td class="text-nowrap small"><?= date('d/m/Y H:i:s', strtotime($log['fecha_intento'])) ?></td>
                                    <td class="d-none d-sm-table-cell">
                                        <?php if ($log['origen'] === 'facturacion'): ?>
                                            <span class="badge badge-origen-facturacion">
                                                <i class="bi bi-cash-coin"></i> Facturación
                                            </span>
                                        <?php else: ?>
                                            <span class="badge badge-origen-agencia">
                                                <i class="bi bi-people"></i> Agencia
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($log['exito']): ?>
                                            <span class="badge bg-success"><i class="bi bi-check-circle"></i> OK</span>
                                        <?php else: ?>
                                            <span class="badge bg-danger"><i class="bi bi-x-circle"></i> Fallo</span>
                                            <?php if (!empty($log['motivo_fallo'])): ?>
                                                <div class="small text-danger d-md-none"><?= htmlspecialchars($log['motivo_fallo']) ?></div>
                                            <?php endif; ?>
                                        <?php endif; ?>
                                    </td>
                                    <td class="small correo-cell"><?= htmlspecialchars($log['correo']) ?></td>
                                    <td class="small d-none d-lg-table-cell"><code><?= htmlspecialchars($log['ip']) ?></code></td>
                                    <td class="d-none d-md-table-cell text-nowrap">
                                        <?php
                                        $icono = match($log['dispositivo']) {
                                            'PC' => 'bi-pc-display',
                                            'Móvil' => 'bi-phone',
                                            'Tablet' => 'bi-tablet',
                                            default => 'bi-question-circle'
                                        };
                                        ?>
                                        <i class="bi <?= $icono ?>"></i> <?= htmlspecialchars($log['dispositivo']) ?>
                                    </td>
                                    <td class="small d-none d-lg-table-cell"><?= htmlspecialchars($log['navegador'] ?? '—') ?></td>
                                    <td class="small text-danger d-none d-md-table-cell"><?= htmlspecialchars($log['motivo_fallo'] ?? '') ?></td>
                                    <td class="text-nowrap d-none d-md-table-cell">
                                        <?php if ($log['exito']): $act = $conteoAcciones[$log['id']] ?? null; ?>
                                            <a href="?sesion=<?= (int)$log['id'] ?>" class="btn btn-outline-dark btn-sm py-0">
                                                <i class="bi bi-clock-history"></i> <?= (int)($act['acciones'] ?? 0) ?> acciones
                                            </a>
                                            <?php if (!empty($act['cerro'])): ?>
                                                <i class="bi bi-box-arrow-right text-secondary" title="Cerró sesión"></i>
                                            <?php endif; ?>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-nowrap small">
                                        <?php if ($ultima = $conteoAcciones[$log['id']]['ultima'] ?? null): ?>
                                            <span class="badge bg-<?= $colores[$ultima['accion']] ?? 'secondary' ?>"><?= htmlspecialchars($etiquetas[$ultima['accion']] ?? $ultima['accion']) ?></span>
                                            <?= htmlspecialchars($ultima['tabla'] ?? '') ?>
                                            <div class="text-muted"><?= date('d/m H:i:s', strtotime($ultima['fecha'])) ?></div>
                                        <?php elseif ($log['exito']): ?>
                                            <span class="text-muted fst-italic">No hay acciones</span>
                                        <?php else: ?>
                                            <span class="text-muted">—</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
<?php endif; ?>
</div>
<script>
// Validación del rango: la fecha inicio no puede ser mayor que la fecha fin
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

// Al tocar una fila se abre el detalle de ese login
document.querySelectorAll('.fila-log').forEach(fila => {
    fila.addEventListener('click', e => {
        if (e.target.closest('a, button')) return;
        window.location.href = 'ver_logs.php?sesion=' + fila.dataset.id;
    });
});
</script>
</body>
</html>