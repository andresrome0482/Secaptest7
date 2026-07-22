<?php
require_once __DIR__ . '/../src/helpers.php';
session_start();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.php');
    exit;
}

$identifier = trim($_POST['identifier'] ?? '');
if ($identifier === '') {
    header('Location: index.php?error=notfound');
    exit;
}

$moodleBaseUrl = env_required('MOODLE_BASE_URL');
$moodleToken   = env_required('MOODLE_WS_TOKEN');

try {
    $user = moodle_find_user($moodleBaseUrl, $moodleToken, $identifier);
} catch (Throwable $e) {
    log_event('Error buscando usuario en Moodle', ['identifier' => $identifier, 'error' => $e->getMessage()]);

    // Modo diagnóstico opcional: con DEBUG=1 en las variables de entorno,
    // muestra el error real en pantalla en vez de solo en los logs.
    // Recuerda quitar DEBUG=1 en Render una vez resuelto el problema.
    if (env_optional('DEBUG', '0') === '1') {
        http_response_code(500);
        header('Content-Type: text/plain; charset=utf-8');
        echo "DEBUG activo — error real:\n\n" . $e->getMessage();
        exit;
    }

    header('Location: index.php?error=lookupfailed');
    exit;
}

if (!$user) {
    log_event('Usuario no encontrado', ['identifier' => $identifier]);
    header('Location: index.php?error=notfound');
    exit;
}

// Guardamos el userid identificado en la sesión del navegador (server-side,
// el cliente nunca ve ni puede manipular este valor) para que verify.php
// y callback.php sepan a quién corresponde esta verificación.
$_SESSION['moodle_userid'] = (int) $user['id'];
$_SESSION['moodle_identifier'] = $identifier;

header('Location: verify.php');
exit;
