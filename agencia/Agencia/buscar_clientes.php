<?php
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../session_config.php'; session_start();
date_default_timezone_set('America/Mexico_City');
$rol = $_SESSION['rol'] ?? '';
if (!isset($_SESSION['usuario_id']) || !in_array($rol, ['admin', 'superadmin', 'usuario'], true)) {
    header("Location: ../login.php");
    exit;
}
$esAdmin = $rol === 'admin' || $rol === 'superadmin';

// Conexión a la base de datos
$pdo = conexionPDO();

$buscar = $_GET['buscar'] ?? '';
$fechaInicio = $_GET['fecha_inicio'] ?? '';
$fechaFin = $_GET['fecha_fin'] ?? '';
$salaFiltro = isset($_GET['sala_id']) ? (int)$_GET['sala_id'] : '';

// ============================================================
// LÍMITE DURO: evita respuestas gigantes que tarden mucho
// ============================================================
const LIMITE_RESULTADOS = 100;

// Filtro de sala para usuarios no-admin
$salaId = (!$esAdmin && !empty($_SESSION['sala_id'])) ? $_SESSION['sala_id'] : null;
$filtroSala = $salaId !== null;

// Sin término de búsqueda ni filtros: no consultar
if (empty($buscar) && empty($fechaInicio) && empty($fechaFin) && !$filtroSala && empty($salaFiltro)) {
    echo '<div id="resultado-contador" data-total="0"></div>';
    echo '<div id="resultado-limite" data-limite="' . LIMITE_RESULTADOS . '" data-truncado="0"></div>';
    echo '<tr><td colspan="7" class="text-center">Ingrese un término de búsqueda o use los filtros</td></tr>';
    exit;
}

$whereConditions = [];
$params = [];

if (!empty($buscar)) {
    // Con prepares nativos cada placeholder debe ser único
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
}

if ($filtroSala) {
    $whereConditions[] = "c.sala_id = :sala_id";
    $params[':sala_id'] = $salaId;
}

if ($esAdmin && !empty($salaFiltro)) {
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
$joins = "
    LEFT JOIN usuarios u ON c.usuario_id = u.id
    LEFT JOIN usuarios uc ON c.usuario_closer = uc.id
    LEFT JOIN salas s ON c.sala_id = s.id
";

// ============================================================
// PRIMERO: contar el total real (sin límite).
// Los JOIN solo hacen falta si la búsqueda filtra por sus columnas
// ============================================================
$sqlCount = "SELECT COUNT(*) FROM cliente c " . (!empty($buscar) ? $joins : '') . " $whereClause";
$stmtCount = $pdo->prepare($sqlCount);
$stmtCount->execute($params);
$totalResultados = (int)$stmtCount->fetchColumn();

// ============================================================
// SEGUNDO: obtener solo los primeros LIMITE_RESULTADOS
// ============================================================
$sql = "
    SELECT
        c.*,
        CONCAT(COALESCE(u.apemat, '')) AS nombre_completo_usuario,
        COALESCE(NULLIF(uc.apemat, ''), 'No asignado') AS nombre_completo_closer,
        s.nombre as sala_nombre
    FROM cliente c
    $joins
    $whereClause
    ORDER BY c.fecha_registro DESC
    LIMIT " . LIMITE_RESULTADOS . "
";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
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

        echo '<tr data-id="' . (int)$cliente['id'] . '" style="cursor: pointer;">';
        echo '<td>' . htmlspecialchars($cliente['nombre'] . ' ' . $cliente['apepat'] . ' ' . $cliente['apemat']) . '</td>';
        echo '<td>' . htmlspecialchars($cliente['asunto'] ?? '') . '</td>';
        echo '<td>' . htmlspecialchars($cliente['descripcion'] ?? '') . '</td>';
        echo '<td>' . htmlspecialchars($cliente['nombre_completo_usuario']) . '</td>';
        echo '<td class="' . $closerClass . '">' . htmlspecialchars($cliente['nombre_completo_closer']) . '</td>';
        echo '<td class="text-center">' . htmlspecialchars($salaNombre) . '</td>';
        echo '<td class="text-center">' . htmlspecialchars($fechaRegistro) . '</td>';
        echo '</tr>';
    }
} else {
    $criterio = !empty($buscar) ? 'los criterios de búsqueda' : 'los filtros especificados';
    echo '<tr><td colspan="7" class="text-center">No se encontraron clientes con ' . $criterio . '</td></tr>';
}
