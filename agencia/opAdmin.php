<?php

ini_set('session.cookie_lifetime', 86400 * 30); // Hasta que se cierre el navegador

ini_set('session.gc_maxlifetime', 86400 * 30); // 30 días de vida máxima

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



// Redirección según el rol

if ($_SESSION['rol'] == 'usuario') {

    header("Location: agencia.php");

    exit;

} elseif ($_SESSION['rol'] == 'admin' || $_SESSION['rol'] === 'superadmin') {

    // El admin se queda en esta página

} else {

    header("Location: ./login.php");

    exit;

}



// Conexión a la base de datos

require_once 'config/database.php';



// Obtener permisos del admin desde la base de datos

$admin_id = $_SESSION['usuario_id'];

$query_permisos = "SELECT acceso_todas_salas, salas_permitidas FROM usuarios WHERE id = ?";

$stmt_permisos = $conn->prepare($query_permisos);

$stmt_permisos->bind_param("i", $admin_id);

$stmt_permisos->execute();

$result_permisos = $stmt_permisos->get_result();

$permisos = $result_permisos->fetch_assoc();

$stmt_permisos->close();



$acceso_todas_salas = $permisos['acceso_todas_salas'];

$salas_permitidas = json_decode($permisos['salas_permitidas'] ?? '[]', true) ?: [];



// Determinar qué pestaña está activa y limpiar parámetros

$tab_activa = 'diario';

$fecha_seleccionada = date('Y-m-d');

$mes_seleccionado = date('Y-m');

$sala_seleccionada = isset($_GET['sala']) ? $_GET['sala'] : 'todas';



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

if ($acceso_todas_salas == 1) {

    $query_salas = "SELECT id, nombre FROM salas WHERE activo = 1 ORDER BY nombre";

    $result_salas = $conn->query($query_salas);

} else {

    if (!empty($salas_permitidas)) {

        $placeholders = implode(',', array_fill(0, count($salas_permitidas), '?'));

        $query_salas = "SELECT id, nombre FROM salas WHERE activo = 1 AND id IN ($placeholders) ORDER BY nombre";

        $stmt_salas = $conn->prepare($query_salas);



        // Crear tipos de parámetros (todos 'i' para enteros)

        $types = str_repeat('i', count($salas_permitidas));

        $stmt_salas->bind_param($types, ...$salas_permitidas);

        $stmt_salas->execute();

        $result_salas = $stmt_salas->get_result();

    } else {

        // Si no tiene salas permitidas, mostrar solo una opción

        $result_salas = false;

    }

}



$salas = [];

if ($result_salas) {

    while ($sala = $result_salas->fetch_assoc()) {

        $salas[] = $sala;

    }

}



// Formatear fechas para mostrar

$fecha_mostrar = date('d M Y', strtotime($fecha_seleccionada));

$anio_mes_mostrar = date('F Y', strtotime($mes_seleccionado . '-01'));



// Preparar condicionales para las consultas SQL

$condicion_sala_diario = "";

$condicion_sala_mensual = "";

$condicion_sala_ventas_diario = "";

$condicion_sala_ventas_mensual = "";



// Obtener nombre de la sala seleccionada

$nombre_sala_seleccionada = "Todas las salas";

if ($sala_seleccionada !== 'todas' && is_numeric($sala_seleccionada)) {

    $sala_id = (int)$sala_seleccionada;

    $query_nombre_sala = "SELECT nombre FROM salas WHERE id = ?";

    $stmt_nombre = $conn->prepare($query_nombre_sala);

    $stmt_nombre->bind_param("i", $sala_id);

    $stmt_nombre->execute();

    $result_nombre = $stmt_nombre->get_result();

    if ($row = $result_nombre->fetch_assoc()) {

        $nombre_sala_seleccionada = $row['nombre'];

    }

    $stmt_nombre->close();



    // Agregar condiciones a las consultas

    $condicion_sala_diario = " AND c.sala_id = ? ";

    $condicion_sala_mensual = " AND c.sala_id = ? ";

    $condicion_sala_ventas_diario = " AND v.sala_id = ? ";

    $condicion_sala_ventas_mensual = " AND v.sala_id = ? ";

}



// ============================================================================

// CONEXIÓN ADICIONAL PARA GANANCIA VOLUMEN

// ============================================================================



$ganancia_volumen_diaria = 0;

$ganancia_volumen_mensual = 0;

$venta_bruta_diaria = 0;

$venta_bruta_mensual = 0;



// Conexión para ganancia volumen

$host_ventas = '65.99.205.112';

