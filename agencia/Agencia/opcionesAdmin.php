<?php require_once __DIR__ . '/../../includes/db.php';
ini_set('session.gc_probability', 1);
ini_set('session.gc_divisor', 1000); // Solo 0.1% de probabilidad de limpieza
ini_set('session.cookie_secure', 0);
ini_set('session.cookie_httponly', 1);
ini_set('session.use_strict_mode', 1);
header('Access-Control-Allow-Origin: *');
require_once __DIR__ . '/../../session_config.php'; session_start();
date_default_timezone_set('America/Mexico_City');

// Validación de roles
if (!isset($_SESSION['usuario_id'])) {
    header("Location: ./login.php");
    exit;
}

// Redirección según el rol
$rol = $_SESSION['rol'] ?? '';
if ($rol === 'usuario') {
    header("Location: agencia.php");
    exit;
} elseif ($rol !== 'admin' && $rol !== 'superadmin') {
    header("Location: ./login.php");
    exit;
}
// El admin y el superadmin se quedan en esta página

// Conexión a la base de datos
require_once __DIR__ . '/../config/database.php';

// Obtener permisos del admin desde la base de datos
$stmt_permisos = $conn->prepare("SELECT acceso_todas_salas, salas_permitidas FROM usuarios WHERE id = ?");
$stmt_permisos->execute([$_SESSION['usuario_id']]);
$permisos = $stmt_permisos->fetch(PDO::FETCH_ASSOC) ?: [];

// Superadmin siempre tiene acceso a todas las salas
$acceso_todas_salas = $rol === 'superadmin' ? 1 : (int)($permisos['acceso_todas_salas'] ?? 0);
$salas_permitidas = json_decode($permisos['salas_permitidas'] ?? '[]', true) ?: [];

// Determinar qué pestaña está activa y limpiar parámetros
$tab_activa = 'diario';
$fecha_seleccionada = date('Y-m-d');
$mes_seleccionado = date('Y-m');
$sala_seleccionada = $_GET['sala'] ?? 'todas';

// Si el admin no tiene acceso a todas las salas y tiene salas permitidas
if ($acceso_todas_salas == 0 && !empty($salas_permitidas)) {
    // Forzar la primera sala permitida como selección
    $sala_seleccionada = $salas_permitidas[0];
}

// Si hay parámetro de fecha, mostrar solo fecha
if (isset($_GET['fecha'])) {
    $tab_activa = 'diario';
    $fecha_seleccionada = $_GET['fecha'];
}

// Si hay parámetro de mes (y no fecha), mostrar solo mes
if (isset($_GET['mes']) && !isset($_GET['fecha'])) {
    $tab_activa = 'mensual';
    $mes_seleccionado = $_GET['mes'];
}

