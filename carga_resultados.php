<?php
/**
 * Archivo: carga_resultados.php
 * Propósito: Cargar CSV (matricula, carrera, resultado, dia_inscripcion) a la tabla `resultados`.
 * Características claves:
 *  - Detecta delimitador automáticamente (coma, punto y coma, tab, barra).
 *  - Convierte todo a UTF-8 (respeta acentos y "ñ").
 *  - Acepta CABECERAS flexibles: orden distinto, acentos, sin acentos, sinónimos (estatus/estado/status, resulatado, fecha/día inscripción).
 *  - Inserta o actualiza por matrícula (sin duplicados) con ON DUPLICATE KEY UPDATE.
 *  - NO sobreescribe dia_inscripcion si viene vacío/NULL en la carga.
 *  - Muestra filas con error y permite descargar CSV de errores.
 *  - Muestra conteo: nuevos, actualizados y con error.
 *
 * Requisito: `resultados.matricula` debe ser PRIMARY KEY o UNIQUE.
 * Tabla destino (sugerida):
 *   CREATE TABLE resultados (
 *     matricula VARCHAR(50) PRIMARY KEY,
 *     carrera   VARCHAR(255) NOT NULL,
 *     resultado VARCHAR(50)  NOT NULL,
 *     dia_inscripcion DATE NULL
 *   ) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
 */

// ====== CONFIG DB ======
$host = "localhost";
$user = "root";
$password = "BpjysVrFK6LJ5XyW";
$dbname = "utsem_examen";

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$conn = new mysqli($host, $user, $password, $dbname);
$conn->set_charset("utf8mb4"); // UTF-8 real

$flash = "";
$errores = []; // filas con error: ['linea','motivo','matricula','carrera','resultado','dia_inscripcion']