$dbname_ventas = 'noreplyc_crmregistroventas';

$username_ventas = 'noreplyc_alexsanchez09';

$password_ventas = 'SWn_VrAc3K7lq;LS';



try {

    $conn_ventas = new mysqli($host_ventas, $username_ventas, $password_ventas, $dbname_ventas);

    if ($conn_ventas->connect_error) {

        $ganancia_volumen_diaria = 0;

        $ganancia_volumen_mensual = 0;

        $venta_bruta_diaria = 0;

        $venta_bruta_mensual = 0;

    } else {

        $conn_ventas->set_charset("utf8");



        // CALCULAR GANANCIA VOLUMEN DIARIA Y VENTA BRUTA

        $query_ventas_dia = "

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

            WHERE DATE(v.fecha) = ? 

        ";



        // Agregar condición de sala si está seleccionada

        if ($sala_seleccionada !== 'todas' && is_numeric($sala_seleccionada)) {

            $query_ventas_dia .= " AND v.sala_id = ? ";

        }



        $stmt_ventas_dia = $conn_ventas->prepare($query_ventas_dia);

        if ($stmt_ventas_dia) {

            if ($sala_seleccionada !== 'todas' && is_numeric($sala_seleccionada)) {

                $stmt_ventas_dia->bind_param("si", $fecha_seleccionada, $sala_seleccionada);

            } else {

                $stmt_ventas_dia->bind_param("s", $fecha_seleccionada);

            }

            $stmt_ventas_dia->execute();

            $result_ventas_dia = $stmt_ventas_dia->get_result();



            while ($venta = $result_ventas_dia->fetch_assoc()) {

                $ventaBruta = (float)$venta['precio'];

                $venta_bruta_diaria += $ventaBruta;



                if (!empty($venta['liner_nombre'])) {

                    $ganancia_volumen_diaria += $ventaBruta / 2;

                }

                if (!empty($venta['closer1_nombre'])) {

                    $ganancia_volumen_diaria += $ventaBruta / 2;

                }

                if (!empty($venta['closer2_nombre'])) {

                    $ganancia_volumen_diaria += $ventaBruta / 3;

                }

                if (!empty($venta['front_nombre'])) {

                    $ganancia_volumen_diaria += $ventaBruta;

                }

            }

            $stmt_ventas_dia->close();

        }



        // CALCULAR GANANCIA VOLUMEN MENSUAL Y VENTA BRUTA

        $anio = date('Y', strtotime($mes_seleccionado . '-01'));

        $mes = date('m', strtotime($mes_seleccionado . '-01'));



        $query_ventas_mes = "

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

            WHERE YEAR(v.fecha) = ? AND MONTH(v.fecha) = ?

        ";



        // Agregar condición de sala si está seleccionada

        if ($sala_seleccionada !== 'todas' && is_numeric($sala_seleccionada)) {

            $query_ventas_mes .= " AND v.sala_id = ? ";

        }



        $stmt_ventas_mes = $conn_ventas->prepare($query_ventas_mes);

        if ($stmt_ventas_mes) {

            if ($sala_seleccionada !== 'todas' && is_numeric($sala_seleccionada)) {

                $stmt_ventas_mes->bind_param("iii", $anio, $mes, $sala_seleccionada);

            } else {

                $stmt_ventas_mes->bind_param("ii", $anio, $mes);

            }

            $stmt_ventas_mes->execute();

            $result_ventas_mes = $stmt_ventas_mes->get_result();



            while ($venta = $result_ventas_mes->fetch_assoc()) {

                $ventaBruta = (float)$venta['precio'];

                $venta_bruta_mensual += $ventaBruta;



                if (!empty($venta['liner_nombre'])) {

                    $ganancia_volumen_mensual += $ventaBruta / 2;

                }

                if (!empty($venta['closer1_nombre'])) {

                    $ganancia_volumen_mensual += $ventaBruta / 2;

                }

                if (!empty($venta['closer2_nombre'])) {

                    $ganancia_volumen_mensual += $ventaBruta / 3;

                }

                if (!empty($venta['front_nombre'])) {

                    $ganancia_volumen_mensual += $ventaBruta;

                }

            }

            $stmt_ventas_mes->close();

        }



        // ============================================================================

        // CONSULTA PARA GRAFICA LINEAL - VENTA BRUTA POR DÍA DEL MES

        // ============================================================================



        $ventas_por_dia = array();

        $dias_del_mes = array();



        // Obtener número de días en el mes seleccionado

        $ultimo_dia = date('t', strtotime($mes_seleccionado . '-01'));



        // Inicializar array con todos los días del mes (1 al último día)

        for ($dia = 1; $dia <= $ultimo_dia; $dia++) {

            $fecha_dia = sprintf('%04d-%02d-%02d', $anio, $mes, $dia);

            $dias_del_mes[] = $dia;

            $ventas_por_dia[$dia] = 0; // Inicializar en 0

        }



        // Consultar ventas por día del mes

        $query_ventas_por_dia = "

            SELECT DAY(v.fecha) as dia, SUM(v.precio) as total_venta

            FROM ventas_facturacion v

            WHERE YEAR(v.fecha) = ? AND MONTH(v.fecha) = ?

        ";



        // Agregar condición de sala si está seleccionada

        if ($sala_seleccionada !== 'todas' && is_numeric($sala_seleccionada)) {

            $query_ventas_por_dia .= " AND v.sala_id = ? ";

        }



        $query_ventas_por_dia .= " GROUP BY DAY(v.fecha) ORDER BY dia";



        $stmt_ventas_dia = $conn_ventas->prepare($query_ventas_por_dia);

        if ($stmt_ventas_dia) {

            if ($sala_seleccionada !== 'todas' && is_numeric($sala_seleccionada)) {

                $stmt_ventas_dia->bind_param("iii", $anio, $mes, $sala_seleccionada);

            } else {

                $stmt_ventas_dia->bind_param("ii", $anio, $mes);

            }

            $stmt_ventas_dia->execute();

            $result_ventas_dia = $stmt_ventas_dia->get_result();



            while ($row = $result_ventas_dia->fetch_assoc()) {

                $dia = (int)$row['dia'];

                $ventas_por_dia[$dia] = (float)$row['total_venta'];

            }

            $stmt_ventas_dia->close();

        }



        // Convertir el array asociativo a array indexado para JavaScript

        $ventas_por_dia_array = array_values($ventas_por_dia);



        $conn_ventas->close();

    }

} catch (Exception $e) {

    $ganancia_volumen_diaria = 0;

    $ganancia_volumen_mensual = 0;

    $venta_bruta_diaria = 0;

    $venta_bruta_mensual = 0;

    $ventas_por_dia_array = array();

    $dias_del_mes = array();

}



