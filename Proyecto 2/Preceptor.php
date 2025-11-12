<?php
session_start();
if (!isset($_SESSION["usuario"])) { header("Location: login.php"); exit(); }
include('conexion.php'); // Asumo que este archivo maneja la conexión a la BD ($conn)

$rol_usuario = isset($_SESSION['rol']) ? $_SESSION['rol'] : '';
// El DNI es VARCHAR(15) en tu BD (taller_colegio.sql) para Empleado y Alumno.
$usuario_dni = isset($_SESSION['usuario']) ? (string)$_SESSION['usuario'] : null;

// --- CONTROL DE ACCESO ---
if ($rol_usuario !== 'Preceptor') { 
    echo "<h1>Acceso Denegado</h1><p>Solo los Preceptores pueden acceder a esta vista.</p>";
    echo "<a href='logout.php'>Cerrar sesión</a>";
    exit(); 
}

// Mensajes de feedback
$mensaje_exito = null;
$mensaje_error = null;

// --- FUNCIÓN PARA OBTENER NOMBRE DEL USUARIO ---
$nombre_usuario_display = 'Preceptor'; // Valor por defecto
if ($usuario_dni && isset($conn)) {
    // Buscamos el nombre del empleado logueado (asumiendo tabla 'empleado')
    $stmt_nombre = $conn->prepare("SELECT Nombre, Apellido FROM empleado WHERE DNI = ?");
    $stmt_nombre->bind_param("s", $usuario_dni);
    @$stmt_nombre->execute();
    $result_nombre = @$stmt_nombre->get_result();
    if ($result_nombre && $row_nombre = $result_nombre->fetch_assoc()) {
        // Concatenamos y sanitizamos el nombre completo para mostrarlo
        $nombre_usuario_display = htmlspecialchars($row_nombre['Nombre'] . ' ' . $row_nombre['Apellido']);
    }
    @$stmt_nombre->close();
}


// --- FUNCIÓN DE VERIFICACIÓN DE PERMISOS ---
// Usa 's' para DNI (VARCHAR) y 'i' para IDs (INT)
function verificarPermisos($conn, $dni, $curso_id, $materia_id = null) {
    if ($dni === null) return false;
    
    // 1. Verificar permiso sobre el curso (DNI como string 's', idCurso como int 'i')
    $stmt_curso = $conn->prepare("SELECT 1 FROM curso_has_empleado WHERE Empleado_DNI = ? AND Curso_idCurso = ?");
    $stmt_curso->bind_param("si", $dni, $curso_id);
    $stmt_curso->execute();
    $tiene_permiso_curso = $stmt_curso->get_result()->num_rows > 0;
    $stmt_curso->close();

    // 2. Si se pide materia (solo para Profesor), verificar también el permiso de materia. (No usado por Preceptor)
    if ($materia_id !== null) {
        // DNI como string 's', idMateria como int 'i'
        $stmt_materia = $conn->prepare("SELECT 1 FROM materia_has_empleado WHERE Empleado_DNI = ? AND Materia_idMateria = ?");
        $stmt_materia->bind_param("si", $dni, $materia_id);
        $stmt_materia->execute();
        $tiene_permiso_materia = $stmt_materia->get_result()->num_rows > 0;
        $stmt_materia->close();
        return $tiene_permiso_curso && $tiene_permiso_materia;
    }

    return $tiene_permiso_curso; // Retorna true si tiene permiso sobre el curso (usado por Preceptor)
}

// --- LÓGICA DE FORMULARIOS ---

// LÓGICA PARA ASIGNAR CURSO
error_reporting(E_ALL);
ini_set('display_errors', 1);

if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['asignar'])) {
    if ($rol_usuario == 'Profesor' || $rol_usuario == 'Preceptor') {
        $curso_id = isset($_POST['curso_id_asignar']) ? (int)$_POST['curso_id_asignar'] : null;
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

        if (count($success_messages) > 0) { $mensaje_exito = implode("<br>", $success_messages); }
        if (count($error_messages) > 0) { $mensaje_error = implode("<br>", $error_messages); }
    } else {
        $mensaje_error = "❌ No tienes permiso para realizar esta acción.";
    }
}

