<?php
// Regente.php

session_start();
// 1. Verificar sesión y redireccionar si no está logueado
if (!isset($_SESSION["usuario"])) { header("Location: login.php"); exit(); }

// Asegúrate de que 'conexion.php' contiene la variable $conn para la conexión a la base de datos
include('conexion.php'); 

// NOTA IMPORTANTE: Tu sesión actualmente solo maneja un rol con $_SESSION['rol'].
// Asumimos que el rol guardado en $_SESSION['rol'] es el de más alta jerarquía (Director/Regente)
$rol_usuario = isset($_SESSION['rol']) ? $_SESSION['rol'] : '';
$usuario_dni = isset($_SESSION['usuario']) ? (string)$_SESSION['usuario'] : null;

// Habilitar excepciones para manejo de errores en consultas SQL
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);


// --- FUNCIÓN PARA OBTENER NOMBRE DEL USUARIO ---
$nombre_usuario_display = 'Usuario';
if ($usuario_dni) {
    $stmt_nombre = $conn->prepare("SELECT Nombre, Apellido FROM empleado WHERE DNI = ?");
    $stmt_nombre->bind_param("s", $usuario_dni);
    $stmt_nombre->execute();
    $result_nombre = $stmt_nombre->get_result();
    if ($row_nombre = $result_nombre->fetch_assoc()) {
        $nombre_usuario_display = htmlspecialchars($row_nombre['Nombre'] . ' ' . $row_nombre['Apellido']);
    }
    @$stmt_nombre->close(); 
}
// --------------------------------------------------------

// 2. Control de acceso principal: Solo Director o Regente
if ($rol_usuario !== 'Director' && $rol_usuario !== 'Regente') { 
    echo "<h1>Acceso Denegado</h1><p>Solo los Directores o Regentes pueden acceder a esta vista.</p>";
    echo "<a href='logout.php'>Cerrar sesión</a>";
    exit(); 
}

// 3. NUEVAS VARIABLES DE CONTROL
$curso_seleccionado_resumen = isset($_GET['curso_id']) ? (int)$_GET['curso_id'] : null;

// Determinar la vista: 'resumen' (por defecto) o 'asignar_roles' (nueva)
$vista = isset($_GET['view']) && $_GET['view'] === 'roles' ? 'asignar_roles' : 'resumen'; 

$mensaje_exito = $_GET['exito'] ?? null;
$mensaje_error = $_GET['error'] ?? null;

// --- LÓGICA ESPECÍFICA PARA LA VISTA ASIGNAR_ROLES (Si el usuario es Regente) ---
if ($vista === 'asignar_roles' && $rol_usuario === 'Regente') {
    
    // 1. OBTENER TODOS LOS ROLES DISPONIBLES
    $rolesArr = [];
    try {
        $res = mysqli_query($conn, "SELECT idRol, Nombre FROM Rol ORDER BY Nombre ASC");
        while ($r = mysqli_fetch_assoc($res)) {
            $rolesArr[] = $r;
        }
    } catch (mysqli_sql_exception $e) {
        $mensaje_error = "Error al cargar roles: " . $e->getMessage();
    }

    // 2. OBTENER TODOS LOS EMPLEADOS CON SU ROL ACTUAL
    $sqlEmpleados = "
        SELECT 
            e.DNI, 
            e.Nombre, 
            e.Apellido,
            GROUP_CONCAT(r.Nombre SEPARATOR ', ') AS RolesActuales
        FROM 
            Empleado e
        LEFT JOIN 
            Empleado_has_Rol ehr ON e.DNI = ehr.Empleado_DNI
        LEFT JOIN 
            Rol r ON ehr.Rol_idRol = r.idRol
        GROUP BY 
            e.DNI
        ORDER BY 
            e.Apellido, e.Nombre;
    ";
    $empleados = [];
    try {
        $resEmpleados = mysqli_query($conn, $sqlEmpleados);
        while ($e = mysqli_fetch_assoc($resEmpleados)) {
            $empleados[] = $e;
        }
    } catch (mysqli_sql_exception $e) {
        $mensaje_error = "Error al cargar empleados: " . $e->getMessage();
    }
}
// --- FIN LÓGICA ESPECÍFICA ASIGNAR_ROLES ---