// ====== HELPERS ======
function a_utf8($s) {
    if ($s === null) return '';
    $enc = mb_detect_encoding($s, ['UTF-8','Windows-1252','ISO-8859-1','ISO-8859-15'], true);
    if ($enc === false) $enc = 'UTF-8';
    if ($enc !== 'UTF-8') {
        $s = mb_convert_encoding($s, 'UTF-8', $enc);
    }
    return $s;
}
function quitar_bom($s) {
    if (strncmp($s, "\xEF\xBB\xBF", 3) === 0) {
        return substr($s, 3);
    }
    return $s;
}
function limpiarHeader($s) {
    $s = a_utf8($s);
    $s = quitar_bom($s);
    return trim($s);
}
function h($s){ return htmlspecialchars($s ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

// Normaliza clave: minúsculas, sin acentos ni caracteres no alfanuméricos
function normalizar_clave($s) {
    $s = a_utf8($s);
    $s = quitar_bom($s);
    $s = mb_strtolower($s, 'UTF-8');
    $s = strtr($s, [
        'á'=>'a','é'=>'e','í'=>'i','ó'=>'o','ú'=>'u','ü'=>'u','ñ'=>'n',
        'Á'=>'a','É'=>'e','Í'=>'i','Ó'=>'o','Ú'=>'u','Ü'=>'u','Ñ'=>'n'
    ]);
    // quitar todo lo que no sea a-z 0-9
    $s = preg_replace('/[^a-z0-9]/u', '', $s);
    return $s;
}

/**
 * Intenta mapear cabeceras flexibles a índices: matricula, carrera, resultado, dia_inscripcion
 * Sinónimos contemplados: resultado -> resulatado/estatus/estado/status
 * dia_inscripcion -> fecha/fechainscripcion/dia/diainscripcion/fecha_insc/fecha_inscripcion
 */
function mapear_cabeceras($headers) {
    $map = ['matricula'=>null, 'carrera'=>null, 'resultado'=>null, 'dia_inscripcion'=>null];
    $norms = [];
    foreach ($headers as $i => $h) {
        $norms[$i] = normalizar_clave($h);
    }
    // 1) Exactos
    foreach ($norms as $i => $n) {
        if ($n === 'matricula')        $map['matricula'] = $i;
        if ($n === 'carrera')          $map['carrera']   = $i;
        if ($n === 'resultado')        $map['resultado'] = $i;
        if ($n === 'diainscripcion')   $map['dia_inscripcion'] = $i;
    }
    // 2) Sinónimos comunes
    foreach ($norms as $i => $n) {
        if ($map['resultado'] === null && in_array($n, ['resulatado','estatus','estado','status'], true)) {
            $map['resultado'] = $i;
        }
        if ($map['dia_inscripcion'] === null && in_array($n, ['fecha','fechainscripcion','dia','fecha_insc','fechainsc','fechainscrip','fechainscripcion'], true)) {
            $map['dia_inscripcion'] = $i;
        }
    }
    // 3) Por contiene (heurística)
    if ($map['matricula'] === null) {
        foreach ($norms as $i => $n) { if (strpos($n, 'matricul') !== false) { $map['matricula'] = $i; break; } }
    }
    if ($map['carrera'] === null) {
        foreach ($norms as $i => $n) {
            if (strpos($n, 'carrera') !== false || strpos($n, 'programa') !== false || strpos($n, 'licenciatura') !== false) { $map['carrera'] = $i; break; }
        }
    }
    if ($map['resultado'] === null) {
        foreach ($norms as $i => $n) {
            if (strpos($n, 'resultad') !== false || strpos($n, 'estatus') !== false || strpos($n, 'estado') !== false || strpos($n, 'status') !== false) { $map['resultado'] = $i; break; }
        }
    }
    if ($map['dia_inscripcion'] === null) {
        foreach ($norms as $i => $n) {
            if (strpos($n, 'fecha') !== false || strpos($n, 'dia') !== false) { $map['dia_inscripcion'] = $i; break; }
        }
    }
    return [$map, $norms];
}

// Detecta delimitador a partir de la primera línea no vacía
function detectar_delimitador($file) {
    $fh = fopen($file, 'r');
    if ($fh === false) return ',';
    $delims = [',',';',"\t",'|'];
    $line = '';
    while (!feof($fh)) {
        $line = fgets($fh);
        if ($line !== false && trim($line) !== '') break;
    }
    fclose($fh);
    if ($line === false) return ',';
    $best = ','; $bestCount = -1;
    foreach ($delims as $d) {
        $c = substr_count($line, $d);
        if ($c > $bestCount) { $bestCount = $c; $best = $d; }
    }
    return $best;
}

/**
 * Normaliza una fecha cualquiera a formato SQL YYYY-MM-DD.
 * Acepta:
 *   - YYYY-MM-DD (pasa directo si válida)
 *   - DD/MM/YYYY o DD-MM-YYYY (convierte a YYYY-MM-DD)
 * Devuelve string YYYY-MM-DD o NULL si no válida o vacía.
 */
function normalizar_fecha_sql($s) {
    $s = trim((string)$s);
    if ($s === '') return null;

    // 1) ISO directo
    if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $s, $m)) {
        if (checkdate((int)$m[2], (int)$m[3], (int)$m[1])) return $s;
        return null;
    }

    // 2) DD/MM/YYYY o DD-MM-YYYY
    if (preg_match('/^(\d{2})[\/\-](\d{2})[\/\-](\d{4})$/', $s, $m)) {
        $d = (int)$m[1]; $mo = (int)$m[2]; $y = (int)$m[3];
        if (checkdate($mo, $d, $y)) return sprintf('%04d-%02d-%02d', $y, $mo, $d);
        return null;
    }

    // 3) Último intento con DateTime parser (puede fallar en strings ambiguos)
    try {
        $dt = new DateTime($s);
        return $dt->format('Y-m-d');
    } catch (Exception $e) {
        return null;
    }
}

// ====== DESCARGAR PLANTILLA ======
if (isset($_GET['plantilla'])) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=plantilla_resultados.csv');
    echo "\xEF\xBB\xBF"; // BOM para Excel
    $out = fopen('php://output', 'w');
    // Encabezados
    fputcsv($out, ['matricula', 'carrera', 'resultado', 'dia_inscripcion']);
    // Ejemplos (puedes cambiarlos o quitarlos)
    fputcsv($out, ['2025001', 'Licenciatura en Medicina Veterinaria y Zootecnia', 'Admitido',  '2025-09-06']);
    fputcsv($out, ['2025002', 'Técnico Superior Universitario en Contaduría',    'Pendiente', '']);
    fputcsv($out, ['2025003', 'Licenciatura en Enfermería',                      'Admitido',  '05/09/2025']); // Acepta DD/MM/YYYY
    fclose($out);
    exit;
}

