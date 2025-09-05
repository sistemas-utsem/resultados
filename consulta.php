<?php
/**
 * Archivo: consulta.php
 * Función: Consultar resultado por matrícula en la tabla `resultados` y,
 *          mostrar mensajes de contacto o inscripción según la carrera.
 */

// ====== CONFIG DB ======
$host = "localhost";
$user = "root";
$password = "BpjysVrFK6LJ5XyW";
$dbname = "utsem_examen";

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$conn = new mysqli($host, $user, $password, $dbname);
$conn->set_charset("utf8mb4");

$mensaje = "";

// Normaliza texto para comparación
function normalizar($str) {
    $str = mb_strtolower($str, 'UTF-8');
    $str = strtr($str, [
        'á'=>'a','é'=>'e','í'=>'i','ó'=>'o','ú'=>'u','ü'=>'u','ñ'=>'n',
        'Á'=>'a','É'=>'e','Í'=>'i','Ó'=>'o','Ú'=>'u','Ü'=>'u','Ñ'=>'n'
    ]);
    $str = preg_replace('/\s+/', ' ', $str);
    $str = preg_replace('/[^a-z0-9 ]/u', '', $str);
    return trim($str);
}

// Formatea fecha YYYY-MM-DD a "05 de septiembre de 2025"
function fechaLarga($fechaSQL) {
    if (!$fechaSQL) return "";
    setlocale(LC_TIME, "es_ES.UTF-8", "spanish"); // asegurar idioma español
    $timestamp = strtotime($fechaSQL);
    return strftime("%d de %B de %Y", $timestamp);
}

