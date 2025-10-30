<?php
// =============================
// DEPURACIÓN Y CONFIGURACIÓN
// =============================
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/error_log.txt');

// =============================
// VALIDACIÓN DEL MÉTODO
// =============================
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    die('Error: Método no permitido. Use POST.');
}

// =============================
// AUTOLOAD PHPMailer
// =============================
$autoloadPath = __DIR__ . '/vendor/autoload.php';
if (!file_exists($autoloadPath)) {
    die('Error: Falta vendor/autoload.php. Ejecuta "composer install" o "composer require phpmailer/phpmailer".');
}
require $autoloadPath;

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// =============================
// CAPTURA Y LIMPIEZA DE DATOS
// =============================
function limpiar($campo) {
    return htmlspecialchars(trim($campo), ENT_QUOTES, 'UTF-8');
}

$quien_soy = limpiar($_POST['quien_soy'] ?? '');
$tipo_documento = limpiar($_POST['tipo_documento'] ?? '');
$numero_documento = limpiar($_POST['numero_documento'] ?? '');
$nombres_apellidos = limpiar($_POST['nombres_apellidos'] ?? '');
$telefono = limpiar($_POST['telefono'] ?? '');
$correo = filter_var($_POST['correo'] ?? '', FILTER_SANITIZE_EMAIL);
$domicilio = limpiar($_POST['domicilio'] ?? '');
$departamento = limpiar($_POST['departamento'] ?? '');
$provincia = limpiar($_POST['provincia'] ?? '');
$distrito = limpiar($_POST['distrito'] ?? '');
$tipo_bien = limpiar($_POST['tipo_bien'] ?? '');
$moneda = limpiar($_POST['moneda'] ?? '');
$monto_reclamado = limpiar($_POST['monto_reclamado'] ?? '');
$descripcion_bien = limpiar($_POST['descripcion_bien'] ?? '');
$tipo_incidente = limpiar($_POST['tipo_incidente'] ?? 'Reclamo');
$fecha_incidencia = limpiar($_POST['fecha_incidencia'] ?? '');
$detalle_reclamo = limpiar($_POST['detalle_reclamo'] ?? '');
$solicitud = limpiar($_POST['solicitud'] ?? '');
$anio = date('Y');

// =============================
// VALIDAR CAMPOS OBLIGATORIOS
// =============================
$campos_obligatorios = [
    'quien_soy' => $quien_soy,
    'tipo_documento' => $tipo_documento,
    'numero_documento' => $numero_documento,
    'nombres_apellidos' => $nombres_apellidos,
    'telefono' => $telefono,
    'correo' => $correo,
    'domicilio' => $domicilio,
    'departamento' => $departamento,
    'provincia' => $provincia,
    'distrito' => $distrito,
    'tipo_bien' => $tipo_bien,
    'descripcion_bien' => $descripcion_bien,
    'tipo_incidente' => $tipo_incidente,
    'detalle_reclamo' => $detalle_reclamo,
    'solicitud' => $solicitud
];

foreach ($campos_obligatorios as $campo => $valor) {
    if (empty($valor)) {
        http_response_code(400);
        die("Error: Falta el campo requerido: $campo");
    }
}

if (!filter_var($correo, FILTER_VALIDATE_EMAIL)) {
    http_response_code(400);
    die("Error: Correo electrónico inválido.");
}

// =============================
// GENERAR CÓDIGO ÚNICO
// =============================
$contadorArchivo = ($tipo_incidente === 'Queja') ? 'contador_quejas.txt' : 'contador_reclamos.txt';
$prefijo = ($tipo_incidente === 'Queja') ? 'Q' : 'R';

if (!file_exists($contadorArchivo)) file_put_contents($contadorArchivo, "1");

$numeroActual = (int)file_get_contents($contadorArchivo);
file_put_contents($contadorArchivo, $numeroActual + 1);

$numeroCodigo = $prefijo . str_pad($numeroActual, 3, '0', STR_PAD_LEFT) . " - $anio";

