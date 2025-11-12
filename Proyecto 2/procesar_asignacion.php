<?php
// procesar_asignacion.php

include 'conexion.php';
session_start();

// --- CONTROL DE ACCESO (CRÍTICO) ---
// Verificación para asegurar que SOLO el Regente pueda ejecutar el código de modificación de roles.
if (!isset($_SESSION['rol']) || $_SESSION['rol'] !== 'Regente' || $_SERVER["REQUEST_METHOD"] !== "POST") {
    // Redirección si el usuario no es Regente o no es un POST válido.
    header('Location: Regente.php?view=roles&error=no_autorizado');
    exit();
}
// --- FIN CONTROL DE ACCESO ---


mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$dni_empleado = $_POST['dni_empleado'] ?? '';
$roles_a_asignar = $_POST['roles_a_asignar'] ?? []; // Array de ID de roles

if (empty($dni_empleado) || empty($roles_a_asignar)) {
    // Si falta DNI o no se seleccionó ningún rol, fallar.
    header("Location: Regente.php?view=roles&error=asignacion_fallida");
    exit();
}

try {
    // Iniciar Transacción
    mysqli_begin_transaction($conn);

    // 1. ELIMINAR TODOS LOS ROLES ANTERIORES del empleado
    $sqlDelete = "DELETE FROM Empleado_has_Rol WHERE Empleado_DNI = ?";
    $stmtDelete = mysqli_prepare($conn, $sqlDelete);
    mysqli_stmt_bind_param($stmtDelete, "s", $dni_empleado);
    mysqli_stmt_execute($stmtDelete);
    mysqli_stmt_close($stmtDelete);

    // 2. INSERTAR LOS NUEVOS ROLES SELECCIONADOS
    $sqlInsert = "INSERT INTO Empleado_has_Rol (Empleado_DNI, Rol_idRol) VALUES (?, ?)";
    $stmtInsert = mysqli_prepare($conn, $sqlInsert);

    foreach ($roles_a_asignar as $rol_id) {
        $rol_id_int = intval($rol_id);
        mysqli_stmt_bind_param($stmtInsert, "si", $dni_empleado, $rol_id_int);
        mysqli_stmt_execute($stmtInsert);
    }
    mysqli_stmt_close($stmtInsert);

    // 3. Confirmar la transacción
    mysqli_commit($conn);

    // Redirigir con mensaje de éxito a la vista de roles
    header("Location: Regente.php?view=roles&exito=asignacion_exitosa");
    exit();

} catch (mysqli_sql_exception $e) {
    // Revertir cambios si algo falla
    mysqli_rollback($conn);
    // Redirigir con mensaje de error
    error_log("Error de asignación de roles: " . $e->getMessage()); // Registrar el error en el log
    header("Location: Regente.php?view=roles&error=asignacion_fallida");
    exit();
}
?>