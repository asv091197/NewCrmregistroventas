<?php
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../session_config.php'; session_start();
date_default_timezone_set('America/Mexico_City');
if (!isset($_SESSION['usuario_id']) || ($_SESSION['rol'] !== 'admin' && $_SESSION['rol'] !== 'superadmin' && $_SESSION['rol'] !== 'usuario')) {
    header("Location: ../login.php");
    exit;
}

// Conexión a la base de datos
$pdo = conexionPDO();

$buscar = isset($_GET['buscar']) ? $_GET['buscar'] : '';
$fechaInicio = isset($_GET['fecha_inicio']) ? $_GET['fecha_inicio'] : '';
$fechaFin = isset($_GET['fecha_fin']) ? $_GET['fecha_fin'] : '';
$salaFiltro = isset($_GET['sala_id']) ? (int)$_GET['sala_id'] : '';

// ============================================================
// LÍMITE DURO: evita respuestas gigantes que tarden mucho
// ============================================================
const LIMITE_RESULTADOS = 100;

$totalResultados = 0;
$truncado = false;

// Filtro de sala para usuarios no-admin
if ($_SESSION['rol'] !== 'admin' && $_SESSION['rol'] !== 'superadmin' && isset($_SESSION['sala_id']) && !empty($_SESSION['sala_id'])) {
    $filtroSala = true;
    $salaId = $_SESSION['sala_id'];
} else {
    $filtroSala = false;
    $salaId = null;
}