// LÓGICA PARA JUSTIFICAR AUSENCIAS (PRECEPTOR)
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['justificar_ausencias'])) {
    if ($rol_usuario == 'Preceptor') {
        $curso_id = (int)$_POST['curso_id_gestion'];
        $fecha = $_POST['fecha_gestion'];
        $asistencias_a_justificar = isset($_POST['justificar']) ? $_POST['justificar'] : [];

        // Preceptor solo necesita permiso sobre el curso.
        if (verificarPermisos($conn, $usuario_dni, $curso_id)) {
            $count = 0;
            if (!empty($asistencias_a_justificar)) {
                // Preparamos para actualizar solo si el estado actual es 'Ausente' para evitar errores.
                $stmt = $conn->prepare("UPDATE asistencia SET Estado = 'Justificado' WHERE Alumno_DNI = ? AND Curso_idCurso = ? AND Dia = ? AND Estado = 'Ausente'");
                
    foreach ($asistencias_a_justificar as $alumno_dni) {
                                        
    $alumno_dni_str = (string)$alumno_dni; 
    // s, i, s (Alumno_DNI, Curso ID, Dia)
    $stmt->bind_param("sis", $alumno_dni_str, $curso_id, $fecha);
    $stmt->execute();
    $count += $stmt->affected_rows;

    // 🔍 Buscar el idAsistencia correspondiente al alumno, curso y fecha
    $stmt_id = $conn->prepare("SELECT idAsistencia FROM asistencia WHERE Alumno_DNI = ? AND Curso_idCurso = ? AND Dia = ? LIMIT 1");
    $stmt_id->bind_param("sis", $alumno_dni_str, $curso_id, $fecha);
    $stmt_id->execute();
    $result_id = $stmt_id->get_result();
    $idAsistencia = null;
    if ($row_id = $result_id->fetch_assoc()) {
        $idAsistencia = $row_id['idAsistencia'];
    }
    $stmt_id->close();

    // --- Guardar justificativo y archivo adjunto ---
    $nombre_campo_archivo = "archivo_justificativo_" . $alumno_dni;
    $nombre_campo_motivo = "motivo_" . $alumno_dni;

    // Si el preceptor subió un archivo
    if (isset($_FILES[$nombre_campo_archivo]) && $_FILES[$nombre_campo_archivo]['error'] === UPLOAD_ERR_OK) {
        // 1️⃣ Crear carpeta absoluta si no existe
        $carpeta_destino = __DIR__ . "/justificativos/";
        if (!file_exists($carpeta_destino)) {
            mkdir($carpeta_destino, 0777, true);
        }

        // 2️⃣ Definir nombre del archivo y moverlo
        $nombre_original = $_FILES[$nombre_campo_archivo]['name'];
        $extension = pathinfo($nombre_original, PATHINFO_EXTENSION);
        $nombre_unico = "just_" . $alumno_dni . "_" . time() . "." . $extension;
        $ruta_servidor = $carpeta_destino . $nombre_unico;       // ruta absoluta
        $ruta_base_datos = "justificativos/" . $nombre_unico;    // ruta corta para mostrar

        if (move_uploaded_file($_FILES[$nombre_campo_archivo]['tmp_name'], $ruta_servidor)) {
            //echo "<p style='color:green;'>✅ Justificativo guardado: {$ruta_base_datos}</p>";

            // 3️⃣ Tomar motivo y guardar en BD
            $motivo = isset($_POST[$nombre_campo_motivo]) ? $_POST[$nombre_campo_motivo] : null;

            $stmt_insert = $conn->prepare("
                INSERT INTO justificativo (Asistencia_idAsistencia, Alumno_DNI, Fecha_Justificativo, Motivo, Archivo, Subido_Por)
                VALUES (?, ?, ?, ?, ?, ?)
            ");
            $stmt_insert->bind_param("isssss", $idAsistencia, $alumno_dni, $fecha, $motivo, $ruta_base_datos, $usuario_dni);
            $stmt_insert->execute();
            $stmt_insert->close();
        } else {
            echo "<p style='color:red;'>❌ Error al guardar el justificativo para el alumno $alumno_dni</p>";
        }
    }
}

$stmt->close();

            }
            if ($count > 0) {
                $mensaje_exito = "✅ Se justificaron $count ausencias para el curso/fecha seleccionados.";
            } else {
                $mensaje_error = "⚠️ No se justificó ninguna ausencia o no se encontraron ausencias pendientes para el curso/fecha.";
            }
        } else {
            $mensaje_error = "❌ No tienes permiso para gestionar este curso.";
        }
    } else {
        $mensaje_error = "❌ Solo el Preceptor puede realizar esta acción.";
    }
}


// --- LÓGICA CORREGIDA PARA GUARDAR ALUMNO, CURSO, MATERIA Y GRUPO ---
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['guardar_alumno'])) {
    if ($rol_usuario == 'Preceptor') {
        $dni = (string)$_POST['dni']; 
        $nombre = trim($_POST['nombre']);
        $apellido = trim($_POST['apellido']);
        $correo = trim($_POST['correo']);
        $curso_id = (int)$_POST['curso_id'];
        $materia_id = (int)$_POST['materia_id']; // materia elegida manualmente
        $grupo_id = (int)$_POST['grupo_id'];

        if (!empty($dni) && !empty($nombre) && !empty($apellido) && !empty($curso_id) && !empty($grupo_id) && !empty($materia_id)) {
            $conn->begin_transaction();
            try {
                // 1️⃣ Insertar alumno
                // Usamos INSERT IGNORE para alumnos que ya existen
                $stmt_alumno = $conn->prepare("INSERT IGNORE INTO alumno (DNI, Nombre, Apellido, Correo) VALUES (?, ?, ?, ?)");
                $stmt_alumno->bind_param("ssss", $dni, $nombre, $apellido, $correo);
                $stmt_alumno->execute();
                
                // Si la inserción falló (porque el DNI ya existe), no es necesariamente un error fatal.
                $alumno_insertado = $stmt_alumno->affected_rows > 0 || $stmt_alumno->errno == 0;
                
                if (!$alumno_insertado) {
                    // Si el DNI ya existe, verificamos que el nombre/apellido coincida antes de continuar.
                    $stmt_check = $conn->prepare("SELECT Nombre, Apellido FROM alumno WHERE DNI = ?");
                    $stmt_check->bind_param("s", $dni);
                    $stmt_check->execute();
                    $row_check = $stmt_check->get_result()->fetch_assoc();
                    $stmt_check->close();
                    
                    if (strtolower($row_check['Nombre']) !== strtolower($nombre) || strtolower($row_check['Apellido']) !== strtolower($apellido)) {
                        throw new mysqli_sql_exception("El DNI {$dni} ya existe con otro nombre.", 1062);
                    }
                }
                
                // 2️⃣ Asignar curso (usamos IGNORE para evitar duplicados si el alumno ya está en el curso)
                $stmt_curso = $conn->prepare("INSERT IGNORE INTO alumno_has_curso (Alumno_DNI, Curso_idCurso) VALUES (?, ?)");
                $stmt_curso->bind_param("si", $dni, $curso_id);
                $stmt_curso->execute();

                // 3️⃣ Asignar grupo (usamos ON DUPLICATE KEY UPDATE para actualizar si ya está asignado a otro grupo)
                $stmt_grupo = $conn->prepare("INSERT INTO alumno_has_grupos_rotación (Alumno_DNI, Grupos_Rotación_idGrupos_Rotación) VALUES (?, ?) ON DUPLICATE KEY UPDATE Grupos_Rotación_idGrupos_Rotación = VALUES(Grupos_Rotación_idGrupos_Rotación)");
                $stmt_grupo->bind_param("si", $dni, $grupo_id);
                $stmt_grupo->execute();

                // 4️⃣ Asignar materia seleccionada manualmente (usamos IGNORE para evitar duplicados)
                $stmt_materia = $conn->prepare("INSERT IGNORE INTO materia_has_alumno (Materia_idMateria, Alumno_DNI, notas) VALUES (?, ?, NULL)");
                $stmt_materia->bind_param("is", $materia_id, $dni);
                $stmt_materia->execute();

                // 5️⃣ Asignar automáticamente TODAS las materias del grupo (además de la elegida)
                $sql_materias = "SELECT Materia_idMateria FROM grupos_rotación_has_materia WHERE Grupos_Rotación_idGrupos_Rotación = ?";
                $stmt_materias = $conn->prepare($sql_materias);
                $stmt_materias->bind_param("i", $grupo_id);
                $stmt_materias->execute();
                $result_materias = $stmt_materias->get_result();

                $stmt_auto = $conn->prepare("INSERT IGNORE INTO materia_has_alumno (Materia_idMateria, Alumno_DNI, notas) VALUES (?, ?, NULL)");
                while ($row = $result_materias->fetch_assoc()) {
                    $materia_auto = $row['Materia_idMateria'];
                    // Insertar todas las materias del grupo, incluyendo la seleccionada (el INSERT IGNORE se encarga del duplicado)
                    $stmt_auto->bind_param("is", $materia_auto, $dni);
                    $stmt_auto->execute();
                }
                $stmt_auto->close();
                $stmt_materias->close();

                $conn->commit();
                $mensaje_exito = "✅ Alumno agregado/actualizado correctamente, con curso, grupo y materias asignadas.";
            } catch (mysqli_sql_exception $e) {
                $conn->rollback();
                if ($e->getCode() == 1062) {
                    $mensaje_error = "⚠️ El DNI {$dni} ya existe con otro nombre o ya está registrado en alguna relación clave. Revise los datos.";
                } else {
                    $mensaje_error = "❌ Error al registrar/actualizar alumno: " . $e->getMessage();
                }
            }

            if (isset($stmt_alumno)) $stmt_alumno->close();
            if (isset($stmt_curso)) $stmt_curso->close();
            if (isset($stmt_grupo)) $stmt_grupo->close();
            if (isset($stmt_materia)) $stmt_materia->close();

        } else {
            $mensaje_error = "⚠️ Faltan campos obligatorios (DNI, Nombre, Apellido, Curso, Grupo o Materia).";
        }
    } else {
        $mensaje_error = "❌ Solo el Preceptor puede agregar alumnos.";
    }
}