// Si hay parámetro de sala, validar que el admin tenga acceso
if (isset($_GET['sala'])) {
    if ($acceso_todas_salas == 1 || in_array($_GET['sala'], $salas_permitidas)) {
        $sala_seleccionada = $_GET['sala'];
    }
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
// Si no tiene salas permitidas, la lista queda vacía

// Formatear fechas para mostrar
$fecha_mostrar = date('d M Y', strtotime($fecha_seleccionada));
$anio_mes_mostrar = date('F Y', strtotime($mes_seleccionado . '-01'));
$anio = date('Y', strtotime($mes_seleccionado . '-01'));
$mes = date('m', strtotime($mes_seleccionado . '-01'));

// Filtro por sala: condición SQL y parámetro extra para las consultas
$filtrar_sala = $sala_seleccionada !== 'todas' && is_numeric($sala_seleccionada);
$params_sala = $filtrar_sala ? [(int)$sala_seleccionada] : [];
$condicion_sala_clientes = $filtrar_sala ? " AND c.sala_id = ? " : "";
$condicion_sala_ventas = $filtrar_sala ? " AND v.sala_id = ? " : "";

// Obtener nombre de la sala seleccionada
$nombre_sala_seleccionada = "Todas las salas";
if ($filtrar_sala) {
    $stmt_nombre = $conn->prepare("SELECT nombre FROM salas WHERE id = ?");
    $stmt_nombre->execute($params_sala);
    if ($row = $stmt_nombre->fetch(PDO::FETCH_ASSOC)) {
        $nombre_sala_seleccionada = $row['nombre'];
    }
}

// ============================================================================
// GANANCIA VOLUMEN Y VENTA BRUTA
// ============================================================================

// Suma venta bruta y ganancia volumen de un conjunto de ventas
function calcularVentas(PDOStatement $stmt): array
{
    $bruta = 0;
    $ganancia = 0;
    while ($venta = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $ventaBruta = (float)$venta['precio'];
        $bruta += $ventaBruta;

        if (!empty($venta['liner_nombre'])) {
            $ganancia += $ventaBruta / 2;
        }
        if (!empty($venta['closer1_nombre'])) {
            $ganancia += $ventaBruta / 2;
        }
        if (!empty($venta['closer2_nombre'])) {
            $ganancia += $ventaBruta / 3;
        }
        if (!empty($venta['front_nombre'])) {
            $ganancia += $ventaBruta;
        }
    }
    return [$bruta, $ganancia];
}

$ganancia_volumen_diaria = 0;
$ganancia_volumen_mensual = 0;
$venta_bruta_diaria = 0;
$venta_bruta_mensual = 0;
$ventas_por_dia_array = [];
$dias_del_mes = [];

try {
    $select_ventas = "
        SELECT v.precio,
            v.liner_id, v.closer1_id, v.closer2_id, v.full_stack_id,
            liner.apemat as liner_nombre,
            c1.apemat as closer1_nombre,
            c2.apemat as closer2_nombre,
            fs.apemat as front_nombre
        FROM ventas_facturacion v
        LEFT JOIN usuarios liner ON v.liner_id = liner.id
        LEFT JOIN usuarios c1 ON v.closer1_id = c1.id
        LEFT JOIN usuarios c2 ON v.closer2_id = c2.id
        LEFT JOIN usuarios fs ON v.full_stack_id = fs.id
    ";

    // CALCULAR GANANCIA VOLUMEN DIARIA Y VENTA BRUTA
    $stmt_ventas_dia = $conn->prepare($select_ventas . " WHERE DATE(v.fecha) = ? " . $condicion_sala_ventas);
    $stmt_ventas_dia->execute(array_merge([$fecha_seleccionada], $params_sala));
    [$venta_bruta_diaria, $ganancia_volumen_diaria] = calcularVentas($stmt_ventas_dia);

    // CALCULAR GANANCIA VOLUMEN MENSUAL Y VENTA BRUTA
    $stmt_ventas_mes = $conn->prepare($select_ventas . " WHERE YEAR(v.fecha) = ? AND MONTH(v.fecha) = ? " . $condicion_sala_ventas);
    $stmt_ventas_mes->execute(array_merge([$anio, $mes], $params_sala));
    [$venta_bruta_mensual, $ganancia_volumen_mensual] = calcularVentas($stmt_ventas_mes);

    // ============================================================================
    // CONSULTA PARA GRAFICA LINEAL - VENTA BRUTA POR DÍA DEL MES
    // ============================================================================

    // Inicializar todos los días del mes (1 al último día) en 0
    $ultimo_dia = (int)date('t', strtotime($mes_seleccionado . '-01'));
    $dias_del_mes = range(1, $ultimo_dia);
    $ventas_por_dia = array_fill(1, $ultimo_dia, 0);

    // Consultar ventas por día del mes
    $query_ventas_por_dia = "
        SELECT DAY(v.fecha) as dia, SUM(v.precio) as total_venta
        FROM ventas_facturacion v
        WHERE YEAR(v.fecha) = ? AND MONTH(v.fecha) = ?
        {$condicion_sala_ventas}
        GROUP BY DAY(v.fecha) ORDER BY dia
    ";
    $stmt_ventas_por_dia = $conn->prepare($query_ventas_por_dia);
    $stmt_ventas_por_dia->execute(array_merge([$anio, $mes], $params_sala));
    while ($row = $stmt_ventas_por_dia->fetch(PDO::FETCH_ASSOC)) {
        $ventas_por_dia[(int)$row['dia']] = (float)$row['total_venta'];
    }

    // Convertir el array asociativo a array indexado para JavaScript
    $ventas_por_dia_array = array_values($ventas_por_dia);
} catch (Exception $e) {
    error_log("Error al calcular ventas en opcionesAdmin.php: " . $e->getMessage());
    $ganancia_volumen_diaria = 0;
    $ganancia_volumen_mensual = 0;
    $venta_bruta_diaria = 0;
    $venta_bruta_mensual = 0;
    $ventas_por_dia_array = [];
    $dias_del_mes = [];
}

// ============================================================================
// CONSULTAS PARA LLAMADAS (INCLUYEN FILTRO DE SALA)
// ============================================================================

// Limpia símbolos y espacios repetidos del apellido materno
function limpiarApemat($apemat)
{
    $apemat_limpio = preg_replace('/[^\p{L}\p{N}\s]/u', '', (string)$apemat);
    return trim(preg_replace('/\s+/', ' ', $apemat_limpio));
}

// Llamadas por usuario (liner o closer), ordenadas de mayor a menor
function llamadasPorUsuario(PDO $conn, string $condiciones, array $params): array
{
    $stmt = $conn->prepare("
        SELECT
            u.apemat,
            COUNT(*) as cantidad
        FROM cliente c
        INNER JOIN usuarios u ON
            u.id = c.usuario_id OR u.id = c.usuario_closer
        WHERE {$condiciones}
        GROUP BY u.apemat
        ORDER BY cantidad DESC
    ");
    $stmt->execute($params);

    $resultado = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $resultado[] = [
            'apemat' => limpiarApemat($row['apemat']),
            'cantidad' => $row['cantidad']
        ];
    }
    return $resultado;
}

// TODOS los usuarios de la fecha seleccionada y su top 3
$todos_usuarios = llamadasPorUsuario($conn, "DATE(c.fecha_registro) = ? " . $condicion_sala_clientes, array_merge([$fecha_seleccionada], $params_sala));
$top_usuarios = array_slice($todos_usuarios, 0, 3);

// Volumen mensual y su top 3
$usuarios_mensual = llamadasPorUsuario($conn, "YEAR(c.fecha_registro) = ? AND MONTH(c.fecha_registro) = ? " . $condicion_sala_clientes, array_merge([$anio, $mes], $params_sala));
$top3_mensual = array_slice($usuarios_mensual, 0, 3);
$total_mensual = array_sum(array_column($usuarios_mensual, 'cantidad'));

// Estadísticas diarias
$total_llamadas = array_sum(array_column($todos_usuarios, 'cantidad'));
$total_agentes = count($todos_usuarios);
$promedio_llamadas = $total_agentes > 0 ? round($total_llamadas / $total_agentes, 1) : 0;

// Estadísticas mensuales
$total_agentes_mensual = count($usuarios_mensual);
$promedio_mensual = $total_agentes_mensual > 0 ? round($total_mensual / $total_agentes_mensual, 1) : 0;

// Nombre a mostrar del usuario en sesión
$nombre_usuario_sesion = !empty($_SESSION['apemat']) ? $_SESSION['apemat']
    : (!empty($_SESSION['nombre']) ? $_SESSION['nombre']
    : (!empty($_SESSION['usuario']) ? $_SESSION['usuario'] : 'Usuario'));

// Parámetros escapados para atributos y enlaces
$fecha_attr = htmlspecialchars($fecha_seleccionada);
$mes_attr = htmlspecialchars($mes_seleccionado);
$query_reporte_diario = htmlspecialchars(http_build_query(['fecha' => $fecha_seleccionada, 'sala' => $sala_seleccionada]));
$query_reporte_mensual = htmlspecialchars(http_build_query(['mes' => $mes_seleccionado, 'sala' => $sala_seleccionada]));

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
    <style>
        .card-hover:hover {
            transform: translateY(-5px);
            transition: all 0.3s ease;
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.15) !important;
        }

        .stat-card {
            border-left: 4px solid #007bff;
            background: linear-gradient(135deg, #f8f9fa 0%, #ffffff 100%);
        }

        .stat-card-mensual {
            border-left: 4px solid #28a745;
            background: linear-gradient(135deg, #f0fff4 0%, #ffffff 100%);
        }

        .stat-card-ganancia {
            border-left: 4px solid #ffc107;
            background: linear-gradient(135deg, #fffbf0 0%, #ffffff 100%);
        }

        .podium-item {
            border-radius: 10px;
            margin-bottom: 10px;
            border: none;
        }

        .podium-1 {
            background: linear-gradient(135deg, #FFD700 0%, #FFA500 100%);
        }

        .podium-2 {
            background: linear-gradient(135deg, #C0C0C0 0%, #A9A9A9 100%);
        }

        .podium-3 {
            background: linear-gradient(135deg, #CD7F32 0%, #8B4513 100%);
        }

        .chart-container {
            background: white;
            border-radius: 15px;
            padding: 20px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
        }

        .welcome-section {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border-radius: 15px;
            color: white;
            padding: 2rem;
            margin-bottom: 2rem;
        }

        .date-selector {
            background: white;
            border-radius: 10px;
            padding: 1rem;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
        }

        .btn-excel {
            background: linear-gradient(135deg, #21a366 0%, #107c41 100%);
            border: none;
            color: white;
            font-weight: 600;
        }

        .btn-excel:hover {
            background: linear-gradient(135deg, #107c41 0%, #0d6b37 100%);
            color: white;
            transform: translateY(-2px);
            transition: all 0.3s ease;
        }

        .nav-tabs .nav-link.active {
            font-weight: 600;
            border-bottom: 3px solid #007bff;
        }

        .tab-pane {
            padding-top: 1.5rem;
        }

        .loading {
            display: none;
            text-align: center;
            padding: 20px;
        }

        .loading-spinner {
            width: 3rem;
            height: 3rem;
        }

        .money-icon {
            color: #28a745;
            font-weight: bold;
        }

        .sala-badge {
            background: linear-gradient(135deg, #6c5ce7 0%, #a29bfe 100%);
            color: white;
            font-weight: bold;
        }

        .small-chart-container {
            background: white;
            border-radius: 10px;
            padding: 15px;
            box-shadow: 0 2px 5px rgba(0, 0, 0, 0.1);
        }
    </style>
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
                </ul>
                <span class="navbar-text text-muted me-3 d-none d-md-block">
                    <?= htmlspecialchars($nombre_usuario_sesion) ?>
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
                        <h1 class="display-6 fw-bold">Bienvenido, <?= htmlspecialchars($nombre_usuario_sesion) ?>!</h1>
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
                                                value="<?= $fecha_attr ?>"
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
                                                        <a class="dropdown-item" href="reporte_ganancia_volumen.php?<?= $query_reporte_diario ?>" target="_blank">
                                                            <i class="bi bi-file-earmark-excel me-2 text-success"></i>Excel Diario
                                                        </a>
                                                    </li>
                                                    <li>
                                                        <a class="dropdown-item" href="reporte_pdf_ganancia_volumen.php?<?= $query_reporte_diario ?>" target="_blank">
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
                                                value="<?= $mes_attr ?>"
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
                                                        <a class="dropdown-item" href="reporte_ganancia_volumen_mensual.php?<?= $query_reporte_mensual ?>" target="_blank">
                                                            <i class="bi bi-file-earmark-excel me-2 text-success"></i>Excel Mensual
                                                        </a>
                                                    </li>
                                                    <li>
                                                        <a class="dropdown-item" href="reporte_pdf_ganancia_volumen_mensual.php?<?= $query_reporte_mensual ?>" target="_blank">
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
        document.addEventListener('DOMContentLoaded', function() {
            // Formulario Diario
            const formDiario = document.getElementById('formDiario');
            const loadingDiario = document.getElementById('loadingDiario');
            const contenidoDiario = document.getElementById('contenidoDiario');

            // Formulario Mensual
            const formMensual = document.getElementById('formMensual');
            const loadingMensual = document.getElementById('loadingMensual');
            const contenidoMensual = document.getElementById('contenidoMensual');

            // Manejar formulario diario
            formDiario.addEventListener('submit', function(e) {
                e.preventDefault();
                const fecha = document.getElementById('fecha').value;
                const sala = document.getElementById('sala_diario').value;

                // Mostrar loading
                loadingDiario.style.display = 'block';
                contenidoDiario.style.display = 'none';

                // Actualizar URL con fecha y sala
                const nuevaURL = `opcionesAdmin.php?fecha=${fecha}&sala=${sala}`;
                window.location.href = nuevaURL;
            });

            // Manejar formulario mensual
            formMensual.addEventListener('submit', function(e) {
                e.preventDefault();
                const mes = document.getElementById('mes').value;
                const sala = document.getElementById('sala_mensual').value;

                // Mostrar loading
                loadingMensual.style.display = 'block';
                contenidoMensual.style.display = 'none';

                // Actualizar URL con mes y sala
                const nuevaURL = `opcionesAdmin.php?mes=${mes}&sala=${sala}`;
                window.location.href = nuevaURL;
            });

            // Sincronizar selección de sala entre pestañas solo si ambos son select
            const salaDiario = document.getElementById('sala_diario');
            const salaMensual = document.getElementById('sala_mensual');

            if (salaDiario && salaMensual && salaDiario.tagName === 'SELECT' && salaMensual.tagName === 'SELECT') {
                salaDiario.addEventListener('change', function() {
                    salaMensual.value = this.value;
                });

                salaMensual.addEventListener('change', function() {
                    salaDiario.value = this.value;
                });
            }

            // Inicializar gráficas
            <?php if (!empty($todos_usuarios)): ?>
                crearGraficaBarras('graficaBarras',
                    <?= json_encode(array_column($todos_usuarios, 'apemat'), JSON_HEX_TAG | JSON_HEX_AMP) ?>,
                    <?= json_encode(array_column($todos_usuarios, 'cantidad')) ?>,
                    '65%', '50%');
            <?php endif; ?>
            <?php if (!empty($usuarios_mensual)): ?>
                crearGraficaBarras('graficaBarrasMensual',
                    <?= json_encode(array_column($usuarios_mensual, 'apemat'), JSON_HEX_TAG | JSON_HEX_AMP) ?>,
                    <?= json_encode(array_column($usuarios_mensual, 'cantidad')) ?>,
                    '55%', '40%');
            <?php endif; ?>
            inicializarGraficaLinealVentas();

            // Gráfica de barras de llamadas por agente (diaria y mensual)
            function crearGraficaBarras(canvasId, apellidos, cantidades, luz, luzBorde) {
                const ctx = document.getElementById(canvasId);
                if (!ctx) return;

                const colores = cantidades.map((cantidad, index) => {
                    const hue = (index * 137.508) % 360;
                    return `hsl(${hue}, 70%, ${luz})`;
                });

                new Chart(ctx, {
                    type: 'bar',
                    data: {
                        labels: apellidos,
                        datasets: [{
                            label: 'Llamadas Realizadas',
                            data: cantidades,
                            backgroundColor: colores,
                            borderColor: colores.map(color => color.replace(luz, luzBorde)),
                            borderWidth: 2,
                            borderRadius: 8,
                            borderSkipped: false,
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: {
                            legend: {
                                display: true,
                                position: 'top'
                            },
                            tooltip: {
                                backgroundColor: 'rgba(0, 0, 0, 0.8)',
                                displayColors: false,
                                callbacks: {
                                    label: function(context) {
                                        return `Llamadas: ${context.parsed.y}`;
                                    }
                                }
                            }
                        },
                        scales: {
                            y: {
                                beginAtZero: true
                            },
                            x: {
                                ticks: {
                                    maxRotation: 45,
                                    minRotation: 45
                                }
                            }
                        }
                    }
                });
            }

            function inicializarGraficaLinealVentas() {
                <?php if (!empty($ventas_por_dia_array)): ?>
                    const ctxLineal = document.getElementById('graficaLinealVentas');
                    if (ctxLineal) {
                        const dias = <?= json_encode($dias_del_mes) ?>;
                        const ventas = <?= json_encode($ventas_por_dia_array) ?>;

                        // Formato de dinero para tooltips
                        const formatoMXN = new Intl.NumberFormat('es-MX', {
                            style: 'currency',
                            currency: 'MXN',
                            minimumFractionDigits: 2
                        });

                        // Crear array de etiquetas para los días
                        const labelsDias = dias.map(dia => `Día ${dia}`);

                        new Chart(ctxLineal, {
                            type: 'line',
                            data: {
                                labels: labelsDias,
                                datasets: [{
                                    label: 'Venta Bruta por Día',
                                    data: ventas,
                                    borderColor: '#28a745',
                                    backgroundColor: 'rgba(40, 167, 69, 0.1)',
                                    borderWidth: 3,
                                    fill: true,
                                    tension: 0.4,
                                    pointBackgroundColor: '#28a745',
                                    pointBorderColor: '#ffffff',
                                    pointBorderWidth: 2,
                                    pointRadius: 5,
                                    pointHoverRadius: 8
                                }]
                            },
                            options: {
                                responsive: true,
                                maintainAspectRatio: false,
                                plugins: {
                                    legend: {
                                        display: true,
                                        position: 'top',
                                        labels: {
                                            font: {
                                                size: 14,
                                                weight: 'bold'
                                            }
                                        }
                                    },
                                    tooltip: {
                                        backgroundColor: 'rgba(0, 0, 0, 0.8)',
                                        displayColors: false,
                                        callbacks: {
                                            label: function(context) {
                                                return `Venta: ${formatoMXN.format(context.parsed.y)}`;
                                            }
                                        }
                                    }
                                },
                                scales: {
                                    y: {
                                        beginAtZero: true,
                                        ticks: {
                                            callback: function(value) {
                                                return new Intl.NumberFormat('es-MX', {
                                                    style: 'currency',
                                                    currency: 'MXN',
                                                    minimumFractionDigits: 0
                                                }).format(value);
                                            }
                                        },
                                        grid: {
                                            color: 'rgba(0, 0, 0, 0.1)'
                                        },
                                        title: {
                                            display: true,
                                            text: 'Venta Bruta (MXN)',
                                            font: {
                                                size: 14,
                                                weight: 'bold'
                                            }
                                        }
                                    },
                                    x: {
                                        grid: {
                                            color: 'rgba(0, 0, 0, 0.1)'
                                        },
                                        title: {
                                            display: true,
                                            text: 'Días del Mes',
                                            font: {
                                                size: 14,
                                                weight: 'bold'
                                            }
                                        }
                                    }
                                },
                                interaction: {
                                    intersect: false,
                                    mode: 'index'
                                },
                                elements: {
                                    line: {
                                        tension: 0.4
                                    }
                                }
                            }
                        });
                    }
                <?php endif; ?>
            }
        });
    </script>
</body>

</html>
