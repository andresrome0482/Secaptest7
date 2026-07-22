<?php
header('Content-Type: application/json');
echo json_encode([
    'service' => 'didit-moodle-webhook',
    'status' => 'ok',
    'time' => date('c'),
]);
