<?php
session_start();
$error = "";

// 1. SI YA HAY SESIÓN, ENTRAR
if (isset($_SESSION['usuario'])) {
    header("Location: registros.php");
    exit();
}

// 2. SI NO HAY SESIÓN PERO HAY COOKIE, ENTRAR
if (!isset($_SESSION['usuario']) && isset($_COOKIE['usuario_dni'])) {
    include 'conexion.php';
    $dni_cookie = $_COOKIE['usuario_dni'];
    
    $sql_auto = "SELECT r.Nombre AS rol 
                 FROM Empleado_has_Rol er 
                 JOIN Rol r ON er.Rol_idRol = r.idRol 
                 WHERE er.Empleado_DNI = ?";
    $stmt_auto = mysqli_prepare($conn, $sql_auto);
    mysqli_stmt_bind_param($stmt_auto, "s", $dni_cookie);
    mysqli_stmt_execute($stmt_auto);
    $res_auto = mysqli_stmt_get_result($stmt_auto);
    
    if ($fila = mysqli_fetch_assoc($res_auto)) {
        $_SESSION['usuario'] = $dni_cookie;
        $_SESSION['rol'] = $fila['rol'];
        header("Location: registros.php");
        exit();
    }
}

// 3. LOGIN MANUAL
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    include 'conexion.php';

    $mail = $_POST['mail'];
    $password_formulario = $_POST['password'];

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
        $roles_autorizados = ["Regente", "Profesor", "Preceptor", "Director"];

        if (in_array($usuario["rol"], $roles_autorizados)) {
            $_SESSION['usuario'] = $usuario['DNI'];
            $_SESSION['rol'] = $usuario["rol"];

            if (isset($_POST['recordarme'])) {
                setcookie("usuario_dni", $usuario['DNI'], time() + (86400 * 30), "/");
            }

            header("Location: registros.php");
            exit();
        } else {
            $error = "❌ Tu rol no tiene permiso para acceder a este sistema.";
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
            <form method="POST" action="" autocomplete="off">
                <img src="imagenes/logo escuela.jpg" alt="Logo" class="logo">
                <h2 class="title">Iniciar Sesión</h2>

                <label for="mail" class="label">Correo: </label>
                <input type="email" id="mail" name="mail" class="input" required autocomplete="off">

                <label for="password" class="label">Contraseña:</label>
                <input type="password" id="password" name="password" class="input" required autocomplete="new-password">

                <div style="text-align: left; margin: 10px 0;">
                    <input type="checkbox" name="recordarme" id="recordarme">
                    <label for="recordarme" style="font-size: 0.9rem; color: #333;">Recordarme</label>
                </div>

                <button type="submit" class="btn btn-primary btn-lg">Iniciar Sesión</button>

                <?php if (!empty($error)): ?>
                    <p class="error" style="color:red; font-weight:bold; margin-top:10px;">
                        <?php echo $error; ?>
                    </p>
                <?php endif; ?>
                
                <p class="crear-cuenta"><a href="crear_cuenta.php">Crear cuenta</a></p>
            </form>
        </div>
    </div>
    <script type="text/javascript" src="js/main.js"></script>
</body>
</html>