// Mapa de mensajes por carrera para estado "Pendiente"
$mensajesPendiente = [
    normalizar('Licenciatura en Enfermería') =>
        'Comunicarse a la Coordinación de la Carrera de la Licenciatura en Enfermería, al teléfono (724) 269 4016 al 22 ext. 131, correo: <a href="mailto:enfermeria@utsem.edu.mx">enfermeria@utsem.edu.mx</a>',
    normalizar('Técnico Superior Universitario en Contaduría') =>
        'Comunicarse a la Dirección de Carrera de Contaduría, al teléfono (724) 269 4016 al 22 ext. 131, correo: <a href="mailto:dir-conta@utsem.edu.mx">dir-conta@utsem.edu.mx</a>',
    normalizar('Técnico Superior Universitario en Enseñanza del Idioma Inglés') =>
        'Comunicarse a la Coordinación de la Carrera de Lengua Inglesa, al teléfono (724) 269 4016 al 22 ext. 132, correo: <a href="mailto:dir-lein@utsem.edu.mx">dir-lein@utsem.edu.mx</a>',
    normalizar('Técnico Superior Universitario en Sistemas de Manufactura Flexible') =>
        'Comunicarse a la Dirección de la Carrera de Mecatrónica, al teléfono (724) 269 4016 al 22 ext. 227, correo: <a href="mailto:mecatronica@utsem.edu.mx">mecatronica@utsem.edu.mx</a>',
    normalizar('Técnico Superior Universitario en Desarrollo de Software Multiplataforma') =>
        'Comunicarse a la Dirección de Carrera de Tecnologías de la Información, al teléfono (724) 269 4016 al 22 ext. 228, correo: <a href="mailto:tics@utsem.edu.mx">tics@utsem.edu.mx</a>',
    normalizar('Técnico Superior Universitario en Tecnología de Alimentos') =>
        'Comunicarse a la Dirección de la Carrera de Tecnología de Alimento, al teléfono (724) 269 4016 al 22 ext. 108, correo: <a href="mailto:mecatronica@utsem.edu.mx">mecatronica@utsem.edu.mx</a>',
];

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $matricula = $_POST["matricula"] ?? "";

    // Valida formato básico
    if (!preg_match('/^[A-Za-z0-9\-]+$/', $matricula)) {
        $mensaje = "<p class='pendiente'>La matrícula ingresada no es válida.</p>";
    } else {
        $stmt = $conn->prepare("SELECT matricula, carrera, resultado, dia_inscripcion FROM resultados WHERE matricula = ?");
        $stmt->bind_param("s", $matricula);
        $stmt->execute();
        $res = $stmt->get_result();

        if ($res && $res->num_rows > 0) {
            $row = $res->fetch_assoc();
            $resultado = strtolower(trim($row["resultado"] ?? ""));
            $clase = ($resultado === "admitido") ? "admitido" : "pendiente";

            $m  = "<p><strong>Matrícula:</strong> " . htmlspecialchars($row["matricula"], ENT_QUOTES, 'UTF-8') . "</p>";
            $m .= "<p><strong>Carrera:</strong> "   . htmlspecialchars($row["carrera"],   ENT_QUOTES, 'UTF-8') . "</p>";
            $m .= "<p class='{$clase}'><strong>Resultado:</strong> " . htmlspecialchars($row["resultado"], ENT_QUOTES, 'UTF-8') . "</p>";

            // 👉 Si es Pendiente: muestra mensaje de contacto
            if ($clase === "pendiente") {
                $clave = normalizar($row["carrera"]);
                $alias = normalizar('Técnico Superior Universitario en Enseñansa del Idioma Inglés');
                if (!isset($mensajesPendiente[$alias])) {
                    $mensajesPendiente[$alias] = $mensajesPendiente[ normalizar('Técnico Superior Universitario en Enseñanza del Idioma Inglés') ];
                }
                $texto = $mensajesPendiente[$clave] ?? 'Por favor comunícate con tu Dirección o Coordinación de carrera para recibir indicaciones.';
                $m .= "<div class='mensaje'><p>{$texto}</p></div>";
            }

            // 👉 Si es Admitido en Medicina Veterinaria y Zootecnia: muestra inscripción con fecha larga
            if ($clase === "admitido" && normalizar($row["carrera"]) === normalizar("Licenciatura en Medicina Veterinaria y Zootecnia")) {
                $fechaTexto = fechaLarga($row["dia_inscripcion"]);
                $m .= "<div class='mensaje'><p><strong>Inscripción a la Licenciatura en Medicina Veterinaria y Zootecnia</strong><br>
                       {$fechaTexto} de 8:00 a 15:00 horas.</p></div>";
            }

            $mensaje = $m;
        } else {
            $mensaje = "<p class='pendiente'>No se encontró la matrícula ingresada.</p>";
        }
        $stmt->close();
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Consulta de Resultados - UTSEM</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <style>
        body { font-family: 'Segoe UI', sans-serif; background:#f9f9f9; color:#333; margin:0; }
        .container { max-width: 680px; margin: 40px auto; padding: 24px; background:#fff; border-radius:12px; box-shadow:0 8px 24px rgba(0,0,0,0.08); text-align:center; }
        header img { width: 140px; margin-bottom: 12px; }
        h2 { color:#006837; margin:0 0 4px 0; }
        p.tagline { margin:0 0 18px 0; color:#4a4a4a; }
        form { display:grid; grid-template-columns:1fr auto; gap:10px; margin-bottom:16px; }
        input[type="text"] { padding: 12px; font-size: 1em; border:1px solid #cfd8dc; border-radius:8px; width:100%; box-sizing:border-box; }
        button { padding: 12px 18px; font-size: 1em; background:#006837; color:#fff; border:none; border-radius:8px; cursor:pointer; white-space:nowrap; }
        button:hover { background:#004d2c; }
        .resultado { text-align:left; margin-top:10px; }
        .resultado p { margin:8px 0; }
        .admitido  { color:#006837; font-weight:700; }
        .pendiente { color:#b30000; font-weight:700; }
        .mensaje { margin-top:14px; background:#f1f8f4; border-left:4px solid #006837; padding:12px 14px; border-radius:6px; }
        .mensaje a { color:#006837; text-decoration:none; } 
        .mensaje a:hover { text-decoration:underline; }

        @media (max-width: 600px) {
            .container { margin: 20px; padding: 16px; }
            header img { width: 110px; }
            h2 { font-size: 1.2em; }
            form { grid-template-columns: 1fr; }
            button { width: 100%; }
        }
    </style>
</head>
<body>
    <div class="container">
        <header>
            <img src="https://utsem.edomex.gob.mx/sites/utsem.edomex.gob.mx/files/images/Imagenes%20de%20pie/LOGO_UTSEM.png" alt="UTSEM Logo">
        </header>
        <h2>Consulta de Resultados - Licenciatura en Medicina Veterinaria y Zootecnia</h2>
        <p class="tagline"><em>“Nuestro compromiso, la Excelencia Educativa”</em></p>

        <form method="post" autocomplete="off" novalidate>
            <input type="text" name="matricula" placeholder="Ingresa tu matrícula" required>
            <button type="submit">Consultar</button>
        </form>

        <div class="resultado"><?php echo $mensaje; ?></div>
    </div>
</body>
</html>
