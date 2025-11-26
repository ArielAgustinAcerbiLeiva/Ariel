<?php
// ===============================================
// Archivo: profesor.php (CÓDIGO COMPLETO Y CORREGIDO)
// ===============================================

session_start();
// 1. CONTROL DE ACCESO: Solo Profesor
if (!isset($_SESSION["usuario"]) || $_SESSION['rol'] !== 'Profesor') { 
    header("Location: login.php"); 
    exit(); 
}

// Asegúrate de que 'conexion.php' contiene la conexión a la base de datos ($conn)
include('conexion.php'); 

$rol_usuario = 'Profesor'; 
$usuario_dni = isset($_SESSION['usuario']) ? (string)$_SESSION['usuario'] : null;

// --- OBTENER NOMBRE DEL USUARIO ---
$nombre_usuario_display = 'Profesor';
if ($usuario_dni && isset($conn)) {
    $stmt_nombre = $conn->prepare("SELECT Nombre, Apellido FROM empleado WHERE DNI = ?");
    $stmt_nombre->bind_param("s", $usuario_dni);
    @$stmt_nombre->execute();
    $result_nombre = @$stmt_nombre->get_result();
    if ($result_nombre && $row_nombre = $result_nombre->fetch_assoc()) {
        $nombre_usuario_display = htmlspecialchars($row_nombre['Nombre'] . ' ' . $row_nombre['Apellido']);
    }
    @$stmt_nombre->close(); 
}

$mensaje_exito = null;
$mensaje_error = null;

// =========================================================================
// DEFINICIÓN DE VARIABLES DE VISTA 
// =========================================================================

// CAMBIO AQUÍ: La vista por defecto es 'gestionar'
$vista = isset($_GET['vista']) ? $_GET['vista'] : 'gestionar';
// Variables unificadas para gestión de curso/materia/grupo
$curso_seleccionado_gestion = isset($_REQUEST['curso_id_gestion']) ? (int)$_REQUEST['curso_id_gestion'] : null;
$materia_seleccionada = isset($_REQUEST['materia_id']) ? (int)$_REQUEST['materia_id'] : null;
$grupo_seleccionado_gestion = isset($_REQUEST['grupo_id_gestion']) ? (int)$_REQUEST['grupo_id_gestion'] : null;
$vista_gestion = isset($_REQUEST['tab']) ? $_REQUEST['tab'] : 'asistencia'; 
$fecha_gestion = isset($_REQUEST['fecha_asistencia']) ? $_REQUEST['fecha_asistencia'] : date('Y-m-d');

// Las variables de búsqueda ($curso_seleccionado_busqueda, $dni_buscado) ya no se usan en el flujo corregido.

// =========================================================================
// AJAX: HISTORIAL DE ASISTENCIAS
// =========================================================================
if (isset($_GET['action']) && $_GET['action'] == 'fetch_history') {
    $dni = $_GET['dni'] ?? null;
    $curso = $_GET['curso_id'] ?? null;
    $materia = $_GET['materia_id'] ?? null;
    
    // Verificación de parámetros y permisos (aunque la seguridad se confía al front-end en este punto, es buena práctica)
    if ($dni && $curso && $materia && verificarPermisosProfesor($conn, $usuario_dni, $curso, $materia)) { 
        $sql = "SELECT DATE_FORMAT(Dia, '%d/%m/%Y') as Fecha, Estado,
                CASE 
                    WHEN Estado = 'Presente' THEN '🟢 Presente' 
                    WHEN Estado = 'Ausente' THEN '🔴 Ausente' 
                    WHEN Estado = 'Justificado' THEN '🟡 Justificado' 
                    ELSE '⚪ No Registrado'
                END as EstadoDisplay
                FROM asistencia 
                WHERE Alumno_DNI = ? AND Curso_idCurso = ? AND Materia_idMateria = ? 
                ORDER BY Dia DESC";
        
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("sii", $dni, $curso, $materia);
        $stmt->execute();
        $res = $stmt->get_result();

        echo "<h4>Historial de Asistencias</h4>";
        if ($res->num_rows > 0) {
            echo "<table style='width:100%; border-collapse:collapse; margin-top:10px;'>
                    <tr style='background:#f0f0f0;'><th style='border:1px solid #ddd; padding:5px;'>Fecha</th><th style='border:1px solid #ddd; padding:5px;'>Estado</th></tr>";
            while($row = $res->fetch_assoc()) {
                echo "<tr><td style='border:1px solid #ddd; padding:5px;'>{$row['Fecha']}</td><td style='border:1px solid #ddd; padding:5px;'>{$row['EstadoDisplay']}</td></tr>";
            }
            echo "</table>";
        } else {
            echo "<p>Sin registros previos.</p>";
        }
        $stmt->close();
    } else {
         echo "<p>Error: No se puede cargar el historial o no tiene permisos.</p>";
    }
    exit(); 
}

