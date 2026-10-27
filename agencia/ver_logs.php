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

$filtroExito  = $_GET['exito'] ?? '';
$filtroOrigen = $_GET['origen'] ?? '';
$filtroCorreo = trim($_GET['correo'] ?? '');
$filtroFecha  = $_GET['fecha'] ?? date('Y-m-d');

$where  = ["DATE(fecha_intento) = ?"];
$params = [$filtroFecha];
$types  = "s";

if ($filtroExito !== '') {
    $where[]  = "exito = ?";
    $params[] = (int)$filtroExito;
    $types   .= "i";
}
if ($filtroOrigen !== '') {
    $where[]  = "origen = ?";
    $params[] = $filtroOrigen;
    $types   .= "s";
}
if ($filtroCorreo !== '') {
    $where[]  = "correo LIKE ?";
    $params[] = "%$filtroCorreo%";
    $types   .= "s";
}

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

function formatearDetalle($json) {
    $datos = json_decode($json ?? '', true);
    if (!is_array($datos)) return htmlspecialchars($json ?? '');
    $partes = [];
    foreach ($datos as $campo => $valor) {
        if (is_array($valor) && array_key_exists('antes', $valor)) {
            $partes[] = "<strong>" . htmlspecialchars($campo) . "</strong>: "
                . htmlspecialchars(var_export($valor['antes'], true)) . " &rarr; "
                . htmlspecialchars(var_export($valor['despues'], true));
        } else {
            $texto = is_scalar($valor) || $valor === null ? var_export($valor, true) : json_encode($valor, JSON_UNESCAPED_UNICODE);
            $partes[] = "<strong>" . htmlspecialchars($campo) . "</strong>: " . htmlspecialchars($texto);
        }
    }
    return implode('<br>', $partes);
}

