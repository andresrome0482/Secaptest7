<?php
require_once __DIR__ . '/../src/helpers.php';

// --- Leer el cuerpo RAW exacto (necesario para validar la firma) -----------
$rawBody = file_get_contents('php://input');
$signature = $_SERVER['HTTP_X_SIGNATURE_V2'] ?? null;

$webhookSecret = env_required('DIDIT_WEBHOOK_SECRET');

if (!didit_verify_signature($rawBody, $signature, $webhookSecret)) {
        log_event('Firma de webhook inválida', [
                          'signature_present' => (bool) $signature,
                          'body_bytes' => strlen($rawBody),
                          'content_length_header' => $_SERVER['CONTENT_LENGTH'] ?? null,
                          'post_max_size' => ini_get('post_max_size'),
                      ]);
        http_response_code(401);
        echo 'Invalid signature';
        exit;
}

$payload = json_decode($rawBody, true);
if (!is_array($payload)) {
        http_response_code(400);
        echo 'Malformed payload';
        exit;
}

$status     = $payload['status'] ?? null;
$vendorData = $payload['vendor_data'] ?? null; // aquí viaja el id de usuario de Moodle
$sessionId  = $payload['session_id'] ?? null;

log_event('Webhook recibido', ['status' => $status, 'vendor_data' => $vendorData, 'session_id' => $sessionId, 'body_bytes' => strlen($rawBody)]);

if ($status !== 'Approved' || !$vendorData || !ctype_digit((string) $vendorData)) {
        http_response_code(200);
        echo 'Ignored (status=' . ($status ?? 'null') . ')';
        exit;
}

$moodleBaseUrl = env_required('MOODLE_BASE_URL');
$moodleToken   = env_required('MOODLE_WS_TOKEN');
$userId        = (int) $vendorData;

// Ventana de sesión: cuánto tiempo queda desuspendida la cuenta antes de que
// el cron de re-suspensión la bloquee de nuevo, forzando una nueva
// verificación en el siguiente inicio de sesión.
$sessionMinutes = (int) env_optional('SESSION_WINDOW_MINUTES', '15');
$expiresAt = time() + ($sessionMinutes * 60);

try {
        moodle_update_user($moodleBaseUrl, $moodleToken, $userId, ['suspended' => 0], [
                                   'diditsessionexpiry' => $expiresAt,
                               ]);
        log_event('Usuario activado en Moodle', ['userid' => $userId, 'session_id' => $sessionId, 'expires_at' => $expiresAt]);
        http_response_code(200);
        echo 'OK';
} catch (Throwable $e) {
        log_event('Error activando usuario en Moodle', ['userid' => $userId, 'error' => $e->getMessage()]);
        http_response_code(500);
        echo 'Error updating Moodle user';
}
