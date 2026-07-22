<?php
session_start();

$status = $_GET['status'] ?? '';
$moodleBaseUrl = getenv('MOODLE_BASE_URL') ?: '#';
$moodleLoginUrl = rtrim($moodleBaseUrl, '/') . '/login/index.php';

$isApproved = ($status === 'Approved');
$title = $isApproved ? '¡Identidad verificada!' : 'Verificación en revisión';
$message = $isApproved
    ? 'Tu identidad fue confirmada. Ya puedes iniciar sesión con tu usuario y contraseña habituales.'
    : 'Tu verificación quedó en revisión o no se completó. Si crees que es un error, contacta al soporte de la plataforma.';

// Limpiar la sesión del portal: cada inicio de sesión requiere repetir el
// proceso desde index.php.
unset($_SESSION['moodle_userid'], $_SESSION['moodle_identifier']);
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= htmlspecialchars($title) ?></title>
<style>
  body { font-family: system-ui, sans-serif; background: #F2F4F7; margin: 0; display: flex; min-height: 100vh; align-items: center; justify-content: center; }
  .card { background: #fff; border-radius: 12px; padding: 40px; max-width: 440px; text-align: center; box-shadow: 0 1px 3px rgba(0,0,0,0.1); }
  h1 { color: #1B3358; font-size: 22px; margin-bottom: 12px; }
  p { color: #5A6472; line-height: 1.5; }
  a.button { display: inline-block; margin-top: 20px; background: #1B3358; color: #fff; text-decoration: none; padding: 10px 22px; border-radius: 8px; font-size: 14px; }
</style>
</head>
<body>
  <div class="card">
    <h1><?= htmlspecialchars($title) ?></h1>
    <p><?= htmlspecialchars($message) ?></p>
    <a class="button" href="<?= htmlspecialchars($moodleLoginUrl) ?>">Ir al inicio de sesión de Moodle</a>
  </div>
</body>
</html>
