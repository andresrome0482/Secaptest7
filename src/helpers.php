<?php
/**
 * Funciones compartidas por verify.php y webhook.php.
 * Todas las credenciales se leen de variables de entorno — nunca hardcodeadas.
 */

function env_required(string $name): string {
    $value = getenv($name);
    if ($value === false || $value === '') {
        http_response_code(500);
        error_log("Falta la variable de entorno requerida: {$name}");
        exit("Server misconfigured: missing {$name}");
    }
    return $value;
}

function env_optional(string $name, string $default = ''): string {
    $value = getenv($name);
    return ($value === false || $value === '') ? $default : $value;
}

/**
 * Verifica la firma HMAC-SHA256 enviada por Didit en el header X-Signature-V2.
 * Se firma sobre el cuerpo RAW exacto recibido (sin re-serializar el JSON).
 */
function didit_verify_signature(string $rawBody, ?string $signatureHeader, string $secret): bool {
    if (empty($signatureHeader) || empty($secret)) {
        return false;
    }
    $computed = hash_hmac('sha256', $rawBody, $secret);
    return hash_equals($computed, $signatureHeader);
}

/**
 * Crea una sesión de verificación en Didit.
 * Devuelve el array decodificado de la respuesta (incluye "url" y "session_id").
 *
 * @throws RuntimeException si la llamada falla
 */
function didit_create_session(string $apiKey, string $baseUrl, string $workflowId, string $vendorData, string $callbackUrl): array {
    $payload = json_encode([
        'workflow_id' => $workflowId,
        'vendor_data' => $vendorData,
        'callback'    => $callbackUrl,
    ]);

    $ch = curl_init(rtrim($baseUrl, '/') . '/session/');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Accept: application/json',
            'x-api-key: ' . $apiKey,
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($response === false) {
        throw new RuntimeException("Error de red al llamar a Didit: {$curlError}");
    }
    if ($httpCode < 200 || $httpCode >= 300) {
        throw new RuntimeException("Didit respondió HTTP {$httpCode}: {$response}");
    }

    $decoded = json_decode($response, true);
    if (!is_array($decoded) || empty($decoded['url']) || empty($decoded['session_id'])) {
        throw new RuntimeException("Respuesta inesperada de Didit: {$response}");
    }
    return $decoded;
}

/**
 * Actualiza un usuario de Moodle vía Web Services REST (core_user_update_users).
 * Requiere un token con la capability moodle/user:update sobre el sistema.
 *
 * @param array $fields Campos simples a actualizar, ej. ['suspended' => 0]
 * @param array $customFields Pares ['shortname' => 'valor'] para perfiles personalizados
 * @throws RuntimeException si la llamada falla o Moodle devuelve un error
 */
function moodle_update_user(string $moodleBaseUrl, string $wsToken, int $userId, array $fields, array $customFields = []): void {
    $userPayload = array_merge(['id' => $userId], $fields);

    if (!empty($customFields)) {
        $i = 0;
        foreach ($customFields as $shortname => $value) {
            $userPayload['customfields'][$i]['type'] = $shortname;
            $userPayload['customfields'][$i]['value'] = $value;
            $i++;
        }
    }

    $params = [
        'wstoken' => $wsToken,
        'wsfunction' => 'core_user_update_users',
        'moodlewsrestformat' => 'json',
        'users' => [$userPayload],
    ];

    $response = moodle_rest_call($moodleBaseUrl, $params);

    if (is_array($response) && isset($response['exception'])) {
        $msg = $response['message'] ?? 'Error desconocido de Moodle';
        throw new RuntimeException("Moodle rechazó la actualización: {$msg}");
    }
}

/**
 * Busca un usuario de Moodle por username o email (core_user_get_users_by_field).
 * Devuelve el array del usuario o null si no se encontró.
 */
function moodle_find_user(string $moodleBaseUrl, string $wsToken, string $identifier): ?array {
    $field = filter_var($identifier, FILTER_VALIDATE_EMAIL) ? 'email' : 'username';

    $params = [
        'wstoken' => $wsToken,
        'wsfunction' => 'core_user_get_users_by_field',
        'moodlewsrestformat' => 'json',
        'field' => $field,
        'values' => [$identifier],
    ];

    $response = moodle_rest_call($moodleBaseUrl, $params);

    if (is_array($response) && isset($response['exception'])) {
        $msg = $response['message'] ?? 'Error desconocido de Moodle';
        throw new RuntimeException("Moodle rechazó la búsqueda: {$msg}");
    }
    if (!is_array($response) || empty($response)) {
        return null;
    }
    return $response[0];
}

/**
 * Lista usuarios de Moodle que cumplen un criterio simple (core_user_get_users).
 * Usado por el cron de re-suspensión para encontrar cuentas actualmente
 * desuspendidas (suspended = 0) y revisar si su ventana de sesión expiró.
 */