// ============================================================================

// CONSULTAS ORIGINALES PARA LLAMADAS (MODIFICADAS PARA INCLUIR SALA)

// ============================================================================



// Consulta para obtener los 3 principales usuarios de la fecha seleccionada

$query_top3 = "

    SELECT 

        u.apemat,

        COUNT(*) as cantidad

    FROM cliente c

    INNER JOIN usuarios u ON 

        u.id = c.usuario_id OR u.id = c.usuario_closer

    WHERE DATE(c.fecha_registro) = ? 

    {$condicion_sala_diario}

    GROUP BY u.apemat

    ORDER BY cantidad DESC 

    LIMIT 3

";



$stmt_top3 = $conn->prepare($query_top3);

if ($sala_seleccionada !== 'todas' && is_numeric($sala_seleccionada)) {

    $stmt_top3->bind_param("si", $fecha_seleccionada, $sala_seleccionada);

} else {

    $stmt_top3->bind_param("s", $fecha_seleccionada);

}

$stmt_top3->execute();

$result_top3 = $stmt_top3->get_result();



$top_usuarios = [];

while ($row = $result_top3->fetch_assoc()) {

    $apemat_limpio = preg_replace('/[^\p{L}\p{N}\s]/u', '', $row['apemat']);

    $apemat_limpio = trim(preg_replace('/\s+/', ' ', $apemat_limpio));

    $top_usuarios[] = [

        'apemat' => $apemat_limpio,

        'cantidad' => $row['cantidad']

    ];

}

$stmt_top3->close();



// Consulta para obtener TODOS los usuarios de la fecha seleccionada

$query_todos = "

    SELECT 

        u.apemat,

        COUNT(*) as cantidad

    FROM cliente c

    INNER JOIN usuarios u ON 

        u.id = c.usuario_id OR u.id = c.usuario_closer

    WHERE DATE(c.fecha_registro) = ? 

    {$condicion_sala_diario}

    GROUP BY u.apemat

    ORDER BY cantidad DESC

";



$stmt_todos = $conn->prepare($query_todos);

if ($sala_seleccionada !== 'todas' && is_numeric($sala_seleccionada)) {

    $stmt_todos->bind_param("si", $fecha_seleccionada, $sala_seleccionada);

} else {

    $stmt_todos->bind_param("s", $fecha_seleccionada);

}