?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Registros - Resumen General</title>
    <link rel="shortcut icon" href="imagenes/logo escuela.jpg">
    <style>
        /* Estilos Base y Tipografía */
        body { 
            background-color: #f0f2f5; 
            color: #333; 
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; 
            margin: 0;
            padding: 0;
        }
        
        /* Top Bar - Modernizada */
        .top-bar { 
            background-color: #1a73e8; /* Azul más profundo y moderno */
            color: white; 
            padding: 10px 40px; 
            display: flex; 
            justify-content: space-between; 
            align-items: center;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            position: relative; /* Clave para el dropdown */
        }
        .top-bar img { border-radius: 50%; }

        /* Nav Menu */
        .nav-menu { 
            background-color: #1565c0; 
            padding: 0 40px; 
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        }
        .nav-menu-list {
            list-style: none;
            padding: 0;
            margin: 0;
            display: flex;
        }
        .nav-menu-list a { 
            color: white; 
            text-decoration: none; 
            padding: 15px 20px; 
            display: block;
            transition: background-color 0.3s, border-bottom 0.3s; 
        }
        .nav-menu-list a.active { 
            background-color: #1a73e8; 
            border-radius: 0; 
            border-bottom: 3px solid #ffeb3b; 
        }
        .nav-menu-list a:hover { 
            background-color: #1976d2; 
        }

        /* Contenido Principal con Animación de Entrada */
        .content { 
            padding: 20px 40px; 
            background-color: white; 
            margin: 20px auto; 
            max-width: 1400px; 
            border-radius: 10px; /* Bordes redondeados */
            box-shadow: 0 4px 12px rgba(0,0,0,0.08); /* Sombra más suave */
            /* === ANIMACIÓN PRINCIPAL: FADE IN UP === */
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

        /* Botones - Estilo Flat/Material con Animación Hover */
        .btn, .btn-buscar { 
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
        .btn:hover, .btn-buscar:hover { 
            background-color: #1565c0; 
            transform: translateY(-2px); /* Pequeña elevación al hacer hover */
            box-shadow: 0 4px 8px rgba(0,0,0,0.15);
        }
        .btn-volver {
            background-color: #6c757d !important; 
        }
        .btn-volver:hover {
            background-color: #5a6268 !important;
        }

        /* Tablas - Estilo Limpio */
        table { 
            width: 100%; 
            border-collapse: separate; 
            border-spacing: 0;
            margin-top: 20px; 
            table-layout: fixed;
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
        /* Animación: Resaltar fila al pasar el mouse */
        tr:hover {
            background-color: #e3f2fd; /* Fondo azul muy claro en hover */
            transition: background-color 0.3s;
        }
        
        /* Estilos específicos de contenido */
        .resumen-notas { 
            font-size: 0.85em; 
            line-height: 1.6; 
            padding-right: 5px; 
        }
        .curso-list-table td { padding: 15px; vertical-align: middle; }
        .curso-list-table .btn-buscar { font-size: 1em; padding: 12px 20px; }

        /* Estilos para mensajes */
        .mensaje-exito, .mensaje-error {
            padding: 15px;
            margin-bottom: 20px;
            border-radius: 5px;
            font-weight: bold;
            text-align: center;
            opacity: 0;
            animation: fadeIn 0.5s forwards; 
        }
        .mensaje-exito {
            color: #155724;
            background-color: #d4edda;
            border: 1px solid #c3e6cb;
        }
        .mensaje-error {
            color: #721c24;
            background-color: #f8d7da;
            border: 1px solid #f5c6cb;
        }

        /* Keyframes para la animación de aparición de mensajes */
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(-10px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        /* Keyframes para la animación de subida de contenido principal */
        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(20px); 
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        /* --- ESTILOS DROPDOWN DE USUARIO --- */
        .user-dropdown {
            position: relative;
            cursor: pointer;
            padding: 5px 10px;
            border-radius: 5px;
            transition: background-color 0.3s;
        }
        .user-dropdown:hover {
            background-color: #1976d2;
        }
        .user-icon {
            font-size: 20px;
            margin-right: 5px;
            user-select: none;
        }
        .dropdown-content {
            display: none;
            position: absolute;
            top: 100%;
            right: 0;
            background-color: white;
            min-width: 250px;
            box-shadow: 0 8px 16px rgba(0,0,0,0.2);
            z-index: 10;
            border-radius: 5px;
            overflow: hidden;
            transform: translateY(10px);
            opacity: 0;
            transition: opacity 0.3s, transform 0.3s;
        }
        .dropdown-content.show {
            display: block;
            opacity: 1;
            transform: translateY(0);
        }
        .dropdown-header {
            padding: 15px;
            background-color: #f1f1f1;
            color: #333;
            border-bottom: 1px solid #ddd;
        }
        .dropdown-header strong {
            display: block;
            font-size: 1.1em;
            margin-bottom: 5px;
        }
        .dropdown-content a {
            color: #333;
            padding: 12px 15px;
            text-decoration: none;
            display: block;
            transition: background-color 0.3s;
        }
        .dropdown-content a:hover {
            background-color: #e3f2fd;
            color: #1a73e8;
        }
        /* --- ESTILOS PARA LA NUEVA VISTA DE ROLES --- */
        .rol-pendiente { color: orange; font-weight: bold; }
        .rol-table td form { display: flex; align-items: center; gap: 5px; }
        .rol-table select { padding: 8px; border-radius: 4px; border: 1px solid #ccc; }
        .rol-table button { padding: 8px 12px; font-size: 0.9em; }
    </style>
</head>
<body>
<div class="top-bar">
    <img src="imagenes/logo escuela.jpg" alt="Logo" style="height: 40px; margin-right: 15px;">
    <div></div> 
    
    <div class="user-dropdown" onclick="toggleDropdown()">
        <span class="user-icon">👤</span> 
        <div class="dropdown-content" id="userDropdownContent">
            <div class="dropdown-header">
                <strong><?= $nombre_usuario_display ?></strong>
                <small>Rol: <?= htmlspecialchars($rol_usuario) ?></small>
                <small>DNI: <?= htmlspecialchars($usuario_dni) ?></small>
            </div>
            <a href="logout.php">🚪 Cerrar sesión</a>
        </div>
    </div>
    </div>

<nav class="nav-menu">
    <ul class="nav-menu-list">
        <li>
            <a href="Regente.php?view=resumen" class="<?= $vista === 'resumen' ? 'active' : '' ?>">
                📊 Resumen General de Alumnos
            </a>
        </li>
        <?php if ($rol_usuario === 'Regente'): ?>
        <li>
            <a href="Regente.php?view=roles" class="<?= $vista === 'asignar_roles' ? 'active' : '' ?>">
                ⚙️ Asignar Roles de Empleados
            </a>
        </li>
        <?php endif; ?>
    </ul>
</nav>

<div class="content">
    <?php if ($mensaje_exito == 'asignacion_exitosa'): ?><div class="mensaje-exito">✅ Roles actualizados correctamente.</div><?php endif; ?>
    <?php if ($mensaje_error == 'asignacion_fallida'): ?><div class="mensaje-error">❌ Error al actualizar roles. Por favor, intente de nuevo.</div><?php endif; ?>
    <?php if ($mensaje_error == 'no_autorizado'): ?><div class="mensaje-error">🚫 No tiene permisos para acceder a esta función administrativa.</div><?php endif; ?>

    <?php if ($vista === 'resumen'): ?>
        <?php if ($curso_seleccionado_resumen === null): ?>
            <h1>Resumen General de Alumnos 🏫</h1>
            <h2>Seleccione un curso para ver el detalle de notas y asistencias</h2>
            
            <table class="curso-list-table">
                <thead><tr><th>CURSO</th><th>CICLO</th><th>ACCIÓN</th></tr></thead>
                <tbody>
                <?php
                // Consulta de TODOS los cursos
                $query_cursos = "SELECT idCurso, Curso, Ciclo FROM curso ORDER BY Ciclo DESC, Curso";
                $stmt_cursos = $conn->prepare($query_cursos);
                $stmt_cursos->execute();
                $cursos_disponibles = $stmt_cursos->get_result();

                if ($cursos_disponibles->num_rows > 0) {
                    while ($curso = $cursos_disponibles->fetch_assoc()) {
                        $curso_display = htmlspecialchars($curso['Curso'] . ' ' . $curso['Ciclo']);
                        echo "<tr>
                                <td><strong>" . htmlspecialchars($curso['Curso']) . "</strong></td>
                                <td>" . htmlspecialchars($curso['Ciclo']) . "</td>
                                <td>
                                    <a href='Regente.php?curso_id={$curso['idCurso']}' class='btn btn-buscar'>
                                        📊 Ver Resumen de " . htmlspecialchars($curso['Curso']) . "
                                    </a>
                                </td>
                            </tr>";
                    }
                } else {
                    echo "<tr><td colspan='3' style='text-align:center; font-weight:bold;'>No hay cursos registrados en el sistema.</td></tr>";
                }
                $stmt_cursos->close();
                ?>
                </tbody>
            </table>

        <?php else: ?>
            <?php
            // Obtener nombre del curso para el título
            $stmt_curso_nombre = $conn->prepare("SELECT Curso, Ciclo FROM curso WHERE idCurso = ?");
            $stmt_curso_nombre->bind_param("i", $curso_seleccionado_resumen);
            $stmt_curso_nombre->execute();
            $curso_info = $stmt_curso_nombre->get_result()->fetch_assoc();
            $curso_nombre_display = htmlspecialchars($curso_info['Curso'] . ' ' . $curso_info['Ciclo']);
            $stmt_curso_nombre->close();
            ?>
            
            <h1>Resumen de Alumnos: <?= $curso_nombre_display ?> 📋</h1>
            <a href="Regente.php" class="btn btn-volver" style="margin-bottom: 15px;">⬅️ Volver a la lista de Cursos</a>
            
            <?php
            // Consulta SQL para el resumen de alumnos del curso seleccionado
            $sql_base = "
                SELECT 
                    a.DNI, 
                    CONCAT(a.Apellido, ', ', a.Nombre) AS Alumno, 
                    CONCAT(c.Curso, ' ', c.Ciclo) AS CursoCompleto,
                    GROUP_CONCAT(DISTINCT CONCAT(m.Materia, ': <strong>', IFNULL(mha.notas, 'Sin nota'), '</strong>') SEPARATOR '<br>') AS Notas, 
                    SUM(CASE WHEN asi.Estado = 'Presente' THEN 1 ELSE 0 END) AS Presentes, 
                    SUM(CASE WHEN asi.Estado = 'Ausente' THEN 1 ELSE 0 END) AS Ausentes,
                    SUM(CASE WHEN asi.Estado = 'Justificado' THEN 1 ELSE 0 END) AS Justificados
                FROM alumno AS a 
                LEFT JOIN alumno_has_curso AS ahc ON a.DNI = ahc.Alumno_DNI 
                LEFT JOIN curso AS c ON ahc.Curso_idCurso = c.idCurso 
                LEFT JOIN materia_has_alumno AS mha ON a.DNI = mha.Alumno_DNI 
                LEFT JOIN materia AS m ON mha.Materia_idMateria = m.idMateria 
                LEFT JOIN asistencia AS asi ON a.DNI = asi.Alumno_DNI
            ";
            
            $sql_final = $sql_base . " WHERE c.idCurso = ? GROUP BY a.DNI ORDER BY Alumno";
            $stmt = $conn->prepare($sql_final);
            $stmt->bind_param("i", $curso_seleccionado_resumen); 
            
            $stmt->execute();
            $result = $stmt->get_result();

            if ($result && $result->num_rows > 0) {
                echo "<table><tr><th>Alumno (DNI)</th><th>Curso</th><th>Notas por Materia</th><th>Presentes</th><th>Ausentes</th><th>Justificados</th></tr>";
                while ($row = $result->fetch_assoc()) {
                    $presentes_display = htmlspecialchars($row['Presentes'] ?? 0);
                    $ausentes_display = htmlspecialchars($row['Ausentes'] ?? 0);
                    $justificados_display = htmlspecialchars($row['Justificados'] ?? 0);
                    
                    if ($row['Ausentes'] > 0) {
                        $ausentes_display = "<span style='color: red; font-weight: bold;'>{$ausentes_display}</span>";
                    }
                    if ($row['Presentes'] > 0) {
                        $presentes_display = "<span style='color: green;'>{$presentes_display}</span>";
                    }

                    echo "<tr>
                            <td><strong>" . htmlspecialchars($row['Alumno']) . "</strong><br><small>DNI: " . htmlspecialchars($row['DNI']) . "</small></td>
                            <td>" . htmlspecialchars($row['CursoCompleto']) . "</td>
                            <td class='resumen-notas'>" . ($row['Notas'] ? $row['Notas'] : 'Sin notas') . "</td>
                            <td>{$presentes_display}</td>
                            <td>{$ausentes_display}</td>
                            <td>{$justificados_display}</td>
                          </tr>"; 
                }
                echo "</table>";
            } else {
                echo "<p style='text-align:center; font-weight:bold;'>No hay alumnos asignados a este curso.</p>";
            }
            $stmt->close();
            ?>
        <?php endif; ?>
    
    <?php elseif ($vista === 'asignar_roles' && $rol_usuario === 'Regente'): ?>
    
        <h1>⚙️ Administración y Asignación de Roles de Empleados</h1>
        <p>Utilice esta tabla para asignar o modificar los cargos (roles) de cualquier empleado. **Recuerde:** La asignación reemplazará cualquier rol anterior. Use CTRL/CMD para seleccionar múltiples roles.</p>

        <table class="rol-table">
            <thead>
                <tr>
                    <th>DNI</th>
                    <th>Nombre Completo</th>
                    <th style="width: 30%;">Roles Actuales</th>
                    <th style="width: 35%;">Asignar/Reemplazar Rol(es)</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($empleados as $empleado) : ?>
                    <tr>
                        <td><?= htmlspecialchars($empleado['DNI']) ?></td>
                        <td><?= htmlspecialchars($empleado['Nombre'] . ' ' . $empleado['Apellido']) ?></td>
                        
                        <td class="<?= $empleado['RolesActuales'] ? '' : 'rol-pendiente' ?>">
                            <?= $empleado['RolesActuales'] ? htmlspecialchars($empleado['RolesActuales']) : 'PENDIENTE' ?>
                        </td>

                        <td>
                            <form method="POST" action="procesar_asignacion.php">
                                <input type="hidden" name="dni_empleado" value="<?= htmlspecialchars($empleado['DNI']) ?>">
                                
                                <select name="roles_a_asignar[]" multiple size="3" style="min-width: 150px;" required>
                                    <?php foreach ($rolesArr as $rol) : ?>
                                        <option value="<?= htmlspecialchars($rol['idRol']) ?>">
                                            <?= htmlspecialchars($rol['Nombre']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                
                                <button type="submit" class="btn">Asignar/Modificar</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    
    <?php endif; ?>

</div>

<script>
    // --- SCRIPT PARA EL DROPDOWN DE USUARIO ---
    function toggleDropdown() {
        document.getElementById("userDropdownContent").classList.toggle("show");
    }

    // Cerrar el dropdown si el usuario hace clic fuera de él
    window.onclick = function(event) {
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