// --- VERIFICAR PERMISOS ---
function verificarPermisosProfesor($conn, $dni, $curso_id, $materia_id) {
    if (!$dni) return false;
    // 1. Verifica si el profesor está asignado al curso
    $stmt = $conn->prepare("SELECT 1 FROM curso_has_empleado WHERE Empleado_DNI = ? AND Curso_idCurso = ?");
    $stmt->bind_param("si", $dni, $curso_id);
    $stmt->execute();
    $p_curso = $stmt->get_result()->num_rows > 0;
    $stmt->close();

    // 2. Verifica si el profesor está asignado a la materia
    $stmt = $conn->prepare("SELECT 1 FROM materia_has_empleado WHERE Empleado_DNI = ? AND Materia_idMateria = ?");
    $stmt->bind_param("si", $dni, $materia_id);
    $stmt->execute();
    $p_materia = $stmt->get_result()->num_rows > 0;
    $stmt->close();

    return $p_curso && $p_materia;
}

// =========================================================================
// LÓGICA POST (PROCESAMIENTO DE FORMULARIOS)
// =========================================================================

// A. Asignar Curso/Materia
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['asignar'])) {
    $c_id = $_POST['curso_id_asignar'] ?? null;
    $m_id = $_POST['materia_id_asignar'] ?? null;
    $msgs = [];

    if ($c_id && $usuario_dni) {
        $stmt = $conn->prepare("INSERT IGNORE INTO curso_has_empleado (Curso_idCurso, Empleado_DNI) VALUES (?, ?)");
        $stmt->bind_param("is", $c_id, $usuario_dni);
        if($stmt->execute() && $stmt->affected_rows > 0) $msgs[] = "✅ Curso asignado.";
        $stmt->close();
    }
    if ($m_id && $usuario_dni) {
        $stmt = $conn->prepare("INSERT IGNORE INTO materia_has_empleado (Materia_idMateria, Empleado_DNI) VALUES (?, ?)");
        $stmt->bind_param("is", $m_id, $usuario_dni);
        if($stmt->execute() && $stmt->affected_rows > 0) $msgs[] = "✅ Materia asignada.";
        $stmt->close();
    }
    if (!empty($msgs)) $mensaje_exito = implode("<br>", $msgs);
}

// B. Guardar Asistencia
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['guardar_asistencias'])) {
    $curso_id = (int)$_POST['curso_id_gestion'];
    $materia_id = (int)$_POST['materia_id'];
    // Captura las IDs de grupo y curso/materia para redirigir correctamente a la vista de gestión
    $grupo_id = (int)$_POST['grupo_id_gestion']; 
    $fecha_a_guardar = $_POST['fecha_asistencia']; 
    $tab = 'asistencia'; // Mantener la pestaña

    if (verificarPermisosProfesor($conn, $usuario_dni, $curso_id, $materia_id)) {
        $asistencias = $_POST['asistencia'] ?? [];
        
        $stmt_check = $conn->prepare("SELECT idAsistencia FROM asistencia WHERE Dia = ? AND Curso_idCurso = ? AND Materia_idMateria = ? AND Alumno_DNI = ?");
        $stmt_upd = $conn->prepare("UPDATE asistencia SET Estado = ? WHERE idAsistencia = ?");
        $stmt_ins = $conn->prepare("INSERT INTO asistencia (Dia, Estado, Curso_idCurso, Materia_idMateria, Alumno_DNI) VALUES (?, ?, ?, ?, ?)");

        foreach ($asistencias as $dni_alumno => $estado) {
            $dni_str = (string)$dni_alumno;
            // Verificar si ya existe un registro para esa fecha/curso/materia/alumno
            $stmt_check->bind_param("siis", $fecha_a_guardar, $curso_id, $materia_id, $dni_str);
            $stmt_check->execute();
            $res = $stmt_check->get_result();
            
            if ($row = $res->fetch_assoc()) {
                // Actualizar si existe
                $stmt_upd->bind_param("si", $estado, $row['idAsistencia']);
                $stmt_upd->execute();
            } else {
                // Insertar si no existe
                $stmt_ins->bind_param("ssiis", $fecha_a_guardar, $estado, $curso_id, $materia_id, $dni_str);
                $stmt_ins->execute();
            }
        }
        $stmt_check->close(); $stmt_upd->close(); $stmt_ins->close();
        
        // Redirección POST-Redirect-GET para evitar reenvío de formulario
        header("Location: ?vista=gestionar&curso_id_gestion=$curso_id&materia_id=$materia_id&grupo_id_gestion=$grupo_id&fecha_asistencia=$fecha_a_guardar&tab=$tab&exito=1");
        exit();

    } else {
        $mensaje_error = "❌ Sin permiso para modificar este curso.";
    }
}

