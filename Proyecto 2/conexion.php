<?php
$servername = "localhost";    // Servidor local
$username   = "root";         // Usuario por defecto de XAMPP / WAMP / Laragon
$password   = "";             // Dejalo vacío si no pusiste clave en MySQL
$database   = "taller_colegio"; // Nombre exacto de tu base de datos

// Crear conexión
$conn = mysqli_connect($servername, $username, $password, $database);

// Verificar conexión
if (!$conn) {
    die("❌ Error al conectar con la base de datos: " . mysqli_connect_error());
}

// Opcional: establecer charset para evitar problemas con acentos
mysqli_set_charset($conn, "utf8mb4");
?>
