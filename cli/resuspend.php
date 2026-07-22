<?php
/**
 * cli/resuspend.php
 *
 * OPCIONAL — solo necesario si decides pagar un Cron Job nativo de Render
 * (mínimo ~$1/mes) en vez de la opción gratuita (public/cron-resuspend.php
 * + un pinger externo gratuito). Ver README.md, sección "Opción B".
 */

require_once __DIR__ . '/../src/helpers.php';

$moodleBaseUrl = env_required('MOODLE_BASE_URL');
$moodleToken   = env_required('MOODLE_WS_TOKEN');

try {
    $summary = run_resuspend_job($moodleBaseUrl, $moodleToken);
} catch (Throwable $e) {
    fwrite(STDERR, "Error listando usuarios: {$e->getMessage()}\n");
    exit(1);
}

echo "Revisados: {$summary['checked']} · Re-suspendidos: {$summary['resuspended']} · Errores: {$summary['errors']}\n";
exit($summary['errors'] > 0 ? 1 : 0);