$stmt_todos->execute();

$result_todos = $stmt_todos->get_result();



$todos_usuarios = [];

while ($row = $result_todos->fetch_assoc()) {

    $apemat_limpio = preg_replace('/[^\p{L}\p{N}\s]/u', '', $row['apemat']);

    $apemat_limpio = trim(preg_replace('/\s+/', ' ', $apemat_limpio));

    $todos_usuarios[] = [

        'apemat' => $apemat_limpio,

        'cantidad' => $row['cantidad']

    ];

}

$stmt_todos->close();



// Consulta para volumen mensual

$query_mensual = "

    SELECT 

        u.apemat,

        COUNT(*) as cantidad

    FROM cliente c

    INNER JOIN usuarios u ON 

        u.id = c.usuario_id OR u.id = c.usuario_closer

    WHERE YEAR(c.fecha_registro) = ? 

        AND MONTH(c.fecha_registro) = ?

    {$condicion_sala_mensual}

    GROUP BY u.apemat

    ORDER BY cantidad DESC

";



$anio = date('Y', strtotime($mes_seleccionado . '-01'));

$mes = date('m', strtotime($mes_seleccionado . '-01'));



$stmt_mensual = $conn->prepare($query_mensual);

if ($sala_seleccionada !== 'todas' && is_numeric($sala_seleccionada)) {

    $stmt_mensual->bind_param("iii", $anio, $mes, $sala_seleccionada);

} else {

    $stmt_mensual->bind_param("ii", $anio, $mes);

}

$stmt_mensual->execute();

$result_mensual = $stmt_mensual->get_result();



$usuarios_mensual = [];

$total_mensual = 0;

while ($row = $result_mensual->fetch_assoc()) {

    $apemat_limpio = preg_replace('/[^\p{L}\p{N}\s]/u', '', $row['apemat']);

    $apemat_limpio = trim(preg_replace('/\s+/', ' ', $apemat_limpio));

    $usuarios_mensual[] = [

        'apemat' => $apemat_limpio,

        'cantidad' => $row['cantidad']

    ];

    $total_mensual += $row['cantidad'];

}

$stmt_mensual->close();



// Top 3 mensual

$top3_mensual = array_slice($usuarios_mensual, 0, 3);



// Estadísticas diarias

$total_llamadas = array_sum(array_column($todos_usuarios, 'cantidad'));

$total_agentes = count($todos_usuarios);

$promedio_llamadas = $total_agentes > 0 ? round($total_llamadas / $total_agentes, 1) : 0;



// Estadísticas mensuales

$total_agentes_mensual = count($usuarios_mensual);

