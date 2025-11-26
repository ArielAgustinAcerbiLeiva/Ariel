<?php
session_start();
session_destroy(); // Mata la sesión del servidor

// Mata la cookie del navegador
if (isset($_COOKIE['usuario_dni'])) {
    setcookie("usuario_dni", "", time() - 3600, "/");
}

header("Location: login.php");
exit();
?>