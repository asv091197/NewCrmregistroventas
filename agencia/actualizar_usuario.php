<?php
// Conexión a la base de datos
$host = '65.99.205.112';
$dbname = 'marketinpro';
$username = 'noreplyc_alexsanchez09';
$password = 'SWn_VrAc3K7lq;LS';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("Error de conexión: " . $e->getMessage());
}

// Verificar ID
$id = $_GET['id'] ?? null;
if (!$id) {
    die("ID de usuario no especificado.");
}

// Procesar actualización
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $nombre = trim($_POST["nombre"]);
    $email = trim($_POST["email"]);
    $rol = $_POST["rol"];
    $nuevaPassword = $_POST["password"];

    if (!empty($nombre) && filter_var($email, FILTER_VALIDATE_EMAIL) && !empty($rol)) {
        try {
            if (!empty($nuevaPassword)) {
                // Si se proporciona nueva contraseña, se actualiza con hash
                $hash = password_hash($nuevaPassword, PASSWORD_DEFAULT);
                $stmt = $pdo->prepare("UPDATE usuarios SET nombre = ?, email = ?, rol = ?, contrasena = ? WHERE id = ?");
                $stmt->execute([$nombre, $email, $rol, $hash, $id]);
            } else {
                // Si no se proporciona contraseña, se actualizan solo los otros campos
                $stmt = $pdo->prepare("UPDATE usuarios SET nombre = ?, email = ?, rol = ? WHERE id = ?");
                $stmt->execute([$nombre, $email, $rol, $id]);
            }

            echo "<div class='alert alert-success'>Usuario actualizado correctamente.</div>";
            echo "<a href='usuarios.php' class='btn btn-primary mt-3'>Volver a la lista</a>";
        } catch (PDOException $e) {
            echo "<div class='alert alert-danger'>Error: " . $e->getMessage() . "</div>";
        }
    } else {
        echo "<div class='alert alert-warning'>Por favor, completa todos los campos correctamente.</div>";
    }
} else {
    echo "<div class='alert alert-danger'>Acceso inválido.</div>";
}
?>