$promedio_mensual = $total_agentes_mensual > 0 ? round($total_mensual / $total_agentes_mensual, 1) : 0;



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

                    <?php if (($_SESSION['rol'] ?? '') === 'superadmin'): ?>

                    <li class="nav-item">

                        <a class="nav-link text-dark" href="ver_logs.php" style="text-decoration: none;">

                            <i class="bi bi-shield-lock me-1"></i>Logs

                        </a>

                    </li>

                    <?php endif; ?>

                </ul>

                <span class="navbar-text text-muted me-3 d-none d-md-block">

                    <?php

                    if (isset($_SESSION['apemat']) && !empty($_SESSION['apemat'])) {

                        echo htmlspecialchars($_SESSION['apemat']);

                    } elseif (isset($_SESSION['nombre']) && !empty($_SESSION['nombre'])) {

                        echo htmlspecialchars($_SESSION['nombre']);

                    } elseif (isset($_SESSION['usuario']) && !empty($_SESSION['usuario'])) {

                        echo htmlspecialchars($_SESSION['usuario']);

                    } else {

                        echo 'Usuario';

                    }

                    ?>

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

                        <h1 class="display-6 fw-bold">Bienvenido, <?php

                                                                    if (isset($_SESSION['apemat']) && !empty($_SESSION['apemat'])) {

                                                                        echo htmlspecialchars($_SESSION['apemat']);

                                                                    } elseif (isset($_SESSION['nombre']) && !empty($_SESSION['nombre'])) {

                                                                        echo htmlspecialchars($_SESSION['nombre']);

                                                                    } elseif (isset($_SESSION['usuario']) && !empty($_SESSION['usuario'])) {

                                                                        echo htmlspecialchars($_SESSION['usuario']);

                                                                    } else {

                                                                        echo 'Usuario';

                                                                    }

                                                                    ?>!</h1>

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

                                                value="<?= $fecha_seleccionada ?>"

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

                                                        <a class="dropdown-item" href="reporte_ganancia_volumen.php?fecha=<?= $fecha_seleccionada ?>&sala=<?= $sala_seleccionada ?>" target="_blank">

                                                            <i class="bi bi-file-earmark-excel me-2 text-success"></i>Excel Diario

                                                        </a>

                                                    </li>

                                                    <li>

                                                        <a class="dropdown-item" href="reporte_pdf_ganancia_volumen.php?fecha=<?= $fecha_seleccionada ?>&sala=<?= $sala_seleccionada ?>" target="_blank">

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

                                                <h3 class="fw-bold text-info"><?= !empty($top_usuarios) ? $top_usuarios[0]['apemat'] : 'N/A' ?></h3>

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

                                                value="<?= $mes_seleccionado ?>"

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

                                                        <a class="dropdown-item" href="reporte_ganancia_volumen_mensual.php?mes=<?= $mes_seleccionado ?>&sala=<?= $sala_seleccionada ?>" target="_blank">

                                                            <i class="bi bi-file-earmark-excel me-2 text-success"></i>Excel Mensual

                                                        </a>

                                                    </li>

                                                    <li>

                                                        <a class="dropdown-item" href="reporte_pdf_ganancia_volumen_mensual.php?mes=<?= $mes_seleccionado ?>&sala=<?= $sala_seleccionada ?>" target="_blank">

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

                                                <h3 class="fw-bold text-primary"><?= !empty($top3_mensual) ? $top3_mensual[0]['apemat'] : 'N/A' ?></h3>

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

            inicializarGraficas();

            inicializarGraficasMensual();

            inicializarGraficaLinealVentas();



            function inicializarGraficas() {

                <?php if (!empty($todos_usuarios)): ?>

                    const ctx = document.getElementById('graficaBarras');

                    if (ctx) {

                        const apellidos = <?= json_encode(array_column($todos_usuarios, 'apemat')) ?>;

                        const cantidades = <?= json_encode(array_column($todos_usuarios, 'cantidad')) ?>;



                        const colores = cantidades.map((cantidad, index) => {

                            const hue = (index * 137.508) % 360;

                            return `hsl(${hue}, 70%, 65%)`;

                        });



                        new Chart(ctx, {

                            type: 'bar',

                            data: {

                                labels: apellidos,

                                datasets: [{

                                    label: 'Llamadas Realizadas',

                                    data: cantidades,

                                    backgroundColor: colores,

                                    borderColor: colores.map(color => color.replace('65%', '50%')),

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

                <?php endif; ?>

            }



            function inicializarGraficasMensual() {

                <?php if (!empty($usuarios_mensual)): ?>

                    const ctxMensual = document.getElementById('graficaBarrasMensual');

                    if (ctxMensual) {

                        const apellidosMensual = <?= json_encode(array_column($usuarios_mensual, 'apemat')) ?>;

                        const cantidadesMensual = <?= json_encode(array_column($usuarios_mensual, 'cantidad')) ?>;



                        const coloresMensual = cantidadesMensual.map((cantidad, index) => {

                            const hue = (index * 137.508) % 360;

                            return `hsl(${hue}, 70%, 55%)`;

                        });



                        new Chart(ctxMensual, {

                            type: 'bar',

                            data: {

                                labels: apellidosMensual,

                                datasets: [{

                                    label: 'Llamadas Realizadas',

                                    data: cantidadesMensual,

                                    backgroundColor: coloresMensual,

                                    borderColor: coloresMensual.map(color => color.replace('55%', '40%')),

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

                <?php endif; ?>

            }



            function inicializarGraficaLinealVentas() {

                <?php if (!empty($ventas_por_dia_array)): ?>

                    const ctxLineal = document.getElementById('graficaLinealVentas');

                    if (ctxLineal) {

                        const dias = <?= json_encode($dias_del_mes) ?>;

                        const ventas = <?= json_encode($ventas_por_dia_array) ?>;



                        // Formatear ventas como dinero

                        const ventasFormateadas = ventas.map(valor => {

                            return new Intl.NumberFormat('es-MX', {

                                style: 'currency',

                                currency: 'MXN',

                                minimumFractionDigits: 2

                            }).format(valor);

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

                                                const valor = context.parsed.y;

                                                return `Venta: ${new Intl.NumberFormat('es-MX', {

                                                    style: 'currency',

                                                    currency: 'MXN',

                                                    minimumFractionDigits: 2

                                                }).format(valor)}`;

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