$stats = $conn->query("
    SELECT
        SUM(exito = 1) as exitosos,
        SUM(exito = 0) as fallidos,
        SUM(origen = 'agencia' AND exito = 1) as agencia_ok,
        SUM(origen = 'facturacion' AND exito = 1) as facturacion_ok,
        COUNT(DISTINCT ip) as ips_unicas,
        COUNT(DISTINCT usuario_id) as usuarios_unicos
    FROM logs_login
    WHERE DATE(fecha_intento) = '$filtroFecha'
")->fetch_assoc();
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Logs de Inicio de Sesión</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        body { background: #f5f7fa; padding: 20px; }
        .stat-card { border-left: 4px solid #007bff; }
        .table-sm td, .table-sm th { font-size: 0.85rem; vertical-align: middle; }
        .ua-text { font-size: 0.7rem; color: #6c757d; max-width: 250px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .badge-origen-agencia { background: #0d6efd; }
        .badge-origen-facturacion { background: #fd7e14; }
        .timeline { border-left: 3px solid #dee2e6; margin-left: 10px; padding-left: 20px; }
        .timeline-item { position: relative; margin-bottom: 14px; }
        .timeline-item::before { content: ''; position: absolute; left: -28px; top: 6px; width: 13px; height: 13px; border-radius: 50%; background: var(--punto, #6c757d); border: 2px solid #fff; }
        .detalle-accion { font-size: 0.8rem; color: #495057; }
    </style>
</head>
<body>
<div class="container-fluid">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h3><i class="bi bi-shield-lock"></i> Logs de Inicio de Sesión</h3>
        <a href="opAdmin.php" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-arrow-left"></i> Volver al Dashboard
        </a>
    </div>

<?php if ($sesionId > 0): ?>
    <?php if (!$sesion): ?>
        <div class="alert alert-warning">No se encontró el inicio de sesión #<?= $sesionId ?>.</div>
    <?php else: ?>
        <?php
        $colores = ['INSERT' => 'success', 'UPDATE' => 'primary', 'DELETE' => 'danger', 'LOGOUT' => 'secondary'];
        $etiquetas = ['INSERT' => 'NUEVO', 'UPDATE' => 'ACTUALIZAR', 'DELETE' => 'ELIMINAR'];
        $hexes   = ['success' => '#198754', 'primary' => '#0d6efd', 'danger' => '#dc3545', 'secondary' => '#6c757d'];
        $ultima  = end($acciones) ?: null;
        $cerro   = $ultima && $ultima['accion'] === 'LOGOUT';
        $fin     = $ultima ? strtotime($ultima['fecha']) : strtotime($sesion['fecha_intento']);
        $duracion = $fin - strtotime($sesion['fecha_intento']);
        ?>
        <a href="?fecha=<?= date('Y-m-d', strtotime($sesion['fecha_intento'])) ?>" class="btn btn-sm btn-outline-primary mb-3">
            <i class="bi bi-arrow-left"></i> Volver a la lista
        </a>
        <div class="card mb-3">
            <div class="card-body">
                <h5 class="mb-1"><i class="bi bi-person-badge"></i> <?= htmlspecialchars($sesion['correo']) ?>
                    <span class="badge badge-origen-<?= $sesion['origen'] === 'facturacion' ? 'facturacion' : 'agencia' ?>"><?= htmlspecialchars(ucfirst($sesion['origen'])) ?></span>
                </h5>
                <small class="text-muted">
                    Sesión #<?= (int)$sesion['id'] ?> ·
                    <?= count(array_filter($acciones, fn($a) => $a['accion'] !== 'LOGOUT')) ?> acciones ·
                    duración <?= gmdate('H:i:s', max(0, $duracion)) ?>
                </small>
            </div>
        </div>

        <div class="card">
            <div class="card-body">
                <div class="timeline">
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

                    <?php foreach ($acciones as $a): $c = $colores[$a['accion']] ?? 'secondary'; ?>
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

                    <?php if ($sesion['exito'] && !$cerro): ?>
                        <div class="timeline-item">
                            <div class="text-muted small fst-italic">Sin cierre de sesión registrado (expiró o se cerró el navegador)</div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    <?php endif; ?>
<?php else: ?>

    <div class="card mb-3">
        <div class="card-body">
            <form method="GET" class="row g-2 align-items-end">
                <div class="col-md-2">
                    <label class="form-label small">Fecha</label>
                    <input type="date" name="fecha" class="form-control form-control-sm" value="<?= htmlspecialchars($filtroFecha) ?>">
                </div>
                <div class="col-md-2">
                    <label class="form-label small">Origen</label>
                    <select name="origen" class="form-select form-select-sm">
                        <option value="">Todos</option>
                        <option value="agencia" <?= $filtroOrigen === 'agencia' ? 'selected' : '' ?>>Agencia</option>
                        <option value="facturacion" <?= $filtroOrigen === 'facturacion' ? 'selected' : '' ?>>Facturación</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label small">Estado</label>
                    <select name="exito" class="form-select form-select-sm">
                        <option value="">Todos</option>
                        <option value="1" <?= $filtroExito === '1' ? 'selected' : '' ?>>Exitosos</option>
                        <option value="0" <?= $filtroExito === '0' ? 'selected' : '' ?>>Fallidos</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label small">Correo</label>
                    <input type="text" name="correo" class="form-control form-control-sm" value="<?= htmlspecialchars($filtroCorreo) ?>" placeholder="usuario@...">
                </div>
                <div class="col-md-3">
                    <button class="btn btn-primary btn-sm w-100"><i class="bi bi-search"></i> Filtrar</button>
                </div>
            </form>
        </div>
    </div>

    <div class="row mb-3">
        <div class="col-md-2">
            <div class="card stat-card" style="border-color:#28a745">
                <div class="card-body py-2">
                    <small class="text-muted">Exitosos</small>
                    <h4 class="mb-0 text-success"><?= (int)$stats['exitosos'] ?></h4>
                </div>
            </div>
        </div>
        <div class="col-md-2">
            <div class="card stat-card" style="border-color:#dc3545">
                <div class="card-body py-2">
                    <small class="text-muted">Fallidos</small>
                    <h4 class="mb-0 text-danger"><?= (int)$stats['fallidos'] ?></h4>
                </div>
            </div>
        </div>
        <div class="col-md-2">
            <div class="card stat-card" style="border-color:#0d6efd">
                <div class="card-body py-2">
                    <small class="text-muted">Agencia OK</small>
                    <h4 class="mb-0 text-primary"><?= (int)$stats['agencia_ok'] ?></h4>
                </div>
            </div>
        </div>
        <div class="col-md-2">
            <div class="card stat-card" style="border-color:#fd7e14">
                <div class="card-body py-2">
                    <small class="text-muted">Facturación OK</small>
                    <h4 class="mb-0" style="color:#fd7e14"><?= (int)$stats['facturacion_ok'] ?></h4>
                </div>
            </div>
        </div>
        <div class="col-md-2">
            <div class="card stat-card" style="border-color:#17a2b8">
                <div class="card-body py-2">
                    <small class="text-muted">IPs Únicas</small>
                    <h4 class="mb-0 text-info"><?= (int)$stats['ips_unicas'] ?></h4>
                </div>
            </div>
        </div>
        <div class="col-md-2">
            <div class="card stat-card" style="border-color:#ffc107">
                <div class="card-body py-2">
                    <small class="text-muted">Usuarios Únicos</small>
                    <h4 class="mb-0 text-warning"><?= (int)$stats['usuarios_unicos'] ?></h4>
                </div>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card-body p-0">
            <div class="table-responsive" style="max-height: 600px; overflow:auto;">
                <table class="table table-sm table-hover mb-0">
                    <thead class="table-dark sticky-top">
                        <tr>
                            <th>Fecha</th>
                            <th>Origen</th>
                            <th>Estado</th>
                            <th>Correo</th>
                            <th>IP</th>
                            <th>Dispositivo</th>
                            <th>Navegador</th>
                            <th>Motivo Fallo</th>
                            <th>Actividad</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($logs)): ?>
                            <tr><td colspan="9" class="text-center text-muted py-4">No hay registros para los filtros seleccionados</td></tr>
                        <?php else: ?>
                            <?php foreach ($logs as $log): ?>
                                <tr>
                                    <td class="text-nowrap small"><?= date('d/m/Y H:i:s', strtotime($log['fecha_intento'])) ?></td>
                                    <td>
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
                                        <?php endif; ?>
                                    </td>
                                    <td class="small"><?= htmlspecialchars($log['correo']) ?></td>
                                    <td class="small"><code><?= htmlspecialchars($log['ip']) ?></code></td>
                                    <td>
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
                                    <td class="small"><?= htmlspecialchars($log['navegador'] ?? '—') ?></td>
                                    <td class="small text-danger"><?= htmlspecialchars($log['motivo_fallo'] ?? '') ?></td>
                                    <td class="text-nowrap">
                                        <?php if ($log['exito']): $act = $conteoAcciones[$log['id']] ?? null; ?>
                                            <a href="?sesion=<?= (int)$log['id'] ?>" class="btn btn-outline-dark btn-sm py-0">
                                                <i class="bi bi-clock-history"></i> <?= (int)($act['acciones'] ?? 0) ?> acciones
                                            </a>
                                            <?php if (!empty($act['cerro'])): ?>
                                                <i class="bi bi-box-arrow-right text-secondary" title="Cerró sesión"></i>
                                            <?php endif; ?>
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
</body>
</html>