if (!empty($buscar)) {
    $whereConditions = [];
    $params = [];

    $searchConditions = [
        "c.nombre LIKE :buscar1",
        "c.apepat LIKE :buscar2", 
        "c.apemat LIKE :buscar3", 
        "c.numero1 LIKE :buscar4", 
        "c.numero2 LIKE :buscar5", 
        "c.asunto LIKE :buscar6", 
        "c.descripcion LIKE :buscar7",
        "CONCAT(COALESCE(u.apemat, '')) LIKE :buscar8",
        "CONCAT(COALESCE(uc.apemat, '')) LIKE :buscar9",
        "s.nombre LIKE :buscar10"
    ];
    
    $whereConditions[] = "(" . implode(" OR ", $searchConditions) . ")";
    for ($i = 1; $i <= 10; $i++) {
        $params[':buscar' . $i] = '%' . $buscar . '%';
    }

    if ($filtroSala) {
        $whereConditions[] = "c.sala_id = :sala_id";
        $params[':sala_id'] = $salaId;
    }
    
    if (($_SESSION['rol'] === 'admin' || $_SESSION['rol'] === 'superadmin') && !empty($salaFiltro)) {
        $whereConditions[] = "c.sala_id = :sala_filtro";
        $params[':sala_filtro'] = $salaFiltro;
    }

    if (!empty($fechaInicio)) {
        $whereConditions[] = "c.fecha_registro >= :fecha_inicio";
        $params[':fecha_inicio'] = $fechaInicio . ' 00:00:00';
    }

    if (!empty($fechaFin)) {
        $whereConditions[] = "c.fecha_registro <= :fecha_fin";
        $params[':fecha_fin'] = $fechaFin . ' 23:59:59';
    }

    $whereClause = 'WHERE ' . implode(' AND ', $whereConditions);

    // ============================================================
    // PRIMERO: contar el total real (sin límite)
    // ============================================================
    $sqlCount = "
        SELECT COUNT(*) as total
        FROM cliente c
        LEFT JOIN usuarios u ON c.usuario_id = u.id
        LEFT JOIN usuarios uc ON c.usuario_closer = uc.id
        LEFT JOIN salas s ON c.sala_id = s.id
        $whereClause
    ";
    $stmtCount = $pdo->prepare($sqlCount);
    foreach ($params as $key => $value) {
        $stmtCount->bindValue($key, $value, PDO::PARAM_STR);
    }
    $stmtCount->execute();
    $totalResultados = (int)$stmtCount->fetch(PDO::FETCH_ASSOC)['total'];

    // ============================================================
    // SEGUNDO: obtener solo los primeros LIMITE_RESULTADOS
    // ============================================================
    $sql = "
        SELECT 
            c.*, 
            CONCAT(COALESCE(u.apemat, '')) AS nombre_completo_usuario,
            COALESCE(CONCAT(COALESCE(uc.apemat, '')), 'No asignado') AS nombre_completo_closer,
            s.nombre as sala_nombre
        FROM cliente c
        LEFT JOIN usuarios u ON c.usuario_id = u.id
        LEFT JOIN usuarios uc ON c.usuario_closer = uc.id
        LEFT JOIN salas s ON c.sala_id = s.id
        $whereClause
        ORDER BY c.fecha_registro DESC
        LIMIT " . LIMITE_RESULTADOS . "
    ";

    $stmt = $pdo->prepare($sql);
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value, PDO::PARAM_STR);
    }
    $stmt->execute();
    $clientes = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $truncado = ($totalResultados > LIMITE_RESULTADOS);

    // Output de metadatos para el JS
    echo '<div id="resultado-contador" data-total="' . $totalResultados . '"></div>';
    echo '<div id="resultado-limite" data-limite="' . LIMITE_RESULTADOS . '" data-truncado="' . ($truncado ? 1 : 0) . '"></div>';
    
    if (count($clientes) > 0) {
        foreach ($clientes as $cliente) {
            $fechaRegistro = date('d/m/Y H:i', strtotime($cliente['fecha_registro']));
            $closerClass = ($cliente['nombre_completo_closer'] === 'No asignado') ? 'no-asignado' : '';
            $salaNombre = $cliente['sala_nombre'] ?? 'Sin sala';
            
            echo '<tr data-id="' . $cliente['id'] . '" style="cursor: pointer;">';
            echo '<td>' . htmlspecialchars($cliente['nombre'] . ' ' . $cliente['apepat'] . ' ' . $cliente['apemat']) . '</td>';
            echo '<td>' . htmlspecialchars($cliente['asunto']) . '</td>';
            echo '<td>' . htmlspecialchars($cliente['descripcion']) . '</td>';
            echo '<td>' . htmlspecialchars($cliente['nombre_completo_usuario']) . '</td>';
            echo '<td class="' . $closerClass . '">' . htmlspecialchars($cliente['nombre_completo_closer']) . '</td>';
            echo '<td class="text-center">' . htmlspecialchars($salaNombre) . '</td>';
            echo '<td class="text-center">' . htmlspecialchars($fechaRegistro) . '</td>';
            echo '</tr>';
        }
    } else {
        echo '<tr><td colspan="7" class="text-center">No se encontraron clientes con los criterios de búsqueda</td></tr>';
    }
} else {
    // Sin término de búsqueda pero con filtros
    if (!empty($fechaInicio) || !empty($fechaFin) || $filtroSala || !empty($salaFiltro)) {
        $whereConditions = [];
        $params = [];

        if ($filtroSala) {
            $whereConditions[] = "c.sala_id = :sala_id";
            $params[':sala_id'] = $salaId;
        }
        
        if (($_SESSION['rol'] === 'admin' || $_SESSION['rol'] === 'superadmin') && !empty($salaFiltro)) {
            $whereConditions[] = "c.sala_id = :sala_filtro";
            $params[':sala_filtro'] = $salaFiltro;
        }

        if (!empty($fechaInicio)) {
            $whereConditions[] = "c.fecha_registro >= :fecha_inicio";
            $params[':fecha_inicio'] = $fechaInicio . ' 00:00:00';
        }

        if (!empty($fechaFin)) {
            $whereConditions[] = "c.fecha_registro <= :fecha_fin";
            $params[':fecha_fin'] = $fechaFin . ' 23:59:59';
        }

        $whereClause = 'WHERE ' . implode(' AND ', $whereConditions);

        // Contar total
        $sqlCount = "SELECT COUNT(*) as total FROM cliente c $whereClause";
        $stmtCount = $pdo->prepare($sqlCount);
        foreach ($params as $key => $value) {
            $stmtCount->bindValue($key, $value, PDO::PARAM_STR);
        }
        $stmtCount->execute();
        $totalResultados = (int)$stmtCount->fetch(PDO::FETCH_ASSOC)['total'];

        $sql = "
            SELECT 
                c.*, 
                CONCAT(COALESCE(u.apemat, '')) AS nombre_completo_usuario,
                COALESCE(CONCAT(COALESCE(uc.apemat, '')), 'No asignado') AS nombre_completo_closer,
                s.nombre as sala_nombre
            FROM cliente c
            LEFT JOIN usuarios u ON c.usuario_id = u.id
            LEFT JOIN usuarios uc ON c.usuario_closer = uc.id
            LEFT JOIN salas s ON c.sala_id = s.id
            $whereClause
            ORDER BY c.fecha_registro DESC
            LIMIT " . LIMITE_RESULTADOS . "
        ";

        $stmt = $pdo->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value, PDO::PARAM_STR);
        }
        $stmt->execute();
        $clientes = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $truncado = ($totalResultados > LIMITE_RESULTADOS);
        
        echo '<div id="resultado-contador" data-total="' . $totalResultados . '"></div>';
        echo '<div id="resultado-limite" data-limite="' . LIMITE_RESULTADOS . '" data-truncado="' . ($truncado ? 1 : 0) . '"></div>';

        if (count($clientes) > 0) {
            foreach ($clientes as $cliente) {
                $fechaRegistro = date('d/m/Y H:i', strtotime($cliente['fecha_registro']));
                $closerClass = ($cliente['nombre_completo_closer'] === 'No asignado') ? 'no-asignado' : '';
                $salaNombre = $cliente['sala_nombre'] ?? 'Sin sala';
                
                echo '<tr data-id="' . $cliente['id'] . '" style="cursor: pointer;">';
                echo '<td>' . htmlspecialchars($cliente['nombre'] . ' ' . $cliente['apepat'] . ' ' . $cliente['apemat']) . '</td>';
                echo '<td>' . htmlspecialchars($cliente['asunto']) . '</td>';
                echo '<td>' . htmlspecialchars($cliente['descripcion']) . '</td>';
                echo '<td>' . htmlspecialchars($cliente['nombre_completo_usuario']) . '</td>';
                echo '<td class="' . $closerClass . '">' . htmlspecialchars($cliente['nombre_completo_closer']) . '</td>';
                echo '<td class="text-center">' . htmlspecialchars($salaNombre) . '</td>';
                echo '<td class="text-center">' . htmlspecialchars($fechaRegistro) . '</td>';
                echo '</tr>';
            }
        } else {
            echo '<tr><td colspan="7" class="text-center">No se encontraron clientes con los filtros especificados</td></tr>';
        }
    } else {
        echo '<div id="resultado-contador" data-total="0"></div>';
        echo '<div id="resultado-limite" data-limite="' . LIMITE_RESULTADOS . '" data-truncado="0"></div>';
        echo '<tr><td colspan="7" class="text-center">Ingrese un término de búsqueda o use los filtros</td></tr>';
    }
}