// C. Guardar Notas
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['guardar_notas'])) {
    $materia_id = (int)$_POST['materia_id'];
    $curso_id = (int)$_POST['curso_id_gestion'];
    // Captura las IDs para redirigir correctamente
    $grupo_id = (int)$_POST['grupo_id_gestion']; 
    $fecha_gestion_post = $_POST['fecha_asistencia'] ?? date('Y-m-d'); // Usamos la fecha que venga oculta
    $tab = 'notas';

    if (verificarPermisosProfesor($conn, $usuario_dni, $curso_id, $materia_id)) {
        $notas = $_POST['notas'];
        // INSERT ON DUPLICATE KEY UPDATE: Para insertar o actualizar la nota
        $stmt = $conn->prepare("INSERT INTO materia_has_alumno (Materia_idMateria, Alumno_DNI, notas) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE notas = VALUES(notas)");
        foreach ($notas as $dni_alumno => $nota) {
            $dni_str = (string)$dni_alumno;
            // Convertir coma a punto para asegurar formato numérico en SQL y manejar vacíos
            $val = trim(str_replace(',', '.', (string)$nota));
            $val = ($val === '') ? null : $val; 
            $stmt->bind_param("iss", $materia_id, $dni_str, $val);
            $stmt->execute();
        }
        $stmt->close();
        
        // Redirección POST-Redirect-GET para evitar reenvío de formulario
        header("Location: ?vista=gestionar&curso_id_gestion=$curso_id&materia_id=$materia_id&grupo_id_gestion=$grupo_id&fecha_asistencia=$fecha_gestion_post&tab=$tab&exito=1");
        exit();
        
    } else {
        $mensaje_error = "❌ Sin permiso para notas.";
    }
}

