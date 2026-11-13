<?php

require_once __DIR__ . '/../includes/db.php';

ini_set('session.gc_probability', 1);

ini_set('session.gc_divisor', 1000); // Solo 0.1% de probabilidad de limpieza

ini_set('session.cookie_secure', 0);

ini_set('session.cookie_httponly', 1);

ini_set('session.use_strict_mode', 1);

header('Access-Control-Allow-Origin: *');

require_once __DIR__ . '/../session_config.php'; session_start();

date_default_timezone_set('America/Mexico_City');



// Validación de roles

if (!isset($_SESSION['usuario_id'])) {

    header("Location: ./login.php");

    exit;

}



// Redirección según el rol (admin y superadmin se quedan en esta página)

$rol = $_SESSION['rol'] ?? '';

if ($rol === 'usuario') {

    header("Location: agencia.php");

    exit;

} elseif ($rol !== 'admin' && $rol !== 'superadmin') {

    header("Location: ./login.php");

    exit;

}



// Conexión a la base de datos

require_once __DIR__ . '/config/database.php';



// Obtener permisos del admin desde la base de datos

$stmt_permisos = $conn->prepare("SELECT acceso_todas_salas, salas_permitidas FROM usuarios WHERE id = ?");

$stmt_permisos->execute([$_SESSION['usuario_id']]);

$permisos = $stmt_permisos->fetch(PDO::FETCH_ASSOC) ?: [];



// Superadmin siempre tiene acceso a todas las salas

$acceso_todas_salas = $rol === 'superadmin' ? 1 : (int)($permisos['acceso_todas_salas'] ?? 0);

$salas_permitidas = json_decode($permisos['salas_permitidas'] ?? '[]', true);

if (!is_array($salas_permitidas)) {

    $salas_permitidas = [];

}



// Determinar qué pestaña está activa

$tab_activa = 'diario';

$fecha_seleccionada = date('Y-m-d');

$mes_seleccionado = date('Y-m');

$sala_seleccionada = $_GET['sala'] ?? 'todas';



// Si el admin no tiene acceso a todas las salas y tiene salas permitidas

if ($acceso_todas_salas == 0 && !empty($salas_permitidas)) {

    // Forzar la primera sala permitida como selección

    $sala_seleccionada = $salas_permitidas[0];

}



// Si hay parámetro de fecha se muestra la vista diaria; si solo hay mes, la mensual

if (isset($_GET['fecha'])) {

    $fecha_seleccionada = $_GET['fecha'];

} elseif (isset($_GET['mes'])) {

    $tab_activa = 'mensual';

    $mes_seleccionado = $_GET['mes'];

}



// Si hay parámetro de sala, validar que el admin tenga acceso

if (isset($_GET['sala']) && ($acceso_todas_salas == 1 || in_array($_GET['sala'], $salas_permitidas))) {

    $sala_seleccionada = $_GET['sala'];

}



// Obtener lista de salas disponibles según permisos

$salas = [];

if ($acceso_todas_salas == 1) {

    $salas = $conn->query("SELECT id, nombre FROM salas WHERE activo = 1 ORDER BY nombre")->fetchAll(PDO::FETCH_ASSOC);

} elseif (!empty($salas_permitidas)) {

    $placeholders = implode(',', array_fill(0, count($salas_permitidas), '?'));

    $stmt_salas = $conn->prepare("SELECT id, nombre FROM salas WHERE activo = 1 AND id IN ($placeholders) ORDER BY nombre");

    $stmt_salas->execute(array_values($salas_permitidas));

    $salas = $stmt_salas->fetchAll(PDO::FETCH_ASSOC);

}



// Formatear fechas para mostrar y para las consultas mensuales

$fecha_mostrar = date('d M Y', strtotime($fecha_seleccionada));

$inicio_mes = strtotime($mes_seleccionado . '-01');

$anio_mes_mostrar = date('F Y', $inicio_mes);

$anio = date('Y', $inicio_mes);

$mes = date('m', $inicio_mes);



// Filtro por sala: condición SQL y parámetro extra para todas las consultas

$nombre_sala_seleccionada = "Todas las salas";

$filtra_sala = $sala_seleccionada !== 'todas' && is_numeric($sala_seleccionada);

$params_sala = $filtra_sala ? [(int)$sala_seleccionada] : [];

$condicion_sala_clientes = $filtra_sala ? " AND c.sala_id = ? " : "";

$condicion_sala_ventas = $filtra_sala ? " AND v.sala_id = ? " : "";



if ($filtra_sala) {

    $stmt_nombre = $conn->prepare("SELECT nombre FROM salas WHERE id = ?");

    $stmt_nombre->execute($params_sala);

    if ($row = $stmt_nombre->fetch(PDO::FETCH_ASSOC)) {

        $nombre_sala_seleccionada = $row['nombre'];

    }

}



// ============================================================================

// VENTA BRUTA (DIARIA, MENSUAL Y POR DÍA DEL MES)

// ============================================================================



$venta_bruta_diaria = 0;

$venta_bruta_mensual = 0;



