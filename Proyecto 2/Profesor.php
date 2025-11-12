<?php
// ===============================================
// Archivo: profesor.php (Exclusivo para Profesor)
// ===============================================

session_start();
// Restringir el acceso: Solo Profesor
if (!isset($_SESSION["usuario"]) || $_SESSION['rol'] !== 'Profesor') { 
    header("Location: login.php"); 
    exit(); 
}

include('conexion.php');

$rol_usuario = 'Profesor'; // Rol fijo
// El DNI es VARCHAR(15) en tu BD (taller_colegio.sql) para Empleado y Alumno.
$usuario_dni = isset($_SESSION['usuario']) ? (string)$_SESSION['usuario'] : null;

// --- FUNCIÓN PARA OBTENER NOMBRE DEL USUARIO ---
$nombre_usuario_display = 'Profesor';
if ($usuario_dni && isset($conn)) {
    // Buscamos el nombre del empleado logueado (asumiendo tabla 'empleado')
    $stmt_nombre = $conn->prepare("SELECT Nombre, Apellido FROM empleado WHERE DNI = ?");
    $stmt_nombre->bind_param("s", $usuario_dni);
    @$stmt_nombre->execute();
    $result_nombre = @$stmt_nombre->get_result();
    if ($result_nombre && $row_nombre = $result_nombre->fetch_assoc()) {
        $nombre_usuario_display = htmlspecialchars($row_nombre['Nombre'] . ' ' . $row_nombre['Apellido']);
    }
    @$stmt_nombre->close(); 
}
// --------------------------------------------------------

// Mensajes de feedback
$mensaje_exito = null;
$mensaje_error = null;

// --- FUNCIÓN DE VERIFICACIÓN DE PERMISOS (Simplificada para Profesor) ---
// Usa 's' para DNI (VARCHAR) y 'i' para IDs (INT)
function verificarPermisosProfesor($conn, $dni, $curso_id, $materia_id) {
    if ($dni === null) return false;
    
    // 1. Verificar permiso sobre el curso (DNI como string 's', idCurso como int 'i')
    $stmt_curso = $conn->prepare("SELECT 1 FROM curso_has_empleado WHERE Empleado_DNI = ? AND Curso_idCurso = ?");
    $stmt_curso->bind_param("si", $dni, $curso_id);
    $stmt_curso->execute();
    $tiene_permiso_curso = $stmt_curso->get_result()->num_rows > 0;
    $stmt_curso->close();

    // 2. Verificar permiso sobre la materia (DNI como string 's', idMateria como int 'i')
    $stmt_materia = $conn->prepare("SELECT 1 FROM materia_has_empleado WHERE Empleado_DNI = ? AND Materia_idMateria = ?");
    $stmt_materia->bind_param("si", $dni, $materia_id);
    $stmt_materia->execute();
    $tiene_permiso_materia = $stmt_materia->get_result()->num_rows > 0;
    $stmt_materia->close();

    // El profesor necesita ambos permisos para gestionar la clase
    return $tiene_permiso_curso && $tiene_permiso_materia;
}

// --- LÓGICA DE FORMULARIOS ---

// LÓGICA PARA ASIGNAR CURSO Y MATERIA
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['asignar'])) {
    $curso_id = isset($_POST['curso_id_asignar']) ? (int)$_POST['curso_id_asignar'] : null;
    $materia_id = isset($_POST['materia_id_asignar']) ? (int)$_POST['materia_id_asignar'] : null;
    $success_messages = [];
    $error_messages = [];

    // 1. ASIGNAR CURSO
    if (!empty($curso_id) && $usuario_dni) {
        $stmt = $conn->prepare("INSERT IGNORE INTO curso_has_empleado (Curso_idCurso, Empleado_DNI) VALUES (?, ?)");
        $stmt->bind_param("is", $curso_id, $usuario_dni);
        if ($stmt->execute()) {
            if ($stmt->affected_rows > 0) {
                $stmt_curso_nombre = $conn->prepare("SELECT Curso, Ciclo FROM curso WHERE idCurso = ?");
                $stmt_curso_nombre->bind_param("i", $curso_id);
                $stmt_curso_nombre->execute();
                $curso_info = $stmt_curso_nombre->get_result()->fetch_assoc();
                $curso_nombre_display = htmlspecialchars($curso_info['Curso'] . ' ' . $curso_info['Ciclo']);
                $stmt_curso_nombre->close();
                $success_messages[] = "✅ Curso '{$curso_nombre_display}' asignado correctamente.";
            } else {
                $error_messages[] = "⚠️ Ya tienes ese curso asignado.";
            }
        } else {
            $error_messages[] = "❌ Error al intentar asignar el curso.";
        }
        $stmt->close();
    }

    // 2. ASIGNAR MATERIA
    if (!empty($materia_id) && $usuario_dni) {
        $stmt = $conn->prepare("INSERT IGNORE INTO materia_has_empleado (Materia_idMateria, Empleado_DNI) VALUES (?, ?)");
        $stmt->bind_param("is", $materia_id, $usuario_dni);

        if ($stmt->execute()) {
            if ($stmt->affected_rows > 0) {
                $stmt_materia_nombre = $conn->prepare("SELECT Materia FROM materia WHERE idMateria = ?");
                $stmt_materia_nombre->bind_param("i", $materia_id);
                $stmt_materia_nombre->execute();
                $materia_info = $stmt_materia_nombre->get_result()->fetch_assoc();
                $materia_nombre_display = htmlspecialchars($materia_info['Materia']);
                $stmt_materia_nombre->close();
                $success_messages[] = "✅ Materia '{$materia_nombre_display}' asignada correctamente.";
            } else {
                $error_messages[] = "⚠️ Ya tienes esa materia asignada.";
            }
        } else {
            $error_messages[] = "❌ Error al intentar asignar la materia.";
        }
        $stmt->close();
    }

    if (count($success_messages) > 0) { $mensaje_exito = implode("<br>", $success_messages); }
    if (count($error_messages) > 0) { $mensaje_error = implode("<br>", $error_messages); }
}


