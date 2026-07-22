<?php
session_start();

$error = $_GET['error'] ?? '';
$errorMessages = [
    'notfound' => 'No encontramos ninguna cuenta con ese usuario o correo.',
    'lookupfailed' => 'No pudimos validar tus datos en este momento. Intenta de nuevo en unos minutos.',
];
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Verificación de identidad · Acceso a la plataforma</title>
<style>
  body { font-family: system-ui, sans-serif; background: #F2F4F7; margin: 0; display: flex; min-height: 100vh; align-items: center; justify-content: center; }
  .card { background: #fff; border-radius: 12px; padding: 40px; max-width: 420px; width: 100%; box-shadow: 0 1px 3px rgba(0,0,0,0.1); }
  h1 { color: #1B3358; font-size: 22px; margin: 0 0 8px; }
  p.sub { color: #5A6472; margin: 0 0 24px; line-height: 1.5; font-size: 14px; }
  label { display: block; font-size: 13px; color: #1B3358; font-weight: 600; margin-bottom: 6px; }
  input[type=text] { width: 100%; box-sizing: border-box; padding: 11px 12px; border: 1px solid #D6DBE2; border-radius: 8px; font-size: 15px; margin-bottom: 18px; }
  button { width: 100%; background: #1B3358; color: #fff; border: none; padding: 12px; border-radius: 8px; font-size: 15px; font-weight: 600; cursor: pointer; }
  button:hover { background: #12233D; }
  .error { background: #FBE9E9; color: #C1272D; padding: 10px 14px; border-radius: 8px; font-size: 13px; margin-bottom: 18px; }
  .note { font-size: 12px; color: #8A93A0; margin-top: 20px; line-height: 1.5; }
</style>
</head>
<body>
  <div class="card">
    <h1>Verifica tu identidad</h1>
    <p class="sub">Antes de iniciar sesión, confirma tu usuario o correo de la plataforma. Te pediremos tu documento y una selfie para validar que eres tú.</p>

    <?php if ($error && isset($errorMessages[$error])): ?>
      <div class="error"><?= htmlspecialchars($errorMessages[$error]) ?></div>
    <?php endif; ?>

    <form action="start.php" method="post">
      <label for="identifier">Usuario o correo electrónico</label>
      <input type="text" id="identifier" name="identifier" autocomplete="username" required>
      <button type="submit">Continuar con la verificación</button>
    </form>

    <p class="note">Tu documento y selfie se procesan de forma segura a través de Didit. No almacenamos esas imágenes en este portal.</p>
  </div>
</body>
</html>