try {

    // Venta bruta del día seleccionado

    $stmt_ventas_dia = $conn->prepare("

        SELECT COALESCE(SUM(v.precio), 0)

        FROM ventas_facturacion v

        WHERE DATE(v.fecha) = ? {$condicion_sala_ventas}

    ");

    $stmt_ventas_dia->execute(array_merge([$fecha_seleccionada], $params_sala));

    $venta_bruta_diaria = (float)$stmt_ventas_dia->fetchColumn();



    // Gráfica lineal: venta bruta por día del mes (todos los días inicializados en 0)

    $dias_del_mes = range(1, (int)date('t', $inicio_mes));

    $ventas_por_dia = array_fill_keys($dias_del_mes, 0);



    $stmt_ventas_mes = $conn->prepare("

        SELECT DAY(v.fecha) as dia, SUM(v.precio) as total_venta

        FROM ventas_facturacion v

        WHERE YEAR(v.fecha) = ? AND MONTH(v.fecha) = ? {$condicion_sala_ventas}

        GROUP BY DAY(v.fecha) ORDER BY dia

    ");

    $stmt_ventas_mes->execute(array_merge([$anio, $mes], $params_sala));

    while ($row = $stmt_ventas_mes->fetch(PDO::FETCH_ASSOC)) {

        $ventas_por_dia[(int)$row['dia']] = (float)$row['total_venta'];

    }



    // Arreglo indexado para JavaScript; el total del mes es la suma de los días

    $ventas_por_dia_array = array_values($ventas_por_dia);

    $venta_bruta_mensual = array_sum($ventas_por_dia_array);

} catch (Exception $e) {

    error_log("opAdmin venta bruta: " . $e->getMessage());

    $venta_bruta_diaria = 0;

    $venta_bruta_mensual = 0;

    $ventas_por_dia_array = array();

    $dias_del_mes = array();

}



// ============================================================================

// LLAMADAS POR AGENTE (FILTRADAS POR SALA)

// ============================================================================



// Deja solo letras, números y espacios en el apellido

function limpiarApemat($apemat)

{

    $apemat_limpio = preg_replace('/[^\p{L}\p{N}\s]/u', '', $apemat ?? '');

    return trim(preg_replace('/\s+/', ' ', $apemat_limpio));

}



// Clientes registrados por agente (liner o closer), de mayor a menor

function llamadasPorAgente(PDO $conn, string $condicion_fecha, string $condicion_sala, array $params)

{

    $stmt = $conn->prepare("

        SELECT

            u.apemat,

            COUNT(*) as cantidad

        FROM cliente c

        INNER JOIN usuarios u ON

            u.id = c.usuario_id OR u.id = c.usuario_closer

        WHERE {$condicion_fecha}

        {$condicion_sala}

        GROUP BY u.apemat

        ORDER BY cantidad DESC

    ");

    $stmt->execute($params);

    $usuarios = [];

    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {

        $usuarios[] = [

            'apemat' => limpiarApemat($row['apemat']),

            'cantidad' => $row['cantidad']

        ];

    }

    return $usuarios;

}



// Todos los usuarios de la fecha seleccionada y su top 3

$todos_usuarios = llamadasPorAgente($conn, "DATE(c.fecha_registro) = ?", $condicion_sala_clientes, array_merge([$fecha_seleccionada], $params_sala));

$top_usuarios = array_slice($todos_usuarios, 0, 3);



// Volumen mensual y su top 3

$usuarios_mensual = llamadasPorAgente($conn, "YEAR(c.fecha_registro) = ? AND MONTH(c.fecha_registro) = ?", $condicion_sala_clientes, array_merge([$anio, $mes], $params_sala));

$top3_mensual = array_slice($usuarios_mensual, 0, 3);

$total_mensual = array_sum(array_column($usuarios_mensual, 'cantidad'));



// Estadísticas diarias

$total_llamadas = array_sum(array_column($todos_usuarios, 'cantidad'));

$total_agentes = count($todos_usuarios);

$promedio_llamadas = $total_agentes > 0 ? round($total_llamadas / $total_agentes, 1) : 0;



// Estadísticas mensuales

$total_agentes_mensual = count($usuarios_mensual);

$promedio_mensual = $total_agentes_mensual > 0 ? round($total_mensual / $total_agentes_mensual, 1) : 0;



// Nombre a mostrar en la barra y en la bienvenida

$nombre_mostrar = 'Usuario';

foreach (['apemat', 'nombre', 'usuario'] as $clave) {

    if (!empty($_SESSION[$clave])) {

        $nombre_mostrar = $_SESSION[$clave];

        break;

    }

}



// Función para formato dinero

function formatoDinero($cantidad)

{

    return '$' . number_format($cantidad, 2);

}

?>



<!DOCTYPE html>

<html lang="es">



<head>

    <meta charset="UTF-8">

    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Dashboard Admin - Panel de Control</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">

    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet">

    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

    <link href="CSS/opAdmin.css?v=<?= filemtime(__DIR__ . '/CSS/opAdmin.css') ?>" rel="stylesheet">

</head>



<body class="bg-light">

    <nav class="navbar navbar-expand-lg navbar-light bg-white shadow-sm fixed-top border-bottom">

        <div class="container-fluid">

            <a class="navbar-brand" href="#">

                <i class="bi bi-graph-up"></i> CRM Registro Ventas

            </a>

            <div class="d-flex align-items-center ms-auto">

                <ul class="navbar-nav me-3">

                    <li class="nav-item">

                        <a class="nav-link text-dark fw-medium" href="Usuarios/usuarios.php">

                            <i class="bi bi-people me-1"></i>Usuarios

                        </a>

                    </li>

                    <li class="nav-item">

                        <a class="nav-link text-dark fw-medium" href="Agencia/agencia.php">

                            <i class="bi bi-person-badge me-1"></i>Agentes

                        </a>

                    </li>

                    <li class="nav-item">

                        <a class="nav-link text-dark" href="../facturacion/ventas.php" style="text-decoration: none;">

                            🕵️ Ventas

                        </a>

                    </li>

                    <li class="nav-item">

                        <a class="nav-link text-dark" href="../facturacion/gestionar_plataformas.php" style="text-decoration: none;">

                            💳 Plataformas

                        </a>

                    </li>

                    <?php if (($_SESSION['rol'] ?? '') === 'superadmin'): ?>

                    <li class="nav-item">

                        <a class="nav-link text-dark" href="Logs" style="text-decoration: none;">

                            <i class="bi bi-shield-lock me-1"></i>Logs

                        </a>

                    </li>

                    <?php endif; ?>

                </ul>

                <span class="navbar-text text-muted me-3 d-none d-md-block">

                    <?= htmlspecialchars($nombre_mostrar) ?>

                </span>

                <a href="logout.php" class="btn btn-sm btn-outline-danger">

                    <i class="bi bi-box-arrow-right me-1"></i>Salir

                </a>

            </div>

        </div>

    </nav>



    <main style="padding: 2em; margin-top: 80px;">

        <div class="container-fluid">

            <!-- Welcome Section -->

            <div class="welcome-section">

                <div class="row align-items-center">

                    <div class="col-md-6">

                        <h1 class="display-6 fw-bold">Bienvenido, <?= htmlspecialchars($nombre_mostrar) ?>!</h1>

                        <p class="lead mb-0">Panel de control y estadísticas de rendimiento</p>

                    </div>

                    <div class="col-md-6">

                        <!-- Este espacio ahora está vacío -->

                    </div>

                </div>

            </div>



            <!-- Pestañas -->

            <ul class="nav nav-tabs mb-4" id="myTab" role="tablist">

                <li class="nav-item" role="presentation">

                    <button class="nav-link <?= $tab_activa == 'diario' ? 'active' : '' ?>" id="diario-tab" data-bs-toggle="tab" data-bs-target="#diario" type="button" role="tab" aria-controls="diario" aria-selected="<?= $tab_activa == 'diario' ? 'true' : 'false' ?>">

                        <i class="bi bi-calendar-day me-2"></i>Vista Diaria

                    </button>

                </li>

                <li class="nav-item" role="presentation">

                    <button class="nav-link <?= $tab_activa == 'mensual' ? 'active' : '' ?>" id="mensual-tab" data-bs-toggle="tab" data-bs-target="#mensual" type="button" role="tab" aria-controls="mensual" aria-selected="<?= $tab_activa == 'mensual' ? 'true' : 'false' ?>">

                        <i class="bi bi-calendar-month me-2"></i>Vista Mensual

                    </button>

                </li>

            </ul>



            <div class="tab-content" id="myTabContent">

                <!-- Pestaña Diaria -->

                <div class="tab-pane fade <?= $tab_activa == 'diario' ? 'show active' : '' ?>" id="diario" role="tabpanel" aria-labelledby="diario-tab">

                    <!-- Selector de Fecha Diaria y Sala -->

                    <div class="row mb-4">

                        <div class="col-12">

                            <div class="card">

                                <div class="card-body">

                                    <form id="formDiario" class="row g-3 align-items-center">

                                        <div class="col-md-4">

                                            <label for="fecha" class="form-label fw-bold">

                                                <i class="bi bi-calendar3 me-2"></i>Seleccionar Fecha:

                                            </label>

                                            <input type="date"

                                                class="form-control"

                                                id="fecha"

                                                name="fecha"

                                                value="<?= htmlspecialchars($fecha_seleccionada) ?>"

                                                max="<?= date('Y-m-d') ?>">

                                        </div>



                                        <?php if ($acceso_todas_salas == 1): ?>

                                            <!-- Mostrar selector de sala solo si tiene acceso a todas las salas -->

                                            <div class="col-md-4">

                                                <label for="sala_diario" class="form-label fw-bold">

                                                    <i class="bi bi-building me-2"></i>Seleccionar Sala:

                                                </label>

                                                <select class="form-select" id="sala_diario" name="sala">

                                                    <option value="todas" <?= $sala_seleccionada == 'todas' ? 'selected' : '' ?>>Todas las salas</option>

                                                    <?php foreach ($salas as $sala): ?>

                                                        <option value="<?= $sala['id'] ?>" <?= $sala_seleccionada == $sala['id'] ? 'selected' : '' ?>>

                                                            <?= htmlspecialchars($sala['nombre']) ?>

                                                        </option>

                                                    <?php endforeach; ?>

                                                </select>

                                            </div>

                                        <?php else: ?>

                                            <!-- Mostrar sala fija si solo tiene acceso a salas específicas -->

                                            <div class="col-md-4">

                                                <label class="form-label fw-bold">

                                                    <i class="bi bi-building me-2"></i>Sala Asignada:

                                                </label>

                                                <div class="form-control bg-light">

                                                    <?php if (!empty($salas)): ?>

                                                        <?= htmlspecialchars($salas[0]['nombre']) ?>

                                                        <input type="hidden" id="sala_diario" name="sala" value="<?= $salas[0]['id'] ?>">

                                                    <?php else: ?>

                                                        Sin sala asignada

                                                    <?php endif; ?>

                                                </div>

                                            </div>

                                        <?php endif; ?>



                                        <div class="col-md-2">

                                            <button type="submit" class="btn btn-primary mt-4">

                                                <i class="bi bi-search me-2"></i>Buscar

                                            </button>

                                        </div>

                                        <div class="col-md-2 text-end">

                                            <div class="dropdown mt-4">

                                                <button class="btn btn-success dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">

                                                    <i class="bi bi-download me-2"></i>Descargar

                                                </button>

                                                <ul class="dropdown-menu">

                                                    <li>

                                                        <a class="dropdown-item" href="reporte_ganancia_volumen.php?<?= htmlspecialchars(http_build_query(['fecha' => $fecha_seleccionada, 'sala' => $sala_seleccionada])) ?>" target="_blank">

                                                            <i class="bi bi-file-earmark-excel me-2 text-success"></i>Excel Diario

                                                        </a>

                                                    </li>

                                                    <li>

                                                        <a class="dropdown-item" href="reporte_pdf_ganancia_volumen.php?<?= htmlspecialchars(http_build_query(['fecha' => $fecha_seleccionada, 'sala' => $sala_seleccionada])) ?>" target="_blank">

                                                            <i class="bi bi-file-earmark-pdf me-2 text-danger"></i>PDF Diario

                                                        </a>

                                                    </li>

                                                </ul>

                                            </div>

                                        </div>

                                    </form>

                                </div>

                            </div>

                        </div>

                    </div>



                    <!-- Loading Spinner -->

                    <div id="loadingDiario" class="loading">

                        <div class="spinner-border text-primary loading-spinner" role="status">

                            <span class="visually-hidden">Cargando...</span>

                        </div>

                        <p class="mt-2">Cargando datos...</p>

                    </div>



                    <!-- Contenido Dinámico Diario -->

                    <div id="contenidoDiario">

                        <!-- Fecha y Sala Seleccionada -->

                        <div class="row mb-4">

                            <div class="col-12">

                                <div class="alert alert-info d-flex justify-content-between align-items-center">

                                    <div>

                                        <i class="bi bi-calendar-check me-2"></i>

                                        <strong>Mostrando datos para:</strong>

                                        <span class="ms-2 fw-bold"><?= $fecha_mostrar ?></span>

                                        <span class="ms-3">

                                            <i class="bi bi-building me-2"></i>

                                            <strong>Sala:</strong>

                                            <span class="badge sala-badge ms-2"><?= htmlspecialchars($nombre_sala_seleccionada) ?></span>

                                        </span>

                                    </div>

                                    <?php if ($fecha_seleccionada == date('Y-m-d')): ?>

                                        <span class="badge bg-success">

                                            <i class="bi bi-circle-fill me-1"></i>En Vivo

                                        </span>

                                    <?php endif; ?>

                                </div>

                            </div>

                        </div>



                        <!-- Statistics Cards Diarias -->

                        <div class="row mb-4">

                            <!-- Tarjeta 1: Total Llamadas -->

                            <div class="col-xl-2 col-md-4 mb-4">

                                <div class="card stat-card card-hover border-0 h-100">

                                    <div class="card-body">

                                        <div class="d-flex justify-content-between">

                                            <div>

                                                <h6 class="card-title text-muted mb-2">Total Llamadas</h6>

                                                <h3 class="fw-bold text-primary"><?= $total_llamadas ?></h3>

                                                <small class="text-muted"><?= $fecha_seleccionada == date('Y-m-d') ? 'En tiempo real' : 'Completado' ?></small>

                                            </div>

                                            <div class="align-self-center">

                                                <i class="bi bi-telephone-fill text-primary fs-2"></i>

                                            </div>

                                        </div>

                                    </div>

                                </div>

                            </div>



                            <!-- Tarjeta 2: Agentes Activos -->

                            <div class="col-xl-2 col-md-4 mb-4">

                                <div class="card stat-card card-hover border-0 h-100">

                                    <div class="card-body">

                                        <div class="d-flex justify-content-between">

                                            <div>

                                                <h6 class="card-title text-muted mb-2">Agentes Activos</h6>

                                                <h3 class="fw-bold text-success"><?= $total_agentes ?></h3>

                                                <small class="text-muted"><?= $fecha_seleccionada == date('Y-m-d') ? 'Conectados' : 'Participaron' ?></small>

                                            </div>

                                            <div class="align-self-center">

                                                <i class="bi bi-people-fill text-success fs-2"></i>

                                            </div>

                                        </div>

                                    </div>

                                </div>

                            </div>



                            <!-- Tarjeta 3: Promedio por Agente -->

                            <div class="col-xl-2 col-md-4 mb-4">

                                <div class="card stat-card card-hover border-0 h-100">

                                    <div class="card-body">

                                        <div class="d-flex justify-content-between">

                                            <div>

                                                <h6 class="card-title text-muted mb-2">Promedio por Agente</h6>

                                                <h3 class="fw-bold text-warning"><?= $promedio_llamadas ?></h3>

                                                <small class="text-muted">Llamadas por agente</small>

                                            </div>

                                            <div class="align-self-center">

                                                <i class="bi bi-graph-up text-warning fs-2"></i>

                                            </div>

                                        </div>

                                    </div>

                                </div>

                            </div>



                            <!-- Tarjeta 4: Top Performance -->

                            <div class="col-xl-3 col-md-6 mb-4">

                                <div class="card stat-card card-hover border-0 h-100">

                                    <div class="card-body">

                                        <div class="d-flex justify-content-between">

                                            <div>

                                                <h6 class="card-title text-muted mb-2">Top Performance</h6>

                                                <h3 class="fw-bold text-info"><?= !empty($top_usuarios) ? htmlspecialchars($top_usuarios[0]['apemat']) : 'N/A' ?></h3>

                                                <small class="text-muted">Líder del día</small>

                                            </div>

                                            <div class="align-self-center">

                                                <i class="bi bi-trophy-fill text-info fs-2"></i>

                                            </div>

                                        </div>

                                    </div>

                                </div>

                            </div>



                            <!-- Tarjeta 5: Ganancia Volumen -->

                            <div class="col-xl-3 col-md-6 mb-4">

                                <div class="card stat-card-ganancia card-hover border-0 h-100">

                                    <div class="card-body">

                                        <div class="d-flex justify-content-between">

                                            <div>

                                                <h6 class="card-title text-muted mb-2">Venta Bruta Total</h6>

                                                <h3 class="fw-bold text-warning"><?= formatoDinero($venta_bruta_diaria) ?></h3>

                                                <small class="text-muted">Acumulado del día</small>

                                            </div>

                                            <div class="align-self-center">

                                                <i class="bi bi-currency-dollar money-icon fs-2"></i>

                                            </div>

                                        </div>

                                    </div>

                                </div>

                            </div>

                        </div>



                        <!-- Charts and Top Agents Diarios -->

                        <div class="row">

                            <!-- Gráfica de Barras Diaria -->

                            <div class="col-xl-8 col-lg-7 mb-4">

                                <div class="card border-0 card-hover h-100">

                                    <div class="card-header bg-white border-0 py-3">

                                        <h5 class="card-title mb-0 fw-bold text-dark">

                                            <i class="bi bi-bar-chart-fill text-primary me-2"></i>

                                            Desempeño de Agentes - <?= $fecha_mostrar ?>

                                            <span class="badge bg-info ms-2"><?= htmlspecialchars($nombre_sala_seleccionada) ?></span>

                                        </h5>

                                    </div>

                                    <div class="card-body">

                                        <?php if (!empty($todos_usuarios)): ?>

                                            <div style="height: 400px;">

                                                <canvas id="graficaBarras"></canvas>

                                            </div>

                                        <?php else: ?>

                                            <div class="text-center py-5">

                                                <i class="bi bi-inbox fs-1 text-muted"></i>

                                                <p class="text-muted mt-3">No hay datos para la fecha y sala seleccionadas</p>

                                            </div>

                                        <?php endif; ?>

                                    </div>

                                </div>

                            </div>



                            <!-- Top 3 Agentes Diarios -->

                            <div class="col-xl-4 col-lg-5 mb-4">

                                <div class="card border-0 card-hover h-100">

                                    <div class="card-header bg-white border-0 py-3">

                                        <h5 class="card-title mb-0 fw-bold text-dark">

                                            <i class="bi bi-trophy-fill text-warning me-2"></i>

                                            Top 3 Agentes - Día

                                            <span class="badge bg-info ms-2"><?= htmlspecialchars($nombre_sala_seleccionada) ?></span>

                                        </h5>

                                    </div>

                                    <div class="card-body">

                                        <?php if (!empty($top_usuarios)): ?>

                                            <div class="d-flex flex-column">

                                                <?php foreach ($top_usuarios as $index => $usuario): ?>

                                                    <div class="podium-item p-3 text-white <?= 'podium-' . ($index + 1) ?> mb-3">

                                                        <div class="d-flex justify-content-between align-items-center">

                                                            <div class="d-flex align-items-center">

                                                                <div class="rank-circle bg-white text-dark rounded-circle d-flex align-items-center justify-content-center me-3"

                                                                    style="width: 40px; height: 40px; font-weight: bold;">

                                                                    <?= $index + 1 ?>

                                                                </div>

                                                                <div>

                                                                    <h6 class="mb-0 fw-bold"><?= htmlspecialchars($usuario['apemat']) ?></h6>

                                                                    <small>Agente</small>

                                                                </div>

                                                            </div>

                                                            <div class="text-end">

                                                                <h4 class="mb-0 fw-bold"><?= $usuario['cantidad'] ?></h4>

                                                                <small>Llamadas</small>

                                                            </div>

                                                        </div>

                                                    </div>

                                                <?php endforeach; ?>

                                            </div>

                                        <?php else: ?>

                                            <div class="text-center py-4">

                                                <i class="bi bi-emoji-frown fs-1 text-muted"></i>

                                                <p class="text-muted mt-2">No hay agentes con llamadas</p>

                                            </div>

                                        <?php endif; ?>

                                    </div>

                                </div>

                            </div>

                        </div>

                    </div>

                </div>



                <!-- Pestaña Mensual -->

                <div class="tab-pane fade <?= $tab_activa == 'mensual' ? 'show active' : '' ?>" id="mensual" role="tabpanel" aria-labelledby="mensual-tab">

                    <!-- Selector de Mes y Sala -->

                    <div class="row mb-4">

                        <div class="col-12">

                            <div class="card">

                                <div class="card-body">

                                    <form id="formMensual" class="row g-3 align-items-center">

                                        <div class="col-md-4">

                                            <label for="mes" class="form-label fw-bold">

                                                <i class="bi bi-calendar-month me-2"></i>Seleccionar Mes:

                                            </label>

                                            <input type="month"

                                                class="form-control"

                                                id="mes"

                                                name="mes"

                                                value="<?= htmlspecialchars($mes_seleccionado) ?>"

                                                max="<?= date('Y-m') ?>">

                                        </div>



                                        <?php if ($acceso_todas_salas == 1): ?>

                                            <!-- Mostrar selector de sala solo si tiene acceso a todas las salas -->

                                            <div class="col-md-4">

                                                <label for="sala_mensual" class="form-label fw-bold">

                                                    <i class="bi bi-building me-2"></i>Seleccionar Sala:

                                                </label>

                                                <select class="form-select" id="sala_mensual" name="sala">

                                                    <option value="todas" <?= $sala_seleccionada == 'todas' ? 'selected' : '' ?>>Todas las salas</option>

                                                    <?php foreach ($salas as $sala): ?>

                                                        <option value="<?= $sala['id'] ?>" <?= $sala_seleccionada == $sala['id'] ? 'selected' : '' ?>>

                                                            <?= htmlspecialchars($sala['nombre']) ?>

                                                        </option>

                                                    <?php endforeach; ?>

                                                </select>

                                            </div>

                                        <?php else: ?>

                                            <!-- Mostrar sala fija si solo tiene acceso a salas específicas -->

                                            <div class="col-md-4">

                                                <label class="form-label fw-bold">

                                                    <i class="bi bi-building me-2"></i>Sala Asignada:

                                                </label>

                                                <div class="form-control bg-light">

                                                    <?php if (!empty($salas)): ?>

                                                        <?= htmlspecialchars($salas[0]['nombre']) ?>

                                                        <input type="hidden" id="sala_mensual" name="sala" value="<?= $salas[0]['id'] ?>">

                                                    <?php else: ?>

                                                        Sin sala asignada

                                                    <?php endif; ?>

                                                </div>

                                            </div>

                                        <?php endif; ?>



                                        <div class="col-md-2">

                                            <button type="submit" class="btn btn-primary mt-4">

                                                <i class="bi bi-search me-2"></i>Buscar

                                            </button>

                                        </div>

                                        <div class="col-md-2 text-end">

                                            <div class="dropdown mt-4">

                                                <button class="btn btn-success dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">

                                                    <i class="bi bi-download me-2"></i>Descargar

                                                </button>

                                                <ul class="dropdown-menu">

                                                    <li>

                                                        <a class="dropdown-item" href="reporte_ganancia_volumen_mensual.php?<?= htmlspecialchars(http_build_query(['mes' => $mes_seleccionado, 'sala' => $sala_seleccionada])) ?>" target="_blank">

                                                            <i class="bi bi-file-earmark-excel me-2 text-success"></i>Excel Mensual

                                                        </a>

                                                    </li>

                                                    <li>

                                                        <a class="dropdown-item" href="reporte_pdf_ganancia_volumen_mensual.php?<?= htmlspecialchars(http_build_query(['mes' => $mes_seleccionado, 'sala' => $sala_seleccionada])) ?>" target="_blank">

                                                            <i class="bi bi-file-earmark-pdf me-2 text-danger"></i>PDF Mensual

                                                        </a>

                                                    </li>

                                                </ul>

                                            </div>

                                        </div>

                                    </form>

                                </div>

                            </div>

                        </div>

                    </div>



                    <!-- Loading Spinner -->

                    <div id="loadingMensual" class="loading">

                        <div class="spinner-border text-primary loading-spinner" role="status">

                            <span class="visually-hidden">Cargando...</span>

                        </div>

                        <p class="mt-2">Cargando datos...</p>

                    </div>



                    <!-- Contenido Dinámico Mensual -->

                    <div id="contenidoMensual">

                        <!-- Fecha y Sala Seleccionada Mensual -->

                        <div class="row mb-4">

                            <div class="col-12">

                                <div class="alert alert-success d-flex justify-content-between align-items-center">

                                    <div>

                                        <i class="bi bi-calendar-month me-2"></i>

                                        <strong>Mostrando datos para:</strong>

                                        <span class="ms-2 fw-bold"><?= $anio_mes_mostrar ?></span>

                                        <span class="ms-3">

                                            <i class="bi bi-building me-2"></i>

                                            <strong>Sala:</strong>

                                            <span class="badge sala-badge ms-2"><?= htmlspecialchars($nombre_sala_seleccionada) ?></span>

                                        </span>

                                    </div>

                                    <?php if ($mes_seleccionado == date('Y-m')): ?>

                                        <span class="badge bg-success">

                                            <i class="bi bi-circle-fill me-1"></i>Mes Actual

                                        </span>

                                    <?php endif; ?>

                                </div>

                            </div>

                        </div>



                        <!-- Statistics Cards Mensuales -->

                        <div class="row mb-4">

                            <!-- Tarjeta 1: Total Llamadas Mes -->

                            <div class="col-xl-2 col-md-4 mb-4">

                                <div class="card stat-card-mensual card-hover border-0 h-100">

                                    <div class="card-body">

                                        <div class="d-flex justify-content-between">

                                            <div>

                                                <h6 class="card-title text-muted mb-2">Total Llamadas Mes</h6>

                                                <h3 class="fw-bold text-success"><?= $total_mensual ?></h3>

                                                <small class="text-muted">Acumulado mensual</small>

                                            </div>

                                            <div class="align-self-center">

                                                <i class="bi bi-telephone-fill text-success fs-2"></i>

                                            </div>

                                        </div>

                                    </div>

                                </div>

                            </div>



                            <!-- Tarjeta 2: Agentes Activos -->

                            <div class="col-xl-2 col-md-4 mb-4">

                                <div class="card stat-card-mensual card-hover border-0 h-100">

                                    <div class="card-body">

                                        <div class="d-flex justify-content-between">

                                            <div>

                                                <h6 class="card-title text-muted mb-2">Agentes Activos</h6>

                                                <h3 class="fw-bold text-info"><?= $total_agentes_mensual ?></h3>

                                                <small class="text-muted">Este mes</small>

                                            </div>

                                            <div class="align-self-center">

                                                <i class="bi bi-people-fill text-info fs-2"></i>

                                            </div>

                                        </div>

                                    </div>

                                </div>

                            </div>



                            <!-- Tarjeta 3: Promedio por Agente -->

                            <div class="col-xl-2 col-md-4 mb-4">

                                <div class="card stat-card-mensual card-hover border-0 h-100">

                                    <div class="card-body">

                                        <div class="d-flex justify-content-between">

                                            <div>

                                                <h6 class="card-title text-muted mb-2">Promedio por Agente</h6>

                                                <h3 class="fw-bold text-warning"><?= $promedio_mensual ?></h3>

                                                <small class="text-muted">Llamadas por agente</small>

                                            </div>

                                            <div class="align-self-center">

                                                <i class="bi bi-graph-up text-warning fs-2"></i>

                                            </div>

                                        </div>

                                    </div>

                                </div>

                            </div>



                            <!-- Tarjeta 4: Top Performance Mes -->

                            <div class="col-xl-3 col-md-6 mb-4">

                                <div class="card stat-card-mensual card-hover border-0 h-100">

                                    <div class="card-body">

                                        <div class="d-flex justify-content-between">

                                            <div>

                                                <h6 class="card-title text-muted mb-2">Top Performance Mes</h6>

                                                <h3 class="fw-bold text-primary"><?= !empty($top3_mensual) ? htmlspecialchars($top3_mensual[0]['apemat']) : 'N/A' ?></h3>

                                                <small class="text-muted">Líder del mes</small>

                                            </div>

                                            <div class="align-self-center">

                                                <i class="bi bi-trophy-fill text-primary fs-2"></i>

                                            </div>

                                        </div>

                                    </div>

                                </div>

                            </div>



                            <!-- Tarjeta 5: Ganancia Volumen -->

                            <div class="col-xl-3 col-md-6 mb-4">

                                <div class="card stat-card-ganancia card-hover border-0 h-100">

                                    <div class="card-body">

                                        <div class="d-flex justify-content-between">

                                            <div>

                                                <h6 class="card-title text-muted mb-2">Venta Bruta Total</h6>

                                                <h3 class="fw-bold text-warning"><?= formatoDinero($venta_bruta_mensual) ?></h3>

                                                <small class="text-muted">Acumulado mensual</small>

                                            </div>

                                            <div class="align-self-center">

                                                <i class="bi bi-currency-dollar money-icon fs-2"></i>

                                            </div>

                                        </div>

                                    </div>

                                </div>

                            </div>

                        </div>



                        <!-- NUEVA SECCIÓN: GRÁFICA LINEAL DE VENTAS POR DÍA -->

                        <div class="row mb-4">

                            <div class="col-12">

                                <div class="card border-0 card-hover">

                                    <div class="card-header bg-white border-0 py-3">

                                        <h5 class="card-title mb-0 fw-bold text-dark">

                                            <i class="bi bi-graph-up text-success me-2"></i>

                                            Venta Bruta por Día - <?= $anio_mes_mostrar ?>

                                            <span class="badge bg-info ms-2"><?= htmlspecialchars($nombre_sala_seleccionada) ?></span>

                                        </h5>

                                    </div>

                                    <div class="card-body">

                                        <?php if (!empty($ventas_por_dia_array)): ?>

                                            <div style="height: 300px;">

                                                <canvas id="graficaLinealVentas"></canvas>

                                            </div>

                                        <?php else: ?>

                                            <div class="text-center py-4">

                                                <i class="bi bi-graph-up text-muted fs-1"></i>

                                                <p class="text-muted mt-2">No hay datos de ventas para el mes seleccionado</p>

                                            </div>

                                        <?php endif; ?>

                                    </div>

                                </div>

                            </div>

                        </div>



                        <!-- Charts and Top Agents Mensuales -->

                        <div class="row">

                            <!-- Gráfica de Barras Mensual -->

                            <div class="col-xl-8 col-lg-7 mb-4">

                                <div class="card border-0 card-hover h-100">

                                    <div class="card-header bg-white border-0 py-3">

                                        <h5 class="card-title mb-0 fw-bold text-dark">

                                            <i class="bi bi-bar-chart-fill text-success me-2"></i>

                                            Desempeño de Agentes - <?= $anio_mes_mostrar ?>

                                            <span class="badge bg-info ms-2"><?= htmlspecialchars($nombre_sala_seleccionada) ?></span>

                                        </h5>

                                    </div>

                                    <div class="card-body">

                                        <?php if (!empty($usuarios_mensual)): ?>

                                            <div style="height: 400px;">

                                                <canvas id="graficaBarrasMensual"></canvas>

                                            </div>

                                        <?php else: ?>

                                            <div class="text-center py-5">

                                                <i class="bi bi-inbox fs-1 text-muted"></i>

                                                <p class="text-muted mt-3">No hay datos para el mes y sala seleccionados</p>

                                            </div>

                                        <?php endif; ?>

                                    </div>

                                </div>

                            </div>



                            <!-- Top 3 Agentes Mensuales -->

                            <div class="col-xl-4 col-lg-5 mb-4">

                                <div class="card border-0 card-hover h-100">

                                    <div class="card-header bg-white border-0 py-3">

                                        <h5 class="card-title mb-0 fw-bold text-dark">

                                            <i class="bi bi-trophy-fill text-success me-2"></i>

                                            Top 3 Agentes - Mes

                                            <span class="badge bg-info ms-2"><?= htmlspecialchars($nombre_sala_seleccionada) ?></span>

                                        </h5>

                                    </div>

                                    <div class="card-body">

                                        <?php if (!empty($top3_mensual)): ?>

                                            <div class="d-flex flex-column">

                                                <?php foreach ($top3_mensual as $index => $usuario): ?>

                                                    <div class="podium-item p-3 text-white <?= 'podium-' . ($index + 1) ?> mb-3">

                                                        <div class="d-flex justify-content-between align-items-center">

                                                            <div class="d-flex align-items-center">

                                                                <div class="rank-circle bg-white text-dark rounded-circle d-flex align-items-center justify-content-center me-3"

                                                                    style="width: 40px; height: 40px; font-weight: bold;">

                                                                    <?= $index + 1 ?>

                                                                </div>

                                                                <div>

                                                                    <h6 class="mb-0 fw-bold"><?= htmlspecialchars($usuario['apemat']) ?></h6>

                                                                    <small>Agente</small>

                                                                </div>

                                                            </div>

                                                            <div class="text-end">

                                                                <h4 class="mb-0 fw-bold"><?= $usuario['cantidad'] ?></h4>

                                                                <small>Llamadas</small>

                                                            </div>

                                                        </div>

                                                    </div>

                                                <?php endforeach; ?>

                                            </div>

                                        <?php else: ?>

                                            <div class="text-center py-4">

                                                <i class="bi bi-emoji-frown fs-1 text-muted"></i>

                                                <p class="text-muted mt-2">No hay agentes con llamadas este mes</p>

                                            </div>

                                        <?php endif; ?>

                                    </div>

                                </div>

                            </div>

                        </div>

                    </div>

                </div>

            </div>

        </div>

    </main>



    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

    <script>
        // Datos del servidor para el JS de esta pagina
        const datosDashboard = <?= json_encode([
            'diario'  => ['etiquetas' => array_column($todos_usuarios, 'apemat'), 'cantidades' => array_column($todos_usuarios, 'cantidad')],
            'mensual' => ['etiquetas' => array_column($usuarios_mensual, 'apemat'), 'cantidades' => array_column($usuarios_mensual, 'cantidad')],
            'lineal'  => ['dias' => $dias_del_mes ?? [], 'ventas' => $ventas_por_dia_array ?? []],
        ], JSON_HEX_TAG | JSON_HEX_AMP) ?>;
    </script>
    <script src="JS/opAdmin.js?v=<?= filemtime(__DIR__ . '/JS/opAdmin.js') ?>"></script>

</body>



</html>

