<?php
include 'conexion.php';
session_start();

// Forzar MySQLi a lanzar excepciones en errores
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

// Verificar conexión
if (!$conn) {
    die("❌ Error de conexión a la base de datos: " . mysqli_connect_error());
}

// [CÓDIGO ELIMINADO]: Ya no es necesario cargar roles de la base de datos, pues el formulario ya no los pide.
/* $rolesArr = [];
$res = mysqli_query($conn, "SELECT idRol, Nombre FROM Rol");
while ($r = mysqli_fetch_assoc($res)) {
    $rolesArr[] = $r;
}
*/

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // Tratar DNI como string (tu columna es VARCHAR)
    $dni = trim($_POST['dni'] ?? '');
    $nombre = trim($_POST['nombre'] ?? '');
    $apellido = trim($_POST['apellido'] ?? '');
    $mail = trim($_POST['mail'] ?? '');
    $contraseña = $_POST['contraseña'] ?? '';
    
    // [CÓDIGO ELIMINADO]: Se ignora la selección de cargos del formulario (aunque el campo se haya eliminado en el HTML).
    // $cargos_seleccionados = $_POST['cargo'] ?? []; 

    // Validaciones básicas
    if ($dni === '' || $nombre === '' || $apellido === '' || $mail === '' || $contraseña === '') {
        $error = "⚠️ Todos los campos son obligatorios.";
    } elseif (!filter_var($mail, FILTER_VALIDATE_EMAIL)) {
        $error = "📧 El formato del correo electrónico no es válido.";
    } else {
        try {
            // Iniciar transacción
            mysqli_begin_transaction($conn);

            // Insertar empleado (Se mantiene, solo se crea la cuenta base)
            // NOTA: DNI es VARCHAR en tu esquema, por eso usamos "sssss"
            $sqlEmpleado = "INSERT INTO Empleado (DNI, Nombre, Apellido, Correo, Contraseña) VALUES (?, ?, ?, ?, ?)";
            $stmtEmpleado = mysqli_prepare($conn, $sqlEmpleado);
            // RECUERDA: Por seguridad, deberías usar password_hash() para cifrar la contraseña aquí.
            mysqli_stmt_bind_param($stmtEmpleado, "sssss", $dni, $nombre, $apellido, $mail, $contraseña);
            mysqli_stmt_execute($stmtEmpleado);
            mysqli_stmt_close($stmtEmpleado);

            // [BLOQUE CRÍTICO ELIMINADO]: Se remueve completamente el código que insertaba datos en Empleado_has_Rol,
            // garantizando que el usuario no se asigne un cargo.
            /* if (!empty($cargos_seleccionados) && is_array($cargos_seleccionados)) {
                $sqlRol = "INSERT INTO Empleado_has_Rol (Empleado_DNI, Rol_idRol) VALUES (?, ?)";
                $stmtRol = mysqli_prepare($conn, $sqlRol);

                foreach ($cargos_seleccionados as $rol_id) {
                    $rol_id_int = intval($rol_id);
                    mysqli_stmt_bind_param($stmtRol, "si", $dni, $rol_id_int);
                    mysqli_stmt_execute($stmtRol);
                }
                mysqli_stmt_close($stmtRol);
            } 
            */

            // Confirmar cambios
            mysqli_commit($conn);

            // Redirigir a login con un mensaje que indique que la cuenta está pendiente de activación/asignación de rol.
            header("Location: login.php?registro=pendiente");
            exit();

        } catch (mysqli_sql_exception $e) {
            // Revertir cambios si falla algo
            mysqli_rollback($conn);

            if ($e->getCode() == 1062) {
                $error = "❌ Error: El DNI o el correo electrónico ya está registrado.";
            } else {
                $error = "❌ Error al registrar. Detalle: " . $e->getMessage();
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Crear cuenta</title>
    <link rel="stylesheet" href="Css/base.css">
    <link rel="stylesheet" href="Css/crea_cuenta.css">
    <link rel="shortcut icon" href="imagenes/logo escuela.jpg">
</head>
<body>
    <img class="wave" src="imagenes/wave.png">
    <div class="container">
        <div class="img"><img src="imagenes/bg.svg" alt="Fondo"></div>
        <div class="login-content">
            <form method="POST" action="">
                <img src="imagenes/logo escuela.jpg" alt="Logo" class="logo">
                <h2 class="title">Crear Cuenta</h2>

                <label class="label">DNI:</label>
                <input type="text" name="dni" class="input no-spin" required value="<?= htmlspecialchars($_POST['dni'] ?? '') ?>">

                <label class="label">Nombre:</label>
                <input type="text" name="nombre" class="input" required value="<?= htmlspecialchars($_POST['nombre'] ?? '') ?>">

                <label class="label">Apellido:</label>
                <input type="text" name="apellido" class="input" required value="<?= htmlspecialchars($_POST['apellido'] ?? '') ?>">

                <label class="label">Correo electrónico:</label>
                <input type="email" name="mail" class="input" required value="<?= htmlspecialchars($_POST['mail'] ?? '') ?>">

                <label class="label">Contraseña:</label>
                <input type="password" name="contraseña" class="input" required>

                <button type="submit" class="btn btn-primary btn-lg" style="margin-top:15px;">Crear cuenta</button>

                <?php if (isset($error)) echo "<p class='error' style='color:red; font-weight:bold; margin-top:10px;'>" . htmlspecialchars($error) . "</p>"; ?>

                <p class="crear-cuenta"><a href="login.php">¿Ya tenés cuenta? Iniciar sesión</a></p>
            </form>
        </div>
    </div>

    </body>
</html>