// LÓGICA DE ASISTENCIA
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['guardar_asistencias'])) {
    $curso_id = (int)$_POST['curso_id_gestion'];
    $materia_id = (int)$_POST['materia_id'];
    
    if (verificarPermisosProfesor($conn, $usuario_dni, $curso_id, $materia_id)) {
        $fecha = date('Y-m-d');
        $asistencias_alumnos = isset($_POST['asistencia']) ? $_POST['asistencia'] : [];

        $stmt_check = $conn->prepare("SELECT idAsistencia FROM asistencia WHERE Dia = ? AND Curso_idCurso = ? AND Materia_idMateria = ? AND Alumno_DNI = ?");
        $stmt_update = $conn->prepare("UPDATE asistencia SET Estado = ? WHERE idAsistencia = ?");
        $stmt_insert = $conn->prepare("INSERT INTO asistencia (Dia, Estado, Curso_idCurso, Materia_idMateria, Alumno_DNI) VALUES (?, ?, ?, ?, ?)");

        if (!empty($asistencias_alumnos)) {
            foreach ($asistencias_alumnos as $alumno_dni => $estado) {
                $alumno_dni_str = (string)$alumno_dni; 
                // 1. Verificar si ya existe registro de asistencia para hoy
                $stmt_check->bind_param("siis", $fecha, $curso_id, $materia_id, $alumno_dni_str);
                $stmt_check->execute();
                $result = $stmt_check->get_result();
                if ($row = $result->fetch_assoc()) {
                    // Si existe, actualizar
                    $asistencia_id = $row['idAsistencia'];
                    $stmt_update->bind_param("si", $estado, $asistencia_id);
                    $stmt_update->execute();
                } else {
                    // Si no existe, insertar nuevo
                    $stmt_insert->bind_param("ssiis", $fecha, $estado, $curso_id, $materia_id, $alumno_dni_str);
                    $stmt_insert->execute();
                }
            }
        }
        $stmt_check->close();
        $stmt_update->close();
        $stmt_insert->close();
        $mensaje_exito = "✅ Asistencias guardadas para el día de hoy.";
    } else {
        $mensaje_error = "❌ No tienes permiso para modificar este curso o materia.";
    }
}

// LÓGICA PARA GUARDAR NOTAS
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['guardar_notas'])) {
    $materia_id = (int)$_POST['materia_id'];
    $curso_id = (int)$_POST['curso_id_gestion'];
    
    if (verificarPermisosProfesor($conn, $usuario_dni, $curso_id, $materia_id)) {
        $notas = $_POST['notas'];
        // INSERT... ON DUPLICATE KEY UPDATE: Actualiza si ya existe la combinación Materia_idMateria, Alumno_DNI
        $stmt = $conn->prepare("INSERT INTO materia_has_alumno (Materia_idMateria, Alumno_DNI, notas) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE notas = VALUES(notas)");
        
        foreach ($notas as $alumno_dni => $nota) {
            $alumno_dni_str = (string)$alumno_dni;
            $nota_sanitizada = trim(str_replace(',', '.', (string)$nota)); // Permite comas y puntos, guarda como string
            
            // Validar si la nota es vacía o null
            if ($nota_sanitizada === '') {
                $nota_final = null; // Guardar como NULL o cadena vacía si no hay nota
            } else {
                $nota_final = $nota_sanitizada;
            }

            // Usar 's' para la nota ya que es un VARCHAR en BD
            $stmt->bind_param("iss", $materia_id, $alumno_dni_str, $nota_final);
            $stmt->execute();
        }
        $stmt->close();
        $mensaje_exito = "✅ Notas actualizadas correctamente.";
    } else {
        $mensaje_error = "❌ No tienes permiso para modificar las notas de este curso o materia.";
    }
}

// --- VARIABLES DE CONTROL Y LÓGICA DE VISTA ---
$vista = isset($_GET['vista']) ? $_GET['vista'] : 'mis_cursos'; // Vista por defecto para Profesor

// Variables de filtro para GESTIONAR
$curso_seleccionado_gestion = isset($_REQUEST['curso_id_gestion']) ? (int)$_REQUEST['curso_id_gestion'] : null;
$materia_seleccionada = isset($_REQUEST['materia_id']) ? (int)$_REQUEST['materia_id'] : null;
// NUEVO: Variable de filtro de Grupo
$grupo_seleccionado_gestion = isset($_REQUEST['grupo_id_gestion']) ? (int)$_REQUEST['grupo_id_gestion'] : null;

