<?php
/**
 * public/cron-resuspend.php
 *
 * Alternativa GRATUITA al Cron Job nativo de Render (que cobra un mínimo de
 * ~$1/mes). Este endpoint hace exactamente lo mismo que cli/resuspend.php,
 * pero se dispara por HTTP en vez de por un scheduler propio de Render.
 *
 * Debes llamarlo periódicamente (cada 5 minutos recomendado) desde un
 * disparador externo gratuito: GitHub Actions programado o cron-job.org.
 * Ver README.md, sección "Opción A (gratis)".
 *
 * Protegido por un secreto compartido (CRON_SECRET) para que nadie más
 * pueda invocarlo y forzar re-suspensiones arbitrarias.
 */

require_once __DIR__ . '/../src/helpers.php';

$expectedSecret = env_required('CRON_SECRET');
$providedSecret = $_GET['secret'] ?? ($_SERVER['HTTP_X_CRON_SECRET'] ?? '');

if (!hash_equals($expectedSecret, (string) $providedSecret)) {
    http_response_code(401);
    echo 'Unauthorized';
    exit;
}

$moodleBaseUrl = env_required('MOODLE_BASE_URL');
$moodleToken   = env_required('MOODLE_WS_TOKEN');

header('Content-Type: application/json');

try {
    $summary = run_resuspend_job($moodleBaseUrl, $moodleToken);
    echo json_encode(array_merge(['ok' => true], $summary));
} catch (Throwable $e) {
    log_event('cron-resuspend: error', ['error' => $e->getMessage()]);
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
