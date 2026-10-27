<?php
// Configuración de conexión
$host = "65.99.205.112";
$dbname = "noreplyc_crmregistroventas";
$username = "noreplyc_alexsanchez09";
$password = "SWn_VrAc3K7lq;LS";
$charset = "utf8";

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=$charset", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
} catch (PDOException $e) {
    echo "Error de conexión: " . $e->getMessage();
    exit;
}

// Validar que venga el ID
if (empty($_POST['id'])) {
    echo "Error: faltan datos del cliente.";
    exit;
}

$id              = $_POST['id'];
$nombre          = $_POST['nombre'] ?? '';
$apepat          = $_POST['apepat'] ?? '';
$apemat          = $_POST['apemat'] ?? '';
$numero1         = $_POST['numero1'] ?? '';
$numero2         = $_POST['numero2'] ?? '';
$asunto          = $_POST['asunto'] ?? '';
$descripcion     = $_POST['descripcion'] ?? '';
$usuarios_id     = !empty($_POST['usuarios_id']) ? intval($_POST['usuarios_id']) : null;
$usuario_closer  = !empty($_POST['usuario_closer']) ? intval($_POST['usuario_closer']) : null;
$sala_id         = !empty($_POST['sala_id']) ? intval($_POST['sala_id']) : null; // Manejar 0 como NULL

// Validar que sala_id no sea 0
if ($sala_id === 0) {
    $sala_id = null;
}

// Actualizar cliente
try {
    $sql = "UPDATE cliente 
            SET nombre = :nombre,
                apepat = :apepat,
                apemat = :apemat,
                numero1 = :numero1,
                numero2 = :numero2,
                asunto = :asunto,
                descripcion = :descripcion,
                usuario_id = :usuario_id,
                usuario_closer = :usuario_closer,
                sala_id = :sala_id
            WHERE id = :id";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':nombre'        => $nombre,
        ':apepat'        => $apepat,
        ':apemat'        => $apemat,
        ':numero1'       => $numero1,
        ':numero2'       => $numero2,
        ':asunto'        => $asunto,
        ':descripcion'   => $descripcion,
        ':usuario_id'    => $usuarios_id,
        ':usuario_closer'=> $usuario_closer,
        ':sala_id'       => $sala_id,
        ':id'            => $id
    ]);

    echo "Cliente actualizado correctamente";
} catch (PDOException $e) {
    echo "Error al actualizar el cliente: " . $e->getMessage();
    error_log("Error actualizar cliente: " . $e->getMessage() . " - sala_id: " . ($sala_id ?? 'NULL'));
}
?>