// ====== DESCARGAR ERRORES ======
if (isset($_POST['descargar_errores']) && isset($_POST['errores_json'])) {
    $errores = json_decode($_POST['errores_json'], true) ?: [];
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=errores_carga_resultados.csv');
    echo "\xEF\xBB\xBF";
    $out = fopen('php://output', 'w');
    fputcsv($out, ['linea', 'motivo', 'matricula', 'carrera', 'resultado', 'dia_inscripcion']);
    foreach ($errores as $e) {
        fputcsv($out, [
            $e['linea'] ?? '',
            $e['motivo'] ?? '',
            $e['matricula'] ?? '',
            $e['carrera'] ?? '',
            $e['resultado'] ?? '',
            $e['dia_inscripcion'] ?? '',
        ]);
    }
    fclose($out);
    exit;
}

// ====== PROCESAR CSV ======
$nuevos = null; $actualizados = null; $conError = null;
$debug_headers = null; // para mostrar al usuario qué cabeceras recibió
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['archivo']) && !isset($_POST['descargar_errores'])) {
    try {
        if ($_FILES['archivo']['error'] !== UPLOAD_ERR_OK) {
            throw new RuntimeException("Error al subir el archivo (código {$_FILES['archivo']['error']}).");
        }
        $tmp = $_FILES['archivo']['tmp_name'];
        if (!is_uploaded_file($tmp)) {
            throw new RuntimeException("El archivo no es válido.");
        }

        $delim = detectar_delimitador($tmp);
        $hfile = fopen($tmp, 'r');
        if ($hfile === false) {
            throw new RuntimeException("No se pudo leer el archivo.");
        }

        // Cabecera original (guardamos para debug)
        $headerRaw = fgetcsv($hfile, 0, $delim);
        if ($headerRaw === false) {
            fclose($hfile);
            throw new RuntimeException("El archivo está vacío.");
        }
        $debug_headers = $headerRaw;
        $header = array_map('limpiarHeader', $headerRaw);
        list($map, $norms) = mapear_cabeceras($header);

        if ($map['matricula'] === null || $map['carrera'] === null || $map['resultado'] === null) {
            fclose($hfile);
            $recibidas = [];
            foreach ($header as $i => $hval) {
                $recibidas[] = h($hval) . " (→ " . h($norms[$i]) . ")";
            }
            $detalle = implode(', ', $recibidas);
            throw new RuntimeException("Las columnas no coinciden con lo esperado. Se requieren al menos: matricula, carrera, resultado. (Opcional: dia_inscripcion). Recibidas: {$detalle}. Descarga la plantilla.");
        }

        // Preparar UPSERT (NO sobreescribe dia_inscripcion si viene NULL)
        $sql = "INSERT INTO resultados (matricula, carrera, resultado, dia_inscripcion)
                VALUES (?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                    carrera = VALUES(carrera),
                    resultado = VALUES(resultado),
                    dia_inscripcion = IFNULL(VALUES(dia_inscripcion), resultados.dia_inscripcion)";
        $stmt = $conn->prepare($sql);

        $nuevos = 0; $actualizados = 0; $conError = 0;
        $linea = 2; // primera línea de datos (la 1 es cabecera)

        $conn->begin_transaction();
        try {
            while (($row = fgetcsv($hfile, 0, $delim)) !== false) {
                // Fila vacía
                $vacia = true;
                foreach ($row as $cell) { if (trim((string)$cell) !== '') { $vacia = false; break; } }
                if ($vacia) { $linea++; continue; }

                // Tomar valores por índices mapeados
                $matricula = isset($row[$map['matricula']]) ? trim(a_utf8($row[$map['matricula']])) : '';
                $carrera   = isset($row[$map['carrera']])   ? trim(a_utf8($row[$map['carrera']]))   : '';
                $resultado = isset($row[$map['resultado']]) ? trim(a_utf8($row[$map['resultado']])) : '';
                $dia_insc_raw = ($map['dia_inscripcion'] !== null && isset($row[$map['dia_inscripcion']]))
                                ? trim(a_utf8($row[$map['dia_inscripcion']])) : '';

                // Validaciones básicas
                if ($matricula === '' || $carrera === '' || $resultado === '') {
                    $conError++;
                    $errores[] = [
                        'linea' => $linea,
                        'motivo' => 'Campos vacíos (matricula/carrera/resultado)',
                        'matricula' => $matricula,
                        'carrera'   => $carrera,
                        'resultado' => $resultado,
                        'dia_inscripcion' => $dia_insc_raw,
                    ];
                    $linea++;
                    continue;
                }

                // Normalizar fecha (permite vacía)
                $dia_sql = normalizar_fecha_sql($dia_insc_raw); // null si inválida o vacía

                // Si dieron fecha pero es inválida -> error
                if ($dia_insc_raw !== '' && $dia_sql === null) {
                    $conError++;
                    $errores[] = [
                        'linea' => $linea,
                        'motivo' => 'Fecha inválida (use YYYY-MM-DD o DD/MM/YYYY)',
                        'matricula' => $matricula,
                        'carrera'   => $carrera,
                        'resultado' => $resultado,
                        'dia_inscripcion' => $dia_insc_raw,
                    ];
                    $linea++;
                    continue;
                }

                // Bind
                // Si $dia_sql es null, mandamos NULL real para que NO sobreescriba (gracias al IFNULL en el ON DUPLICATE)
                $stmt->bind_param("ssss", $matricula, $carrera, $resultado, $dia_sql);
                if ($stmt->execute()) {
                    // affected_rows: 1 => insert; 2 => update
                    if ($stmt->affected_rows === 1) {
                        $nuevos++;
                    } elseif ($stmt->affected_rows === 2) {
                        $actualizados++;
                    }
                } else {
                    $conError++;
                    $motivo = 'Error al insertar/actualizar';
                    $errores[] = [
                        'linea' => $linea,
                        'motivo' => $motivo,
                        'matricula' => $matricula,
                        'carrera'   => $carrera,
                        'resultado' => $resultado,
                        'dia_inscripcion' => $dia_insc_raw,
                    ];
                }
                $linea++;
            }
            $conn->commit();
        } catch (Throwable $e) {
            $conn->rollback();
            fclose($hfile);
            throw $e;
        }

        fclose($hfile);
        $stmt->close();

        $flash  = "<p class='ok'>Nuevos: <strong>".h((string)$nuevos)."</strong>. ";
        $flash .= "Actualizados: <strong>".h((string)$actualizados)."</strong>. ";
        $flash .= "Con error: <strong>".h((string)$conError)."</strong>.</p>";

    } catch (Throwable $ex) {
        $flash = "<p class='error'>".h($ex->getMessage())."</p>";
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Carga de Resultados (CSV) - UTSEM</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<style>
    :root{ --green:#006837; --green-dark:#004d2c; --bg:#f9f9f9; --text:#333; }
    *{box-sizing:border-box}
    body{margin:0;font-family:system-ui,-apple-system,"Segoe UI",Roboto,Arial,sans-serif;background:var(--bg);color:var(--text)}
    .wrap{max-width:1000px;margin:40px auto;background:#fff;border-radius:14px;box-shadow:0 10px 28px rgba(0,0,0,.08);padding:24px}
    header{display:flex;flex-direction:column;align-items:center;text-align:center}
    header img{width:160px;margin-bottom:10px}
    h1{color:var(--green);margin:.25rem 0 0 0;font-size:1.5rem}
    p.sub{margin:.25rem 0 1rem 0;color:#555}
    .row{display:flex;gap:14px;flex-wrap:wrap;align-items:center;justify-content:center;margin-bottom:16px}
    a.btn, button.btn{display:inline-block;padding:12px 16px;border-radius:10px;border:1px solid var(--green);background:var(--green);color:#fff;text-decoration:none;font-weight:600;cursor:pointer}
    a.btn:hover, button.btn:hover{background:var(--green-dark);border-color:var(--green-dark)}
    .panel{background:#f6fbf8;border-left:4px solid var(--green);padding:12px 14px;border-radius:8px;margin:10px 0}
    form.upload{display:grid;gap:10px;grid-template-columns:1fr auto}
    input[type="file"]{padding:10px;border:1px solid #cfd8dc;border-radius:10px;background:#fff}
    .flash{margin-top:12px}
    .flash .ok{color:var(--green);font-weight:700}
    .flash .error{color:#b30000;font-weight:700}
    table{width:100%;border-collapse:collapse;margin-top:16px}
    th, td{border:1px solid #e0e0e0;padding:8px;text-align:left;font-size:.95rem}
    th{background:#eef7f1}
    .muted{color:#666}
    footer{margin-top:18px;text-align:center;color:#666;font-size:.9rem}
    @media (max-width:600px){form.upload{grid-template-columns:1fr} .wrap{margin:20px} header img{width:130px} table{display:block;overflow:auto;white-space:nowrap}}
</style>
</head>
<body>
<div class="wrap">
    <header>
        <img src="https://utsem.edomex.gob.mx/sites/utsem.edomex.gob.mx/files/images/Imagenes%20de%20pie/LOGO_UTSEM.png" alt="UTSEM">
        <h1>Carga de Resultados (CSV)</h1>
        <p class="sub"><em>“Nuestro compromiso, la Excelencia Educativa”</em></p>
    </header>

    <div class="panel">
        <strong>Instrucciones:</strong>
        <ol>
            <li>Descarga la plantilla CSV y ábrela en Excel/LibreOffice.</li>
            <li>Llena las columnas (en cualquier orden): <code>matricula</code>, <code>carrera</code>, <code>resultado</code>, <code>dia_inscripcion</code> (opcional).</li>
            <li>Formato de fecha recomendado: <code>YYYY-MM-DD</code> (ej. <code>2025-09-05</code>). También se acepta <code>DD/MM/YYYY</code> o <code>DD-MM-YYYY</code>.</li>
            <li>Guarda como CSV. El sistema detecta el separador y convierte a UTF-8.</li>
            <li>En actualizaciones: si dejas <code>dia_inscripcion</code> vacío, <strong>se conserva el valor existente</strong> en la base de datos.</li>
        </ol>
    </div>

    <div class="row">
        <a class="btn" href="?plantilla=1">Descargar plantilla</a>
    </div>

    <form class="upload" method="post" enctype="multipart/form-data" autocomplete="off">
        <input type="file" name="archivo" accept=".csv" required>
        <button class="btn" type="submit">Subir y procesar</button>
    </form>

    <div class="flash"><?php echo $flash; ?></div>

    <?php if (!empty($errores)): ?>
        <h2>Filas con error (<?php echo count($errores); ?>)</h2>
        <form method="post" style="margin-bottom:10px;">
            <input type="hidden" name="errores_json" value='<?php echo json_encode($errores, JSON_UNESCAPED_UNICODE|JSON_HEX_APOS|JSON_HEX_AMP|JSON_HEX_TAG); ?>'>
            <button class="btn" type="submit" name="descargar_errores" value="1">Descargar errores en CSV</button>
        </form>
        <table>
            <thead>
                <tr>
                    <th>Línea</th>
                    <th>Motivo</th>
                    <th>Matrícula</th>
                    <th>Carrera</th>
                    <th>Resultado</th>
                    <th>Día inscripción</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($errores as $e): ?>
                <tr>
                    <td><?php echo h((string)($e['linea'] ?? '')); ?></td>
                    <td><?php echo h((string)($e['motivo'] ?? '')); ?></td>
                    <td><?php echo h((string)($e['matricula'] ?? '')); ?></td>
                    <td><?php echo h((string)($e['carrera'] ?? '')); ?></td>
                    <td><?php echo h((string)($e['resultado'] ?? '')); ?></td>
                    <td><?php echo h((string)($e['dia_inscripcion'] ?? '')); ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>

    <footer>
        Tabla destino: <code>resultados(matricula, carrera, resultado, dia_inscripcion)</code>. Asegura que <code>matricula</code> sea UNIQUE o PRIMARY KEY.
    </footer>
</div>
</body>
</html>
