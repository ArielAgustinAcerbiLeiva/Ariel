<?php
// Iniciar sesión para acceder a las variables de $_SESSION
session_start();

// 1. VERIFICACIÓN DE SESIÓN
// Si el usuario no está logueado, lo envía a login.php y detiene la ejecución.
if (!isset($_SESSION["usuario"])) { 
    header("Location: login.php"); 
    exit(); 
}

// Incluir la conexión a la base de datos (necesaria para cualquier script futuro, aunque no se use aquí directamente)
include('conexion.php');

// Obtener el rol del usuario
$rol_usuario = isset($_SESSION['rol']) ? $_SESSION['rol'] : '';

// 2. ENRUTAMIENTO POR ROL
// Redirige al archivo específico para el rol.
switch ($rol_usuario) {
    case 'Director':
    case 'Regente':
        // Redirige al archivo Regente.php
        header("Location: Regente.php");
        exit();
    case 'Preceptor':
        // Redirige al archivo Preceptor.php
        header("Location: Preceptor.php");
        exit();
    case 'Profesor':
        // **IMPORTANTE:** Debes crear este archivo (Profesor.php) con la lógica restante.
        // Mientras tanto, se puede redirigir aquí.
        header("Location: Profesor.php");
        exit();
    default:
        // Manejar roles no reconocidos o sin permisos
        // Podrías mostrar un mensaje de error o redirigir a una página de acceso denegado.
        header("Location: acceso_denegado.php"); 
        exit();
}

// Si por alguna razón la redirección no funciona (casi nunca pasa, pero es buena práctica)
echo "<h1>Error de redirección</h1><p>Tu rol no fue reconocido o el sistema no pudo cargarlo. Por favor, <a href='logout.php'>cierra la sesión</a> e inténtalo de nuevo.</p>";

?>