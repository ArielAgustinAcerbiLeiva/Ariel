<?php
session_start();
$error = "";

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    include 'conexion.php';

    $mail = $_POST['mail'];
    $password_formulario = $_POST['password'];

    // --- Consulta actualizada según tu base de datos ---
    // Coincide con las tablas: Empleado, Empleado_has_Rol y Rol
    $sql = "SELECT e.DNI, e.Contraseña, r.Nombre AS rol
            FROM Empleado e
            JOIN Empleado_has_Rol er ON e.DNI = er.Empleado_DNI
            JOIN Rol r ON er.Rol_idRol = r.idRol
            WHERE e.Correo = ? AND e.Contraseña = ?";

    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, "ss", $mail, $password_formulario);
    mysqli_stmt_execute($stmt);
    $resultado = mysqli_stmt_get_result($stmt);

    if ($usuario = mysqli_fetch_assoc($resultado)) {
        // Lista de roles con acceso permitido
        $roles_autorizados = ["Regente", "Profesor", "Preceptor"];

        if (in_array($usuario["rol"], $roles_autorizados)) {
            $_SESSION['usuario'] = $usuario['DNI'];
            $_SESSION['rol'] = $usuario["rol"];
            header("Location: registros.php");
            exit();
        } else {
            $error = "⛔ Acceso denegado: su rol («" . htmlspecialchars($usuario["rol"]) . "») no tiene permiso.";
        }
    } else {
        $error = "❌ Correo o contraseña incorrectos.";
    }

    mysqli_close($conn);
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Login</title>
    <link rel="stylesheet" href="Css/base.css">
    <link rel="stylesheet" href="Css/login_styles.css">
    <link rel="shortcut icon" href="imagenes/logo escuela.jpg">
</head>
<body>
    <img class="wave" src="imagenes/wave.png">
    <div class="container">
        <div class="img">
            <img src="imagenes/bg.svg" alt="Fondo">
        </div>
        <div class="login-content">
            <form method="POST" action="">
                <img src="imagenes/logo escuela.jpg" alt="Logo" class="logo">
                <h2 class="title">Iniciar Sesión</h2>

                <label for="mail" class="label">Correo: </label>
                <input type="email" id="mail" name="mail" class="input" required>

                <label for="password" class="label">Contraseña:</label>
                <input type="password" id="password" name="password" class="input" required>

                <button type="submit" class="btn btn-primary btn-lg">Iniciar Sesión</button>

                <?php if (!empty($error)) echo "<p class='error' style='color:red; font-weight:bold;'>$error</p>"; ?>

                <p class="crear-cuenta"><a href="crear_cuenta.php">¿No tenés cuenta? Registrate aquí</a></p>
            </form>
        </div>
    </div>
</body>
</html>