// Si viene de una redirección exitosa (solo para Gestionar Clase)
if (isset($_GET['exito']) && $vista == 'gestionar') {
    $fecha_fmt = date("d/m/Y", strtotime($fecha_gestion));
    if ($vista_gestion == 'asistencia') {
        $mensaje_exito = "✅ Asistencia guardada para el día <b>$fecha_fmt</b>.";
    } elseif ($vista_gestion == 'notas') {
        $mensaje_exito = "✅ Notas actualizadas.";
    }
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Panel Profesor</title>
    <link rel="shortcut icon" href="imagenes/logo escuela.jpg">
    <style>
        :root { --c-p: #007bff; --c-w: #fff; --c-e: #dc3545; }
        body { background: #f0f2f5; color: #333; font-family: 'Segoe UI', sans-serif; margin: 0; padding: 0; }
        .top-bar { background: var(--c-p); color: var(--c-w); padding: 10px 40px; display: flex; justify-content: flex-end; align-items: center; box-shadow: 0 2px 4px rgba(0,0,0,0.1); position: relative; }
        .nav-menu { background: #1565c0; padding: 0 40px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); display: flex; gap: 10px; }
        .nav-menu a { color: #fff; padding: 15px 20px; text-decoration: none; display: block; transition: 0.3s; }
        .nav-menu a.active { background: #1a73e8; border-bottom: 3px solid #ffeb3b; }
        .nav-menu a:hover { background: #1976d2; }
        .content { padding: 30px; background: #fff; margin: 20px auto; max-width: 1400px; border-radius: 10px; box-shadow: 0 4px 12px rgba(0,0,0,0.1); }
        h1, h2 { color: #1a73e8; border-bottom: 1px solid #eee; padding-bottom: 10px; margin-top: 20px; }
        .btn { background: #1a73e8; color: #fff; border: none; padding: 10px 20px; border-radius: 5px; cursor: pointer; text-decoration: none; display: inline-block; margin-right: 5px; transition: 0.3s; }
        .btn:hover { background: #1565c0; transform: translateY(-2px); }
        .btn-guardar { background: #28a745; margin-top: 20px; }
        .btn-info { background-color: #00aced !important; }
        .btn-volver { background-color: #6c757d !important; }
        table { width: 100%; border-collapse: collapse; margin-top: 20px; }
        th, td { border: 1px solid #e0e0e0; padding: 12px; text-align: left; vertical-align: middle; }
        th { background: #f5f5f5; font-weight: bold; }
        tr:nth-child(even) { background-color: #f9f9f9; }
        .form-container { padding: 20px; background: #f9f9f9; border-radius: 8px; margin-bottom: 20px; border: 1px solid #e0e0e0; }
        .form-group { margin-bottom: 15px; }
        .form-group label { display: block; margin-bottom: 5px; font-weight: 600; }
        select, input[type="text"], input[type="date"] { padding: 8px; border: 1px solid #ccc; border-radius: 4px; min-width: 200px; }
        .mensaje-exito { background: #d4edda; color: #155724; padding: 15px; border-radius: 5px; margin-bottom: 20px; border: 1px solid #c3e6cb; }
        .mensaje-error { background: #f8d7da; color: #721c24; padding: 15px; border-radius: 5px; margin-bottom: 20px; border: 1px solid #f5c6cb; }
        .tabs { display: flex; border-bottom: 2px solid #e0e0e0; margin-bottom: 15px; }
        .tab-btn { padding: 10px 20px; background: #f1f1f1; border: none; cursor: pointer; margin-right: 5px; border-radius: 5px 5px 0 0; color: #555; }
        .tab-btn.active { background: #fff; color: #007bff; border: 1px solid #e0e0e0; border-bottom: 1px solid #fff; font-weight: bold; }
        td input[type="radio"] { width: 18px; height: 18px; vertical-align: middle; margin-right: 5px; cursor: pointer; }
        td label { vertical-align: middle; cursor: pointer; margin-right: 15px; }
        .modal { display: none; position: fixed; z-index: 1001; left: 0; top: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); }
        .modal-content { background: #fff; margin: 10% auto; padding: 20px; width: 60%; border-radius: 8px; position: relative; }
        .close-button { position: absolute; right: 15px; top: 10px; font-size: 25px; cursor: pointer; font-weight: bold; }
        .user-dropdown { position: relative; cursor: pointer; }
        .user-dropdown img { width: 40px; height: 40px; border-radius: 50%; border: 2px solid #fff; }
        .dropdown-content { display: none; position: absolute; right: 0; background: #fff; min-width: 200px; box-shadow: 0 8px 16px rgba(0,0,0,0.2); z-index: 1000; top: 50px; border-radius: 5px; }
        .dropdown-content.show { display: block; }
        .dropdown-logout-link { display: block; padding: 10px; background: var(--c-e); color: #fff; text-align: center; text-decoration: none; font-weight: bold; }
        .dropdown-user-info { padding: 15px; border-bottom: 1px solid #eee; }
        .alerta-asignacion { background: #fff3cd; color: #856404; padding: 15px; border-radius: 5px; border: 1px solid #ffeeba; }
    </style>
</head>
<body>

<div class="top-bar">
    <div class="user-dropdown" onclick="document.getElementById('ddContent').classList.toggle('show')">
        <img src="imagenes/logo escuela.jpg" alt="User">
        <div id="ddContent" class="dropdown-content">
            <div class="dropdown-user-info">Hola, <strong><?= $nombre_usuario_display ?></strong></div>
            <a href="logout.php" class="dropdown-logout-link">Cerrar Sesión</a>
        </div>
    </div>
</div>

<nav class="nav-menu">
    <a href="?vista=gestionar" class="<?= ($vista == 'gestionar') ? 'active' : '' ?>">✍️ Gestionar Clase</a> 
    
    <a href="?vista=mis_cursos" class="<?= ($vista == 'mis_cursos' || $vista == 'ver_alumnos') ? 'active' : '' ?>">📚 Mis Cursos</a>
    
    <a href="?vista=asignar_curso" class="<?= ($vista == 'asignar_curso') ? 'active' : '' ?>">➕ Asignar</a>
</nav><div class="content">


    <?php if ($mensaje_exito): ?><div class="mensaje-exito"><?= $mensaje_exito ?></div><?php endif; ?>
    <?php if ($mensaje_error): ?><div class="mensaje-error"><?= $mensaje_error ?></div><?php endif; ?>

    <?php // ==================================== VISTA ASIGNAR CURSO ==================================== ?>
    <?php if ($vista == 'asignar_curso'): ?>
        <h1>Asignarme Cursos y Materias ✨</h1>
        <div class="form-container">
            <p style="font-weight: 500; color: #666; margin-bottom: 25px;">📌 Selecciona el **curso** del que deseas hacerte cargo y la **materia** que dictas.</p>
            <form method="POST" action="?vista=asignar_curso" class="form-asignar">
                <div class="form-group">
                    <label>📚 Seleccionar Curso (obligatorio):</label>
                    <select name="curso_id_asignar" required>
                        <option value="">-- Elija un curso --</option>
                        <?php 
                        $cursos_libres = $conn->query("SELECT idCurso, Curso, Ciclo FROM curso ORDER BY Ciclo DESC, Curso"); 
                        while ($c = $cursos_libres->fetch_assoc()) { 
                            echo "<option value='{$c['idCurso']}' >{$c['Curso']} {$c['Ciclo']}</option>"; 
                        } 
                        ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>📖 Seleccionar Materia (obligatorio):</label>
                    <select name="materia_id_asignar" required>
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
        
    <?php // ==================================== VISTA MIS CURSOS (MODIFICADA) ==================================== ?>
    <?php elseif ($vista == 'mis_cursos'): ?>
        <h1>Mis Cursos Asignados 📚</h1>
        <?php
        $stmt_cursos = $conn->prepare("SELECT c.idCurso, c.Curso, c.Ciclo FROM curso c JOIN curso_has_empleado che ON c.idCurso = che.Curso_idCurso WHERE che.Empleado_DNI = ? ORDER BY c.Curso");
        $stmt_cursos->bind_param("s", $usuario_dni);
        $stmt_cursos->execute();
        $res_cursos = $stmt_cursos->get_result();

        if ($res_cursos->num_rows > 0) {
            echo "<table>
                    <thead><tr><th>CURSO</th><th>CICLO</th><th>ACCIÓN</th></tr></thead>
                    <tbody>";
            while ($curso = $res_cursos->fetch_assoc()) {
                // Se actualiza el botón para usar la vista 'ver_alumnos' y el parámetro 'curso_id_gestion'
                echo "<tr>
                        <td><strong>" . htmlspecialchars($curso['Curso']) . "</strong></td>
                        <td>" . htmlspecialchars($curso['Ciclo']) . "</td>
                        <td><a href='?vista=ver_alumnos&curso_id_gestion={$curso['idCurso']}' class='btn btn-info'>👥 Ver Alumnos</a></td>
                    </tr>";
            }
            echo "</tbody></table>";
        } else {
            echo "<div class='alerta-asignacion'>
                    <strong>¡Atención!</strong> No tienes cursos asignados.
                    <p>Ve a <a href='?vista=asignar_curso'>➕ Asignarme Cursos/Materias</a>.</p>
                  </div>";
        }
        $stmt_cursos->close();
        ?>
        
    <?php // ==================================== VISTA VER ALUMNOS (MÉTRICAS) ==================================== ?>
    <?php elseif ($vista == 'ver_alumnos' && $curso_seleccionado_gestion): ?>
        
        <?php 
        // Consulta para obtener el nombre completo del curso
        $stmt_curso = $conn->prepare("SELECT Curso, Ciclo FROM curso WHERE idCurso = ?");
        $stmt_curso->bind_param("i", $curso_seleccionado_gestion);
        $stmt_curso->execute();
        $res_curso = $stmt_curso->get_result();
        $nombre_curso = $res_curso->fetch_assoc();
        $curso_display = htmlspecialchars($nombre_curso['Curso'] . ' ' . $nombre_curso['Ciclo']);
        $stmt_curso->close();

        $materia_nombre = 'N/A';
        if ($materia_seleccionada) {
            $stmt_materia = $conn->prepare("SELECT Materia FROM materia WHERE idMateria = ?");
            $stmt_materia->bind_param("i", $materia_seleccionada);
            $stmt_materia->execute();
            $materia_nombre = $stmt_materia->get_result()->fetch_assoc()['Materia'] ?? 'N/A';
            $stmt_materia->close();
        }
        ?>
        
        <h1>Alumnos del Curso <?= $curso_display ?> 👥</h1>
        <a href='?vista=mis_cursos' class='btn btn-volver'>↩️ Volver a Mis Cursos</a>
        
        <h2 style="margin-top:20px;">Selección de Materia</h2>
        <div class="form-container" style="max-width: 500px; margin: 20px 0;">
            <p>Selecciona la materia para ver las métricas de asistencia y notas.</p>
            <form id="materiaForm" method="GET" action="?vista=ver_alumnos" style="display:flex; gap:10px; align-items:flex-end;">
                <input type="hidden" name="vista" value="ver_alumnos">
                <input type="hidden" name="curso_id_gestion" value="<?= $curso_seleccionado_gestion ?>">
                <div class="form-group" style="margin-bottom:0;">
                    <label>📖 Materia:</label>
                    <select name="materia_id" required>
                        <option value="">-- Seleccionar --</option>
                        <?php
                        // Solo materias que dicta el profesor
                        $q = "SELECT m.idMateria, m.Materia FROM materia m JOIN materia_has_empleado mhe ON m.idMateria = mhe.Materia_idMateria WHERE mhe.Empleado_DNI = ? ORDER BY m.Materia";
                        $stmt = $conn->prepare($q);
                        $stmt->bind_param("s", $usuario_dni);
                        $stmt->execute();
                        $res = $stmt->get_result();
                        while ($m = $res->fetch_assoc()) {
                            $sel = ($m['idMateria'] == $materia_seleccionada) ? 'selected' : '';
                            echo "<option value='{$m['idMateria']}' $sel>{$m['Materia']}</option>";
                        }
                        $stmt->close();
                        ?>
                    </select>
                </div>
                <button type="submit" class="btn btn-info">Cargar</button>
            </form>
        </div>

        <?php if ($materia_seleccionada): ?>
            <h2 style="margin-top:30px;">Listado de Alumnos y Métricas (Materia: <?= htmlspecialchars($materia_nombre) ?>)</h2>
            <?php
            // Consulta de Alumnos y Métricas de Asistencia/Notas
            $q_alumnos_metricas = "
                SELECT 
                    a.DNI, 
                    CONCAT(a.Apellido, ', ', a.Nombre) AS Alumno,
                    IFNULL(mha.notas, 'N/A') AS Nota,
                    COALESCE(SUM(CASE WHEN asi.Estado = 'Presente' THEN 1 ELSE 0 END), 0) AS P,
                    COALESCE(SUM(CASE WHEN asi.Estado = 'Ausente' THEN 1 ELSE 0 END), 0) AS A,
                    COALESCE(SUM(CASE WHEN asi.Estado = 'Justificado' THEN 1 ELSE 0 END), 0) AS J,
                    (COALESCE(SUM(CASE WHEN asi.Estado = 'Ausente' THEN 1 ELSE 0 END), 0) / NULLIF(COUNT(asi.idAsistencia), 0)) * 100 AS PorcentajeFaltas
                FROM alumno a
                JOIN alumno_has_curso ahc ON a.DNI = ahc.Alumno_DNI
                LEFT JOIN materia_has_alumno mha ON a.DNI = mha.Alumno_DNI AND mha.Materia_idMateria = ?
                LEFT JOIN asistencia asi ON a.DNI = asi.Alumno_DNI AND asi.Curso_idCurso = ? AND asi.Materia_idMateria = ?
                WHERE ahc.Curso_idCurso = ?
                GROUP BY a.DNI
                ORDER BY a.Apellido, a.Nombre
            ";

            $stmt_metricas = $conn->prepare($q_alumnos_metricas);
            // Tipos: i (materia), i (curso), i (materia), i (curso)
            $stmt_metricas->bind_param("iiii", $materia_seleccionada, $curso_seleccionado_gestion, $materia_seleccionada, $curso_seleccionado_gestion);
            $stmt_metricas->execute();
            $alumnos_metricas = $stmt_metricas->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt_metricas->close();

            if (count($alumnos_metricas) > 0) {
                echo "<table>
                        <thead>
                            <tr>
                                <th>Alumno (DNI)</th>
                                <th>Nota ({$materia_nombre})</th>
                                <th>P</th>
                                <th>A (Faltas)</th>
                                <th>J (Just.)</th>
                                <th>% Faltas</th>
                                <th>Historial</th>
                                </tr>
                        </thead>
                        <tbody>";

                foreach ($alumnos_metricas as $al) {
                    $dni = $al['DNI'];
                    $porcentaje_faltas = is_numeric($al['PorcentajeFaltas']) ? number_format($al['PorcentajeFaltas'], 1) . '%' : '0.0%';
                    
                    echo "<tr>
                            <td><strong>" . htmlspecialchars($al['Alumno']) . "</strong><br><small>{$dni}</small></td>
                            <td><strong>" . htmlspecialchars($al['Nota']) . "</strong></td>
                            <td>{$al['P']}</td>
                            <td><strong style='color:red;'>{$al['A']}</strong></td>
                            <td>{$al['J']}</td>
                            <td>{$porcentaje_faltas}</td>
                            <td>
                                <button onclick=\"verHistorialAsistenciasBusqueda('{$dni}', '{$curso_seleccionado_gestion}', '{$materia_seleccionada}')\" class='btn btn-info' style='background:#f39c12 !important;'>
                                    📜 Historial
                                </button>
                            </td>
                            </tr>";
                }
                
                echo "</tbody></table>";
            } else {
                echo "<p style='text-align:center; padding:20px;'>No hay alumnos registrados en este curso.</p>";
            }
            ?>
        <?php else: ?>
             <p class="mensaje-error" style="max-width: 500px;">Por favor, selecciona una materia para visualizar las notas y faltas de los alumnos.</p>
        <?php endif; ?>
        
    <?php // ==================================== VISTA GESTIONAR CLASE (SOLO POR GRUPO) ==================================== ?>
    <?php elseif ($vista == 'gestionar'): ?>
        <h1>Gestión de Clase 👩‍🏫</h1>
        
        <div class="filtros form-container">
            <form class="filtros-form" method="GET" style="display: flex; gap: 10px; flex-wrap: wrap; align-items: flex-end;">
                <input type="hidden" name="vista" value="gestionar">
                <input type="hidden" name="tab" value="<?= $vista_gestion ?>">
                
                <div class="form-group" style="margin-bottom:0;">
                    <label>Curso:</label>
                    <select name="curso_id_gestion">
                        <option value="">-- Seleccionar --</option>
                        <?php
                        $q = "SELECT c.idCurso, c.Curso, c.Ciclo FROM curso c JOIN curso_has_empleado che ON c.idCurso = che.Curso_idCurso WHERE che.Empleado_DNI = ? ORDER BY c.Curso";
                        $stmt = $conn->prepare($q);
                        $stmt->bind_param("s", $usuario_dni);
                        $stmt->execute();
                        $res = $stmt->get_result();
                        while ($c = $res->fetch_assoc()) {
                            $sel = ($c['idCurso'] == $curso_seleccionado_gestion) ? 'selected' : '';
                            echo "<option value='{$c['idCurso']}' $sel>{$c['Curso']} {$c['Ciclo']}</option>";
                        }
                        $stmt->close();
                        ?>
                    </select>
                </div>
                
                <div class="form-group" style="margin-bottom:0;">
                    <label>Materia:</label>
                    <select name="materia_id">
                        <option value="">-- Seleccionar --</option>
                        <?php
                        $q = "SELECT m.idMateria, m.Materia FROM materia m JOIN materia_has_empleado mhe ON m.idMateria = mhe.Materia_idMateria WHERE mhe.Empleado_DNI = ? ORDER BY m.Materia";
                        $stmt = $conn->prepare($q);
                        $stmt->bind_param("s", $usuario_dni);
                        $stmt->execute();
                        $res = $stmt->get_result();
                        while ($m = $res->fetch_assoc()) {
                            $sel = ($m['idMateria'] == $materia_seleccionada) ? 'selected' : '';
                            echo "<option value='{$m['idMateria']}' $sel>{$m['Materia']}</option>";
                        }
                        $stmt->close();
                        ?>
                    </select>
                </div>

                <div class="form-group" style="margin-bottom:0;">
                    <label>Grupo:</label>
                    <select name="grupo_id_gestion">
                        <option value="">-- Seleccionar --</option>
                        <?php
                        $grupos = $conn->query("SELECT idGrupos_Rotación, Nombre_Grupo FROM grupos_rotación ORDER BY Nombre_Grupo");
                        while ($g = $grupos->fetch_assoc()) {
                            $sel = ($g['idGrupos_Rotación'] == $grupo_seleccionado_gestion) ? 'selected' : '';
                            echo "<option value='{$g['idGrupos_Rotación']}' $sel>{$g['Nombre_Grupo']}</option>";
                        }
                        ?>
                    </select>
                </div>

                <div class="form-group" style="margin-bottom:0;">
                    <label style="color:#007bff;">Fecha:</label>
                    <input type="date" name="fecha_asistencia" value="<?= $fecha_gestion ?>" max="<?= date('Y-m-d') ?>">
                </div>

                <button type="submit" class="btn btn-cargar">Cargar Listado</button>
            </form>
        </div>

        <?php if ($curso_seleccionado_gestion && $materia_seleccionada && $grupo_seleccionado_gestion): 
            if (!verificarPermisosProfesor($conn, $usuario_dni, $curso_seleccionado_gestion, $materia_seleccionada)) {
                echo "<div class='mensaje-error'>❌ No tienes permiso para este curso y materia.</div>";
            } else {
                // Construcción de URL base para las pestañas
                $base_url = "?vista=gestionar&curso_id_gestion=$curso_seleccionado_gestion&materia_id=$materia_seleccionada&grupo_id_gestion=$grupo_seleccionado_gestion&fecha_asistencia=$fecha_gestion&tab=";
                
                echo "<div class='tabs'>
                        <a href='{$base_url}asistencia' class='tab-btn " . ($vista_gestion=='asistencia'?'active':'') . "'>🗓️ Asistencia (" . date('d/m', strtotime($fecha_gestion)) . ")</a>
                        <a href='{$base_url}notas' class='tab-btn " . ($vista_gestion=='notas'?'active':'') . "'>📝 Notas</a>
                      </div>";
                
                echo "<div class='tab-content'>";

                // Consulta SQL: Solo filtra por Curso y Grupo (no por DNI individual)
                $q_alumnos = "SELECT a.DNI, CONCAT(a.Apellido, ', ', a.Nombre) AS Alumno, IFNULL(mha.notas, '') AS nota_actual,
                                (SELECT Estado FROM asistencia asi WHERE asi.Alumno_DNI = a.DNI AND asi.Curso_idCurso = ? AND asi.Materia_idMateria = ? AND asi.Dia = ?) as estado_dia
                              FROM `alumno` a 
                              JOIN `alumno_has_curso` ahc ON a.DNI = ahc.Alumno_DNI 
                              JOIN `alumno_has_grupos_rotación` ahgr ON a.DNI = ahgr.Alumno_DNI
                              LEFT JOIN `materia_has_alumno` mha ON a.DNI = mha.Alumno_DNI AND mha.Materia_idMateria = ?
                              WHERE ahc.Curso_idCurso = ? AND ahgr.`Grupos_Rotación_idGrupos_Rotación` = ?
                              ORDER BY a.Apellido, a.Nombre";
                
                $stmt = $conn->prepare($q_alumnos);
                if(!$stmt) die("Error SQL: " . $conn->error);
                
                // Tipos: i (curso), i (materia), s (fecha), i (materia), i (curso), i (grupo)
                $stmt->bind_param("iisiis", $curso_seleccionado_gestion, $materia_seleccionada, $fecha_gestion, $materia_seleccionada, $curso_seleccionado_gestion, $grupo_seleccionado_gestion);
                $stmt->execute();
                $alumnos = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
                $stmt->close();

                if (count($alumnos) > 0) {
                    if ($vista_gestion == 'asistencia') { ?>
                        <form method="POST">
                            <input type="hidden" name="curso_id_gestion" value="<?= $curso_seleccionado_gestion ?>">
                            <input type="hidden" name="materia_id" value="<?= $materia_seleccionada ?>">
                            <input type="hidden" name="grupo_id_gestion" value="<?= $grupo_seleccionado_gestion ?>">
                            <input type="hidden" name="fecha_asistencia" value="<?= $fecha_gestion ?>">
                            
                            <table>
                                <thead><tr><th>Alumno</th><th>Estado (<?= date('d/m/Y', strtotime($fecha_gestion)) ?>)</th></tr></thead>
                                <tbody>
                                <?php foreach ($alumnos as $al): $dni=$al['DNI']; $est=$al['estado_dia']; ?>
                                    <tr>
                                        <td><?= htmlspecialchars($al['Alumno']) ?><br><small><?= $dni ?></small></td>
                                        <td class="radio-group">
                                            <label><input type="radio" name="asistencia[<?= $dni ?>]" value="Presente" <?= ($est=='Presente')?'checked':'' ?>> P</label>
                                            <label><input type="radio" name="asistencia[<?= $dni ?>]" value="Ausente" <?= ($est=='Ausente'||!$est)?'checked':'' ?>> A</label>
                                            
                                            <?php 
                                            // Si ya está justificado (por un Preceptor), se muestra pero no se edita
                                            if($est == 'Justificado') {
                                                echo "<span style='color:orange; font-weight:bold; margin-left:10px;'>⚠️ Justificado</span>";
                                            }
                                            ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                            <button type="submit" name="guardar_asistencias" class="btn btn-guardar">💾 Guardar Asistencia</button>
                        </form>
                    <?php } else { ?>
                        <form method="POST">
                            <input type="hidden" name="curso_id_gestion" value="<?= $curso_seleccionado_gestion ?>">
                            <input type="hidden" name="materia_id" value="<?= $materia_seleccionada ?>">
                            <input type="hidden" name="grupo_id_gestion" value="<?= $grupo_seleccionado_gestion ?>">
                            <input type="hidden" name="fecha_asistencia" value="<?= $fecha_gestion ?>">
                            
                            <table>
                                <thead><tr><th>Alumno</th><th>Nota</th></tr></thead>
                                <tbody>
                                <?php foreach ($alumnos as $al): ?>
                                    <tr>
                                        <td><?= htmlspecialchars($al['Alumno']) ?></td>
                                        <td><input type="text" name="notas[<?= $al['DNI'] ?>]" value="<?= htmlspecialchars($al['nota_actual']) ?>" style="width:80px; text-align:center;" placeholder="0-10"></td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                            <button type="submit" name="guardar_notas" class="btn btn-guardar">💾 Guardar Notas</button>
                        </form>
                    <?php }
                } else {
                    echo "<p style='text-align:center; padding:20px;'>No hay alumnos registrados en este grupo o curso.</p>";
                }
                echo "</div>"; // Cierre tab-content
            }
        endif; ?>

    <?php endif; // Fin if vista gestionar ?>
</div>

<div id="historialModal" class="modal" style="display:none;">
    <div class="modal-content">
        <span class="close-button" onclick="document.getElementById('historialModal').style.display='none'">&times;</span>
        <div id="historial-contenido"><p style="text-align:center;">Cargando...</p></div>
    </div>
</div>

<script>
    // Menú usuario
    function toggleDropdown() { 
        document.getElementById("ddContent").classList.toggle("show"); 
    }
    
    // AJAX Historial (Se llama desde la vista ver_alumnos)
    function verHistorialAsistenciasBusqueda(dni, curso, materia) {
        document.getElementById('historialModal').style.display = 'block';
        document.getElementById('historial-contenido').innerHTML = "<p style='text-align:center;'>Cargando...</p>";
        
        fetch(`?action=fetch_history&dni=${dni}&curso_id=${curso}&materia_id=${materia}`)
            .then(r => r.text())
            .then(d => document.getElementById('historial-contenido').innerHTML = d)
            .catch(e => document.getElementById('historial-contenido').innerHTML = "<p>Error al cargar el historial.</p>");
    }

    // Cerrar modales al hacer click fuera
    window.onclick = function(e) {
        // Cierre del dropdown de usuario
        if (!e.target.matches('.user-dropdown') && !e.target.closest('.user-dropdown')) {
            var d = document.getElementById("ddContent");
            if (d && d.classList.contains('show')) d.classList.remove('show');
        }
        // Cierre del modal de historial
        if (e.target == document.getElementById('historialModal')) {
            document.getElementById('historialModal').style.display = "none";
        }
    }
</script>
</body>
</html>