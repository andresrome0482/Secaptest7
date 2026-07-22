<?php
require_once __DIR__ . '/../src/helpers.php';
session_start();

$userId = $_SESSION['moodle_userid'] ?? null;
if (!$userId) {
    header('Location: index.php');
    exit;
}

$apiKey      = env_required('DIDIT_API_KEY');
$workflowId  = env_required('DIDIT_WORKFLOW_ID');
$apiBaseUrl  = env_optional('DIDIT_API_BASE_URL', 'https://verification.didit.me/v3');
$publicUrl   = env_required('PUBLIC_BASE_URL');

$callbackUrl = rtrim($publicUrl, '/') . '/callback.php';

try {
    $session = didit_create_session($apiKey, $apiBaseUrl, $workflowId, (string) $userId, $callbackUrl);
    log_event('Sesión Didit creada', ['userid' => $userId, 'session_id' => $session['session_id']]);

    header('Location: ' . $session['url'], true, 302);
    exit;
} catch (Throwable $e) {
    log_event('Error creando sesión Didit', ['userid' => $userId, 'error' => $e->getMessage()]);
    http_response_code(502);
    echo 'No fue posible iniciar la verificación de identidad. Intenta de nuevo en unos minutos.';
    exit;
}