$vista_gestion = isset($_REQUEST['tab']) ? $_REQUEST['tab'] : 'asistencia'; // Default tab for Profesor: asistencia
$curso_seleccionado_busqueda = isset($_REQUEST['curso_id_busqueda']) ? (int)$_REQUEST['curso_id_busqueda'] : null; 
$dni_buscado = isset($_REQUEST['dni_buscado']) ? trim((string)$_REQUEST['dni_buscado']) : ''; 
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Profesor - Registros del Sistema</title>
    <link rel="shortcut icon" href="imagenes/logo escuela.jpg">
    <style>
        :root {
            --color-principal: #007bff;  
            --color-claro: #ffffff;       
            --color-error: #dc3545;      
                }

        body { 
            background-color: #f0f2f5;
        }

        body { 
            background-color: #f0f2f5; 
            color: #333; 
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; 
            margin: 0;
            padding: 0;
        }
        
        /* Top Bar */
        .top-bar { 
            background-color: var(--color-principal); 
            color: var(--color-claro); 
            padding: 10px 40px; 
            display: flex; 
            justify-content: flex-end; /* Alinea todo a la derecha */
            align-items: center; 
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            position: relative; 
        }
        

        /* Nav Menu */
        .nav-menu { 
            background-color: #1565c0; 
            padding: 0 40px; 
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            display: flex;
            gap: 10px; /* Espacio entre los elementos del menú */
        }
        .nav-menu a { 
            color: white; 
            text-decoration: none; 
            padding: 15px 20px; 
            display: block;
            transition: background-color 0.3s, border-bottom 0.3s; 
        }
        .nav-menu a.active { 
            background-color: #1a73e8; 
            border-radius: 0; 
            border-bottom: 3px solid #ffeb3b; 
        }
        .nav-menu a:hover { 
            background-color: #1976d2; 
        }

        /* Contenido Principal */
        .content { 
            padding: 20px 40px; 
            background-color: white; 
            margin: 20px auto; 
            max-width: 1400px; 
            border-radius: 10px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.08);
            opacity: 0; 
            animation: fadeInUp 0.7s ease-out 0.2s forwards;
        }
        h1, h2, h3 { 
            color: #1a73e8; 
            border-bottom: 1px solid #e0e0e0; 
            padding-bottom: 10px; 
            margin-top: 25px; 
            font-weight: 600;
        }
        h1 { font-size: 2em; }
        h2 { font-size: 1.5em; }
        h3 { font-size: 1.2em; }

        /* Botones */
        .btn, .btn-buscar, .btn-cargar, .btn-guardar, .btn-agregar, .btn-asignar { 
            background-color: #1a73e8; 
            color: white; 
            border: none; 
            padding: 10px 20px; 
            cursor: pointer; 
            border-radius: 5px; 
            text-decoration: none; 
            display: inline-block; 
            margin-right: 10px; 
            transition: background-color 0.3s, transform 0.1s, box-shadow 0.3s; 
            font-weight: 500;
        }
        .btn:hover, .btn-buscar:hover, .btn-cargar:hover, .btn-guardar:hover, .btn-agregar:hover, .btn-asignar:hover { 
            background-color: #1565c0; 
            transform: translateY(-2px); 
            box-shadow: 0 4px 8px rgba(0,0,0,0.15);
        }
        .btn-volver { background-color: #6c757d !important; }
        .btn-volver:hover { background-color: #5a6268 !important; }
        .btn-guardar { 
            background-color: #28a745; /* Verde para guardar */
            margin-top: 25px; /* Más espacio para el botón de guardar */
            margin-bottom: 5px;
        }
        .btn-guardar:hover {
            background-color: #218838;
        }

        /* Tablas */
        table { 
            width: 100%; 
            border-collapse: separate; 
            border-spacing: 0;
            margin-top: 20px; 
            table-layout: fixed; /* Por defecto para uniformidad */
            border-radius: 8px;
            overflow: hidden; 
            box-shadow: 0 1px 3px rgba(0,0,0,0.05);
        }
        th, td { 
            border: 1px solid #e0e0e0; 
            padding: 12px; 
            text-align: left; 
            vertical-align: middle; 
            word-wrap: break-word; 
            color: #444; 
        } 
        th { 
            background-color: #f5f5f5; 
            color: #333; 
            font-weight: 700; 
            text-transform: uppercase;
            font-size: 0.85em;
        } 
        tr:nth-child(even) { 
            background-color: #f9f9f9; 
        } 
        tr:hover {
            background-color: #e3f2fd;
            transition: background-color 0.3s;
        }
        
        /* Formularios y Filtros */
        .form-container, .filtros { 
            margin-bottom: 20px; 
            padding: 25px; /* Más padding */
            border: 1px solid #e0e0e0; 
            border-radius: 10px; 
            background-color: #ffffff; 
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
        }
        .form-group { margin-bottom: 15px; }
        .form-group label { display: block; margin-bottom: 8px; font-weight: 600; color: #555; }
        .form-group select, .form-group input[type="text"] { 
            width: 100%; 
            padding: 10px; 
            border: 1px solid #c0c0c0; 
            border-radius: 5px; 
            box-sizing: border-box;
            font-size: 1em;
            transition: border-color 0.3s, box-shadow 0.3s;
            background-color: #fff;
        }
        .form-group select:focus, .form-group input[type="text"]:focus {
            border-color: #1a73e8;
            box-shadow: 0 0 0 3px rgba(26, 115, 232, 0.2);
            outline: none;
        }

        .filtros label { font-weight: 600; color: #555; margin-right: 10px; }
        .filtros select, .filtros input[type="text"] {
            padding: 8px 12px;
            border: 1px solid #c0c0c0;
            border-radius: 5px;
            font-size: 0.95em;
            margin-right: 10px;
            min-width: 180px;
            background-color: #fff;
            transition: border-color 0.3s, box-shadow 0.3s;
        }
        .filtros select:focus, .filtros input[type="text"]:focus {
            border-color: #1a73e8;
            box-shadow: 0 0 0 3px rgba(26, 115, 232, 0.2);
            outline: none;
        }
        .filtros .btn-cargar { margin-top: 0; }


        /* Mensajes */
        .mensaje-exito, .mensaje-error, .alerta-asignacion {
            padding: 15px;
            border-radius: 8px;
            font-weight: bold;
            margin-bottom: 20px;
            text-align: center;
            box-shadow: 0 2px 4px rgba(0,0,0,0.05);
        }
        .mensaje-exito { color: #155724; background-color: #d4edda; border: 1px solid #c3e6cb; }
        .mensaje-error { color: #721c24; background-color: #f8d7da; border: 1px solid #f5c6cb; }
        .alerta-asignacion {
            background-color: #fff3cd; /* Amarillo suave */
            border: 1px solid #ffeeba;
            color: #856404;
            font-size: 1.1em;
        }
        .alerta-asignacion a { color: #856404; font-weight: bold; text-decoration: underline; }

        /* Animaciones */
        @keyframes fadeInUp {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }

        /* --- ESTILOS DROPDOWN DE USUARIO --- */
        .user-dropdown {
            position: relative; /* Contenedor para posicionar el menú */
            display: inline-block;
            cursor: pointer;
        }



        .user-dropdown:hover img {
            transform: scale(1.05); /* Pequeño efecto hover en el avatar */
        }

        .user-dropdown img { 
            border-radius: 50%; 
            border: 2px solid var(--color-claro);
            width: 40px; 
            height: 40px; 
            object-fit: cover;
            transition: transform 0.2s;
        }


        .user-icon { font-size: 20px; margin-right: 5px; user-select: none; }



      .dropdown-content {
            display: none; /* Oculto por defecto */
            position: absolute;
            background-color: var(--color-claro);
            min-width: 250px;
            box-shadow: 0px 8px 16px 0px rgba(0,0,0,0.2);
            z-index: 1000;
            top: 55px; /* Separación de la barra superior */
            right: 0; /* Alineado a la derecha del contenedor user-dropdown */
            border-radius: 8px;
            overflow: hidden;
        } 
        
        .dropdown-content.show { display: block; opacity: 1; transform: translateY(0); }
        .dropdown-header { padding: 15px; background-color: #f1f1f1; color: #333; border-bottom: 1px solid #ddd; }
        .dropdown-header strong { display: block; font-size: 1.1em; margin-bottom: 5px; }
        .dropdown-content a { color: #333; padding: 12px 15px; text-decoration: none; display: block; transition: background-color 0.3s; }
        .dropdown-content a:hover { background-color: #e3f2fd; color: #1a73e8; }
        
        /* --- REDISEÑO: ESTILOS PARA ASIGNAR CURSO Y MATERIA --- */
        .form-asignar {
            display: flex;
            flex-direction: column;
            gap: 20px; /* Más espacio entre los grupos de formulario */
            background-color: #fcfcfc; /* Fondo más claro */
            padding: 30px;
            border-radius: 10px;
            border: 1px solid #e0e0e0;
            box-shadow: 0 4px 10px rgba(0,0,0,0.05);
        }
        .form-asignar .form-group {
            position: relative; /* Para posicionar el icono */
        }
        .form-asignar label {
            color: #333; /* Texto más oscuro */
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 8px; /* Espacio entre el icono y el texto del label */
        }
        .form-asignar select {
            border: 1px solid #a0a0a0; /* Borde más sutil */
            padding-right: 40px; /* Espacio para la flecha nativa */
            appearance: none; /* Oculta la flecha nativa para un control total */
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='%23666' width='18px' height='18px'%3E%3Cpath d='M7 10l5 5 5-5z'/%3E%3Cpath d='M0 0h24v24H0z' fill='none'/%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 12px center;
            background-size: 18px;
            cursor: pointer;
        }
        .form-asignar select:focus {
            border-color: #1a73e8;
            box-shadow: 0 0 0 3px rgba(26, 115, 232, 0.2);
        }
        .form-asignar .acciones-btn {
            text-align: right; /* Alinea el botón a la derecha */
            margin-top: 20px;
        }

        /* --- NUEVO: ESTILOS PARA LA TABLA DE BÚSQUEDA (TAMAÑO FIJO) --- */
        .busqueda-detalle-table {
            table-layout: fixed;
        }
        .busqueda-detalle-table th:nth-child(1), .busqueda-detalle-table td:nth-child(1) { width: 25%; } /* Materia */
        .busqueda-detalle-table th:nth-child(2), .busqueda-detalle-table td:nth-child(2) { width: 10%; } /* Nota */
        .busqueda-detalle-table th:nth-child(3), .busqueda-detalle-table td:nth-child(3) { width: 15%; } /* Presentes */
        .busqueda-detalle-table th:nth-child(4), .busqueda-detalle-table td:nth-child(4) { width: 15%; } /* Ausentes */
        .busqueda-detalle-table th:nth-child(5), .busqueda-detalle-table td:nth-child(5) { width: 15%; } /* Justificados */
        .busqueda-detalle-table th:nth-child(6), .busqueda-detalle-table td:nth-child(6) { width: 20%; } /* % Faltas */
        
        /* Estilos específicos de gestión de pestañas */
        .tabs { 
            display: flex; 
            margin-bottom: 0; /* Elimina margen inferior */
            border-bottom: 2px solid #e0e0e0; /* Borde de las pestañas */
        }
        .tab-btn { 
            padding: 12px 20px; 
            cursor: pointer; 
            background-color: #f0f2f5; /* Fondo de pestaña inactiva */
            border: 1px solid #e0e0e0; 
            border-bottom: none; 
            border-radius: 8px 8px 0 0; 
            margin-right: 5px; 
            text-decoration: none; 
            color: #555;
            font-weight: 600;
            transition: all 0.3s ease;
        }
        .tab-btn.active { 
            background-color: white; /* Fondo de pestaña activa */
            color: #1a73e8; 
            border-color: #e0e0e0;
            border-bottom-color: white; /* Simula que está sobre el contenido */
            transform: translateY(1px); /* Pequeña elevación */
            box-shadow: 0 -2px 5px rgba(0,0,0,0.05);
        }
        .tab-btn:hover:not(.active) {
            background-color: #e6f7ff;
            color: #1a73e8;
        }
        .tab-content { 
            padding: 25px; 
            border: 1px solid #e0e0e0; 
            border-top: none; 
            background-color: white; 
            border-radius: 0 0 10px 10px; /* Bordes redondeados inferiores */
            box-shadow: 0 4px 12px rgba(0,0,0,0.05);
            margin-bottom: 20px;
        }

        /* Estilización de Radio Buttons para Asistencia */
        td input[type="radio"] {
            -webkit-appearance: none;
            -moz-appearance: none;
            appearance: none;
            display: inline-block;
            position: relative;
            top: 2px;
            width: 18px;
            height: 18px;
            border-radius: 50%;
            border: 2px solid #ccc;
            background-color: #fff;
            vertical-align: middle;
            margin-right: 5px;
            cursor: pointer;
            outline: none;
            transition: border-color 0.2s, background-color 0.2s;
        }
        td input[type="radio"]:hover {
            border-color: #888;
        }
        td input[type="radio"]:checked {
            border-color: #1a73e8;
            background-color: #1a73e8;
        }
        td input[type="radio"]:checked::before {
            content: '';
            display: block;
            width: 8px;
            height: 8px;
            border-radius: 50%;
            background: white;
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
        }
        td label {
            vertical-align: middle;
            cursor: pointer;
        }
        .radio-group label {
            display: inline-block;
            margin-right: 15px; /* Espacio entre "Presente" y "Ausente" */
        }
        
        .dropdown-logout-link {
            display: block;
            padding: 10px 15px;
            text-align: center;
            text-decoration: none;
            background-color: var(--color-error); /* Rojo para el botón de cerrar sesión */
            color: var(--color-claro);
            font-weight: bold;
            transition: background-color 0.2s;
        }

        .dropdown-logout-link:hover {
            background-color: #c82333;
        }

        .dropdown-user-info {
            padding: 15px;
            background-color: #f8f9fa; /* Fondo gris claro */
            color: #333;
            border-bottom: 1px solid #eee;
        }

        .dropdown-user-info strong {
            color: var(--color-principal);
            display: block;
            margin-bottom: 5px;
        }

        .dropdown-user-info span {
            font-size: 0.9em;
            color: #666;
        }

    </style>
</head>
<body>
<div class="top-bar">
    <div class="user-dropdown" onclick="toggleDropdown()">
        <img src="imagenes/logo escuela.jpg" alt="Avatar">
        
        <div id="userDropdownContent" class="dropdown-content">
            <div class="dropdown-user-info">
                <strong>Hola, <?= $nombre_usuario_display ?></strong>
                <span>(<?= $rol_usuario ?>)</span>
            </div>
            
            <a href="logout.php" class="dropdown-logout-link">🚪 Cerrar Sesión</a>
        </div>
    </div>
</div>


<nav class="nav-menu">
    <a href="?vista=mis_cursos" class="<?= ($vista == 'mis_cursos' || $vista == 'buscar_alumno') ? 'active' : '' ?>">📚 Mis Cursos y Búsqueda</a>
    <a href="?vista=gestionar" class="<?= ($vista == 'gestionar') ? 'active' : '' ?>">✍️ Gestionar Clase</a>
    <a href="?vista=asignar_curso" class="<?= ($vista == 'asignar_curso') ? 'active' : '' ?>">➕ Asignarme Cursos/Materias</a> 
</nav>
<div class="content">
    <?php if (isset($mensaje_exito)): ?><div class="mensaje-exito"><?= $mensaje_exito ?></div><?php endif; ?>
    <?php if (isset($mensaje_error)): ?><div class="mensaje-error"><?= $mensaje_error ?></div><?php endif; ?>

    <?php // ==================================== VISTA ASIGNAR CURSO (REDISEÑADA) ==================================== ?>
    <?php if ($vista == 'asignar_curso'): ?>
        <h1>Asignarme Cursos y Materias ✨</h1>
        <div class="form-container">
            <p style="font-weight: 500; color: #666; margin-bottom: 25px;">📌 Selecciona el **curso** del que deseas hacerte cargo y la **materia** que dictas. ¡Necesitas ambos permisos para gestionar la clase!</p>
            <form method="POST" action="?vista=asignar_curso" class="form-asignar">
                <div class="form-group">
                    <label for="curso_id_asignar">📚 Seleccionar **Curso** (obligatorio):</label>
                    <select id="curso_id_asignar" name="curso_id_asignar" required>
                        <option value="">-- Elija un curso --</option>
                        <?php 
                        $cursos_libres = $conn->query("SELECT idCurso, Curso, Ciclo FROM curso ORDER BY Ciclo DESC, Curso"); 
                        while ($c = $cursos_libres->fetch_assoc()) { 
                            echo "<option value='{$c['idCurso']}'>{$c['Curso']} {$c['Ciclo']}</option>"; 
                        } 
                        ?>
                    </select>
                </div>
                <div class="form-group">
                    <label for="materia_id_asignar">📖 Seleccionar **Materia** (obligatorio):</label>
                    <select id="materia_id_asignar" name="materia_id_asignar" required>
                        <option value="">-- Elija una materia --</option>
                        <?php 
                        $materias_libres = $conn->query("SELECT idMateria, Materia FROM materia ORDER BY Materia"); 
                        while ($m = $materias_libres->fetch_assoc()) { 
                            echo "<option value='{$m['idMateria']}'>{$m['Materia']}</option>"; 
                        } 
                        ?>
                    </select>
                </div>
                <div class="acciones-btn">
                    <button type="submit" name="asignar" class="btn btn-asignar">🔗 Asignarme Curso y Materia</button>
                </div>
            </form>
        </div>
        
    <?php // ==================================== VISTA MIS CURSOS (Resumen y Búsqueda) ==================================== ?>
    <?php elseif ($vista == 'mis_cursos'): ?>
        <h1>Mis Cursos Asignados 📚</h1>
        <?php
        $query_cursos_asignados = "SELECT c.idCurso, c.Curso, c.Ciclo FROM curso c JOIN curso_has_empleado che ON c.idCurso = che.Curso_idCurso WHERE che.Empleado_DNI = ? ORDER BY c.Curso";
        $stmt_cursos_asignados = $conn->prepare($query_cursos_asignados);
        $stmt_cursos_asignados->bind_param("s", $usuario_dni);
        $stmt_cursos_asignados->execute();
        $cursos_asignados = $stmt_cursos_asignados->get_result();

        if ($cursos_asignados->num_rows > 0) {
            echo "<table class='curso-list-table'>
                    <thead><tr><th>CURSO</th><th>CICLO</th><th>ACCIÓN</th></tr></thead>
                    <tbody>";
            while ($curso = $cursos_asignados->fetch_assoc()) {
                echo "<tr>
                        <td><strong>" . htmlspecialchars($curso['Curso']) . "</strong></td>
                        <td>" . htmlspecialchars($curso['Ciclo']) . "</td>
                        <td><a href='?vista=buscar_alumno&curso_id_busqueda={$curso['idCurso']}' class='btn btn-buscar'>🔍 Buscar Alumno en " . htmlspecialchars($curso['Curso']) . "</a></td>
                    </tr>";
            }
            echo "</tbody></table>";
        } else {
            echo "<div class='alerta-asignacion'>
                    <strong>¡Atención!</strong> No tienes cursos asignados.
                    <p>Para comenzar a trabajar, ve a la sección <a href='?vista=asignar_curso'>➕ Asignarme Cursos/Materias</a>.</p>
                  </div>";
        }
        $stmt_cursos_asignados->close();
        ?>
        
    <?php // ==================================== VISTA BUSCAR ALUMNO (IGUALACIÓN DE TAMAÑO) ==================================== ?>
    <?php elseif ($vista == 'buscar_alumno' && $curso_seleccionado_busqueda): ?>
        <h1>Buscar Alumno por DNI/Nombre 🔍</h1>
        <?php
        $stmt_curso_nombre = $conn->prepare("SELECT Curso, Ciclo FROM curso WHERE idCurso = ?");
        $stmt_curso_nombre->bind_param("i", $curso_seleccionado_busqueda);
        $stmt_curso_nombre->execute();
        $curso_info = $stmt_curso_nombre->get_result()->fetch_assoc();
        $curso_nombre_display = htmlspecialchars($curso_info['Curso'] . ' ' . $curso_info['Ciclo']);
        $stmt_curso_nombre->close();
        ?>
        <h2>Búsqueda en Curso: **<?= $curso_nombre_display ?>**</h2>
        
        <div class="filtros form-container">
            <form method="GET" action="profesor.php" style="display: flex; align-items: flex-end; gap: 15px;">
                <input type="hidden" name="vista" value="buscar_alumno">
                <input type="hidden" name="curso_id_busqueda" value="<?= $curso_seleccionado_busqueda ?>">
                <div class="form-group" style="margin-bottom: 0;">
                    <label for="dni_buscado">DNI o Nombre/Apellido:</label>
                    <input type="text" id="dni_buscado" name="dni_buscado" value="<?= htmlspecialchars($dni_buscado) ?>" placeholder="Ingrese DNI o Nombre/Apellido" required style="width: 250px;">
                </div>
                <button type="submit" class="btn btn-buscar">🔍 Buscar</button>
                <a href="?vista=mis_cursos" class="btn btn-volver">⬅️ Volver a Cursos</a>
            </form>
        </div>

        <?php if (!empty($dni_buscado)): ?>
            <?php
            $sql_busqueda = "
                SELECT a.DNI, a.Nombre, a.Apellido, a.Correo 
                FROM alumno a
                JOIN alumno_has_curso ahc ON a.DNI = ahc.Alumno_DNI
                WHERE ahc.Curso_idCurso = ? 
                AND (a.DNI LIKE ? OR CONCAT(a.Nombre, ' ', a.Apellido) LIKE ? OR CONCAT(a.Apellido, ' ', a.Nombre) LIKE ?)
                LIMIT 1
            ";
            $search_term = "%" . $dni_buscado . "%";
            $stmt_busqueda = $conn->prepare($sql_busqueda);
            $stmt_busqueda->bind_param("isss", $curso_seleccionado_busqueda, $search_term, $search_term, $search_term);
            $stmt_busqueda->execute();
            $alumno_encontrado = $stmt_busqueda->get_result()->fetch_assoc();
            $stmt_busqueda->close();

            if ($alumno_encontrado):
            ?>
                <div class="detalle-alumno form-container">
                    <h3>👤 Información del Alumno</h3>
                    <div class="info-box" style="line-height: 1.8;">
                        <p><strong>DNI:</strong> <?= htmlspecialchars($alumno_encontrado['DNI']) ?></p>
                        <p><strong>Nombre Completo:</strong> <?= htmlspecialchars($alumno_encontrado['Apellido']) . ', ' . htmlspecialchars($alumno_encontrado['Nombre']) ?></p>
                        <p><strong>Correo Electrónico:</strong> <?= htmlspecialchars($alumno_encontrado['Correo'] ?? 'N/A') ?></p>
                    </div>

                    <h4>📊 Detalle de Notas y Asistencia (Por Materia)</h4>
                    <?php
                    $sql_detalle = "
                        SELECT 
                            m.Materia, 
                            mha.notas,
                            SUM(CASE WHEN asi.Estado = 'Presente' THEN 1 ELSE 0 END) AS Presentes, 
                            SUM(CASE WHEN asi.Estado = 'Ausente' THEN 1 ELSE 0 END) AS Ausentes,
                            SUM(CASE WHEN asi.Estado = 'Justificado' THEN 1 ELSE 0 END) AS Justificados,
                            SUM(CASE WHEN asi.Estado IN ('Presente', 'Ausente') THEN 1 ELSE 0 END) AS TotalClasesNoJustificadas,
                            CAST(
                                (
                                    SUM(CASE WHEN asi.Estado = 'Ausente' THEN 1 ELSE 0 END) / 
                                    NULLIF(SUM(CASE WHEN asi.Estado IN ('Presente', 'Ausente') THEN 1 ELSE 0 END), 0) * 100
                                ) AS DECIMAL(5,2)
                            ) AS PorcentajeFaltas
                        FROM alumno a
                        JOIN alumno_has_curso ahc ON a.DNI = ahc.Alumno_DNI 
                        LEFT JOIN materia_has_alumno mha ON a.DNI = mha.Alumno_DNI 
                        LEFT JOIN materia m ON mha.Materia_idMateria = m.idMateria
                        LEFT JOIN asistencia asi ON a.DNI = asi.Alumno_DNI AND asi.Curso_idCurso = ahc.Curso_idCurso AND asi.Materia_idMateria = m.idMateria
                        WHERE a.DNI = ? AND ahc.Curso_idCurso = ?
                        GROUP BY m.idMateria
                        ORDER BY m.Materia
                    ";
                    $stmt_detalle = $conn->prepare($sql_detalle);
                    $stmt_detalle->bind_param("si", $alumno_encontrado['DNI'], $curso_seleccionado_busqueda);
                    $stmt_detalle->execute();
                    $detalle_result = $stmt_detalle->get_result();

                    if ($detalle_result->num_rows > 0):
                    ?>
                        <table class="busqueda-detalle-table">
                            <thead><tr><th>Materia</th><th>Nota</th><th>Presentes</th><th>Ausentes</th><th>Justificados</th><th>% Faltas</th></tr></thead>
                            <tbody>
                            <?php while ($det = $detalle_result->fetch_assoc()): ?>
                                <tr>
                                    <td><?= htmlspecialchars($det['Materia'] ?? 'N/A') ?></td>
                                    <td><strong><?= htmlspecialchars($det['notas'] ?? 'Sin nota') ?></strong></td>
                                    <td><?= htmlspecialchars($det['Presentes'] ?? 0) ?></td>
                                    <td><span style="color: <?= ($det['Ausentes'] > 0) ? 'red' : 'inherit' ?>; font-weight: bold;"><?= htmlspecialchars($det['Ausentes'] ?? 0) ?></span></td>
                                    <td><?= htmlspecialchars($det['Justificados'] ?? 0) ?></td>
                                    <td><strong><?= is_numeric($det['PorcentajeFaltas']) ? htmlspecialchars($det['PorcentajeFaltas']) . '%' : '0.00%' ?></strong></td>
                                </tr>
                            <?php endwhile; ?>
                            </tbody>
                        </table>
                    <?php else: ?>
                        <p>No se encontraron datos de notas o asistencia para este alumno en el curso.</p>
                    <?php endif; $stmt_detalle->close(); ?>

                </div>
            <?php else: ?>
                <p style='text-align:center; font-weight:bold; color:red;'>❌ Alumno no encontrado o no pertenece al curso: **<?= $curso_nombre_display ?>**</p>
            <?php endif; ?>
        <?php else: ?>
            <p style='text-align:center;'>Ingrese el DNI o el nombre/apellido del alumno que desea buscar en este curso.</p>
        <?php endif; ?>
        

    <?php // ==================================== VISTA GESTIONAR CLASE (Asistencia/Notas) - DECORADA ==================================== ?>
    <?php elseif ($vista == 'gestionar'): ?>
        <h1>Gestión de Clase (Asistencia y Notas) 👩‍🏫</h1>
        <div class="filtros form-container" style="display: flex; flex-wrap: wrap; align-items: flex-end; gap: 15px;">
            <form class="filtros-form" method="GET" style="display: flex; flex-wrap: wrap; align-items: flex-end; gap: 15px; width: 100%;">
                <input type="hidden" name="vista" value="gestionar">
                
                <div class="form-group" style="flex: 1 1 auto; min-width: 180px; margin-bottom: 0;">
                    <label for="curso_id_gestion">Curso:</label>
                    <select name="curso_id_gestion" id="curso_id_gestion" required>
                        <option value="">-- Elija un curso --</option>
                        <?php
                        $query_cursos = "SELECT c.idCurso, c.Curso, c.Ciclo FROM curso c JOIN curso_has_empleado che ON c.idCurso = che.Curso_idCurso WHERE che.Empleado_DNI = ? ORDER BY c.Curso";
                        $stmt_cursos = $conn->prepare($query_cursos);
                        $stmt_cursos->bind_param("s", $usuario_dni);
                        $stmt_cursos->execute();
                        $cursos = $stmt_cursos->get_result();
                        while ($c = $cursos->fetch_assoc()) {
                            $selected = ($c['idCurso'] == $curso_seleccionado_gestion) ? 'selected' : '';
                            echo "<option value='{$c['idCurso']}' $selected>{$c['Curso']} {$c['Ciclo']}</option>"; 
                        }
                        $stmt_cursos->close();
                        ?>
                    </select>
                </div>

                <div class="form-group" style="flex: 1 1 auto; min-width: 180px; margin-bottom: 0;">
                    <label for="materia_id">Materia:</label>
                    <select name="materia_id" id="materia_id" required>
                        <option value="">-- Elija una materia --</option>
                        <?php
                        $query_materias = "SELECT m.idMateria, m.Materia FROM materia m JOIN materia_has_empleado mhe ON m.idMateria = mhe.Materia_idMateria WHERE mhe.Empleado_DNI = ? ORDER BY m.Materia";
                        $stmt_materias = $conn->prepare($query_materias);
                        $stmt_materias->bind_param("s", $usuario_dni);
                        $stmt_materias->execute();
                        $materias = $stmt_materias->get_result();
                        while ($m = $materias->fetch_assoc()) {
                            $selected = ($m['idMateria'] == $materia_seleccionada) ? 'selected' : '';
                            echo "<option value='{$m['idMateria']}' $selected>{$m['Materia']}</option>";
                        }
                        $stmt_materias->close();
                        ?>
                    </select>
                </div>

                <div class="form-group" style="flex: 1 1 auto; min-width: 180px; margin-bottom: 0;">
                    <label for="grupo_id_gestion">Grupo:</label>
                    <select name="grupo_id_gestion" id="grupo_id_gestion" required>
                        <option value="">-- Elija un grupo --</option>
                        <?php
                        // Cargar todos los grupos de rotación disponibles
                        $query_grupos = "SELECT idGrupos_Rotación, Nombre_Grupo FROM grupos_rotación ORDER BY Nombre_Grupo";
                        $grupos = $conn->query($query_grupos);
                        while ($g = $grupos->fetch_assoc()) {
                            $selected = ($g['idGrupos_Rotación'] == $grupo_seleccionado_gestion) ? 'selected' : '';
                            echo "<option value='{$g['idGrupos_Rotación']}' $selected>" . htmlspecialchars($g['Nombre_Grupo']) . "</option>";
                        }
                        ?>
                    </select>
                </div>

                <button type="submit" class="btn btn-cargar" style="flex-grow: 0;">Cargar Gestión</button>
            </form>
        </div>

        <?php if ($curso_seleccionado_gestion && $materia_seleccionada && $grupo_seleccionado_gestion): 
            if (!verificarPermisosProfesor($conn, $usuario_dni, $curso_seleccionado_gestion, $materia_seleccionada)) {
                echo "<div class='mensaje-error'>❌ No tienes asignados el curso y la materia seleccionados conjuntamente. Por favor, asigna ambos desde el menú.</div>";
            } else {
                // Obtener nombres para el encabezado
                $stmt_nombres = $conn->prepare("SELECT c.Curso, c.Ciclo, m.Materia, g.Nombre_Grupo FROM curso c, materia m, grupos_rotación g WHERE c.idCurso = ? AND m.idMateria = ? AND g.idGrupos_Rotación = ?");
                $stmt_nombres->bind_param("iii", $curso_seleccionado_gestion, $materia_seleccionada, $grupo_seleccionado_gestion);
                $stmt_nombres->execute();
                $nombres = $stmt_nombres->get_result()->fetch_assoc();
                $stmt_nombres->close();

                echo "<h2>Clase: <strong>" . htmlspecialchars($nombres['Curso'] . ' ' . $nombres['Ciclo'] . ' - ' . $nombres['Materia'] . ' (' . $nombres['Nombre_Grupo']) . ")</strong></h2>";
                
                // Pestañas
                $base_url_tab = "?vista=gestionar&curso_id_gestion={$curso_seleccionado_gestion}&materia_id={$materia_seleccionada}&grupo_id_gestion={$grupo_seleccionado_gestion}&tab=";
                echo "<div class='tabs'>
                        <a href='{$base_url_tab}asistencia' class='tab-btn " . ($vista_gestion == 'asistencia' ? 'active' : '') . "'>🗓️ Tomar Asistencia (Hoy)</a>
                        <a href='{$base_url_tab}notas' class='tab-btn " . ($vista_gestion == 'notas' ? 'active' : '') . "'>📝 Cargar Notas</a>
                    </div>";

                echo "<div class='tab-content'>";

                // Lógica de carga de alumnos - AHORA FILTRADA POR GRUPO
                $query_alumnos = "
                    SELECT 
                        a.DNI, 
                        CONCAT(a.Apellido, ', ', a.Nombre) AS Alumno,
                        IFNULL(mha.notas, '') AS nota_actual,
                        (SELECT Estado FROM asistencia asi WHERE asi.Alumno_DNI = a.DNI AND asi.Curso_idCurso = ? AND asi.Materia_idMateria = ? AND asi.Dia = CURDATE() LIMIT 1) as estado_hoy
                    FROM alumno a
                    JOIN alumno_has_curso ahc ON a.DNI = ahc.Alumno_DNI 
                    JOIN alumno_has_grupos_rotación ahgr ON a.DNI = ahgr.Alumno_DNI
                    LEFT JOIN materia_has_alumno mha ON a.DNI = mha.Alumno_DNI AND mha.Materia_idMateria = ?
                    WHERE ahc.Curso_idCurso = ? AND ahgr.Grupos_Rotación_idGrupos_Rotación = ?
                    ORDER BY a.Apellido, a.Nombre";
                
                $stmt_alumnos = $conn->prepare($query_alumnos);
                $stmt_alumnos->bind_param("iiiii", $curso_seleccionado_gestion, $materia_seleccionada, $materia_seleccionada, $curso_seleccionado_gestion, $grupo_seleccionado_gestion);
                $stmt_alumnos->execute();
                $alumnos_result = $stmt_alumnos->get_result();

                if ($alumnos_result->num_rows > 0) {
                    $alumnos_data = $alumnos_result->fetch_all(MYSQLI_ASSOC);
                    $stmt_alumnos->close();

                    if ($vista_gestion == 'asistencia'): 
                    // ==================================== PESTAÑA ASISTENCIA ==================================== 
                        ?>
                        <form method="POST" action="profesor.php?vista=gestionar&curso_id_gestion=<?= $curso_seleccionado_gestion ?>&materia_id=<?= $materia_seleccionada ?>&grupo_id_gestion=<?= $grupo_seleccionado_gestion ?>&tab=asistencia">
                            <input type="hidden" name="curso_id_gestion" value="<?= $curso_seleccionado_gestion ?>">
                            <input type="hidden" name="materia_id" value="<?= $materia_seleccionada ?>">
                            <p style="font-style: italic; color: #666; margin-bottom: 20px;">🗓️ Tomando asistencia para la fecha: <strong><?= date('d/m/Y') ?></strong></p>
                            <table>
                                <thead><tr><th>Alumno (DNI)</th><th colspan="2">Estado de Asistencia</th></tr></thead>
                                <tbody>
                                <?php foreach ($alumnos_data as $alumno): 
                                    $dni = htmlspecialchars($alumno['DNI']);
                                    $estado_hoy = $alumno['estado_hoy'];
                                ?>
                                    <tr>
                                        <td><strong><?= htmlspecialchars($alumno['Alumno']) ?></strong><br><small>DNI: <?= $dni ?></small></td>
                                        <td class="radio-group">
                                            <label>
                                                <input type="radio" name="asistencia[<?= $dni ?>]" value="Presente" <?= ($estado_hoy === 'Presente' || $estado_hoy === null) ? 'checked' : '' ?>> Presente
                                            </label>
                                        </td>
                                        <td class="radio-group">
                                            <label>
                                                <input type="radio" name="asistencia[<?= $dni ?>]" value="Ausente" <?= ($estado_hoy === 'Ausente') ? 'checked' : '' ?>> Ausente
                                            </label>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                            <button type="submit" name="guardar_asistencias" class="btn btn-guardar">💾 Guardar Asistencias</button>
                        </form>
                    <?php 
                    elseif ($vista_gestion == 'notas'):
                    // ==================================== PESTAÑA NOTAS ====================================
                    ?>
                        <form method="POST" action="profesor.php?vista=gestionar&curso_id_gestion=<?= $curso_seleccionado_gestion ?>&materia_id=<?= $materia_seleccionada ?>&grupo_id_gestion=<?= $grupo_seleccionado_gestion ?>&tab=notas">
                            <input type="hidden" name="curso_id_gestion" value="<?= $curso_seleccionado_gestion ?>">
                            <input type="hidden" name="materia_id" value="<?= $materia_seleccionada ?>">
                            <p style="font-style: italic; color: #666; margin-bottom: 20px;">📝 Cargue o modifique las notas para los alumnos de esta materia.</p>
                            <table>
                                <thead><tr><th>Alumno (DNI)</th><th>Nota (0.00 a 10.00)</th></tr></thead>
                                <tbody>
                                <?php foreach ($alumnos_data as $alumno): 
                                    $dni = htmlspecialchars($alumno['DNI']);
                                ?>
                                    <tr>
                                        <td><strong><?= htmlspecialchars($alumno['Alumno']) ?></strong><br><small>DNI: <?= $dni ?></small></td>
                                        <td>
                                            <input type="text" name="notas[<?= $dni ?>]" value="<?= htmlspecialchars($alumno['nota_actual']) ?>" placeholder="Ej: 7.50" pattern="[0-9]{1,2}([.,][0-9]{1,2})?" title="Nota numérica (ej: 7 o 7.50)" style="width: 100px; text-align: center;">
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                            <button type="submit" name="guardar_notas" class="btn btn-guardar">💾 Guardar Notas</button>
                        </form>
                    <?php 
                    endif; 

                } else {
                    echo "<p style='text-align:center;'>No se encontraron alumnos en el **Curso/Materia/Grupo** seleccionado.</p>";
                }
                echo "</div>"; // Cierre tab-content
            } // Cierre if verificarPermisosProfesor
        ?>
        <?php else: ?>
            <p style='text-align:center;'>Seleccione un **Curso**, una **Materia** y un **Grupo** para comenzar la gestión de clase.</p>
        <?php endif; ?>
    <?php endif; // Cierre principal $vista ?>

</div>

<script>
    // --- SCRIPT PARA EL DROPDOWN DE USUARIO ---
    function toggleDropdown() {
        document.getElementById("userDropdownContent").classList.toggle("show");
    }

    // Cerrar el dropdown si el usuario hace clic fuera de él
    window.onclick = function(event) {
        // Verifica si el clic no es dentro del dropdown o el botón que lo activa
        if (!event.target.matches('.user-dropdown') && !event.target.closest('.user-dropdown')) {
            var dropdowns = document.getElementsByClassName("dropdown-content");
            for (var i = 0; i < dropdowns.length; i++) {
                var openDropdown = dropdowns[i];
                if (openDropdown.classList.contains('show')) {
                    openDropdown.classList.remove('show');
                }
            }
        }
    }
</script>
</body>
</html>