// =============================
// GUARDAR EN JSON
// =============================
$registro = [
    'fecha_envio' => date('Y-m-d H:i:s'),
    'codigo' => $numeroCodigo,
    'quien_soy' => $quien_soy,
    'tipo_documento' => $tipo_documento,
    'numero_documento' => $numero_documento,
    'nombres_apellidos' => $nombres_apellidos,
    'telefono' => $telefono,
    'correo' => $correo,
    'domicilio' => $domicilio,
    'departamento' => $departamento,
    'provincia' => $provincia,
    'distrito' => $distrito,
    'tipo_bien' => $tipo_bien,
    'moneda' => $moneda,
    'monto_reclamado' => $monto_reclamado,
    'descripcion_bien' => $descripcion_bien,
    'tipo_incidente' => $tipo_incidente,
    'fecha_incidencia' => $fecha_incidencia,
    'detalle_reclamo' => $detalle_reclamo,
    'solicitud' => $solicitud
];

$file = 'reclamos.json';
$reclamos = file_exists($file) ? json_decode(file_get_contents($file), true) : [];
$reclamos[] = $registro;
file_put_contents($file, json_encode($reclamos, JSON_PRETTY_PRINT));

// =============================
// GUARDAR ARCHIVOS ADJUNTOS
// =============================
$destinoCarpeta = __DIR__ . '/Documentos-subidos/';
if (!is_dir($destinoCarpeta)) mkdir($destinoCarpeta, 0777, true);

$archivosGuardados = [];
if (!empty($_FILES['archivos']['name'][0])) {
    foreach ($_FILES['archivos']['tmp_name'] as $key => $tmp_name) {
        if ($_FILES['archivos']['error'][$key] !== UPLOAD_ERR_OK) continue;
        $nombre = basename($_FILES['archivos']['name'][$key]);
        $rutaFinal = $destinoCarpeta . time() . "_" . $numeroCodigo . "_" . $nombre;
        if (move_uploaded_file($tmp_name, $rutaFinal)) {
            $archivosGuardados[] = $rutaFinal;
        }
    }
}

// =============================
// ENVIAR CORREOS CON PHPMailer
// =============================
$mail = new PHPMailer(true);
try {
    $mail->isSMTP();
    $mail->Host = 'smtp.resend.com';
    $mail->SMTPAuth = true;
    $mail->Username = 'resend';
    $mail->Password = 're_4CNykz21_cQZgu1uBhAE1HxsWNqg8kqpS'; // tu API key
    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
    $mail->Port = 587;
    $mail->CharSet = 'UTF-8';
    $mail->isHTML(true);

    // --- ENVÍO AL ADMIN ---
    $mail->setFrom('soporte@crecefinanzasestrategicas.pe', 'Libro de Reclamaciones');
    $mail->addAddress('ticketeraabc@gmail.com');
    $mail->Subject = "Nuevo $tipo_incidente - N° $numeroCodigo";
    $mail->Body = "
        <h2>Nuevo $tipo_incidente Recibido</h2>
        <p><strong>Código:</strong> $numeroCodigo</p>
        <p><strong>Cliente:</strong> $nombres_apellidos</p>
        <p><strong>Correo:</strong> $correo</p>
        <p><strong>Detalle:</strong><br>$detalle_reclamo</p>
        <p><strong>Solicitud:</strong><br>$solicitud</p>
        <p><strong>Fecha:</strong> " . date('Y-m-d H:i:s') . "</p>
    ";

    foreach ($archivosGuardados as $archivo) {
        if (file_exists($archivo)) {
            $mail->addAttachment($archivo, basename($archivo));
        }
    }

    $mail->send();

    // --- CONFIRMACIÓN AL USUARIO ---
    $mail->clearAddresses();
    $mail->clearAttachments();
    $mail->addAddress($correo);
    $mail->Subject = "Confirmación de recepción de $tipo_incidente - N° $numeroCodigo";
    $mail->Body = "
        <h2>Confirmación de Recepción</h2>
        <p>Estimado(a) $nombres_apellidos,</p>
        <p>Hemos recibido correctamente tu $tipo_incidente.</p>
        <p><strong>Código:</strong> $numeroCodigo</p>
        <p>Nos comunicaremos contigo pronto.</p>
        <p>Atentamente,<br>CRECE FINANZAS<br>Atención al Cliente</p>
    ";
    $mail->send();

    // --- RESPUESTA AL NAVEGADOR ---
    header('Content-Type: text/plain');
    echo $numeroCodigo;

} catch (Exception $e) {
    error_log("Error al enviar correo: {$mail->ErrorInfo}");
    http_response_code(500);
    die("Error al enviar correo: {$mail->ErrorInfo}");
}
?>