function moodle_list_users_by_criteria(string $moodleBaseUrl, string $wsToken, string $key, string $value): array {
    $params = [
        'wstoken' => $wsToken,
        'wsfunction' => 'core_user_get_users',
        'moodlewsrestformat' => 'json',
        'criteria' => [
            ['key' => $key, 'value' => $value],
        ],
    ];

    $response = moodle_rest_call($moodleBaseUrl, $params);

    if (is_array($response) && isset($response['exception'])) {
        $msg = $response['message'] ?? 'Error desconocido de Moodle';
        throw new RuntimeException("Moodle rechazó la búsqueda: {$msg}");
    }
    return $response['users'] ?? [];
}

/**
 * Extrae el valor de un customfield por su shortname desde el array de
 * usuario que devuelve Moodle (core_user_get_users / get_users_by_field).
 */
function moodle_extract_customfield(array $user, string $shortname): ?string {
    foreach ($user['customfields'] ?? [] as $field) {
        if (($field['shortname'] ?? $field['name'] ?? null) === $shortname) {
            return (string) $field['value'];
        }
    }
    return null;
}

/**
 * Llamada genérica POST a Moodle Web Services REST. Devuelve el JSON decodificado.
 */
function moodle_rest_call(string $moodleBaseUrl, array $params): mixed {
    $ch = curl_init(rtrim($moodleBaseUrl, '/') . '/webservice/rest/server.php');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        // OJO: pasar el array directamente a CURLOPT_POSTFIELDS hace que
        // PHP-curl use multipart/form-data y APLANE mal los arreglos
        // anidados (values, criteria, users, customfields), perdiendo la
        // notación values[0]=... que la API REST de Moodle exige. Por eso
        // se serializa explícitamente con http_build_query(), que sí
        // preserva la notación de índices/corchetes correcta.
        CURLOPT_POSTFIELDS => http_build_query($params),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($response === false) {
        throw new RuntimeException("Error de red al llamar a Moodle: {$curlError}");
    }
    if ($httpCode < 200 || $httpCode >= 300) {
        throw new RuntimeException("Moodle respondió HTTP {$httpCode}: {$response}");
    }
    return json_decode($response, true);
}

/**
 * Registro simple a stderr (visible en los logs de Render).
 */
function log_event(string $message, array $context = []): void {
    $line = '[' . date('c') . '] ' . $message;
    if (!empty($context)) {
        $line .= ' ' . json_encode($context, JSON_UNESCAPED_SLASHES);
    }
    // Se escribe explícitamente a php://stderr en vez de usar error_log()
    // "a secas": en la imagen php:8.2-apache, error_log() por defecto puede
    // ir al log interno de Apache (invisible para Render), en vez de al
    // stderr del contenedor (lo único que Render captura). Escribir al
    // stream directamente garantiza que la línea aparezca en los logs.
    file_put_contents('php://stderr', $line . "\n", FILE_APPEND);
}

/**
 * Recorre los usuarios actualmente desuspendidos y re-suspende a quienes ya
 * vencieron su ventana de sesión (customfield diditsessionexpiry). La usan
 * tanto cli/resuspend.php (si se paga un Cron Job de Render) como
 * public/cron-resuspend.php (gratis, disparado por un pinger externo).
 *
 * @return array{checked:int, resuspended:int, errors:int}
 */
function run_resuspend_job(string $moodleBaseUrl, string $moodleToken): array {
    $now = time();
    $checked = 0;
    $reSuspended = 0;
    $errors = 0;

    $users = moodle_list_users_by_criteria($moodleBaseUrl, $moodleToken, 'suspended', '0');

    foreach ($users as $user) {
        $checked++;
        $userId = (int) $user['id'];

        // Nunca tocar cuentas administrativas / de servicio.
        if (in_array($user['username'] ?? '', ['admin', 'guest'], true)) {
            continue;
        }

        $expiry = moodle_extract_customfield($user, 'diditsessionexpiry');
        if ($expiry === null || $expiry === '') {
            continue;
        }

        if ((int) $expiry <= $now) {
            try {
                moodle_update_user($moodleBaseUrl, $moodleToken, $userId, ['suspended' => 1]);
                $reSuspended++;
                log_event('resuspend: cuenta re-suspendida', ['userid' => $userId, 'expiry' => $expiry]);
            } catch (Throwable $e) {
                $errors++;
                log_event('resuspend: error re-suspendiendo', ['userid' => $userId, 'error' => $e->getMessage()]);
            }
        }
    }

    log_event('resuspend: resumen de ejecución', ['checked' => $checked, 'resuspended' => $reSuspended, 'errors' => $errors]);

    return ['checked' => $checked, 'resuspended' => $reSuspended, 'errors' => $errors];
}
