<?php
$conexion = new mysqli("localhost", "root", "BpjysVrFK6LJ5XyW", "utsem_examen");
$mensaje = "";
$carrera = "";
$resultado = "";
$descripcion = "";

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $matricula = $conexion->real_escape_string($_POST["matricula"]);
    $sql = "SELECT carrera, resulatado, descripcion FROM resultados WHERE matricula = '$matricula'";
    $resultado_query = $conexion->query($sql);

    if ($resultado_query->num_rows > 0) {
        $row = $resultado_query->fetch_assoc();
        $carrera = $row["carrera"];
        $resultado = $row["resulatado"];
        $descripcion = $row["descripcion"];
    } else {
        $mensaje = "No se encontró la matrícula ingresada.";
    }
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Consulta de Resultados - UTSEM</title>
    <style>
        body {
            font-family: 'Segoe UI', sans-serif;
            background-color: #f9f9f9;
            color: #333;
            margin: 0;
            padding: 0;
        }
        .container {
            max-width: 600px;
            margin: 40px auto;
            padding: 20px;
            background: white;
            border-radius: 10px;
            box-shadow: 0 0 10px rgba(0,0,0,0.1);
            text-align: center;
        }
        header img {
            width: 120px;
            margin-bottom: 10px;
        }
        h2 {
            color: #006837;
        }
        input, button {
            padding: 12px;
            font-size: 1em;
            margin: 10px 0;
            width: 100%;
            max-width: 100%;
            box-sizing: border-box;
            border: 1px solid #ccc;
            border-radius: 5px;
        }
        button {
            background-color: #006837;
            color: white;
            border: none;
            cursor: pointer;
        }
        button:hover {
            background-color: #004d2c;
        }
        .admitido { color: #006837; font-weight: bold; }
        .pendiente { color: #b30000; font-weight: bold; }
        .mensaje {
            margin-top: 20px;
        }

        @media (max-width: 600px) {
            .container {
                margin: 20px;
                padding: 15px;
            }
            header img {
                width: 100px;
            }
            h2 {
                font-size: 1.2em;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <header>
            <img src="https://utsem.edomex.gob.mx/sites/utsem.edomex.gob.mx/files/images/Imagenes%20de%20pie/LOGO_UTSEM.png" alt="Logo UTSEM">
            <h2>Consulta de Resultados - Segunda Ronda de Examen de Ingreso</h2>
        </header>
        <form method="post">
            <input type="text" name="matricula" placeholder="Ingresa tu matrícula" required>
            <button type="submit">Consultar</button>
        </form>

        <?php if (!empty($mensaje)) { echo "<p class='mensaje'>$mensaje</p>"; } ?>

        <?php if (!empty($resultado)) { ?>
            <div class="mensaje">
                <p><strong>Carrera:</strong> <?php echo $carrera; ?></p>
                <p><strong>Resultado:</strong> 
                    <span class="<?php echo strtolower($resultado); ?>">
                        <?php echo $resultado; ?>
                    </span>
                </p>
                <?php if (strtolower($resultado) == "pendiente") { ?>
                    <p><?php echo $descripcion; ?></p>
                <?php } ?>
            </div>
        <?php } ?>
    </div>
</body>
</html>