// --- VARIABLES DE CONTROL Y LÓGICA DE VISTA ---
$vista = isset($_GET['vista']) ? $_GET['vista'] : 'mis_cursos'; 
$curso_seleccionado_gestion = isset($_REQUEST['curso_id_gestion']) ? (int)$_REQUEST['curso_id_gestion'] : null;
$curso_seleccionado_busqueda = isset($_REQUEST['curso_id_busqueda']) ? (int)$_REQUEST['curso_id_busqueda'] : null; 
$dni_buscado = isset($_REQUEST['dni_buscado']) ? trim((string)$_REQUEST['dni_buscado']) : ''; 

// VARIABLES PARA VISTA DE GESTIÓN DEL PRECEPTOR
$fecha_seleccionada = isset($_REQUEST['fecha_gestion']) ? $_REQUEST['fecha_gestion'] : date('Y-m-d');
$vista_gestion = isset($_REQUEST['tab']) ? $_REQUEST['tab'] : null; 

// Definir pestaña por defecto para Preceptor
if ($rol_usuario == 'Preceptor' && $vista_gestion === null) {
    $vista_gestion = 'justificar';
}

// Restricción de roles
if ($vista == 'resumen') { $vista = 'mis_cursos'; }

?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Registros del Sistema - Preceptor</title>
    <link rel="shortcut icon" href="imagenes/logo escuela.jpg">
    <style>
        /* ================================================= */
        /* === ESTILOS BASE Y MEJORAS DE DISEÑO/ANIMACIÓN === */
        /* ================================================= */

        /* Colores y Tipografía (Ajustado para Preceptor - Mantiene el azul como principal) */
        :root {
            --color-principal: #007bff; /* Azul primario */
            --color-secundario: #0056b3; /* Azul más oscuro */
            --color-exito: #28a745;
            --color-error: #dc3545;
            --color-fondo: #f8f9fa; /* Fondo general */
            --color-claro: #ffffff;
            --sombra-suave: 0 4px 12px rgba(0, 0, 0, 0.08);
            --transicion-rapida: all 0.2s ease-in-out;
        }

        body { 
            background-color: var(--color-fondo); 
            color: #333; 
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            margin: 0;
            padding: 0;
        }

        /* Barra Superior (Top Bar) */
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

        /* --- ESTILOS DEL DROPDOWN (MENÚ DEL AVATAR) --- */
        .user-dropdown {
            position: relative; /* Contenedor para posicionar el menú */
            display: inline-block;
            cursor: pointer;
        }

        .user-dropdown img { 
            border-radius: 50%; 
            border: 2px solid var(--color-claro);
            width: 40px; 
            height: 40px; 
            object-fit: cover;
            transition: transform 0.2s;
        }
        
        .user-dropdown:hover img {
            transform: scale(1.05); /* Pequeño efecto hover en el avatar */
        }

        /* Contenido del menú desplegable */
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

        .dropdown-content.show {
            display: block; /* Muestra el menú */
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
        /* --- FIN ESTILOS DROPDOWN --- */


        /* Menú de Navegación */
        .nav-menu { 
            background-color: var(--color-secundario); 
            padding: 0 40px; 
            display: flex; 
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
        }
        .nav-menu a { 
            color: var(--color-claro); 
            text-decoration: none; 
            padding: 15px 20px; 
            transition: var(--transicion-rapida);
            position: relative;
        }
        .nav-menu a.active { 
            background-color: var(--color-principal);
            font-weight: bold;
            border-bottom: 3px solid #ffeb3b; /* Efecto de pestaña activa */
        }
        .nav-menu a:not(.active):hover { 
            background-color: #004499; 
        }

        /* Contenido Principal */
        .content { 
            padding: 20px 40px; 
            background-color: var(--color-claro); 
            margin: 20px auto; 
            max-width: 1400px; 
            border-radius: 10px;
            box-shadow: var(--sombra-suave); 
        }

        h1, h2, h3 { 
            color: var(--color-principal); 
            border-bottom: 1px solid #e0e0e0; 
            padding-bottom: 10px; 
            margin-top: 25px; 
            font-weight: 600;
        }
        
        /* Tablas */
        table { 
            width: 100%; 
            border-collapse: separate; 
            border-spacing: 0;
            margin-top: 20px; 
            border-radius: 8px;
            overflow: hidden; 
            box-shadow: 0 1px 3px rgba(0,0,0,0.05);
        }
        th, td { 
            border: 1px solid #e0e0e0; 
            padding: 12px; 
            text-align: left; 
            vertical-align: middle; 
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
        tbody tr:hover {
            background-color: #e3f2fd;
            transition: background-color 0.3s;
        }
        
        /* Botones */
        .btn, .btn-buscar, .btn-cargar, .btn-guardar, .btn-agregar, .btn-asignar, .btn-volver { 
            color: var(--color-claro); 
            border: none; 
            padding: 10px 20px; 
            cursor: pointer; 
            border-radius: 5px; 
            text-decoration: none; 
            display: inline-block; 
            margin-right: 5px; 
            transition: var(--transicion-rapida);
            font-weight: 600;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
            background-color: var(--color-principal); /* Default Blue */
        }
        /* Colores específicos de Botones */
        .btn-guardar, .btn-agregar { background-color: var(--color-exito); }
        .btn-volver { background-color: #6c757d; } /* Gris para Volver */
        
        /* Animación en Botones */
        .btn:hover, .btn-buscar:hover, .btn-cargar:hover, .btn-guardar:hover, .btn-agregar:hover, .btn-asignar:hover, .btn-volver:hover { 
            transform: translateY(-1px);
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.2);
            opacity: 0.9;
        }
        .btn-guardar:hover, .btn-agregar:hover { background-color: #1e7e34; }
        .btn-volver:hover { background-color: #5a6268; }

        /* Contenedores de Formulario y Filtros (General) */
        .form-container, .filtros { 
            margin-bottom: 20px; 
            padding: 25px; 
            border: 1px solid #e0e0e0; 
            border-radius: 10px; 
            background-color: var(--color-claro); 
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
        }
        .form-asignar {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 20px;
        }
        .form-asignar .acciones-btn { grid-column: 1 / -1; text-align: right;}

        /* Formularios (Inputs y Selects) */
        select, input[type="text"], input[type="date"], input[type="email"] {
            width: 100%; 
            padding: 10px; 
            border: 1px solid #c0c0c0; 
            border-radius: 5px; 
            box-sizing: border-box;
            font-size: 1em;
            transition: border-color 0.3s, box-shadow 0.3s;
        }
        select:focus, input[type="text"]:focus, input[type="date"]:focus, input[type="email"]:focus {
            border-color: var(--color-principal);
            box-shadow: 0 0 0 3px rgba(0, 123, 255, 0.2);
            outline: none;
        }
        .filtros-form { 
            display: flex; 
            gap: 15px; 
            align-items: flex-end; 
            width: 100%;
        }
        /* Para que los selects en la gestión tomen ancho */
        .filtros-form > * {
            flex-grow: 1;
        }
        .filtros-form button, .filtros-form a.btn {
            flex-grow: 0;
            white-space: nowrap;
        }


        /* Mensajes de Feedback */
        .mensaje-exito, .mensaje-error {
            padding: 15px;
            border-radius: 8px;
            font-weight: bold;
            margin-bottom: 20px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.05);
        }
        .mensaje-exito { color: #155724; background-color: #d4edda; border: 1px solid #c3e6cb; }
        .mensaje-error { color: #721c24; background-color: #f8d7da; border: 1px solid #f5c6cb; }

        /* Detalles de Alumno (Búsqueda) */
        .detalle-alumno {
            border-left: 5px solid var(--color-principal);
            padding-left: 20px;
            margin-top: 20px;
            background-color: #f0f8ff; 
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.05);
        }
        .info-box {
            background-color: var(--color-claro);
            padding: 15px;
            border-radius: 5px;
            margin-bottom: 15px;
            border: 1px solid #b8daff;
        }
        .info-box p {
            margin: 5px 0;
            padding-left: 10px;
            border-left: 2px dotted #ccc;
        }
        
        /* Gestión Nav (Justificar) */
        .nav-gestion {
            display: flex;
            border-bottom: 2px solid #e0e0e0;
            background-color: transparent !important; /* Desactiva el fondo azul */
            padding: 0 !important;
            margin-bottom: 20px;
        }
        .nav-gestion a {
            padding: 12px 20px !important; 
            color: #555 !important;
            font-weight: 600;
            background-color: #f0f2f5 !important;
            border: 1px solid #e0e0e0;
            border-bottom: none;
            border-radius: 8px 8px 0 0;
            margin-right: 5px;
            text-decoration: none;
            transition: all 0.3s ease;
        }
        .nav-gestion a.active {
            background-color: white !important;
            color: var(--color-principal) !important;
            border-color: #e0e0e0;
            border-bottom-color: white !important;
            transform: translateY(1px);
            box-shadow: 0 -2px 5px rgba(0,0,0,0.05);
        }
        
        /* Contenido de la gestión */
        .gestion-content {
             padding: 25px; 
             border: 1px solid #e0e0e0; 
             border-top: none; 
             background-color: white; 
             border-radius: 0 0 10px 10px; 
             box-shadow: 0 4px 12px rgba(0,0,0,0.05); 
             margin-bottom: 20px;
        }
        
        .nav-menu {
             padding: 0 40px; 
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
    <a href="?vista=gestionar" class="<?= ($vista == 'gestionar') ? 'active' : '' ?>">✍️ Gestionar Clase (Justificar)</a>
    <a href="?vista=asignar_curso" class="<?= ($vista == 'asignar_curso') ? 'active' : '' ?>">➕ Asignarse Curso</a> 
    <a href="?vista=agregar_alumno" class="<?= ($vista == 'agregar_alumno') ? 'active' : '' ?>">➕ Agregar Alumno</a>
</nav>

<div class="content">
    <?php if (isset($mensaje_exito)): ?><div class="mensaje-exito"><?= $mensaje_exito ?></div><?php endif; ?>
    <?php if (isset($mensaje_error)): ?><div class="mensaje-error"><?= $mensaje_error ?></div><?php endif; ?>

    <?php if ($vista == 'asignar_curso'): ?>
        <h1>Asignarse Cursos</h1>
        <div class="form-container">
            <p>Selecciona el **curso** del que deseas hacerte cargo.</p>
            <form method="POST" action="?vista=asignar_curso" class="form-asignar">
                <div class="form-group" style="grid-column: 1 / -1;">
                    <label for="curso_id_asignar">Seleccionar **Curso** (obligatorio):</label>
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
                
                <div class="acciones-btn">
                    <button type="submit" name="asignar" class="btn btn-asignar">🔗 Asignarme Curso</button>
                </div>
            </form>
        </div>
        
    <?php elseif ($vista == 'mis_cursos'): ?>
        <h1>Cursos Asignados</h1>
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
            echo "<p style='text-align:center; font-weight:bold;'>No tienes cursos asignados. Usa la opción 'Asignarse Curso' para comenzar.</p>";
        }
        $stmt_cursos_asignados->close();
        ?>
        
    <?php elseif ($vista == 'buscar_alumno' && $curso_seleccionado_busqueda): ?>
        <h1>Buscar Alumno en Curso</h1>
        <?php
        if (verificarPermisos($conn, $usuario_dni, $curso_seleccionado_busqueda)) {
            $stmt_curso_nombre = $conn->prepare("SELECT Curso, Ciclo FROM curso WHERE idCurso = ?");
            $stmt_curso_nombre->bind_param("i", $curso_seleccionado_busqueda);
            $stmt_curso_nombre->execute();
            $curso_info = $stmt_curso_nombre->get_result()->fetch_assoc();
            $curso_nombre_display = htmlspecialchars($curso_info['Curso'] . ' ' . $curso_info['Ciclo']);
            $stmt_curso_nombre->close();
        } else {
            $mensaje_error = "❌ No tienes permiso para gestionar este curso.";
            $curso_nombre_display = "Curso Desconocido";
        }
        ?>
        <h2>Búsqueda en: <?= $curso_nombre_display ?></h2>
        
        <div class="filtros">
            <form method="GET" action="Preceptor.php" class="filtros-form"> 
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

        <?php if (!empty($dni_buscado) && verificarPermisos($conn, $usuario_dni, $curso_seleccionado_busqueda)): ?>
            <?php
            // Aseguramos que solo se muestre UN alumno, ya que DNI es clave.
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
                <div class="detalle-alumno">
                    <h3>👤 Información del Alumno Encontrado</h3>
                    <div class="info-box">
                        <p><strong>DNI:</strong> <?= htmlspecialchars($alumno_encontrado['DNI']) ?></p>
                        <p><strong>Nombre Completo:</strong> <?= htmlspecialchars($alumno_encontrado['Apellido']) . ', ' . htmlspecialchars($alumno_encontrado['Nombre']) ?></p>
                        <p><strong>Correo Electrónico:</strong> <?= htmlspecialchars($alumno_encontrado['Correo'] ?? 'N/A') ?></p>
                    </div>

                    <h4>📊 Detalle de Notas y Asistencia (General)</h4>
                    <?php
                    // Consulta general del alumno en el curso, incluyendo Grupo de Rotación
                    $sql_detalle = "
                        SELECT 
                            m.Materia, 
                            mha.notas,
                            gr.Nombre_Grupo AS Grupo_Rotacion, 
                            SUM(CASE WHEN asi.Estado = 'Presente' THEN 1 ELSE 0 END) AS Presentes, 
                            SUM(CASE WHEN asi.Estado = 'Ausente' THEN 1 ELSE 0 END) AS Ausentes,
                            SUM(CASE WHEN asi.Estado = 'Justificado' THEN 1 ELSE 0 END) AS Justificados
                        FROM alumno a
                        JOIN alumno_has_curso ahc ON a.DNI = ahc.Alumno_DNI 
                        LEFT JOIN alumno_has_grupos_rotación ahgr ON a.DNI = ahgr.Alumno_DNI 
                        LEFT JOIN grupos_rotación gr ON ahgr.Grupos_Rotación_idGrupos_Rotación = gr.idGrupos_Rotación
                        LEFT JOIN materia_has_alumno mha ON a.DNI = mha.Alumno_DNI 
                        LEFT JOIN materia m ON mha.Materia_idMateria = m.idMateria
                        LEFT JOIN asistencia asi ON a.DNI = asi.Alumno_DNI AND asi.Materia_idMateria = m.idMateria
                        WHERE a.DNI = ? AND ahc.Curso_idCurso = ?
                        GROUP BY m.idMateria, gr.Nombre_Grupo 
                        ORDER BY m.Materia
                    ";
                    $stmt_detalle = $conn->prepare($sql_detalle);
                    $stmt_detalle->bind_param("si", $alumno_encontrado['DNI'], $curso_seleccionado_busqueda);
                    $stmt_detalle->execute();
                    $detalle_result = $stmt_detalle->get_result();

                    if ($detalle_result->num_rows > 0):
                    ?>
                        <table>
                            <thead><tr><th>Materia</th><th>Grupo</th><th>Nota</th><th>Presentes</th><th>Ausentes</th><th>Justificados</th></tr></thead>
                            <tbody>
                            <?php while ($det = $detalle_result->fetch_assoc()): ?>
                                <tr>
                                    <td><?= htmlspecialchars($det['Materia'] ?? 'N/A') ?></td>
                                    <td><strong><?= htmlspecialchars($det['Grupo_Rotacion'] ?? 'N/A') ?></strong></td>
                                    <td><strong><?= htmlspecialchars($det['notas'] ?? 'Sin nota') ?></strong></td>
                                    <td><?= htmlspecialchars($det['Presentes'] ?? 0) ?></td>
                                    <td><?= htmlspecialchars($det['Ausentes'] ?? 0) ?></td>
                                    <td><?= htmlspecialchars($det['Justificados'] ?? 0) ?></td>
                                </tr>
                            <?php endwhile; ?>
                            </tbody>
                        </table>
                    <?php else: ?>
                        <p>No se encontraron datos de notas o asistencia para este alumno en el curso.</p>
                    <?php endif; $stmt_detalle->close(); ?>

                </div>
            <?php else: ?>
                <p style='text-align:center; font-weight:bold; color:red;'>❌ Alumno no encontrado o no pertenece al curso: <?= $curso_nombre_display ?></p>
            <?php endif; ?>
        <?php elseif (!verificarPermisos($conn, $usuario_dni, $curso_seleccionado_busqueda)): ?>
             <p style='text-align:center; font-weight:bold; color:red;'>❌ No tienes permiso para gestionar la búsqueda en este curso.</p>
        <?php else: ?>
            <p style='text-align:center;'>Ingrese el DNI o el nombre/apellido del alumno que desea buscar en este curso.</p>
        <?php endif; ?>
        

    <?php elseif ($vista == 'gestionar'): ?>
        <h1>Gestión de Clase (Justificar Ausencias)</h1>
        <div class="filtros form-container">
            <form class="filtros-form" method="GET">
                <input type="hidden" name="vista" value="gestionar">
                <div class="form-group" style="margin-bottom: 0;">
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

                <div class="form-group" style="margin-bottom: 0;">
                    <label for="fecha_gestion">Fecha:</label>
                    <input type="date" id="fecha_gestion" name="fecha_gestion" value="<?= htmlspecialchars($fecha_seleccionada) ?>" required>
                </div>
                <button type="submit" class="btn btn-cargar">Cargar</button>
            </form>
        </div>

        <?php if ($curso_seleccionado_gestion && verificarPermisos($conn, $usuario_dni, $curso_seleccionado_gestion)): ?>
            
            <div class="nav-gestion">
                <a href="?vista=gestionar&curso_id_gestion=<?= $curso_seleccionado_gestion ?>&fecha_gestion=<?= $fecha_seleccionada ?>&tab=justificar" 
                   class="<?= ($vista_gestion == 'justificar') ? 'active' : '' ?>">✅ Justificar Ausencias</a>
                   
            </div>
            
            <div class="gestion-content">
            <?php
            // Lógica de consulta de Alumnos
            $sql_alumnos = "SELECT a.DNI, a.Nombre, a.Apellido FROM alumno a JOIN alumno_has_curso ahc ON a.DNI = ahc.Alumno_DNI WHERE ahc.Curso_idCurso = ? ORDER BY a.Apellido, a.Nombre";
            $params = [$curso_seleccionado_gestion];
            $types = "i";
            
            $stmt_alumnos = $conn->prepare($sql_alumnos);
            $stmt_alumnos->bind_param($types, ...$params);
            $stmt_alumnos->execute();
            $alumnos = $stmt_alumnos->get_result();
            ?>

            <?php if ($vista_gestion == 'justificar'): ?>
                <form method="POST" enctype="multipart/form-data" action="?vista=gestionar&tab=justificar">

                    <input type="hidden" name="curso_id_gestion" value="<?= $curso_seleccionado_gestion ?>">
                    <input type="hidden" name="fecha_gestion" value="<?= htmlspecialchars($fecha_seleccionada) ?>">
                    <h3>Justificar Ausencias para el día: <?= htmlspecialchars(date('d/m/Y', strtotime($fecha_seleccionada))) ?></h3>
                    <table>
                        <thead>
                            <tr>
                                <th>Alumno</th>
                                <th>Estado (Materia más Ausente)</th>
                                <th style="text-align:center;">Justificar</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            // Buscamos si existe al menos una ausencia 'Ausente' para esa fecha/curso
                            $stmt_ausencias = $conn->prepare("SELECT Estado, Materia FROM asistencia asi JOIN materia m ON asi.Materia_idMateria = m.idMateria WHERE Alumno_DNI = ? AND Curso_idCurso = ? AND Dia = ?");
                            $alumnos_con_ausencia = 0;
                            while ($a = $alumnos->fetch_assoc()) {
                                $stmt_ausencias->bind_param("sis", $a['DNI'], $curso_seleccionado_gestion, $fecha_seleccionada);
                                $stmt_ausencias->execute();
                                $res_ausencias = $stmt_ausencias->get_result();
                                
                                $estados = [];
                                $materia_ausente = '';
                                $has_ausente = false;
                                $is_justified = false;

                                while ($row = $res_ausencias->fetch_assoc()) {
                                    $estados[] = $row['Estado'];
                                    if ($row['Estado'] == 'Ausente') {
                                        $has_ausente = true;
                                        $materia_ausente = $row['Materia']; // Muestra la materia donde se registró la Ausencia
                                    } elseif ($row['Estado'] == 'Justificado') {
                                        $is_justified = true;
                                    }
                                }

                                $display_estado = 'N/A';
                                $checkbox = '';
                                
                                if (empty($estados)) {
                                    $display_estado = "<span style='color:gray;'>Sin datos</span>";
                                } elseif ($is_justified) {
                                    $display_estado = "<span style='color:blue; font-weight:bold;'>Justificado</span>";
                                } elseif ($has_ausente) {
                                    $display_estado = "<strong style='color:red;'>Ausente</strong><br><small>({$materia_ausente})</small>";
                                    $checkbox = "<input type='checkbox' name='justificar[]' value='{$a['DNI']}'>";
                                    $checkbox .= "
                                        <br>
                                        <label style='font-size:0.8em;color:#333;'>📎 Adjuntar</label>
                                        <input type='file' name='archivo_justificativo_{$a['DNI']}' accept='image/*,application/pdf' style='width:150px;font-size:0.8em;'>
                                        <input type='text' name='motivo_{$a['DNI']}' placeholder='Motivo (opcional)' style='width:150px;font-size:0.8em;margin-top:5px;'>";

                                    $alumnos_con_ausencia++;

                                } else {
                                    $display_estado = "<span style='color:green;'>Presente</span>";
                                }

                                echo "<tr>
                                            <td>" . htmlspecialchars($a['Apellido'] . ', ' . $a['Nombre']) . "</td>
                                            <td>{$display_estado}</td>
                                            <td style='text-align:center;'>{$checkbox}</td>
                                        </tr>";
                            }
                            @$stmt_ausencias->close();
                            ?>
                        </tbody>
                    </table>
                    <?php if($alumnos_con_ausencia > 0): ?>
                        <div class="acciones-btn" style="text-align: right; margin-top: 15px;"><button type="submit" name="justificar_ausencias" class="btn btn-guardar">✅ Justificar Seleccionados</button></div>
                    <?php else: ?>
                        <p style="text-align:center; font-weight:bold; margin-top:15px;">No hay alumnos ausentes para justificar en esta fecha.</p>
                    <?php endif; ?>
                </form>
            <?php endif; ?>
            </div>
            
        <?php elseif ($curso_seleccionado_gestion && !verificarPermisos($conn, $usuario_dni, $curso_seleccionado_gestion)): ?>
            <div class="mensaje-error" style="text-align:center;">
                <strong>Acceso Denegado:</strong> No tienes permisos asignados al curso seleccionado.
            </div>
        <?php else: ?>
            <p style="text-align:center; font-weight:bold; margin-top:15px;">Seleccione un curso para comenzar a gestionar.</p>
        <?php endif; ?>

<?php elseif ($vista == 'agregar_alumno'): ?>
<h1>Agregar Nuevo Alumno</h1>
    <div class="form-container">
        <p>Complete la información para registrar un nuevo alumno y asignarle sus datos iniciales (Curso, Grupo y Materias).</p>
        <form method="POST" action="?vista=agregar_alumno">
            <div class="form-asignar">
                <div class="form-group"><label for="dni">DNI:</label><input type="text" id="dni" name="dni" required placeholder="DNI del alumno"></div>
                <div class="form-group"><label for="nombre">Nombre:</label><input type="text" id="nombre" name="nombre" required placeholder="Nombre"></div>
                <div class="form-group"><label for="apellido">Apellido:</label><input type="text" id="apellido" name="apellido" required placeholder="Apellido"></div>
                <div class="form-group"><label for="correo">Correo:</label><input type="email" id="correo" name="correo" placeholder="correo@ejemplo.com"></div>

                <div class="form-group">
                    <label for="curso_id">Asignar al Curso:</label>
                    <select id="curso_id" name="curso_id" required>
                        <option value="">-- Seleccione un curso --</option>
                        <?php 
                        $cursos = $conn->query("SELECT idCurso, Curso, Ciclo FROM curso ORDER BY Curso"); 
                        while ($c = $cursos->fetch_assoc()) { 
                            echo "<option value='{$c['idCurso']}'>{$c['Curso']} {$c['Ciclo']}</option>"; 
                        } 
                        ?>
                    </select>
                </div>

                <div class="form-group">
                    <label for="grupo_id">Grupo de Rotación:</label>
                    <select id="grupo_id" name="grupo_id" required>
                        <option value="">-- Seleccione un grupo --</option>
                        <?php 
                        $grupos = $conn->query("SELECT idGrupos_Rotación, Nombre_Grupo FROM grupos_rotación ORDER BY idGrupos_Rotación"); 
                        while ($g = $grupos->fetch_assoc()) { 
                            echo "<option value='{$g['idGrupos_Rotación']}'>{$g['Nombre_Grupo']}</option>"; 
                        } 
                        ?>
                    </select>
                </div>

                <div class="form-group" style="grid-column: 1 / -1;">
                    <label for="materia_id">Asignar Materia Manualmente (Se asignan todas las del grupo después):</label>
                    <select id="materia_id" name="materia_id" required>
                        <option value="">-- Seleccione una materia --</option>
                        <?php 
                        $materias = $conn->query("SELECT idMateria, Materia FROM materia ORDER BY Materia"); 
                        while ($m = $materias->fetch_assoc()) { 
                            echo "<option value='{$m['idMateria']}'>" . htmlspecialchars($m['Materia']) . "</option>"; 
                        } 
                        ?>
                    </select>
                </div>
            </div>

            <div class="acciones-btn"><button type="submit" name="guardar_alumno" class="btn btn-agregar">💾 Guardar Alumno</button></div>
        </form>
    </div>
<?php endif; ?>


</div>

<script>
    // --- SCRIPT PARA EL DROPDOWN DE USUARIO ---
    function toggleDropdown() {
        // Muestra u oculta el contenido del dropdown
        document.getElementById("userDropdownContent").classList.toggle("show");
    }

    // Cerrar el dropdown si el usuario hace clic fuera de él
    window.onclick = function(event) {
        // Verifica si el clic NO es dentro del avatar ni dentro del menú desplegable
        if (!event.target.closest('.user-dropdown')) {
            var dropdowns = document.getElementsByClassName("dropdown-content");
            for (var i = 0; i < dropdowns.length; i++) {
                var openDropdown = dropdowns[i];
                if (openDropdown.classList.contains('show')) {
                    openDropdown.classList.remove('show');
                }
            }
        }
    }

    // Script para el selector de fecha y curso del Preceptor (manteniendo la lógica de auto-submit)
    var selectorFecha = document.getElementById('fecha_gestion');
    var selectorCurso = document.getElementById('curso_id_gestion');

    if (selectorFecha && selectorCurso) {
        var submitOnFilterChange = function() {
            // Solo enviar si ambos filtros (curso y fecha) tienen valor
            if (selectorFecha.value && selectorCurso.value) {
                // Agregar un pequeño delay para que la transición sea visible antes del submit (opcional)
                setTimeout(() => {
                    this.closest('form').submit();
                }, 100);
            }
        };

        selectorFecha.addEventListener('change', submitOnFilterChange);
        // Si el preceptor cambia el curso, también debe recargar
        selectorCurso.addEventListener('change', submitOnFilterChange);
    }
</script>

</body>
</html>