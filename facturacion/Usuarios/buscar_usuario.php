<?php
require_once __DIR__ . '/../../includes/db.php';
// Configuración de manejo de errores
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/php_errors.log');

require_once __DIR__ . '/../../session_config.php'; session_start();
$rol = $_SESSION['rol'] ?? '';
if (!isset($_SESSION['usuario_id']) || ($rol !== 'admin' && $rol !== 'superadmin')) {
    die('Acceso no autorizado');
}

// Conexión a base de datos
try {
    $pdo = conexionPDO();
} catch (PDOException $e) {
    die("Error de conexión");
}

$buscar = trim($_GET['buscar'] ?? '');
$fechaInicio = $_GET['fecha_inicio'] ?? '';
$fechaFin = $_GET['fecha_fin'] ?? '';
$salaFiltro = $_GET['sala'] ?? '';
// Parámetro para mostrar inactivos
$mostrar_inactivos = !empty($_GET['mostrar_inactivos']);

if (strlen($buscar) < 1) {
    echo '<tr><td colspan="7" class="text-center">Ingrese un término de búsqueda</td></tr>';
    exit;
}

try {
    // Obtener el mapa de salas para mostrar nombres
    $salasMap = [];
    foreach ($pdo->query("SELECT id, nombre FROM salas WHERE activo = 1") as $sala) {
        $salasMap[$sala['id']] = $sala['nombre'];
    }

    // Construir consulta con filtros (placeholders distintos: no se pueden repetir con prepares nativos)
    $like = "%$buscar%";
    $whereConditions = ["(u.nombre LIKE :buscar1 OR u.apepat LIKE :buscar2 OR u.apemat LIKE :buscar3 OR u.correo LIKE :buscar4)"];
    $params = [':buscar1' => $like, ':buscar2' => $like, ':buscar3' => $like, ':buscar4' => $like];

    // Solo un superadmin puede ver superadmins
    if ($rol !== 'superadmin') {
        $whereConditions[] = "u.rol <> 'superadmin'";
    }

    // Por defecto, solo mostrar usuarios activos
    if (!$mostrar_inactivos) {
        $whereConditions[] = "u.activo = 1";
    }

    if (!empty($fechaInicio)) {
        $whereConditions[] = "u.fecha_registro >= :fecha_inicio";
        $params[':fecha_inicio'] = $fechaInicio . ' 00:00:00';
    }

    if (!empty($fechaFin)) {
        $whereConditions[] = "u.fecha_registro <= :fecha_fin";
        $params[':fecha_fin'] = $fechaFin . ' 23:59:59';
    }

    // Filtro para sala
    if (!empty($salaFiltro) && $salaFiltro !== 'todas') {
        // Solo usuarios que tienen acceso a esta sala específica
        $whereConditions[] = "JSON_CONTAINS(u.salas_permitidas, :sala_filtro)";
        $params[':sala_filtro'] = json_encode($salaFiltro);
    } elseif ($salaFiltro === 'todas') {
        // "Todas las salas": usuarios con acceso a todas las salas
        $whereConditions[] = "u.acceso_todas_salas = 1";
    }

    $whereClause = 'WHERE ' . implode(' AND ', $whereConditions);

    $stmt = $pdo->prepare("SELECT u.id, u.nombre, u.apepat, u.apemat, u.correo, u.rol, u.fecha_registro, u.acceso_todas_salas, u.salas_permitidas, u.activo
            FROM usuarios_facturacion u
            $whereClause
            ORDER BY u.id DESC
            LIMIT 50");
    $stmt->execute($params);
    $usuarios = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Total de resultados para el contador
    $stmtCount = $pdo->prepare("SELECT COUNT(*) FROM usuarios_facturacion u $whereClause");
    $stmtCount->execute($params);
    $totalResultados = (int)$stmtCount->fetchColumn();

    if (count($usuarios) > 0) {
        // Div oculto para pasar el contador de resultados al JavaScript
        echo '<div id="resultado-contador" data-total="' . $totalResultados . '" style="display: none;"></div>';

        foreach ($usuarios as $usuario) {
            $fechaRegistro = date('d/m/Y H:i', strtotime($usuario['fecha_registro']));
            $badgeClass = $usuario['rol'] === 'admin' ? 'bg-danger' : 'bg-primary';
            $estadoClass = $usuario['activo'] == 1 ? '' : 'table-warning';

            // Valores escapados para HTML
            $uid = (int)$usuario['id'];
            $nombre = htmlspecialchars($usuario['nombre'] ?? '');
            $apepat = htmlspecialchars($usuario['apepat'] ?? '');
            $apemat = htmlspecialchars($usuario['apemat'] ?? '');
            $correo = htmlspecialchars($usuario['correo'] ?? '');
            $rolUsuario = htmlspecialchars($usuario['rol'] ?? '');

            echo "<tr data-id='{$uid}' class='{$estadoClass}'>";
            echo "<td style='cursor: pointer;'>{$nombre} {$apepat} {$apemat}</td>";
            echo "<td style='cursor: pointer;'>{$correo}</td>";
            echo "<td class='text-center' style='cursor: pointer;'><span class='badge {$badgeClass} rol-badge'>{$rolUsuario}</span></td>";

            // Columna de salas con nombres
            echo "<td class='text-center' style='cursor: pointer;'>";
            if ($usuario['acceso_todas_salas'] == 1) {
                echo "<span class='badge bg-success salas-badge' title='Todas las salas'>Todas las salas</span>";
            } else {
                $salasIds = json_decode($usuario['salas_permitidas'] ?? '', true);
                $nombresSalas = [];
                foreach (is_array($salasIds) ? $salasIds : [] as $salaId) {
                    if (isset($salasMap[$salaId])) {
                        $nombresSalas[] = $salasMap[$salaId];
                    }
                }

                if (count($nombresSalas) > 0) {
                    $textoSalas = htmlspecialchars(implode(', ', $nombresSalas));
                    echo "<div class='sala-nombre' title='{$textoSalas}'>{$textoSalas}</div>";
                } else {
                    echo "<span class='badge bg-secondary salas-badge'>Sin salas</span>";
                }
            }
            echo "</td>";

            // Columna de estado
            $estadoBadge = $usuario['activo'] == 1 ?
                '<span class="badge bg-success">Activo</span>' :
                '<span class="badge bg-warning text-dark">Inactivo</span>';
            echo "<td class='text-center' style='cursor: pointer;'>{$estadoBadge}</td>";

            echo "<td class='text-center' style='cursor: pointer;'>{$fechaRegistro}</td>";

            // Botón activar/desactivar
            [$btnClass, $btnAccion, $btnIcono, $btnTexto] = $usuario['activo'] == 1
                ? ['btn-warning', 'desactivar', 'bi-pause-circle', 'Desactivar']
                : ['btn-success', 'activar', 'bi-play-circle', 'Activar'];
            echo "<td class='text-center'>";
            echo "<button class='btn btn-sm {$btnClass} btn-cambiar-estado' data-id='{$uid}' data-nombre='{$nombre} {$apepat} {$apemat}' data-accion='{$btnAccion}'>";
            echo "<i class='bi {$btnIcono}'></i> {$btnTexto}";
            echo "</button>";
            echo "</td>";
            echo "</tr>";
        }
    } else {
        $mensajeNoResultados = "No se encontraron resultados";

        if (!$mostrar_inactivos) {
            $mensajeNoResultados .= " activos";
        }

        if (!empty($fechaInicio) || !empty($fechaFin)) {
            $mensajeNoResultados .= " en el rango de fechas seleccionado";
        }

        if (!empty($salaFiltro)) {
            if ($salaFiltro === 'todas') {
                $mensajeNoResultados .= " con acceso a todas las salas";
            } else {
                $mensajeNoResultados .= " con acceso a la sala: " . htmlspecialchars($salasMap[$salaFiltro] ?? '');
            }
        }

        echo '<tr><td colspan="7" class="text-center">' . $mensajeNoResultados . '</td></tr>';
    }
} catch (PDOException $e) {
    error_log("Error en búsqueda: " . $e->getMessage());
    echo '<tr><td colspan="7" class="text-center">Error en la búsqueda</td></tr>';
}
