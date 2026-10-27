<?php
// Configuración de manejo de errores
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/php_errors.log');

session_start();
if (!isset($_SESSION['usuario_id']) || $_SESSION['rol'] !== 'admin'  && $_SESSION['rol'] !== 'superadmin' ) {
    die('Acceso no autorizado');
}

// Conexión a base de datos
$host = '65.99.205.112';
$dbname = 'noreplyc_crmregistroventas';
$username = 'noreplyc_alexsanchez09';
$password = 'SWn_VrAc3K7lq;LS';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8", $username, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
    ]);
} catch (PDOException $e) {
    die("Error de conexión");
}

$buscar = isset($_GET['buscar']) ? trim($_GET['buscar']) : '';
$fechaInicio = isset($_GET['fecha_inicio']) ? $_GET['fecha_inicio'] : '';
$fechaFin = isset($_GET['fecha_fin']) ? $_GET['fecha_fin'] : '';
$salaFiltro = isset($_GET['sala']) ? $_GET['sala'] : '';
// NUEVO: Parámetro para mostrar inactivos
$mostrar_inactivos = isset($_GET['mostrar_inactivos']) ? (bool)$_GET['mostrar_inactivos'] : false;

if (strlen($buscar) < 1) {
    echo '<tr><td colspan="7" class="text-center">Ingrese un término de búsqueda</td></tr>';
    exit;
}

try {
    // Obtener el mapa de salas para mostrar nombres
    $stmtSalas = $pdo->query("SELECT id, nombre FROM salas WHERE activo = 1");
    $salasMap = [];
    foreach ($stmtSalas as $sala) {
        $salasMap[$sala['id']] = $sala['nombre'];
    }
    
    // Construir consulta con filtros
    $whereConditions = ["(u.nombre LIKE :buscar OR u.apepat LIKE :buscar OR u.apemat LIKE :buscar OR u.correo LIKE :buscar)"];
    $params = [':buscar' => "%$buscar%"];
    
    // NUEVO: Filtro para estado activo/inactivo
    if (!$mostrar_inactivos) {
        // Por defecto, solo mostrar usuarios activos
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
    
    // Filtro para sala - CORREGIDO (misma lógica que en usuarios.php)
    if (!empty($salaFiltro) && $salaFiltro !== 'todas') {
        // Solo usuarios que tienen acceso a esta sala específica
        $whereConditions[] = "JSON_CONTAINS(u.salas_permitidas, :sala_filtro)";
        $params[':sala_filtro'] = json_encode($salaFiltro);
    } elseif ($salaFiltro === 'todas') {
        // Cuando se selecciona "Todas las salas", mostrar usuarios con acceso a todas las salas
        $whereConditions[] = "u.acceso_todas_salas = 1";
    }
    
    $whereClause = 'WHERE ' . implode(' AND ', $whereConditions);
    
    $sql = "SELECT u.id, u.nombre, u.apepat, u.apemat, u.correo, u.rol, u.fecha_registro, u.acceso_todas_salas, u.salas_permitidas, u.activo
            FROM usuarios u 
            $whereClause
            ORDER BY u.id DESC 
            LIMIT 50";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $usuarios = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Obtener el total de resultados para el contador
    $sqlCount = "SELECT COUNT(*) as total FROM usuarios u $whereClause";
    $stmtCount = $pdo->prepare($sqlCount);
    $stmtCount->execute($params);
    $totalResultados = $stmtCount->fetch(PDO::FETCH_ASSOC)['total'];
    
    if (count($usuarios) > 0) {
        // Div oculto para pasar el contador de resultados al JavaScript
        echo '<div id="resultado-contador" data-total="' . $totalResultados . '" style="display: none;"></div>';
        
        foreach ($usuarios as $usuario) {
            $fechaRegistro = date('d/m/Y H:i', strtotime($usuario['fecha_registro']));
            $badgeClass = $usuario['rol'] === 'admin' ? 'bg-danger' : 'bg-primary';
            
            // Clase para estado inactivo
            $estadoClass = $usuario['activo'] == 1 ? '' : 'table-warning';
            
            echo "<tr data-id='{$usuario['id']}' class='{$estadoClass}'>";
              echo "<td style='cursor: pointer;'> {$usuario['apemat']}</td>";
            echo "<td style='cursor: pointer;'>{$usuario['nombre']} {$usuario['apepat']} </td>";
            echo "<td style='cursor: pointer;'>{$usuario['correo']}</td>";
            echo "<td class='text-center' style='cursor: pointer;'><span class='badge {$badgeClass} rol-badge'>{$usuario['rol']}</span></td>";
            
            // Columna de salas modificada para mostrar nombres
            echo "<td class='text-center' style='cursor: pointer;'>";
            if ($usuario['acceso_todas_salas'] == 1) {
                echo "<span class='badge bg-success salas-badge' title='Todas las salas'>Todas las salas</span>";
            } elseif (!empty($usuario['salas_permitidas'])) {
                $salasIds = json_decode($usuario['salas_permitidas'], true);
                $nombresSalas = [];
                
                foreach ($salasIds as $salaId) {
                    if (isset($salasMap[$salaId])) {
                        $nombresSalas[] = $salasMap[$salaId];
                    }
                }
                
                if (count($nombresSalas) > 0) {
                    $textoSalas = implode(', ', $nombresSalas);
                    $countSalas = count($nombresSalas);
                    echo "<div class='sala-nombre' title='{$textoSalas}'>{$textoSalas}</div>";
                } else {
                    echo "<span class='badge bg-secondary salas-badge'>Sin salas</span>";
                }
            } else {
                echo "<span class='badge bg-secondary salas-badge'>Sin salas</span>";
            }
            echo "</td>";
            
            // Columna de estado
            $estadoBadge = $usuario['activo'] == 1 ?
                '<span class="badge bg-success">Activo</span>' :
                '<span class="badge bg-warning text-dark">Inactivo</span>';
            echo "<td class='text-center' style='cursor: pointer;'>{$estadoBadge}</td>";
            
            echo "<td class='text-center' style='cursor: pointer;'>{$fechaRegistro}</td>";
            echo "<td class='text-center'>";
            if ($usuario['activo'] == 1) {
                echo "<button class='btn btn-sm btn-warning btn-cambiar-estado' data-id='{$usuario['id']}' data-nombre='{$usuario['nombre']} {$usuario['apepat']} {$usuario['apemat']}' data-accion='desactivar'>";
                echo "<i class='bi bi-pause-circle'></i> Desactivar";
                echo "</button>";
            } else {
                echo "<button class='btn btn-sm btn-success btn-cambiar-estado' data-id='{$usuario['id']}' data-nombre='{$usuario['nombre']} {$usuario['apepat']} {$usuario['apemat']}' data-accion='activar'>";
                echo "<i class='bi bi-play-circle'></i> Activar";
                echo "</button>";
            }
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
                $nombreSala = '';
                foreach ($salasMap as $salaId => $salaNombre) {
                    if ($salaId == $salaFiltro) {
                        $nombreSala = $salaNombre;
                        break;
                    }
                }
                $mensajeNoResultados .= " con acceso a la sala: " . htmlspecialchars($nombreSala);
            }
        }
        
        echo '<tr><td colspan="7" class="text-center">' . $mensajeNoResultados . '</td></tr>';
    }
} catch (PDOException $e) {
    error_log("Error en búsqueda: " . $e->getMessage());
    echo '<tr><td colspan="7" class="text-center">Error en la búsqueda</td></tr>';
}
?>